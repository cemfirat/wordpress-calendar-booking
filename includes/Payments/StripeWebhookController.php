<?php
namespace Wpcb\Payments;

final class StripeWebhookController {
    public function boot(): void {
        add_action('rest_api_init', function (): void {
            register_rest_route('wpcb/v1', '/payments/stripe/webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(\WP_REST_Request $request) {
        $config = new StripeConfig();
        $secret = $config->webhookSecret();
        if ($secret === '') {
            return new \WP_Error('wpcb_stripe_webhook_unconfigured', 'Stripe webhook is not configured.', ['status' => 503]);
        }

        $body = (string)$request->get_body();
        $signature = (string)$request->get_header('stripe-signature');
        if (!self::verifySignature($body, $signature, $secret)) {
            return new \WP_Error('wpcb_stripe_signature', 'Invalid Stripe signature.', ['status' => 400]);
        }

        $event = json_decode($body, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type']) || !is_array($event['data']['object'] ?? null)) {
            return new \WP_Error('wpcb_stripe_event_invalid', 'Invalid Stripe event.', ['status' => 400]);
        }

        $eventId = sanitize_text_field((string)$event['id']);
        $type = (string)$event['type'];
        $object = $event['data']['object'];
        $mapped = match ($type) {
            'checkout.session.completed' => 'paid',
            'checkout.session.expired', 'checkout.session.async_payment_failed' => 'failed',
            'charge.refunded' => 'refunded',
            default => '',
        };
        if ($mapped === '') {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }

        $repo = new PaymentRepository();
        $reference = '';
        if (str_starts_with($type, 'checkout.session.')) {
            $reference = sanitize_text_field((string)($object['id'] ?? ''));
        } else {
            $uuid = sanitize_text_field((string)($object['metadata']['payment_uuid'] ?? ''));
            $payment = $uuid !== '' ? $repo->findByUuid($uuid) : null;
            $reference = $payment ? (string)$payment->provider_reference : '';
        }
        if ($reference === '') {
            return new \WP_Error('wpcb_stripe_reference_unknown', 'Stripe payment reference is unknown.', ['status' => 404]);
        }

        $amount = (int)($object['amount_total'] ?? $object['amount_refunded'] ?? 0);
        $currency = strtoupper(sanitize_text_field((string)($object['currency'] ?? '')));
        $result = (new PaymentService())->applyProviderEvent('stripe', $eventId, $reference, $mapped, $amount, $currency);
        if (is_wp_error($result)) {
            return new \WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 400]);
        }

        return new \WP_REST_Response(['received' => true], 200);
    }

    public static function verifySignature(string $payload, string $header, string $secret, ?int $now = null): bool {
        if ($payload === '' || $header === '' || $secret === '') {
            return false;
        }
        $timestamp = 0;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int)$value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/i', $value)) {
                $signatures[] = strtolower($value);
            }
        }
        $now = $now ?? time();
        if ($timestamp < 1 || abs($now - $timestamp) > 300 || !$signatures) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }
        return false;
    }
}
