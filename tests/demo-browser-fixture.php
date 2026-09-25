<?php
if (!defined('ABSPATH')) exit(1);
global $wpdb;
$action = getenv('WPCB_DEMO_TEST') ?: '';
$service = new Wpcb\Demo\DemoCalendar();
if ($action === 'remove') { $result = $service->remove(); if (is_wp_error($result)) throw new RuntimeException('Demo cleanup failed'); echo '{}'; return; }
if ($action === 'state') {
    $data = $service->read(); if (is_wp_error($data)) throw new RuntimeException('Invalid demo state');
    $out = ['count' => count($data['entries'] ?? []), 'batch' => $data['batch_id'] ?? '', 'real' => []];
    foreach (['bookings', 'payments', 'tokens', 'resources', 'booking_types', 'sync_jobs', 'deliveries', 'video_meetings'] as $suffix) $out['real'][$suffix] = hash('sha256', wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpcb_{$suffix} ORDER BY id", ARRAY_A)));
    foreach (['wpcb_settings', 'wpcb_stripe_settings', 'wpcb_e2e_mailbox'] as $key) $out['real'][$key] = hash('sha256', serialize(get_option($key)));
    echo wp_json_encode($out); return;
}
throw new RuntimeException('Unknown demo test action');
