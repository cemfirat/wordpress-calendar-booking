<?php
if (!defined('ABSPATH')) { exit; }

function cemb_delivery_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

global $wpdb;
$columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}cemb_deliveries", 0);
foreach (['recipient_class', 'provider_code', 'last_error_code', 'last_attempt_at'] as $column) {
    cemb_delivery_assert(in_array($column, $columns, true), "Delivery schema contains {$column}.");
}

$now = Cemb\Support\Time::formatUtc(Cemb\Support\Time::nowUtc());
$wpdb->insert($wpdb->prefix . 'cemb_bookings', [
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => 1,
    'slot_start' => '2030-01-02 10:00:00',
    'slot_end' => '2030-01-02 10:30:00',
    'status' => Cemb\Booking\BookingStatus::CONFIRMED,
    'full_name' => 'Delivery Test',
    'email' => 'delivery@example.com',
    'source' => 'test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
$bookingId = (int)$wpdb->insert_id;

$repo = new Cemb\Reliability\DeliveryRepository();
$key = 'mail:user:' . $bookingId . ':reminder:test';
$delivery = $repo->begin($bookingId, $key, 'email', 'template:reminder', 'customer', 'wp_mail');
cemb_delivery_assert(!empty($delivery['should_run']), 'New delivery is runnable.');
cemb_delivery_assert($repo->markSending((int)$delivery['id']), 'Delivery attempt transitions to sending.');
$repo->markFailed(
    (int)$delivery['id'],
    'Authorization: topsecret Bearer abc.def token=secret password=hunter2',
    'wp_mail_false'
);
$row = $repo->findByKey($key);
cemb_delivery_assert($row && $row->recipient_class === 'customer', 'Recipient class is recorded.');
cemb_delivery_assert($row && $row->effect_type === 'template:reminder', 'Notification type is recorded.');
cemb_delivery_assert($row && $row->provider_code === 'wp_mail', 'Provider code is recorded.');
cemb_delivery_assert($row && $row->last_error_code === 'wp_mail_false', 'Safe error code is recorded.');
cemb_delivery_assert($row && !empty($row->last_attempt_at), 'Attempt timestamp is recorded.');
cemb_delivery_assert($row && strpos((string)$row->last_error, 'topsecret') === false, 'Authorization secrets are redacted.');
cemb_delivery_assert($row && strpos((string)$row->last_error, 'abc.def') === false, 'Bearer secrets are redacted.');
cemb_delivery_assert($row && strpos((string)$row->last_error, 'hunter2') === false, 'Password-looking values are redacted.');

$filtered = $repo->search(['booking_id' => $bookingId, 'status' => 'failed', 'effect_type' => 'template:reminder']);
cemb_delivery_assert(count($filtered) === 1 && (int)$filtered[0]->id === (int)$delivery['id'], 'Admin delivery filters combine booking, status and type.');
cemb_delivery_assert(in_array('template:reminder', $repo->effectTypes(), true), 'Notification types are discoverable for filters.');

$old = Cemb\Support\Time::formatUtc(Cemb\Support\Time::nowUtc()->modify('-120 days'));
$wpdb->update($wpdb->prefix . 'cemb_deliveries', ['updated_at' => $old], ['id' => (int)$delivery['id']]);
cemb_delivery_assert($repo->cleanup(90) >= 1, 'Terminal delivery records older than retention are cleaned up.');
cemb_delivery_assert($repo->findByKey($key) === null, 'Cleaned delivery is removed.');

$settings = Cemb\Admin\Settings::get();
cemb_delivery_assert((int)$settings['delivery_log_retention_days'] >= 1, 'Delivery-log retention has a configured default.');

$adminSource = file_get_contents(CEMB_DIR . 'includes/Admin/Admin.php');
cemb_delivery_assert(strpos($adminSource, 'Versandprotokoll') !== false, 'Admin delivery log is registered.');
cemb_delivery_assert(strpos($adminSource, 'last_error_code') !== false, 'Admin delivery log exposes the safe error code.');
cemb_delivery_assert(strpos($adminSource, 'Idempotency-Key') !== false, 'Admin delivery log exposes the idempotency key.');
cemb_delivery_assert(strpos($adminSource, 'OAuth-Tokens') !== false, 'Admin log explicitly states that secrets are not stored.');

$wpdb->delete($wpdb->prefix . 'cemb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'cemb_bookings', ['id' => $bookingId]);
echo "PASS: notification delivery log smoke test complete.\n";
