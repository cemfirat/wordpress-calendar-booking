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

$surface = wpcb_readiness_item($initial, 'public_surface');
wpcb_readiness_assert($surface['ready'] === false, 'Fresh integration fixture has no public booking surface yet.');

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
wpcb_readiness_assert(wpcb_readiness_item($service->snapshot(), 'availability')['ready'] === false, 'No active availability rule is reported as incomplete.');
foreach ($ruleRows as $row) {
    $wpdb->update($wpdb->prefix . 'wpcb_availability_rules', ['is_active' => (int)$row->is_active], ['id' => (int)$row->id]);
}

$oldSettings = get_option('wpcb_settings', []);
$badSettings = (array)$oldSettings;
$badSettings['sender_email'] = '';
$badSettings['timezone'] = 'Invalid/Timezone';
update_option('wpcb_settings', $badSettings);
wpcb_readiness_assert(wpcb_readiness_item($service->snapshot(), 'settings')['ready'] === false, 'Invalid sender/timezone settings are reported as incomplete.');
update_option('wpcb_settings', $oldSettings);

$final = $service->snapshot();
wpcb_readiness_assert(wpcb_readiness_item($final, 'booking_type')['ready'] === true, 'Booking type readiness recovers after fixture restore.');
wpcb_readiness_assert(wpcb_readiness_item($final, 'resource')['ready'] === true, 'Resource readiness recovers after fixture restore.');
wpcb_readiness_assert(wpcb_readiness_item($final, 'availability')['ready'] === true, 'Availability readiness recovers after fixture restore.');
wpcb_readiness_assert(wpcb_readiness_item($final, 'settings')['ready'] === true, 'Settings readiness recovers after fixture restore.');
wpcb_readiness_assert(wpcb_readiness_item($final, 'scheduler')['ready'] === true, 'Plugin boot schedules healthy core cron hooks.');
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

WP_CLI::success('Setup readiness smoke test passed.');
