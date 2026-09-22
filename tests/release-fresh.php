<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function cemb_release_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

cemb_release_assert(defined('CEMB_VERSION') && CEMB_VERSION === '2.0.0', 'Release ZIP boots version 2.0.0.');
cemb_release_assert(class_exists('Sabre\\VObject\\Reader'), 'Release ZIP contains Composer runtime dependencies.');
cemb_release_assert(is_file(CEMB_DIR . 'assets/vendor/uikit/uikit.min.css'), 'Release ZIP contains local UIkit CSS fallback.');
cemb_release_assert(is_file(CEMB_DIR . 'assets/vendor/uikit/uikit.min.js'), 'Release ZIP contains local UIkit JavaScript fallback.');
cemb_release_assert(is_file(CEMB_DIR . 'LICENSE') && filesize(CEMB_DIR . 'LICENSE') > 10000, 'Release ZIP contains the complete GPL license.');
cemb_release_assert(shortcode_exists('cemb_booking_form'), 'Fresh release registers booking form shortcode.');
cemb_release_assert(false !== has_action('admin_post_nopriv_cemb_booking_action'), 'Fresh release registers POST-only public booking actions.');

global $wpdb;
foreach ([
    'bookings', 'booking_meta', 'booking_types', 'form_fields', 'availability_rules',
    'exceptions', 'tokens', 'booking_status_log', 'sync_jobs', 'deliveries',
    'calendar_connections', 'booking_type_calendar_connections', 'sync_log',
] as $suffix) {
    $table = $wpdb->prefix . 'cemb_' . $suffix;
    cemb_release_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, 'Fresh release created table ' . $table . '.');
}

cemb_release_assert((int)get_option('cemb_schema_version', 0) === Cemb\Database\SchemaMigration::currentVersion(), 'Fresh install schema migration is current.');
cemb_release_assert((int)get_option('cemb_token_storage_version', 0) === Cemb\Tokens\TokenMigration::currentVersion(), 'Fresh install token storage migration is current.');
cemb_release_assert((int)get_option('cemb_secret_storage_version', 0) === Cemb\Security\SecretMigration::currentVersion(), 'Fresh install secret storage migration is current.');
cemb_release_assert((int)get_option('cemb_time_storage_version', 0) >= 2, 'Fresh install UTC storage migration is current.');
cemb_release_assert((int)get_option('cemb_booking_status_version', 0) >= 2, 'Fresh install booking lifecycle migration is current.');

WP_CLI::success('Fresh release ZIP installation passed.');
