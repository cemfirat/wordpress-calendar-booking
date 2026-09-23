<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_resume_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$config = new Wpcb\Payments\StripeConfig();
$config->save([
    'enabled' => 1,
    'secret_key' => 'sk_test_resume_secret',
    'webhook_secret' => 'whsec_resume_secret',
]);
wpcb_resume_assert($config->ready(), 'Stripe resume fixture is configured.');

$calls = [];
$existingState = 'open';
$newSession = false;
add_filter('pre_http_request', function ($pre, $args, $url) use (&$calls, &$existingState, &$newSession) {
    if (strpos($url, 'https://api.stripe.com/') !== 0) {
        return $pre;
    }
    $calls[] = [$args['method'] ?? 'GET', $url];

    if ($url === 'https://api.stripe.com/v1/checkout/sessions' && ($args['method'] ?? '') === 'POST') {
        $id = $newSession ? 'cs_resume_new' : 'cs_resume_old';
        return [
            'headers' => [],
            'body' => wp_json_encode([
                'id' => $id,
                'url' => 'https://checkout.stripe.com/c/pay/' . $id,
                'status' => 'open',
            ]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    if ($url === 'https://api.stripe.com/v1/checkout/sessions/cs_resume_old' && ($args['method'] ?? '') === 'GET') {
        return [
            'headers' => [],
            'body' => wp_json_encode([
                'id' => 'cs_resume_old',
                'url' => 'https://checkout.stripe.com/c/pay/cs_resume_old',
                'status' => $existingState,
            ]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    return new WP_Error('unexpected_resume_request', 'Unexpected Stripe resume request: ' . $url);
}, 10, 3);

$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeTable = $wpdb->prefix . 'wpcb_booking_types';
$wpdb->insert($typeTable, [
    'name' => 'Stripe Resume Fixture',
    'slug' => 'stripe-resume-' . wp_generate_password(6, false),
    'description' => '',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'capacity' => 1,
    'show_remaining_capacity' => 0,
    'payment_mode' => 'required',
    'price_minor' => 7900,
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
$ownerEmail = 'resume-owner@example.com';
$bookingId = $bookings->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2036-05-06 10:00:00',
    'slot_end' => '2036-05-06 10:30:00',
    'status' => Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED,
    'party_size' => 1,
    'full_name' => 'Resume Owner',
    'email' => $ownerEmail,
    'phone' => '',
    'notes' => '',
    'source' => 'stripe-resume-smoke',
    'lang' => 'de',
    'reserved_until' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+45 minutes')),
    'created_at' => $now,
    'updated_at' => $now,
]);
wpcb_resume_assert($bookingId > 0, 'Resume fixture booking is created.');

$service = new Wpcb\Payments\PaymentService();
$adapter = new Wpcb\Payments\StripeAdapter($config);
$first = $service->begin($bookingId, $adapter);
wpcb_resume_assert(is_object($first) && (string)$first->provider_reference === 'cs_resume_old', 'Initial checkout session is attached.');
wpcb_resume_assert(count($calls) === 1 && $calls[0][0] === 'POST', 'Initial payment creates one Stripe Checkout Session.');

$reused = $service->begin($bookingId, $adapter);
wpcb_resume_assert(is_object($reused) && (string)$reused->provider_reference === 'cs_resume_old', 'Open Stripe Checkout Session is reused.');
wpcb_resume_assert(strpos((string)$reused->checkout_url, 'cs_resume_old') !== false, 'Reused Stripe Checkout returns the existing hosted URL.');
wpcb_resume_assert(count($calls) === 2 && $calls[1][0] === 'GET', 'Resume checks the existing session without creating a duplicate.');

$existingState = 'expired';
$newSession = true;
$replaced = $service->begin($bookingId, $adapter);
wpcb_resume_assert(is_object($replaced) && (string)$replaced->provider_reference === 'cs_resume_new', 'Expired Stripe Checkout Session is replaced on the same payment.');
$payment = (new Wpcb\Payments\PaymentRepository())->forBooking($bookingId);
wpcb_resume_assert($payment && (string)$payment->provider_reference === 'cs_resume_new', 'Replacement provider reference is persisted atomically.');
wpcb_resume_assert((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_payments WHERE booking_id = %d",
    $bookingId
)) === 1, 'Resume never creates a second payment obligation.');
wpcb_resume_assert(count($calls) === 4 && $calls[2][0] === 'GET' && $calls[3][0] === 'POST', 'Expired resume checks then creates exactly one replacement session.');

$sessions = new Wpcb\Portal\CustomerSessionRepository();
$sessionToken = $sessions->create($ownerEmail, 60);
$session = $sessions->authenticate((string)$sessionToken);
wpcb_resume_assert(is_array($session), 'Owner portal session is created.');
$_COOKIE[Wpcb\Portal\CustomerSessionRepository::COOKIE] = (string)$sessionToken;
$_SERVER['REQUEST_URI'] = '/customer-portal/?wpcb_booking=' . $bookingId;
$_SERVER['HTTP_HOST'] = 'example.org';
$_GET = ['wpcb_booking' => $bookingId];
$portal = new Wpcb\Portal\CustomerPortalController();
$html = $portal->shortcode();
wpcb_resume_assert(strpos($html, 'wpcb_portal_payment') !== false, 'Pending owner booking renders a continue-payment action.');
wpcb_resume_assert(strpos($html, (string)$session['csrf']) !== false, 'Continue-payment action is protected by the portal CSRF token.');

$otherToken = $sessions->create('resume-other@example.com', 60);
$_COOKIE[Wpcb\Portal\CustomerSessionRepository::COOKIE] = (string)$otherToken;
$otherHtml = $portal->shortcode();
wpcb_resume_assert(strpos($otherHtml, 'wpcb_portal_payment') === false, 'Another customer cannot see the payment resume action.');

$paid = (new Wpcb\Payments\PaymentRepository())->forBooking($bookingId);
wpcb_resume_assert((new Wpcb\Payments\PaymentRepository())->setStatus((int)$paid->id, 'pending', 'paid'), 'Fixture payment can be settled.');
$_COOKIE[Wpcb\Portal\CustomerSessionRepository::COOKIE] = (string)$sessionToken;
$settledHtml = $portal->shortcode();
wpcb_resume_assert(strpos($settledHtml, 'wpcb_portal_payment') === false, 'Settled payment no longer exposes a resume action.');
$callsBeforeSettled = count($calls);
$settledBegin = $service->begin($bookingId, $adapter);
wpcb_resume_assert(is_object($settledBegin) && empty($settledBegin->checkout_url), 'Settled payment cannot start another Checkout Session.');
wpcb_resume_assert(count($calls) === $callsBeforeSettled, 'Settled payment makes no Stripe resume request.');

$wpdb->update($wpdb->prefix . 'wpcb_payments', ['status' => 'pending'], ['id' => (int)$paid->id]);
$bookings->update($bookingId, [
    'reserved_until' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-1 minute')),
]);
$expiredBegin = $service->begin($bookingId, $adapter);
wpcb_resume_assert(is_wp_error($expiredBegin) && $expiredBegin->get_error_code() === 'wpcb_payment_expired', 'Expired local reservation cannot restart Checkout.');

unset($_COOKIE[Wpcb\Portal\CustomerSessionRepository::COOKIE], $_GET['wpcb_booking']);
$sessions->destroy((string)$sessionToken);
$sessions->destroy((string)$otherToken);
$paymentIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wpcb_payments WHERE booking_id = %d", $bookingId));
foreach ($paymentIds as $paymentId) {
    $wpdb->delete($wpdb->prefix . 'wpcb_payment_events', ['payment_id' => (int)$paymentId]);
}
$wpdb->delete($wpdb->prefix . 'wpcb_payments', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_tokens', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
$wpdb->delete($typeTable, ['id' => $typeId]);
delete_option('wpcb_stripe_settings');

WP_CLI::success('Stripe Checkout resume smoke test passed.');
