<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_backup_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$p = $wpdb->prefix . 'wpcb_';
$service = new Wpcb\Admin\ConfigurationBackupService();
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());

$typeSlug = 'backup-type-' . strtolower(wp_generate_password(6, false));
$resourceSlug = 'backup-resource-' . strtolower(wp_generate_password(6, false));
$fieldKey = 'backup_field_' . strtolower(wp_generate_password(5, false));

$wpdb->insert($p . 'booking_types', [
    'name' => 'Backup Type',
    'slug' => $typeSlug,
    'description' => 'Backup description',
    'duration_minutes' => 45,
    'buffer_before_minutes' => 5,
    'buffer_after_minutes' => 10,
    'capacity' => 3,
    'show_remaining_capacity' => 1,
    'payment_mode' => 'free',
    'price_minor' => 0,
    'currency' => 'EUR',
    'is_active' => 1,
    'is_public' => 1,
    'sort_order' => 91,
    'created_at' => $now,
    'updated_at' => $now,
]);
$typeId = (int)$wpdb->insert_id;

$wpdb->insert($p . 'resources', [
    'name' => 'Backup Resource',
    'slug' => $resourceSlug,
    'public_label' => 'Room A',
    'description' => 'Resource description',
    'capacity' => 3,
    'is_active' => 1,
    'is_public' => 1,
    'sort_order' => 92,
    'created_at' => $now,
    'updated_at' => $now,
]);
$resourceId = (int)$wpdb->insert_id;

$wpdb->insert($p . 'booking_type_resources', [
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'created_at' => $now,
    'updated_at' => $now,
]);

$wpdb->insert($p . 'form_fields', [
    'field_key' => $fieldKey,
    'label' => 'Company',
    'field_type' => 'text',
    'is_required' => 0,
    'is_active' => 1,
    'options_json' => null,
    'validation_rules_json' => null,
    'sort_order' => 93,
    'created_at' => $now,
    'updated_at' => $now,
]);

$wpdb->insert($p . 'availability_rules', [
    'scope_type' => 'resource',
    'scope_id' => $resourceId,
    'weekday' => 2,
    'start_time' => '09:00:00',
    'end_time' => '12:00:00',
    'slot_duration_minutes' => 45,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'min_notice_minutes' => 60,
    'max_days_in_advance' => 60,
    'is_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);

$wpdb->insert($p . 'exceptions', [
    'type' => 'blocked_range',
    'title' => 'Backup Block',
    'date_start' => '2032-01-10 09:00:00',
    'date_end' => '2032-01-10 10:00:00',
    'all_day' => 0,
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'is_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);

$secretMarker = 'SUPER-SECRET-BACKUP-MARKER';
$customerMarker = 'customer-backup-marker@example.com';
$wpdb->insert($p . 'calendar_connections', [
    'provider' => 'caldav',
    'name' => 'Backup Calendar',
    'remote_calendar_id' => 'safe-calendar-id',
    'credentials_enc' => $secretMarker,
    'config_json' => wp_json_encode(['password' => $secretMarker, 'endpoint' => 'https://calendar.example.test/']),
    'blocks_availability' => 1,
    'receives_bookings' => 1,
    'is_active' => 1,
    'health_status' => 'ok',
    'last_error_message' => $secretMarker,
    'created_at' => $now,
    'updated_at' => $now,
]);
$connectionId = (int)$wpdb->insert_id;

$wpdb->insert($p . 'resource_calendar_connections', [
    'resource_id' => $resourceId,
    'connection_id' => $connectionId,
    'blocks_availability' => 1,
    'receives_bookings' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);

$wpdb->insert($p . 'bookings', [
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2032-02-01 09:00:00',
    'slot_end' => '2032-02-01 09:45:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'full_name' => 'Backup Customer',
    'email' => $customerMarker,
    'source' => 'test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
$bookingId = (int)$wpdb->insert_id;

$json = $service->exportJson();
wpcb_backup_assert(strpos($json, $secretMarker) === false, 'Export excludes calendar credentials, config secrets and health errors.');
wpcb_backup_assert(strpos($json, $customerMarker) === false, 'Export excludes booking customer data.');
wpcb_backup_assert(strpos($json, '"booking_types"') !== false && strpos($json, $typeSlug) !== false, 'Export contains booking configuration.');
wpcb_backup_assert(strpos($json, '"restore_state": "disabled_until_credentials_reentered"') !== false, 'Export marks calendar metadata as credential-free.');

$before = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}resources");
$dry = $service->importJson($json, true);
wpcb_backup_assert(is_array($dry) && !empty($dry['dry_run']), 'Dry-run validates the backup.');
wpcb_backup_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}resources") === $before, 'Dry-run performs no writes.');

$wpdb->delete($p . 'resource_calendar_connections', ['resource_id' => $resourceId]);
$wpdb->delete($p . 'booking_type_resources', ['booking_type_id' => $typeId]);
$wpdb->delete($p . 'availability_rules', ['scope_type' => 'resource', 'scope_id' => $resourceId]);
$wpdb->delete($p . 'exceptions', ['title' => 'Backup Block']);
$wpdb->delete($p . 'form_fields', ['field_key' => $fieldKey]);
// Keep the booked type/resource rows: restore must update in place and must not touch booking history.

$bookingBefore = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}bookings WHERE id = %d", $bookingId), ARRAY_A);
$applied = $service->importJson($json, false);
wpcb_backup_assert(is_array($applied) && empty($applied['dry_run']), 'Validated backup applies successfully.');
$bookingAfter = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}bookings WHERE id = %d", $bookingId), ARRAY_A);
wpcb_backup_assert($bookingBefore === $bookingAfter, 'Restore does not rewrite existing bookings/history.');

$restoredField = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}form_fields WHERE field_key = %s", $fieldKey));
wpcb_backup_assert($restoredField !== null, 'Restore recreates form fields.');
$mapped = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}booking_type_resources WHERE booking_type_id = %d AND resource_id = %d",
    $typeId,
    $resourceId
));
wpcb_backup_assert($mapped === 1, 'Restore preserves type/resource relationships after ID resolution.');

$calendar = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}calendar_connections WHERE id = %d", $connectionId));
wpcb_backup_assert($calendar !== null && (int)$calendar->is_active === 0, 'Restored calendar metadata is disabled until credentials are re-entered.');
wpcb_backup_assert((string)$calendar->credentials_enc === $secretMarker, 'Existing stored credentials are not overwritten by restore metadata.');

$bad = json_decode($json, true);
$bad['bookings'] = [['email' => 'forbidden@example.com']];
$rejected = $service->importSnapshot($bad, true);
wpcb_backup_assert(is_wp_error($rejected), 'Unknown/unsafe top-level fields fail closed.');

$rollbackSnapshot = json_decode($json, true);
$rollbackSnapshot['resources'][] = [
    'ref' => 'resource-rollback',
    'name' => 'Rollback Resource',
    'slug' => 'rollback-resource-' . strtolower(wp_generate_password(6, false)),
    'public_label' => '',
    'description' => '',
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 999,
];
$rollbackSlug = $rollbackSnapshot['resources'][count($rollbackSnapshot['resources']) - 1]['slug'];
$filter = static function(string $query): string {
    if (strpos($query, $GLOBALS['wpdb']->prefix . 'wpcb_form_fields') !== false) {
        return str_replace($GLOBALS['wpdb']->prefix . 'wpcb_form_fields', $GLOBALS['wpdb']->prefix . 'wpcb_missing_restore_table', $query);
    }
    return $query;
};
add_filter('query', $filter);
$failed = $service->importSnapshot($rollbackSnapshot, false);
remove_filter('query', $filter);
wpcb_backup_assert(is_wp_error($failed), 'Database failure returns an error.');
$rolledBack = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}resources WHERE slug = %s", $rollbackSlug));
wpcb_backup_assert($rolledBack === 0, 'Database failure rolls back earlier restore writes.');

$wpdb->delete($p . 'bookings', ['id' => $bookingId]);
$wpdb->delete($p . 'resource_calendar_connections', ['resource_id' => $resourceId]);
$wpdb->delete($p . 'calendar_connections', ['id' => $connectionId]);
$wpdb->delete($p . 'booking_type_resources', ['booking_type_id' => $typeId]);
$wpdb->delete($p . 'availability_rules', ['scope_type' => 'resource', 'scope_id' => $resourceId]);
$wpdb->delete($p . 'exceptions', ['title' => 'Backup Block']);
$wpdb->delete($p . 'form_fields', ['field_key' => $fieldKey]);
$wpdb->delete($p . 'resources', ['id' => $resourceId]);
$wpdb->delete($p . 'booking_types', ['id' => $typeId]);

WP_CLI::success('Configuration backup/restore smoke test passed.');
