<?php
if (!defined('ABSPATH')) {
    exit(1);
}

global $wpdb;
$name = Wpcb\Database\SchemaMigration::migrationLockName();
$locked = (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 0));
if ($locked !== 1) {
    throw new RuntimeException('Could not acquire schema migration fixture lock.');
}

delete_option('wpcb_schema_verified_version');
delete_option('wpcb_schema_migration_error');
WP_CLI::line('LOCKED');
sleep(7);
$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));

if (!Wpcb\Database\SchemaMigration::maybeRun()) {
    throw new RuntimeException('Schema marker could not be restored after releasing fixture lock.');
}
WP_CLI::success('Schema migration fixture lock released and readiness restored.');
