<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_stripe_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$config = new Wpcb\Payments\StripeConfig();
$saved = $config->save([
    'enabled' => 1,
    'secret_key' => 'sk_test_super_secret',
    'webhook_secret' => 'whsec_test_super_secret',
]);
wpcb_stripe_assert($saved === true && $config->ready(), 'Stripe configuration is encrypted and ready.');
$rawConfig = (array)get_option('wpcb_stripe_settings', []);
$encodedConfig = wp_json_encode($rawConfig);
wpcb_stripe_assert(strpos($encodedConfig, 'sk_test_super_secret') === false, 'Stripe secret key is not stored in plaintext.');
wpcb_stripe_assert(strpos($encodedConfig, 'whsec_test_super_secret') === false, 'Stripe webhook secret is not stored in plaintext.');

$captured = [];
add_filter('pre_http_request', function ($pre, $args, $url) use (&$captured) {
    if (strpos($url, 'https://api.stripe.com/') !== 0) {
        return $pre;
    }
    $captured[] = ['url' => $url, 'args' => $args];

    if ($url === 'https://api.stripe.com/v1/checkout/sessions' && ($args['method'] ?? '') === 'POST') {
        return [
            'headers' => [],
            'body' => wp_json_encode([
                'id' => 'cs_test_wpcb_123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_wpcb_123',
            ]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if ($url === 'https://api.stripe.com/v1/checkout/sessions/cs_test_wpcb_123' && ($args['method'] ?? '') === 'GET') {
        return [
            'headers' => [],
            'body' => wp_json_encode(['id' => 'cs_test_wpcb_123', 'payment_intent' => 'pi_test_wpcb_123']),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if ($url === 'https://api.stripe.com/v1/refunds' && ($args['method'] ?? '') === 'POST') {
        return [
            'headers' => [],
            'body' => wp_json_encode(['id' => 're_test_wpcb_123', 'status' => 'succeeded']),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    return new WP_Error('unexpected_stripe_request', 'Unexpected Stripe request: ' . $url);
}, 10, 3);

$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeTable = $wpdb->prefix . 'wpcb_booking_types';
$wpdb->insert($typeTable, [
    'name' => 'Stripe Fixture',
    'slug' => 'stripe-fixture-' . wp_generate_password(6, false),
    'description' => '',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'capacity' => 1,
    'show_remaining_capacity' => 0,
    'payment_mode' => 'required',
    'price_minor' => 12900,
    'currency' => 'EUR',
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 99,
    'created_at' => $now,
    'updated_at' => $now,
]);
$typeId = (int)$wpdb->insert_id;
$resourceId = (int)get_option('wpcb_default_resource_id', 0);

$bookings = new Wpcb\Booking\BookingRepository();
$bookingId = $bookings->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2035-04-10 10:00:00',
    'slot_end' => '2035-04-10 10:30:00',
    'status' => Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED,
    'party_size' => 1,
    'full_name' => 'Stripe Private Person',
    'email' => 'stripe-private@example.com',
    'phone' => '+43123456789',
    'notes' => 'PRIVATE STRIPE NOTE',
    'source' => 'stripe-smoke',
    'lang' => 'de',
    'reserved_until' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+30 minutes')),
    'created_at' => $now,
    'updated_at' => $now,
]);
wpcb_stripe_assert($bookingId > 0, 'Stripe fixture booking is created.');

$service = new Wpcb\Payments\PaymentService();
$started = $service->begin($bookingId, new Wpcb\Payments\StripeAdapter($config));
wpcb_stripe_assert(is_object($started) && $started->provider === 'stripe', 'Stripe Checkout attaches its provider reference.');
wpcb_stripe_assert((string)$started->provider_reference === 'cs_test_wpcb_123', 'Stripe Checkout session ID is stored as the technical reference.');
wpcb_stripe_assert(strpos((string)$started->checkout_url, 'checkout.stripe.com/') !== false, 'Stripe Checkout returns a hosted checkout URL.');

$checkoutRequest = $captured[0]['args']['body'] ?? [];
$checkoutJson = wp_json_encode($checkoutRequest);
foreach (['stripe-private@example.com', 'Stripe Private Person', '+43123456789', 'PRIVATE STRIPE NOTE'] as $private) {
    wpcb_stripe_assert(strpos($checkoutJson, $private) === false, 'Stripe checkout payload contains no booking/customer PII.');
}
wpcb_stripe_assert((string)($checkoutRequest['line_items[0][price_data][unit_amount]'] ?? '') === '12900', 'Stripe checkout uses the server-side payment amount.');
wpcb_stripe_assert((string)($checkoutRequest['line_items[0][price_data][currency]'] ?? '') === 'eur', 'Stripe checkout uses the server-side currency.');

$payment = (new Wpcb\Payments\PaymentRepository())->forBooking($bookingId);
wpcb_stripe_assert($payment !== null, 'Stripe payment record exists for return-flow checks.');
$successUrl = (string)($checkoutRequest['success_url'] ?? '');
$cancelUrl = (string)($checkoutRequest['cancel_url'] ?? '');
wpcb_stripe_assert(strpos($successUrl, 'wpcb_payment_return=success') !== false && strpos($successUrl, rawurlencode((string)$payment->payment_uuid)) !== false, 'Stripe success return carries only the technical payment UUID.');
wpcb_stripe_assert(strpos($cancelUrl, 'wpcb_payment_return=cancelled') !== false && strpos($cancelUrl, rawurlencode((string)$payment->payment_uuid)) !== false, 'Stripe cancel return carries only the technical payment UUID.');
foreach (['stripe-private@example.com', 'Stripe Private Person', '+43123456789', 'PRIVATE STRIPE NOTE'] as $private) {
    wpcb_stripe_assert(strpos($successUrl . $cancelUrl, $private) === false, 'Stripe return URLs contain no customer PII.');
}
$returnController = new Wpcb\Payments\StripeReturnController();
$beforeReturn = (new Wpcb\Payments\PaymentRepository())->forBooking($bookingId);
$pendingView = $returnController->viewModel((string)$payment->payment_uuid, 'success');
$cancelView = $returnController->viewModel((string)$payment->payment_uuid, 'cancelled');
$afterReturn = (new Wpcb\Payments\PaymentRepository())->forBooking($bookingId);
wpcb_stripe_assert($pendingView['state'] === 'pending' && $cancelView['state'] === 'pending', 'Success/cancel return pages do not claim payment before webhook confirmation.');
wpcb_stripe_assert((string)$beforeReturn->status === (string)$afterReturn->status && (string)$afterReturn->status === 'pending', 'Read-only return status lookup does not mutate payment state.');
wpcb_stripe_assert($returnController->viewModel('not-a-payment', 'success')['state'] === 'unknown', 'Malformed payment UUID returns a generic safe state.');

$payload = wp_json_encode([
    'id' => 'evt_wpcb_paid_1',
    'type' => 'checkout.session.completed',
    'data' => ['object' => [
        'id' => 'cs_test_wpcb_123',
        'amount_total' => 12900,
        'currency' => 'eur',
        'payment_status' => 'paid',
        'metadata' => ['payment_uuid' => (string)$payment->payment_uuid],
    ]],
]);
$timestamp = time();
$signature = hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_test_super_secret');
$header = 't=' . $timestamp . ',v1=' . $signature;
wpcb_stripe_assert(
    Wpcb\Payments\StripeWebhookController::verifySignature($payload, $header, 'whsec_test_super_secret', $timestamp),
    'Stripe webhook signature verifies with timestamp tolerance.'
);
wpcb_stripe_assert(
    !Wpcb\Payments\StripeWebhookController::verifySignature($payload, 't=' . $timestamp . ',v1=' . str_repeat('0', 64), 'whsec_test_super_secret', $timestamp),
    'Invalid Stripe webhook signature is rejected.'
);

$unpaidPayload = wp_json_encode([
    'id' => 'evt_wpcb_unpaid_completed',
    'type' => 'checkout.session.completed',
    'data' => ['object' => [
        'id' => 'cs_test_wpcb_123',
        'amount_total' => 12900,
        'currency' => 'eur',
        'payment_status' => 'unpaid',
        'metadata' => ['payment_uuid' => (string)$payment->payment_uuid],
    ]],
]);
$unpaidSignature = hash_hmac('sha256', $timestamp . '.' . $unpaidPayload, 'whsec_test_super_secret');
$unpaidRequest = new WP_REST_Request('POST', '/wpcb/v1/payments/stripe/webhook');
$unpaidRequest->set_body($unpaidPayload);
$unpaidRequest->set_header('stripe-signature', 't=' . $timestamp . ',v1=' . $unpaidSignature);
$unpaidResponse = (new Wpcb\Payments\StripeWebhookController())->handle($unpaidRequest);
wpcb_stripe_assert($unpaidResponse instanceof WP_REST_Response && !empty($unpaidResponse->get_data()['ignored']), 'Completed Checkout with unpaid status does not confirm payment.');
wpcb_stripe_assert((new Wpcb\Payments\PaymentRepository())->forBooking($bookingId)->status === 'pending', 'Unpaid Checkout leaves payment pending.');

$request = new WP_REST_Request('POST', '/wpcb/v1/payments/stripe/webhook');
$request->set_body($payload);
$request->set_header('stripe-signature', $header);
$response = (new Wpcb\Payments\StripeWebhookController())->handle($request);
wpcb_stripe_assert($response instanceof WP_REST_Response && $response->get_status() === 200, 'Verified Stripe webhook is accepted.');
$paid = (new Wpcb\Payments\PaymentRepository())->forBooking($bookingId);
wpcb_stripe_assert($paid && $paid->status === 'paid', 'Completed Stripe Checkout marks the payment paid.');
$paidView = $returnController->viewModel((string)$payment->payment_uuid, 'success');
wpcb_stripe_assert($paidView['state'] === 'paid', 'Return page shows paid only after verified webhook state exists.');

$retry = (new Wpcb\Payments\StripeWebhookController())->handle($request);
wpcb_stripe_assert($retry instanceof WP_REST_Response && $retry->get_status() === 200, 'Duplicate Stripe webhook is idempotent.');

$confirmed = (new Wpcb\Booking\BookingTransitionService())->apply(
    $bookingId,
    Wpcb\Booking\BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
    'test',
    'Stripe confirmation fixture'
);
wpcb_stripe_assert(is_array($confirmed) && !empty($confirmed['changed']), 'Paid Stripe reservation can transition to confirmed.');

$bad = new WP_REST_Request('POST', '/wpcb/v1/payments/stripe/webhook');
$bad->set_body($payload);
$bad->set_header('stripe-signature', 't=' . $timestamp . ',v1=' . str_repeat('0', 64));
$badResult = (new Wpcb\Payments\StripeWebhookController())->handle($bad);
wpcb_stripe_assert(is_wp_error($badResult) && $badResult->get_error_code() === 'wpcb_stripe_signature', 'Invalid signed webhook is rejected before lifecycle processing.');

$cancelled = (new Wpcb\Booking\BookingTransitionService())->apply(
    $bookingId,
    Wpcb\Booking\BookingStateMachine::USER_CANCELLED,
    'test',
    'Stripe refund fixture'
);
wpcb_stripe_assert(is_array($cancelled) && !empty($cancelled['changed']), 'Paid Stripe booking can enter cancellation.');
$refundPending = (new Wpcb\Payments\PaymentRepository())->forBooking($bookingId);
wpcb_stripe_assert($refundPending && $refundPending->status === 'refund_pending', 'Cancellation moves Stripe payment to refund pending.');

$refunded = $service->refund((int)$refundPending->id, new Wpcb\Payments\StripeAdapter($config));
wpcb_stripe_assert(is_object($refunded) && $refunded->status === 'refunded', 'Stripe refund reaches refunded state.');
wpcb_stripe_assert(count($captured) === 3, 'Stripe flow performs checkout, session lookup and refund requests only.');

$paymentIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wpcb_payments WHERE booking_id = %d", $bookingId));
foreach ($paymentIds as $paymentId) {
    $wpdb->delete($wpdb->prefix . 'wpcb_payment_events', ['payment_id' => (int)$paymentId]);
}
$wpdb->delete($wpdb->prefix . 'wpcb_payments', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
$wpdb->delete($typeTable, ['id' => $typeId]);
delete_option('wpcb_stripe_settings');

WP_CLI::success('Stripe Checkout smoke test passed.');
