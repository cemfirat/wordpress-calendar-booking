<?php
if (!defined('ABSPATH')) {
    exit(1);
}
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$charset = $wpdb->get_charset_collate();
$p = $wpdb->prefix . 'wpcb_';

dbDelta("CREATE TABLE {$p}bookings (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    booking_uuid varchar(64) NOT NULL,
    booking_type_id bigint unsigned NOT NULL,
    slot_start datetime NOT NULL,
    slot_end datetime NOT NULL,
    status varchar(50) NOT NULL,
    full_name varchar(190) DEFAULT NULL,
    email varchar(190) NOT NULL,
    phone varchar(100) DEFAULT NULL,
    notes longtext DEFAULT NULL,
    admin_notes longtext DEFAULT NULL,
    source varchar(50) DEFAULT 'frontend',
    lang varchar(10) DEFAULT 'de',
    confirmed_at datetime DEFAULT NULL,
    approved_at datetime DEFAULT NULL,
    cancelled_at datetime DEFAULT NULL,
    updated_at_user datetime DEFAULT NULL,
    reserved_until datetime DEFAULT NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY (id),
    KEY status (status)
) {$charset};");

dbDelta("CREATE TABLE {$p}booking_meta (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    booking_id bigint unsigned NOT NULL,
    meta_key varchar(190) NOT NULL,
    meta_value longtext DEFAULT NULL,
    PRIMARY KEY (id),
    KEY booking_id (booking_id)
) {$charset};");

dbDelta("CREATE TABLE {$p}booking_types (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    name varchar(190) NOT NULL,
    slug varchar(190) NOT NULL,
    description text DEFAULT NULL,
    duration_minutes int NOT NULL,
    buffer_before_minutes int NOT NULL DEFAULT 0,
    buffer_after_minutes int NOT NULL DEFAULT 0,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    is_public tinyint(1) NOT NULL DEFAULT 1,
    sort_order int NOT NULL DEFAULT 0,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY (id)
) {$charset};");

dbDelta("CREATE TABLE {$p}availability_rules (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    scope_type varchar(50) NOT NULL DEFAULT 'global',
    scope_id bigint unsigned DEFAULT NULL,
    weekday tinyint NOT NULL,
    start_time time NOT NULL,
    end_time time NOT NULL,
    slot_duration_minutes int NOT NULL DEFAULT 30,
    buffer_before_minutes int NOT NULL DEFAULT 0,
    buffer_after_minutes int NOT NULL DEFAULT 0,
    min_notice_minutes int NOT NULL DEFAULT 0,
    max_days_in_advance int NOT NULL DEFAULT 30,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY (id)
) {$charset};");

dbDelta("CREATE TABLE {$p}exceptions (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    type varchar(50) NOT NULL,
    title varchar(190) NOT NULL,
    date_start datetime NOT NULL,
    date_end datetime NOT NULL,
    all_day tinyint(1) NOT NULL DEFAULT 0,
    booking_type_id bigint unsigned DEFAULT NULL,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY (id)
) {$charset};");

dbDelta("CREATE TABLE {$p}tokens (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    booking_id bigint unsigned NOT NULL,
    token_type varchar(50) NOT NULL,
    token_hash varchar(255) NOT NULL,
    expires_at datetime NOT NULL,
    used_at datetime DEFAULT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id),
    KEY booking_id (booking_id)
) {$charset};");

dbDelta("CREATE TABLE {$p}booking_status_log (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    booking_id bigint unsigned NOT NULL,
    old_status varchar(50) DEFAULT NULL,
    new_status varchar(50) NOT NULL,
    context varchar(100) DEFAULT NULL,
    changed_by varchar(50) DEFAULT NULL,
    note text DEFAULT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id)
) {$charset};");

dbDelta("CREATE TABLE {$p}sync_jobs (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    booking_id bigint unsigned NOT NULL,
    job_type varchar(50) NOT NULL,
    payload_json longtext DEFAULT NULL,
    status varchar(20) NOT NULL DEFAULT 'pending',
    attempts int NOT NULL DEFAULT 0,
    last_error text DEFAULT NULL,
    available_at datetime NOT NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY (id)
) {$charset};");

dbDelta("CREATE TABLE {$p}sync_log (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    job_id bigint unsigned DEFAULT NULL,
    booking_id bigint unsigned DEFAULT NULL,
    level varchar(20) NOT NULL,
    message text NOT NULL,
    created_at datetime NOT NULL,
    PRIMARY KEY (id)
) {$charset};");

update_option('wpcb_settings', [
    'timezone' => 'Europe/Vienna',
    'mode' => 'automatic',
    'sender_name' => 'Legacy Site',
    'sender_email' => 'legacy@example.com',
    'icloud_sync_enabled' => 1,
    'icloud_sync_password_enc' => base64_encode('legacy-unauthenticated-secret'),
    'calendar_urls' => 'https://example.test/legacy.ics',
]);
delete_option('wpcb_schema_version');
delete_option('wpcb_token_storage_version');
delete_option('wpcb_secret_storage_version');
delete_option('wpcb_time_storage_version');
delete_option('wpcb_booking_status_version');

$wpdb->insert($p . 'booking_types', [
    'id' => 77,
    'name' => 'Legacy Consultation',
    'slug' => 'legacy-consultation',
    'description' => 'Keep me',
    'duration_minutes' => 45,
    'buffer_before_minutes' => 5,
    'buffer_after_minutes' => 10,
    'is_active' => 1,
    'is_public' => 1,
    'sort_order' => 1,
    'created_at' => '2026-01-01 10:00:00',
    'updated_at' => '2026-01-01 10:00:00',
]);
$wpdb->insert($p . 'availability_rules', [
    'id' => 88,
    'scope_type' => 'booking_type',
    'scope_id' => 77,
    'weekday' => 4,
    'start_time' => '09:00:00',
    'end_time' => '12:00:00',
    'slot_duration_minutes' => 45,
    'buffer_before_minutes' => 5,
    'buffer_after_minutes' => 10,
    'min_notice_minutes' => 60,
    'max_days_in_advance' => 60,
    'is_active' => 1,
    'created_at' => '2026-01-01 10:00:00',
    'updated_at' => '2026-01-01 10:00:00',
]);
$wpdb->insert($p . 'bookings', [
    'id' => 99,
    'booking_uuid' => 'legacy-booking-fixture',
    'booking_type_id' => 77,
    'slot_start' => '2026-01-15 09:00:00',
    'slot_end' => '2026-01-15 09:45:00',
    'status' => 'email_unconfirmed',
    'full_name' => 'Legacy Customer',
    'email' => 'legacy-customer@example.com',
    'source' => 'frontend',
    'lang' => 'de',
    'reserved_until' => '2026-01-15 08:30:00',
    'created_at' => '2026-01-01 10:00:00',
    'updated_at' => '2026-01-01 10:00:00',
]);
$wpdb->insert($p . 'tokens', [
    'booking_id' => 99,
    'token_type' => 'doi',
    'token_hash' => wp_hash_password('legacy-raw-token'),
    'expires_at' => '2026-12-31 12:00:00',
    'used_at' => null,
    'created_at' => '2026-01-01 10:00:00',
]);
$wpdb->insert($p . 'booking_status_log', [
    'booking_id' => 99,
    'old_status' => null,
    'new_status' => 'email_unconfirmed',
    'context' => 'legacy',
    'changed_by' => 'system',
    'note' => 'legacy fixture',
    'created_at' => '2026-01-01 10:00:00',
]);

WP_CLI::success('Legacy 1.x database fixture created.');
