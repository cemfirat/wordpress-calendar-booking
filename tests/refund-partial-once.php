<?php
if (!defined('ABSPATH')) { exit(1); }

final class WpcbRefundRaceAdapter implements Wpcb\Payments\PaymentAdapterInterface {
    public function code(): string { return 'race_fake'; }
    public function createPayment(array $context) { return new WP_Error('unused', 'unused'); }
    public function refund(array $context) {
        usleep(250000);
        return [
            'provider_refund_id' => 'race-refund-' . hash('sha256', (string)($context['idempotency_key'] ?? '')),
            'provider_status' => 'succeeded',
            'amount_minor' => (int)($context['amount_minor'] ?? 0),
            'currency' => (string)($context['currency'] ?? ''),
            'provider_created_at' => time(),
        ];
    }
}

$id = (int)getenv('WPCB_TEST_PAYMENT_ID');
$result = (new Wpcb\Payments\PaymentService())->refund($id, new WpcbRefundRaceAdapter());
if (is_wp_error($result)) {
    echo 'REJECTED ' . $result->get_error_code() . PHP_EOL;
    return;
}
echo 'REFUNDED ' . (int)($result->refunded_minor ?? 0) . PHP_EOL;
