<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_api_webhook_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

if (!did_action('rest_api_init')) {
    do_action('rest_api_init');
}

$server = rest_get_server();
$public = new WP_REST_Request('GET', '/wpcb/v1/booking-types');
$publicResponse = $server->dispatch($public);
wpcb_api_webhook_assert($publicResponse->get_status() === 200, 'Public booking-type REST endpoint responds.');
$publicData = $publicResponse->get_data();
wpcb_api_webhook_assert(isset($publicData['items']) && is_array($publicData['items']), 'Public booking-type REST response is structured.');
wpcb_api_webhook_assert(strpos(wp_json_encode($publicData), 'credentials') === false, 'Public REST response excludes provider credentials.');

wp_set_current_user(0);
$denied = $server->dispatch(new WP_REST_Request('GET', '/wpcb/v1/bookings'));
wpcb_api_webhook_assert(in_array($denied->get_status(), [401,403], true), 'Booking administration REST endpoint requires authentication/capability.');

$admin = get_user_by('login', 'admin');
wpcb_api_webhook_assert($admin !== false, 'REST/webhook smoke admin user exists.');
wp_set_current_user((int)$admin->ID);

$types = (new Wpcb\Booking\BookingTypeRepository())->all(true);
$type = $types[0] ?? null;
wpcb_api_webhook_assert($type !== null, 'A public booking type is available for REST/webhook smoke coverage.');
$typeId = (int)$type->id;

$availability = new WP_REST_Request('GET', '/wpcb/v1/availability/' . $typeId);
$availability->set_param('days', 21);
$availability->set_param('party_size', 1);
$availabilityResponse = $server->dispatch($availability);
$availabilityData = $availabilityResponse->get_data();
$apiSlots = (array)($availabilityData['items'] ?? []);
wpcb_api_webhook_assert($availabilityResponse->get_status() === 200 && !empty($apiSlots), 'Public availability REST endpoint returns signed slots.');
wpcb_api_webhook_assert(!isset($apiSlots[0]['resource_id']), 'Public availability API does not expose private resource IDs.');
wpcb_api_webhook_assert(!empty($apiSlots[0]['slot_token']), 'Public availability API exposes a signed booking token.');

$slotService = new Wpcb\Availability\SlotService();
$slots = $slotService->getSlots($typeId, 21);
$slot = $slots[0] ?? null;
wpcb_api_webhook_assert($slot !== null, 'A canonical slot is available for REST transition fixture.');
$token = (new Wpcb\Tokens\SlotTokenService())->issue(
    $typeId,
    (string)$slot['start'],
    (string)$slot['end'],
    (int)$slot['resource_id']
);
$reservation = new Wpcb\Booking\ReservationService();
$bookingId = $reservation->reserve($token, $typeId, [
    'full_name' => 'API Idempotency Person',
    'email' => 'api-idempotency@example.com',
    'phone' => '',
    'notes' => '',
    'source' => 'test',
    'lang' => 'en',
    'party_size' => 1,
]);
wpcb_api_webhook_assert(!is_wp_error($bookingId) && (int)$bookingId > 0, 'REST transition fixture booking is reserved.');
$bookingId = (int)$bookingId;

$pending = (new Wpcb\Booking\BookingTransitionService())->apply(
    $bookingId,
    Wpcb\Booking\BookingStateMachine::EMAIL_CONFIRMED_APPROVAL,
    'test',
    'REST idempotency fixture'
);
wpcb_api_webhook_assert(is_array($pending) && !empty($pending['changed']), 'REST transition fixture reaches pending approval.');

$idempotencyKey = 'rest-transition-' . wp_generate_password(24, false, false);
$transition = new WP_REST_Request('POST', '/wpcb/v1/bookings/' . $bookingId . '/transition');
$transition->set_header('Idempotency-Key', $idempotencyKey);
$transition->set_param('event', Wpcb\Booking\BookingStateMachine::ADMIN_APPROVED);
$transitionResponse = $server->dispatch($transition);
$transitionData = $transitionResponse->get_data();
wpcb_api_webhook_assert($transitionResponse->get_status() === 200, 'Authenticated REST lifecycle transition succeeds.');
wpcb_api_webhook_assert(!empty($transitionData['transition']['changed']), 'First idempotent REST transition performs the state change.');

$replay = new WP_REST_Request('POST', '/wpcb/v1/bookings/' . $bookingId . '/transition');
$replay->set_header('Idempotency-Key', $idempotencyKey);
$replay->set_param('event', Wpcb\Booking\BookingStateMachine::ADMIN_APPROVED);
$replayResponse = $server->dispatch($replay);
wpcb_api_webhook_assert($replayResponse->get_status() === 200, 'Idempotent REST mutation can be replayed safely.');
wpcb_api_webhook_assert($replayResponse->get_data() === $transitionData, 'Idempotent REST replay returns the original response.');

global $wpdb;
$approvalEvents = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_status_log WHERE booking_id=%d AND context=%s",
    $bookingId,
    Wpcb\Booking\BookingStateMachine::ADMIN_APPROVED
));
wpcb_api_webhook_assert($approvalEvents === 1, 'Idempotent REST replay does not duplicate lifecycle transitions.');

$secret = 'ci-webhook-secret-' . wp_generate_password(32, false, false);
$endpointRepo = new Wpcb\Webhooks\WebhookEndpointRepository();
$endpointId = $endpointRepo->save([
    'name' => 'CI Webhook',
    'url' => 'https://example.com/wpcb-hook',
    'secret' => $secret,
    'events' => ['booking.created'],
    'is_active' => 1,
]);
wpcb_api_webhook_assert(!is_wp_error($endpointId) && (int)$endpointId > 0, 'Webhook endpoint is stored with encrypted secret.');
$endpointId = (int)$endpointId;
$listedEndpoint = $endpointRepo->all(false)[0] ?? null;
wpcb_api_webhook_assert($listedEndpoint && !property_exists($listedEndpoint, 'secret_enc'), 'Webhook endpoint listing never returns encrypted secret material.');

$httpAttempts = 0;
$signatureVerified = false;
$payloadSafe = false;
$httpFilter = static function ($preempt, $args, $url) use (&$httpAttempts, &$signatureVerified, &$payloadSafe, $secret) {
    if ($url !== 'https://example.com/wpcb-hook') {
        return $preempt;
    }
    ++$httpAttempts;
    $body = (string)($args['body'] ?? '');
    $headers = (array)($args['headers'] ?? []);
    $timestamp = (int)($headers['X-WPCB-Timestamp'] ?? 0);
    $signature = (string)($headers['X-WPCB-Signature'] ?? '');
    $signatureVerified = Wpcb\Webhooks\WebhookSigner::verify($secret, $timestamp, $body, $signature);
    $decoded = json_decode($body, true);
    $payloadSafe = is_array($decoded)
        && ($decoded['event'] ?? '') === 'booking.created'
        && !array_key_exists('notes', (array)($decoded['booking'] ?? []))
        && strpos($body, $secret) === false;
    if ($httpAttempts === 1) {
        return new WP_Error('ci_webhook_failure', 'Simulated retryable transport failure secret=DO-NOT-LEAK');
    }
    return [
        'headers' => [],
        'body' => '',
        'response' => ['code' => 204, 'message' => 'No Content'],
        'cookies' => [],
        'filename' => null,
    ];
};
add_filter('pre_http_request', $httpFilter, 10, 3);

$slots = $slotService->getSlots($typeId, 21);
$webhookSlot = $slots[0] ?? null;
wpcb_api_webhook_assert($webhookSlot !== null, 'A second slot is available for webhook fixture.');
$webhookToken = (new Wpcb\Tokens\SlotTokenService())->issue(
    $typeId,
    (string)$webhookSlot['start'],
    (string)$webhookSlot['end'],
    (int)$webhookSlot['resource_id']
);
$webhookBookingId = $reservation->reserve($webhookToken, $typeId, [
    'full_name' => 'Webhook Person',
    'email' => 'webhook@example.com',
    'phone' => '',
    'notes' => 'PRIVATE NOTE MUST NOT ENTER WEBHOOK',
    'source' => 'test',
    'lang' => 'en',
    'party_size' => 1,
]);
wpcb_api_webhook_assert(!is_wp_error($webhookBookingId) && (int)$webhookBookingId > 0, 'Booking creation enqueues subscribed webhook event.');
$webhookBookingId = (int)$webhookBookingId;

$jobs = new Wpcb\Webhooks\WebhookJobRepository();
$queued = $jobs->search(['endpoint_id' => $endpointId], 10);
wpcb_api_webhook_assert(count($queued) === 1, 'One logical booking event creates exactly one endpoint delivery job.');
$jobId = (int)$queued[0]->id;

$service = new Wpcb\Webhooks\WebhookService();
$service->processPending(10);
$failed = $jobs->find($jobId);
wpcb_api_webhook_assert($failed && $failed->status === 'failed' && (int)$failed->attempts === 1, 'Failed webhook delivery is retained for retry.');
wpcb_api_webhook_assert(strpos((string)$failed->last_error, 'DO-NOT-LEAK') === false, 'Webhook job error redacts obvious secret values.');
wpcb_api_webhook_assert($signatureVerified && $payloadSafe, 'Webhook request has independently verifiable HMAC and privacy-safe payload.');

$wpdb->update($wpdb->prefix . 'wpcb_webhook_jobs', [
    'next_attempt_at' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-1 minute')),
], ['id' => $jobId]);
$service->processPending(10);
$sent = $jobs->find($jobId);
wpcb_api_webhook_assert($sent && $sent->status === 'sent' && (int)$sent->attempts === 2, 'Webhook retry succeeds on the same logical delivery job.');
wpcb_api_webhook_assert($httpAttempts === 2, 'Webhook retry performs exactly two transport attempts.');

$delivery = (new Wpcb\Reliability\DeliveryRepository())->findByKey('webhook:' . $endpointId . ':' . $sent->event_id);
wpcb_api_webhook_assert($delivery && $delivery->status === 'sent', 'Webhook delivery is visible in the existing reliability ledger.');
remove_filter('pre_http_request', $httpFilter, 10);

foreach ([$bookingId, $webhookBookingId] as $cleanupId) {
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $cleanupId]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $cleanupId]);
    $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $cleanupId]);
}
$wpdb->delete($wpdb->prefix . 'wpcb_deliveries', ['booking_id' => $webhookBookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_webhook_jobs', ['endpoint_id' => $endpointId]);
$wpdb->delete($wpdb->prefix . 'wpcb_webhook_endpoints', ['id' => $endpointId]);
$wpdb->query("DELETE FROM {$wpdb->prefix}wpcb_api_idempotency WHERE route LIKE 'booking-transition:%'");

echo "PASS: REST API and webhook smoke test complete.\n";
