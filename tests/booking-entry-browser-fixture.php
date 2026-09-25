<?php
// Test-only isolated data for the packaged browser suite. Never shipped.
if (!defined('ABSPATH')) exit(1);
global $wpdb;
$p = $wpdb->prefix . 'wpcb_';
$action = getenv('WPCB_ENTRY_ACTION') ?: '';
$key = 'wpcb_entry_e2e_fixture';
$state = get_option($key, []);
$cleanup = static function () use ($wpdb, $p, $key): void {
    $state = get_option($key, []);
    if (!$state) return;
    foreach ($state['bookings'] ?? [] as $id) {
        foreach (['tokens', 'booking_meta', 'booking_status_log', 'sync_jobs', 'deliveries', 'sync_log'] as $suffix) $wpdb->delete($p . $suffix, ['booking_id' => $id]);
        $wpdb->delete($p . 'bookings', ['id' => $id]);
    }
    foreach ($state['series'] ?? [] as $id) $wpdb->delete($p . 'booking_series', ['id' => $id]);
    foreach ($state['types'] ?? [] as $id) {
        $wpdb->delete($p . 'availability_rules', ['scope_type' => 'booking_type', 'scope_id' => $id]);
        $wpdb->delete($p . 'booking_type_resources', ['booking_type_id' => $id]);
        $wpdb->delete($p . 'booking_types', ['id' => $id]);
    }
    if (!empty($state['page'])) wp_delete_post($state['page'], true);
    foreach ($state['options'] ?? [] as $name => $value) {
        if ($value === null) delete_option($name); else update_option($name, $value, false);
    }
    delete_option($key);
};
if ($action === 'setup') {
    $cleanup();
    $state = ['options' => [], 'types' => [], 'bookings' => [], 'series' => []];
    foreach (['wpcb_settings', 'wpcb_stripe_settings', 'timezone_string'] as $name) $state['options'][$name] = get_option($name, null);
    update_option($key, $state, false);
    $settings = (array)get_option('wpcb_settings', []);
    $settings = array_merge($settings, ['timezone' => 'UTC', 'timing_enabled' => 0, 'rate_limit_enabled' => 0, 'notifications_enabled' => 0, 'calendar_urls' => '', 'calendar_url' => '']);
    update_option('wpcb_settings', $settings, false);
    update_option('timezone_string', 'UTC');
    update_option('wpcb_stripe_settings', [], false);
    $resource = (int)$wpdb->get_var("SELECT id FROM {$p}resources WHERE is_active=1 ORDER BY id LIMIT 1");
    if (!$resource) throw new RuntimeException('No fixture resource');
    $state['resource'] = $resource;
    foreach (['Entry Free A', 'Entry Free B', 'Entry Paid'] as $i => $name) {
        $id = (new Wpcb\Admin\ConfigurationService())->saveBookingType(['name' => $name, 'slug' => 'entry-e2e-' . $i, 'is_active' => 1, 'is_public' => 1, 'payment_mode' => $i === 2 ? 'required' : 'free', 'price_minor' => 1000]);
        if (!$id) throw new RuntimeException('Unable to create fixture type');
        $state['types'][] = $id; update_option($key, $state, false);
        $now = gmdate('Y-m-d H:i:s');
        if ($wpdb->insert($p . 'booking_type_resources', ['booking_type_id' => $id, 'resource_id' => $resource, 'created_at' => $now, 'updated_at' => $now]) !== 1) throw new RuntimeException('Unable to assign fixture resource');
        for ($day = 1; $day <= 7; $day++) (new Wpcb\Admin\ConfigurationService())->saveRule(['scope_type' => 'booking_type', 'scope_id' => $id, 'weekday' => $day, 'start_time' => '08:00', 'end_time' => '18:00', 'is_active' => 1, 'max_days_in_advance' => 30]);
    }
    $page = wp_insert_post(['post_title' => 'Entry test', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[wpcb_booking_calendar] [wpcb_booking_form]'], true);
    if (is_wp_error($page)) throw new RuntimeException('Unable to create fixture page');
    $state['page'] = (int)$page; update_option($key, $state, false);
    echo wp_json_encode(['types' => $state['types'], 'page' => get_permalink($page)]); return;
}
if ($action === 'ready') {
    $result = (new Wpcb\Payments\StripeConfig())->save(['enabled' => 1, 'secret_key' => 'sk_test_synthetic_entry_only', 'webhook_secret' => 'whsec_synthetic_entry_only']);
    if (is_wp_error($result)) throw new RuntimeException('Unable to configure synthetic credentials');
    echo '{}'; return;
}
if ($action === 'disabled') { (new Wpcb\Payments\StripeConfig())->save(['enabled' => 0]); echo '{}'; return; }
if ($action === 'make_single' || $action === 'make_series') {
    $now = gmdate('Y-m-d H:i:s'); $series = 0;
    if ($action === 'make_series') {
        $series = (new Wpcb\Booking\BookingSeriesRepository())->create(['booking_type_id' => $state['types'][0], 'resource_id' => $state['resource'], 'occurrence_count' => 2, 'timezone' => 'UTC']);
        if (!$series) throw new RuntimeException('Unable to create fixture series');
        $state['series'][] = $series; update_option($key, $state, false);
    }
    $created = [];
    for ($i = 0; $i < ($series ? 2 : 1); $i++) {
        $start = (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->modify('+' . ($i * 7) . ' days')->setTime(10, 0);
        $data = ['booking_uuid' => wp_generate_uuid4(), 'booking_type_id' => $state['types'][0], 'resource_id' => $state['resource'], 'status' => 'reserved_unconfirmed', 'slot_start' => $start->format('Y-m-d H:i:s'), 'slot_end' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'), 'reserved_until' => gmdate('Y-m-d H:i:s', time() + 3600), 'full_name' => 'Synthetic Entry', 'email' => 'entry-e2e@example.invalid', 'source' => 'entry-e2e', 'created_at' => $now, 'updated_at' => $now];
        if ($series) { $data['series_id'] = $series; $data['series_occurrence'] = $i; }
        if ($wpdb->insert($p . 'bookings', $data) !== 1) throw new RuntimeException('Unable to create fixture booking');
        $id = (int)$wpdb->insert_id;
        $created[] = $id; $state['bookings'][] = $id; update_option($key, $state, false);
    }
    $state['latest'] = $created; update_option($key, $state, false);
    $token = (new Wpcb\Tokens\TokenService())->create($created[0], 'doi', 60);
    echo wp_json_encode(['url' => add_query_arg(['wpcb_action' => 'confirm', 'wpcb_token' => $token], home_url('/')), 'ids' => $created]); return;
}
if ($action === 'expire') {
    $ids = $state['latest'];
    $wpdb->update($p . 'bookings', ['reserved_until' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => end($ids)]);
    echo '{}'; return;
}
if ($action === 'counts') {
    $out = [];
    foreach (['bookings', 'tokens', 'payments', 'sync_jobs', 'deliveries', 'booking_status_log'] as $suffix) $out[$suffix] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}{$suffix}");
    $out['states'] = [];
    foreach ($state['bookings'] ?? [] as $id) $out['states'][] = $wpdb->get_row($wpdb->prepare("SELECT status,reserved_until FROM {$p}bookings WHERE id=%d", $id), ARRAY_A);
    $out['used_tokens'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}tokens WHERE used_at IS NOT NULL");
    echo wp_json_encode($out); return;
}
if ($action === 'cleanup') { $cleanup(); echo '{}'; return; }
throw new RuntimeException('Unknown entry fixture action');
