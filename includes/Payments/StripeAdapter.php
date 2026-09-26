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
            return new \WP_Error('wpcb_stripe_not_configured', 'Stripe is not configured.', ['refund_outcome'=>'not_submitted']);
        }

        $amount = max(0, (int)($context['amount_minor'] ?? 0));
        $currency = strtoupper(sanitize_text_field((string)($context['currency'] ?? '')));
        $paymentUuid = sanitize_text_field((string)($context['payment_uuid'] ?? ''));
        $refundUuid = sanitize_text_field((string)($context['refund_uuid'] ?? ''));
        $existingRefundId = sanitize_text_field((string)($context['provider_refund_id'] ?? ''));
        $idempotencyKey = sanitize_text_field((string)($context['idempotency_key'] ?? ''));
        if ($amount < 1 || !preg_match('/^[A-Z]{3}$/', $currency)
            || $paymentUuid === '' || $refundUuid === '' || $idempotencyKey === ''
        ) {
            return new \WP_Error('wpcb_stripe_refund_context_invalid', 'Stripe refund context is invalid.', ['refund_outcome'=>'not_submitted']);
        }

        if ($existingRefundId !== '') {
            $refund = $this->request(
                'GET',
                '/v1/refunds/' . rawurlencode($existingRefundId),
                [],
                $secret
            );
            if (is_wp_error($refund)) {
                return $this->refundRequestError($refund, true);
            }
            return $this->refundResult($refund, $amount, $currency);
        }

        $sessionId = sanitize_text_field((string)($context['provider_reference'] ?? ''));
        if ($sessionId === '') {
            return new \WP_Error('wpcb_stripe_reference_missing', 'Stripe checkout reference is missing.', ['refund_outcome'=>'not_submitted']);
        }

        $session = $this->request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), [], $secret);
        if (is_wp_error($session)) {
            return new \WP_Error(
                $session->get_error_code(),
                $session->get_error_message(),
                ['refund_outcome'=>'not_submitted']
            );
        }
        $intent = sanitize_text_field((string)($session['payment_intent'] ?? ''));
        if ($intent === '') {
            return new \WP_Error('wpcb_stripe_payment_intent_missing', 'Stripe payment intent is missing.', ['refund_outcome'=>'not_submitted']);
        }

        $refund = $this->request(
            'POST',
            '/v1/refunds',
            [
                'payment_intent' => $intent,
                'amount' => (string)$amount,
                'metadata[payment_uuid]' => $paymentUuid,
                'metadata[refund_uuid]' => $refundUuid,
            ],
            $secret,
            ['Idempotency-Key' => $idempotencyKey]
        );
        if (is_wp_error($refund)) {
            return $this->refundRequestError($refund, false);
        }
        return $this->refundResult($refund, $amount, $currency);
    }

    private function refundResult(array $refund, int $expectedAmount, string $expectedCurrency) {
        $refundId = sanitize_text_field((string)($refund['id'] ?? ''));
        $status = sanitize_key((string)($refund['status'] ?? ''));
        $amount = (int)($refund['amount'] ?? 0);
        $currency = strtoupper(sanitize_text_field((string)($refund['currency'] ?? '')));
        if ($refundId === '' || !in_array($status, PaymentRefundStatus::providerStatuses(), true)
            || $amount !== $expectedAmount || !hash_equals($expectedCurrency, $currency)
        ) {
            return new \WP_Error(
                'wpcb_stripe_refund_invalid',
                'Stripe returned an invalid refund state.',
                ['refund_outcome'=>'uncertain']
            );
        }
        return [
            'provider_refund_id' => $refundId,
            'provider_status' => $status,
            'amount_minor' => $amount,
            'currency' => $currency,
            'failure_reason' => sanitize_key((string)($refund['failure_reason'] ?? '')),
            'provider_created_at' => max(0, (int)($refund['created'] ?? 0)),
        ];
    }

    private function refundRequestError(\WP_Error $error, bool $checkingExisting): \WP_Error {
        $data = $error->get_error_data();
        $status = is_array($data) ? (int)($data['http_status'] ?? 0) : 0;
        $uncertain = $checkingExisting
            || $error->get_error_code() === 'wpcb_stripe_http'
            || $status >= 500;
        return new \WP_Error(
            $error->get_error_code(),
            $error->get_error_message(),
            ['refund_outcome'=>$uncertain ? 'uncertain' : 'not_submitted']
        );
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
            return new \WP_Error('wpcb_stripe_http', 'Stripe request failed.', ['http_status'=>0]);
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            return new \WP_Error('wpcb_stripe_api', 'Stripe rejected the request.', ['http_status'=>$status]);
        }
        return $decoded;
    }
}
