<?php
namespace Wpcb\Payments;

final class StripeAdapter implements PaymentAdapterInterface {
    private StripeConfig $config;
    private string $apiBase;

    public function __construct(?StripeConfig $config = null, string $apiBase = 'https://api.stripe.com') {
        $this->config = $config ?: new StripeConfig();
        $this->apiBase = rtrim($apiBase, '/');
    }

    public function code(): string {
        return 'stripe';
    }

    public function createPayment(array $context) {
        $secret = $this->config->secretKey();
        if ($secret === '') {
            return new \WP_Error('wpcb_stripe_not_configured', 'Stripe is not configured.');
        }

        $paymentUuid = sanitize_text_field((string)($context['payment_uuid'] ?? ''));
        $existingReference = sanitize_text_field((string)($context['provider_reference'] ?? ''));
        $amount = (int)($context['amount_minor'] ?? 0);
        $currency = strtolower(sanitize_text_field((string)($context['currency'] ?? '')));
        if ($paymentUuid === '' || $amount < 1 || !preg_match('/^[a-z]{3}$/', $currency)) {
            return new \WP_Error('wpcb_stripe_context_invalid', 'Stripe checkout context is invalid.');
        }

        if ($existingReference !== '') {
            $existing = $this->request(
                'GET',
                '/v1/checkout/sessions/' . rawurlencode($existingReference),
                [],
                $secret
            );
            if (is_wp_error($existing)) {
                return $existing;
            }
            $status = sanitize_key((string)($existing['status'] ?? ''));
            if ($status === 'open') {
                $url = $this->checkoutUrl((string)($existing['url'] ?? ''));
                if ($url === '') {
                    return new \WP_Error('wpcb_stripe_checkout_invalid', 'Stripe returned an invalid checkout session.');
                }
                return [
                    'provider_reference' => $existingReference,
                    'checkout_url' => $url,
                ];
            }
            if ($status !== 'expired') {
                return new \WP_Error('wpcb_stripe_checkout_not_resumable', 'Stripe checkout cannot be resumed yet.');
            }
        }

        $body = [
            'mode' => 'payment',
            'client_reference_id' => $paymentUuid,
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][product_data][name]' => 'WordPress Calendar Booking',
            'line_items[0][price_data][unit_amount]' => (string)$amount,
            'line_items[0][quantity]' => '1',
            'metadata[payment_uuid]' => $paymentUuid,
            'payment_intent_data[metadata][payment_uuid]' => $paymentUuid,
            'success_url' => add_query_arg([
                'wpcb_payment_return' => 'success',
                'wpcb_payment_uuid' => $paymentUuid,
            ], home_url('/')),
            'cancel_url' => add_query_arg([
                'wpcb_payment_return' => 'cancelled',
                'wpcb_payment_uuid' => $paymentUuid,
            ], home_url('/')),
        ];

        $result = $this->request('POST', '/v1/checkout/sessions', $body, $secret);
        if (is_wp_error($result)) {
            return $result;
        }
        $reference = sanitize_text_field((string)($result['id'] ?? ''));
        $url = $this->checkoutUrl((string)($result['url'] ?? ''));
        if ($reference === '' || $url === '') {
            return new \WP_Error('wpcb_stripe_checkout_invalid', 'Stripe returned an invalid checkout session.');
        }

        return [
            'provider_reference' => $reference,
            'checkout_url' => $url,
        ];
    }

    public function refund(array $context) {
        $secret = $this->config->secretKey();
        if ($secret === '') {
            return new \WP_Error('wpcb_stripe_not_configured', 'Stripe is not configured.');
        }
        $sessionId = sanitize_text_field((string)($context['provider_reference'] ?? ''));
        if ($sessionId === '') {
            return new \WP_Error('wpcb_stripe_reference_missing', 'Stripe checkout reference is missing.');
        }

        $session = $this->request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), [], $secret);
        if (is_wp_error($session)) {
            return $session;
        }
        $intent = sanitize_text_field((string)($session['payment_intent'] ?? ''));
        if ($intent === '') {
            return new \WP_Error('wpcb_stripe_payment_intent_missing', 'Stripe payment intent is missing.');
        }

        $amount = max(0, (int)($context['amount_minor'] ?? 0));
        if ($amount < 1) {
            return new \WP_Error('wpcb_stripe_refund_amount_invalid', 'Stripe refund amount is invalid.');
        }
        $refund = $this->request(
            'POST',
            '/v1/refunds',
            ['payment_intent' => $intent, 'amount' => (string)$amount],
            $secret,
            ['Idempotency-Key' => sanitize_text_field((string)($context['idempotency_key'] ?? ''))]
        );
        if (is_wp_error($refund)) {
            return $refund;
        }
        $refundId = sanitize_text_field((string)($refund['id'] ?? ''));
        if ($refundId === '') {
            return new \WP_Error('wpcb_stripe_refund_invalid', 'Stripe returned an invalid refund.');
        }
        return ['provider_event_id' => 'stripe-refund:' . $refundId];
    }

    private function checkoutUrl(string $value): string {
        $url = esc_url_raw($value);
        $host = strtolower((string)wp_parse_url($url, PHP_URL_HOST));
        return $url !== '' && ($host === 'checkout.stripe.com' || str_ends_with($host, '.stripe.com'))
            ? $url
            : '';
    }

    private function request(string $method, string $path, array $body, string $secret, array $extraHeaders = []) {
        $headers = array_merge([
            'Authorization' => 'Bearer ' . $secret,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'WordPress-Calendar-Booking/' . WPCB_VERSION,
        ], array_filter($extraHeaders, static fn($value) => is_string($value) && $value !== ''));
        $args = [
            'method' => $method,
            'timeout' => 15,
            'redirection' => 0,
            'headers' => $headers,
        ];
        if ($method !== 'GET') {
            $args['body'] = $body;
        }
        $response = wp_remote_request($this->apiBase . $path, $args);
        if (is_wp_error($response)) {
            return new \WP_Error('wpcb_stripe_http', 'Stripe request failed.');
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            return new \WP_Error('wpcb_stripe_api', 'Stripe rejected the request.');
        }
        return $decoded;
    }
}
