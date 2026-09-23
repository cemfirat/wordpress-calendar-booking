<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_release_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

wpcb_release_assert(defined('WPCB_VERSION') && WPCB_VERSION === '3.15.0', 'Release ZIP boots version 3.15.0.');
wpcb_release_assert(class_exists('Sabre\\VObject\\Reader'), 'Release ZIP contains Composer runtime dependencies.');
wpcb_release_assert(is_file(WPCB_DIR . 'assets/vendor/uikit/uikit.min.css'), 'Release ZIP contains local UIkit CSS fallback.');
wpcb_release_assert(is_file(WPCB_DIR . 'assets/vendor/uikit/uikit.min.js'), 'Release ZIP contains local UIkit JavaScript fallback.');
wpcb_release_assert(is_file(WPCB_DIR . 'LICENSE') && filesize(WPCB_DIR . 'LICENSE') > 10000, 'Release ZIP contains the complete GPL license.');
wpcb_release_assert(shortcode_exists('wpcb_booking_form'), 'Fresh release registers booking form shortcode.');
wpcb_release_assert(shortcode_exists('wpcb_customer_portal'), 'Fresh release registers customer portal shortcode.');
wpcb_release_assert(shortcode_exists('wpcb_waiting_list'), 'Fresh release registers waiting-list shortcode.');
wpcb_release_assert(false !== has_action('admin_post_nopriv_wpcb_waitlist_accept'), 'Fresh release registers POST-only waiting-list acceptance.');
wpcb_release_assert(false !== has_action('admin_post_nopriv_wpcb_booking_action'), 'Fresh release registers POST-only public booking actions.');
if (!did_action('rest_api_init')) {
    do_action('rest_api_init');
}
$routes = rest_get_server()->get_routes();
wpcb_release_assert(isset($routes['/wpcb/v1/booking-types']), 'Fresh release registers the versioned REST API.');
wpcb_release_assert(isset($routes['/wpcb/v1/webhooks/endpoints']), 'Fresh release registers webhook administration routes.');
wpcb_release_assert(isset($routes['/wpcb/v1/payments/stripe/webhook']), 'Fresh release registers the Stripe payment webhook route.');

global $wpdb;
foreach ([
    'bookings', 'booking_series', 'booking_meta', 'booking_types', 'resources', 'booking_type_resources',
    'form_fields', 'availability_rules', 'exceptions', 'tokens', 'booking_status_log',
    'sync_jobs', 'deliveries', 'calendar_connections', 'booking_type_calendar_connections',
    'resource_calendar_connections', 'sync_log', 'api_idempotency', 'webhook_endpoints', 'webhook_deliveries',
    'customer_sessions', 'payments', 'payment_events', 'waiting_list',
    'video_connections', 'booking_type_video_connections', 'video_meetings',
] as $suffix) {
    $table = $wpdb->prefix . 'wpcb_' . $suffix;
    wpcb_release_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, 'Fresh release created table ' . $table . '.');
}

wpcb_release_assert((int)get_option('wpcb_schema_version', 0) === Wpcb\Database\SchemaMigration::currentVersion(), 'Fresh install schema migration is current.');
wpcb_release_assert((int)get_option('wpcb_token_storage_version', 0) === Wpcb\Tokens\TokenMigration::currentVersion(), 'Fresh install token storage migration is current.');
wpcb_release_assert((int)get_option('wpcb_secret_storage_version', 0) === Wpcb\Security\SecretMigration::currentVersion(), 'Fresh install secret storage migration is current.');
wpcb_release_assert((int)get_option('wpcb_time_storage_version', 0) >= 2, 'Fresh install UTC storage migration is current.');
wpcb_release_assert((int)get_option('wpcb_booking_status_version', 0) >= 2, 'Fresh install booking lifecycle migration is current.');
wpcb_release_assert((int)get_option('wpcb_resource_model_version', 0) === Wpcb\Resources\ResourceMigration::currentVersion(), 'Fresh install resource model migration is current.');
$defaultResourceId = (int)get_option('wpcb_default_resource_id', 0);
wpcb_release_assert($defaultResourceId > 0 && (new Wpcb\Resources\ResourceRepository())->find($defaultResourceId) !== null, 'Fresh install creates a default resource.');

WP_CLI::success('Fresh release ZIP installation passed.');
