<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_readiness_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

function wpcb_readiness_item(array $snapshot, string $key): array {
    foreach ((array)($snapshot['items'] ?? []) as $item) {
        if (($item['key'] ?? '') === $key) {
            return $item;
        }
    }
    throw new RuntimeException('Missing readiness item: ' . $key);
}

global $wpdb;
$service = new Wpcb\Admin\SetupReadiness();

$initial = $service->snapshot();
foreach (['booking_type', 'resource', 'availability', 'settings', 'scheduler', 'public_surface'] as $key) {
    wpcb_readiness_assert(isset(wpcb_readiness_item($initial, $key)['ready']), 'Readiness exposes ' . $key . ' state.');
}
wpcb_readiness_assert(
    strpos(file_get_contents(WPCB_DIR . 'includes/Admin/SetupReadiness.php'), 'wpcb_bookings') === false,
    'Readiness service never queries booking/customer records.'
);

$surface = wpcb_readiness_item($initial, 'public_surface');
wpcb_readiness_assert($surface['ready'] === false, 'Fresh integration fixture has no public booking surface yet.');

$decoyId = wp_insert_post([
    'post_title' => 'Readiness decoy page',
    'post_name' => 'readiness-decoy-page',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => '[wpcbXbookingYform]',
], true);
wpcb_readiness_assert(!is_wp_error($decoyId) && (int)$decoyId > 0, 'Decoy page fixture is created.');
wpcb_readiness_assert(
    wpcb_readiness_item($service->snapshot(), 'public_surface')['ready'] === false,
    'Public-surface detection treats shortcode underscores literally.'
);

$pageId = wp_insert_post([
    'post_title' => 'Readiness booking page',
    'post_name' => 'readiness-booking-page',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => '[wpcb_booking_form]',
], true);
wpcb_readiness_assert(!is_wp_error($pageId) && (int)$pageId > 0, 'Published booking page fixture is created.');

$withPage = $service->snapshot();
wpcb_readiness_assert(wpcb_readiness_item($withPage, 'public_surface')['ready'] === true, 'Published shortcode page satisfies public surface readiness.');

$typeRows = $wpdb->get_results("SELECT id, is_active, is_public FROM {$wpdb->prefix}wpcb_booking_types ORDER BY id ASC");
$wpdb->query("UPDATE {$wpdb->prefix}wpcb_booking_types SET is_active = 0, is_public = 0");
wpcb_readiness_assert(wpcb_readiness_item($service->snapshot(), 'booking_type')['ready'] === false, 'No active public booking type is reported as incomplete.');
foreach ($typeRows as $row) {
    $wpdb->update($wpdb->prefix . 'wpcb_booking_types', [
        'is_active' => (int)$row->is_active,
        'is_public' => (int)$row->is_public,
    ], ['id' => (int)$row->id]);
}

$resourceRows = $wpdb->get_results("SELECT id, is_active FROM {$wpdb->prefix}wpcb_resources ORDER BY id ASC");
$wpdb->query("UPDATE {$wpdb->prefix}wpcb_resources SET is_active = 0");
wpcb_readiness_assert(wpcb_readiness_item($service->snapshot(), 'resource')['ready'] === false, 'No active assigned resource is reported as incomplete.');
foreach ($resourceRows as $row) {
    $wpdb->update($wpdb->prefix . 'wpcb_resources', ['is_active' => (int)$row->is_active], ['id' => (int)$row->id]);
}

$ruleRows = $wpdb->get_results("SELECT id, is_active FROM {$wpdb->prefix}wpcb_availability_rules ORDER BY id ASC");
$wpdb->query("UPDATE {$wpdb->prefix}wpcb_availability_rules SET is_active = 0");

$resources = new Wpcb\Resources\ResourceRepository();
$unassignedResourceId = $resources->save([
    'name' => 'Unassigned readiness resource',
    'slug' => 'unassigned-readiness-resource',
    'public_label' => '',
    'description' => '',
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 999,
]);
wpcb_readiness_assert(!is_wp_error($unassignedResourceId), 'Unassigned resource fixture is created.');
$unassignedResourceId = (int)$unassignedResourceId;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$wpdb->insert($wpdb->prefix . 'wpcb_availability_rules', [
    'scope_type' => 'resource',
    'scope_id' => $unassignedResourceId,
    'weekday' => 1,
    'start_time' => '09:00:00',
    'end_time' => '10:00:00',
    'slot_duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'min_notice_minutes' => 0,
    'max_days_in_advance' => 30,
    'is_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);
$unassignedRuleId = (int)$wpdb->insert_id;
wpcb_readiness_assert(
    wpcb_readiness_item($service->snapshot(), 'availability')['ready'] === false,
    'Availability on an unassigned resource does not make the booking path ready.'
);
$wpdb->delete($wpdb->prefix . 'wpcb_availability_rules', ['id' => $unassignedRuleId]);
$resources->delete($unassignedResourceId);

foreach ($ruleRows as $row) {
    $wpdb->update($wpdb->prefix . 'wpcb_availability_rules', ['is_active' => (int)$row->is_active], ['id' => (int)$row->id]);
}
wpcb_readiness_assert(wpcb_readiness_item($service->snapshot(), 'availability')['ready'] === true, 'Effective availability recovers after fixture restore.');

$oldSettings = get_option('wpcb_settings', []);
$badSettings = (array)$oldSettings;
$badSettings['sender_email'] = '';
$badSettings['timezone'] = 'Invalid/Timezone';
update_option('wpcb_settings', $badSettings);
wpcb_readiness_assert(wpcb_readiness_item($service->snapshot(), 'settings')['ready'] === false, 'Invalid sender/timezone settings are reported as incomplete.');
update_option('wpcb_settings', $oldSettings);

$queueOption = Wpcb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION;
$reminderOption = Wpcb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION;
$oldQueueRun = get_option($queueOption, '__wpcb_missing__');
$oldReminderRun = get_option($reminderOption, '__wpcb_missing__');
update_option($queueOption, '2020-01-01 00:00:00', false);
update_option($reminderOption, '2020-01-01 00:00:00', false);
wpcb_readiness_assert(
    wpcb_readiness_item($service->snapshot(), 'scheduler')['ready'] === false,
    'Stale scheduler timestamps are reported as incomplete.'
);
update_option($queueOption, $now, false);
update_option($reminderOption, $now, false);
wpcb_readiness_assert(
    wpcb_readiness_item($service->snapshot(), 'scheduler')['ready'] === true,
    'Current scheduler timestamps with clean queues satisfy readiness.'
);

$final = $service->snapshot();
wpcb_readiness_assert(wpcb_readiness_item($final, 'booking_type')['ready'] === true, 'Booking type readiness recovers after fixture restore.');
wpcb_readiness_assert(wpcb_readiness_item($final, 'resource')['ready'] === true, 'Resource readiness recovers after fixture restore.');
wpcb_readiness_assert(wpcb_readiness_item($final, 'availability')['ready'] === true, 'Availability readiness remains healthy.');
wpcb_readiness_assert(wpcb_readiness_item($final, 'settings')['ready'] === true, 'Settings readiness recovers after fixture restore.');
wpcb_readiness_assert(wpcb_readiness_item($final, 'scheduler')['ready'] === true, 'Scheduler readiness uses the full health snapshot.');
wpcb_readiness_assert($final['ready'] === true, 'Configured core installation is ready without external providers.');

$admin = get_user_by('login', 'admin');
wpcb_readiness_assert($admin !== false, 'WordPress admin fixture user exists.');
wp_set_current_user((int)$admin->ID);
ob_start();
(new Wpcb\Admin\Admin())->dashboard();
$html = (string)ob_get_clean();
wpcb_readiness_assert(strpos($html, 'Einrichtung &amp; Bereitschaft') !== false || strpos($html, 'Einrichtung & Bereitschaft') !== false, 'Dashboard renders setup readiness section.');
wpcb_readiness_assert(strpos($html, 'Bereit für Buchungen.') !== false, 'Dashboard renders ready state.');

wp_delete_post((int)$pageId, true);
wp_delete_post((int)$decoyId, true);
if ($oldQueueRun === '__wpcb_missing__') {
    delete_option($queueOption);
} else {
    update_option($queueOption, $oldQueueRun, false);
}
if ($oldReminderRun === '__wpcb_missing__') {
    delete_option($reminderOption);
} else {
    update_option($reminderOption, $oldReminderRun, false);
}

WP_CLI::success('Setup readiness smoke test passed.');
