<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_api_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

if (!did_action('rest_api_init')) {
    do_action('rest_api_init');
}

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());

$typeId = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}wpcb_booking_types WHERE is_active = 1 AND is_public = 1 ORDER BY id ASC LIMIT 1");
wpcb_api_assert($typeId > 0, 'Public booking type fixture exists.');

$public = new WP_REST_Request('GET', '/wpcb/v1/booking-types');
$response = rest_do_request($public);
wpcb_api_assert($response->get_status() === 200, 'Public booking types endpoint is reachable without authentication.');
$publicData = $response->get_data();
wpcb_api_assert(!empty($publicData['data']), 'Public booking types endpoint returns data.');
wpcb_api_assert(!array_key_exists('admin_notes', $publicData['data'][0]), 'Public booking type schema does not expose admin-only fields.');

wp_set_current_user(0);
$unauthenticated = rest_do_request(new WP_REST_Request('GET', '/wpcb/v1/bookings'));
wpcb_api_assert(in_array($unauthenticated->get_status(), [401, 403], true), 'Booking administration endpoint rejects unauthenticated access.');

$admin = get_user_by('login', 'admin');
wpcb_api_assert($admin !== false, 'WordPress admin fixture exists.');
wp_set_current_user((int)$admin->ID);

$create = new WP_REST_Request('POST', '/wpcb/v1/webhooks/endpoints');
$create->set_header('Content-Type', 'application/json');
$create->set_header('Idempotency-Key', 'endpoint-create-1');
$createBody = [
    'name' => 'API smoke endpoint',
    'url' => 'https://example.com/wpcb-webhook-test',
    'events' => ['booking.created', 'booking.cancelled'],
    'is_active' => true,
];
$create->set_body(wp_json_encode($createBody));
$created = rest_do_request($create);
wpcb_api_assert($created->get_status() === 201, 'Administrator can create a webhook endpoint.');
$createdData = $created->get_data()['data'] ?? [];
$endpointId = (int)($createdData['id'] ?? 0);
$secret = (string)($createdData['secret'] ?? '');
wpcb_api_assert($endpointId > 0 && strlen($secret) >= 32, 'Webhook creation returns the generated secret once.');

$replay = rest_do_request($create);
wpcb_api_assert($replay->get_status() === 201 && ($replay->get_data()['data']['id'] ?? 0) === $endpointId, 'Webhook endpoint creation is idempotent.');

$conflict = new WP_REST_Request('POST', '/wpcb/v1/webhooks/endpoints');
$conflict->set_header('Content-Type', 'application/json');
$conflict->set_header('Idempotency-Key', 'endpoint-create-1');
$conflict->set_body(wp_json_encode(array_merge($createBody, ['name' => 'Different request'])));
$conflictResponse = rest_do_request($conflict);
wpcb_api_assert($conflictResponse->get_status() === 409, 'Reusing an idempotency key for a different request is rejected.');

$GLOBALS['wpcb_webhook_capture'] = null;
add_filter('pre_http_request', static function ($preempt, $args, $url) {
    if ($url === 'https://example.com/wpcb-webhook-test') {
        $GLOBALS['wpcb_webhook_capture'] = ['args' => $args, 'url' => $url];
        return [
            'headers' => [],
            'body' => '',
            'response' => ['code' => 204, 'message' => 'No Content'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    return $preempt;
}, 10, 3);

$bookingRepo = new Wpcb\Booking\BookingRepository();
$bookingId = $bookingRepo->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => (int)get_option('wpcb_default_resource_id', 0),
    'slot_start' => '2032-02-03 09:00:00',
    'slot_end' => '2032-02-03 09:30:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'full_name' => 'PRIVATE WEBHOOK NAME',
    'email' => 'private-webhook@example.com',
    'phone' => '+431111111',
    'notes' => 'PRIVATE WEBHOOK NOTES',
    'source' => 'api-smoke',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
wpcb_api_assert($bookingId > 0, 'Webhook fixture booking is created.');

(new Wpcb\Sync\QueueService())->runNow(20);
$capture = $GLOBALS['wpcb_webhook_capture'];
wpcb_api_assert(is_array($capture), 'Queued webhook is delivered through the shared retry queue.');

$headers = $capture['args']['headers'] ?? [];
$body = (string)($capture['args']['body'] ?? '');
$timestamp = (string)($headers['X-WPCB-Timestamp'] ?? '');
$signature = (string)($headers['X-WPCB-Signature'] ?? '');
$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
wpcb_api_assert($timestamp !== '' && hash_equals($expected, $signature), 'Webhook HMAC signature verifies independently.');

$payload = json_decode($body, true);
wpcb_api_assert(($payload['type'] ?? '') === 'booking.created', 'Webhook uses a stable lifecycle event type.');
wpcb_api_assert(($payload['schema_version'] ?? 0) === 1, 'Webhook payload is explicitly versioned.');
$json = wp_json_encode($payload);
foreach (['PRIVATE WEBHOOK NAME', 'private-webhook@example.com', '+431111111', 'PRIVATE WEBHOOK NOTES'] as $privateValue) {
    wpcb_api_assert(strpos($json, $privateValue) === false, 'Webhook payload excludes private customer content.');
}

$deliveryRows = (new Wpcb\Webhooks\WebhookDeliveryRepository())->recent(20);
$sent = array_values(array_filter($deliveryRows, static fn($row) => (int)$row->booking_id === $bookingId && $row->status === 'sent'));
wpcb_api_assert(count($sent) === 1 && (int)$sent[0]->response_code === 204, 'Successful webhook delivery is visible in administrator delivery history.');

$transition = new WP_REST_Request('POST', '/wpcb/v1/bookings/' . $bookingId . '/transition');
$transition->set_header('Content-Type', 'application/json');
$transition->set_header('Idempotency-Key', 'cancel-booking-' . $bookingId);
$transition->set_body(wp_json_encode(['event' => Wpcb\Booking\BookingStateMachine::ADMIN_CANCELLED, 'note' => 'API smoke cancellation']));
$cancelled = rest_do_request($transition);
wpcb_api_assert($cancelled->get_status() === 200 && !empty($cancelled->get_data()['data']['changed']), 'Authenticated REST mutation applies the canonical booking transition.');

$cancelReplay = rest_do_request($transition);
wpcb_api_assert($cancelReplay->get_data() === $cancelled->get_data(), 'REST mutation replay returns the stored idempotent response.');

$list = new WP_REST_Request('GET', '/wpcb/v1/bookings');
$list->set_param('per_page', 1);
$listResponse = rest_do_request($list);
wpcb_api_assert($listResponse->get_status() === 200 && ($listResponse->get_data()['pagination']['per_page'] ?? 0) === 1, 'Administrator booking list uses bounded pagination.');

$wpdb->delete($wpdb->prefix . 'wpcb_webhook_deliveries', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_sync_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_webhook_endpoints', ['id' => $endpointId]);
$wpdb->query("DELETE FROM {$wpdb->prefix}wpcb_api_idempotency WHERE scope_key IS NOT NULL");

WP_CLI::success('REST API and webhook smoke test passed.');
