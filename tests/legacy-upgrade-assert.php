<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_legacy_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$p = $wpdb->prefix . 'wpcb_';

wpcb_legacy_assert(defined('WPCB_VERSION') && WPCB_VERSION === '3.15.0', 'Legacy fixture boots the 3.15.0 release.');
$booking = $wpdb->get_row("SELECT * FROM {$p}bookings WHERE id = 99");
wpcb_legacy_assert($booking !== null, 'Legacy booking is preserved.');
wpcb_legacy_assert($booking->status === Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED, 'Legacy booking status migrates to canonical lifecycle state.');
wpcb_legacy_assert($booking->slot_start === '2026-01-15 08:00:00', 'Legacy local start time migrates to the same UTC instant.');
wpcb_legacy_assert($booking->slot_end === '2026-01-15 08:45:00', 'Legacy local end time migrates to the same UTC instant.');

$type = $wpdb->get_row("SELECT * FROM {$p}booking_types WHERE id = 77");
wpcb_legacy_assert($type && $type->name === 'Legacy Consultation' && (int)$type->duration_minutes === 45, 'Legacy booking type is preserved.');
$rule = $wpdb->get_row("SELECT * FROM {$p}availability_rules WHERE id = 88");
wpcb_legacy_assert($rule && $rule->start_time === '09:00:00' && $rule->end_time === '12:00:00', 'Legacy availability rule is preserved.');

$token = $wpdb->get_row("SELECT * FROM {$p}tokens WHERE booking_id = 99 ORDER BY id ASC LIMIT 1");
wpcb_legacy_assert($token && !empty($token->used_at), 'Legacy unindexed action token is revoked.');
wpcb_legacy_assert(property_exists($token, 'token_selector'), 'Token schema is upgraded with indexed selectors.');
wpcb_legacy_assert((int)get_option('wpcb_legacy_tokens_revoked', 0) >= 1, 'Legacy token revocation is recorded.');

$settings = (array)get_option('wpcb_settings', []);
wpcb_legacy_assert(empty($settings['icloud_sync_password_enc']), 'Legacy unauthenticated calendar credential is removed.');
wpcb_legacy_assert(empty($settings['icloud_sync_enabled']), 'Legacy write-back is disabled until credentials are re-entered.');
wpcb_legacy_assert((int)get_option('wpcb_secret_reentry_required', 0) === 1, 'Administrator re-entry requirement is recorded for legacy credentials.');

wpcb_legacy_assert((int)get_option('wpcb_schema_version', 0) === Wpcb\Database\SchemaMigration::currentVersion(), 'Legacy database schema is fully migrated.');
wpcb_legacy_assert((int)get_option('wpcb_token_storage_version', 0) === Wpcb\Tokens\TokenMigration::currentVersion(), 'Legacy token storage migration is complete.');
wpcb_legacy_assert((int)get_option('wpcb_secret_storage_version', 0) === Wpcb\Security\SecretMigration::currentVersion(), 'Legacy secret storage migration is complete.');
wpcb_legacy_assert((int)get_option('wpcb_time_storage_version', 0) >= 2, 'Legacy time storage migration is complete.');
wpcb_legacy_assert((int)get_option('wpcb_booking_status_version', 0) >= 2, 'Legacy booking status migration is complete.');
wpcb_legacy_assert((int)get_option('wpcb_resource_model_version', 0) === Wpcb\Resources\ResourceMigration::currentVersion(), 'Legacy resource model migration is complete.');
$default_resource_id = (int)get_option('wpcb_default_resource_id', 0);
wpcb_legacy_assert($default_resource_id > 0, 'Legacy migration creates a default resource.');
wpcb_legacy_assert((int)$booking->resource_id === $default_resource_id, 'Legacy booking is assigned to the default resource.');

wpcb_legacy_assert(class_exists('Sabre\\VObject\\Reader'), 'Upgrade ZIP contains Composer runtime dependencies.');
wpcb_legacy_assert(is_file(WPCB_DIR . 'assets/vendor/uikit/uikit.min.css'), 'Upgrade ZIP contains built UIkit fallback assets.');

WP_CLI::success('Imported 1.x upgrade fixture passed.');
