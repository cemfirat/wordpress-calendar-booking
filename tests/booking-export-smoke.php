<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_export_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeA = $wpdb->insert($wpdb->prefix . 'wpcb_booking_types', [
    'name' => 'Export A', 'slug' => 'export-a', 'description' => '',
    'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
    'is_active' => 1, 'is_public' => 1, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
]) ? (int)$wpdb->insert_id : 0;
$typeB = $wpdb->insert($wpdb->prefix . 'wpcb_booking_types', [
    'name' => 'Export B', 'slug' => 'export-b', 'description' => '',
    'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
    'is_active' => 1, 'is_public' => 1, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
]) ? (int)$wpdb->insert_id : 0;

$repo = new Wpcb\Booking\BookingRepository();
$first = $repo->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeA,
    'slot_start' => '2026-10-01 08:00:00',
    'slot_end' => '2026-10-01 08:30:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'full_name' => 'CSV First',
    'email' => 'first@example.com',
    'phone' => '+431',
    'notes' => 'Allowed note',
    'source' => 'test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
], ['sync_token' => 'must-not-export']);
$second = $repo->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeB,
    'slot_start' => '2026-11-01 08:00:00',
    'slot_end' => '2026-11-01 08:30:00',
    'status' => Wpcb\Booking\BookingStatus::CANCELLED,
    'full_name' => 'CSV Second',
    'email' => 'second@example.com',
    'phone' => '+432',
    'notes' => '',
    'source' => 'test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);

$filtered = $repo->all([
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'booking_type_id' => $typeA,
    'from' => '2026-10-01 00:00:00',
    'to' => '2026-10-31 23:59:59',
]);
wpcb_export_assert(count($filtered) === 1 && (int)$filtered[0]->id === $first, 'Booking repository combines status, type and date filters.');

$outside = $repo->all(['from' => '2026-11-01 00:00:00', 'to' => '2026-11-30 23:59:59']);
wpcb_export_assert(count(array_filter($outside, fn($b) => (int)$b->id === $second)) === 1, 'Date range includes the expected later booking.');

$adminSource = file_get_contents(WPCB_DIR . 'includes/Admin/Admin.php');
wpcb_export_assert(strpos($adminSource, "admin_post_wpcb_export_bookings") !== false, 'CSV export is registered as an authenticated admin action.');
wpcb_export_assert(strpos($adminSource, "check_admin_referer('wpcb_export_bookings')") !== false, 'CSV export requires a nonce.');
wpcb_export_assert(strpos($adminSource, "current_user_can('manage_options')") !== false, 'CSV export requires administrator capability.');
wpcb_export_assert(strpos($adminSource, "wpcb_booking_export_audit") !== false, 'CSV export records an audit entry.');
wpcb_export_assert(strpos($adminSource, "'sync_token'") === false, 'CSV exporter does not include internal sync tokens.');

$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $first]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $second]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $first]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $second]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $first]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $second]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $typeA]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $typeB]);
echo "PASS: booking export smoke test complete.\n";
