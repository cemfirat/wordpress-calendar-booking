<?php
if (!defined('ABSPATH')) {
    exit(1);
}

$action = getenv('WPCB_E2E_ACTION') ?: '';
global $wpdb;
$prefix = $wpdb->prefix . 'wpcb_';
$testEmail = 'browser-e2e@example.com';
$testSlug = 'browser-e2e';

$cleanup = static function () use ($wpdb, $prefix, $testEmail, $testSlug): void {
    $bookingIds = array_map(
        'intval',
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}bookings WHERE email = %s",
            $testEmail
        ))
    );

    foreach ($bookingIds as $bookingId) {
        foreach (['booking_meta', 'booking_status_log', 'tokens', 'sync_jobs', 'deliveries', 'sync_log'] as $suffix) {
            $wpdb->delete($prefix . $suffix, ['booking_id' => $bookingId]);
        }
        $wpdb->delete($prefix . 'bookings', ['id' => $bookingId]);
    }

    $typeIds = array_map(
        'intval',
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}booking_types WHERE slug = %s",
            $testSlug
        ))
    );

    foreach ($typeIds as $typeId) {
        $wpdb->delete($prefix . 'availability_rules', ['scope_type' => 'booking_type', 'scope_id' => $typeId]);
        $wpdb->delete($prefix . 'exceptions', ['booking_type_id' => $typeId]);
        $wpdb->delete($prefix . 'booking_type_calendar_connections', ['booking_type_id' => $typeId]);
        $wpdb->delete($prefix . 'booking_types', ['id' => $typeId]);
    }

    foreach (['wpcb-e2e-shortcodes', 'wpcb-e2e-blocks'] as $pagePath) {
        $page = get_page_by_path($pagePath, OBJECT, 'page');
        if ($page) {
            wp_delete_post((int)$page->ID, true);
        }
    }

    delete_option('wpcb_e2e_mailbox');
};

if ($action === 'setup') {
    $cleanup();

    $settings = (array)get_option('wpcb_settings', []);
    $settings['mode'] = 'approval';
    $settings['timezone'] = 'UTC';
    $settings['timing_enabled'] = 0;
    $settings['min_form_seconds'] = 0;
    $settings['rate_limit_enabled'] = 0;
    $settings['cancel_min_hours'] = 0;
    $settings['change_min_hours'] = 0;
    $settings['notifications_enabled'] = 0;
    $settings['calendar_urls'] = '';
    update_option('wpcb_settings', $settings, false);
    update_option('timezone_string', 'UTC');
    update_option('wpcb_e2e_mailbox', [], false);

    $shortcodePage = wp_insert_post([
        'post_title' => 'WPCB E2E Shortcodes',
        'post_name' => 'wpcb-e2e-shortcodes',
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_content' => "[wpcb_booking_calendar]\n\n[wpcb_booking_form]\n\n[wpcb_customer_portal]",
    ], true);

    $blockPage = wp_insert_post([
        'post_title' => 'WPCB E2E Blocks',
        'post_name' => 'wpcb-e2e-blocks',
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_content' => "<!-- wp:wpcb/availability-calendar /-->\n\n<!-- wp:wpcb/booking-form /-->",
    ], true);

    if (is_wp_error($shortcodePage) || is_wp_error($blockPage)) {
        throw new RuntimeException('Unable to create E2E pages.');
    }

    echo wp_json_encode([
        'shortcode_page_id' => (int)$shortcodePage,
        'block_page_id' => (int)$blockPage,
    ]);
    return;
}

if ($action === 'mailbox') {
    echo wp_json_encode(array_values((array)get_option('wpcb_e2e_mailbox', [])));
    return;
}

if ($action === 'status') {
    $booking = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, booking_type_id, slot_start, slot_end, status, email
             FROM {$prefix}bookings
             WHERE email = %s
             ORDER BY id DESC
             LIMIT 1",
            $testEmail
        ),
        ARRAY_A
    );
    echo wp_json_encode($booking ?: []);
    return;
}

if ($action === 'cleanup') {
    $cleanup();
    echo 'OK';
    return;
}

fwrite(STDERR, "Unknown WPCB_E2E_ACTION.\n");
exit(1);
