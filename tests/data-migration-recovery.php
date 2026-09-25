<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_data_recovery_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

final class WpcbDataMigrationFaultWpdb extends wpdb {
    public string $faultMode = '';
    public int $exceptionUpdates = 0;
    public int $blockedSettingsWrites = 0;

    public function query($query) {
        $sql = ltrim((string)$query);

        if ($this->faultMode === 'time_second_exception_update'
            && preg_match('/^UPDATE\s+`?' . preg_quote($this->prefix . 'wpcb_exceptions', '/') . '`?\s+/i', $sql)
        ) {
            ++$this->exceptionUpdates;
            if ($this->exceptionUpdates === 2) {
                $this->last_error = 'CI injected second time-migration row failure';
                return false;
            }
        }

        if ($this->faultMode === 'secret_settings_write'
            && preg_match('/^UPDATE\s+`?' . preg_quote($this->options, '/') . '`?\s+/i', $sql)
            && strpos($sql, "option_name = 'wpcb_settings'") !== false
        ) {
            ++$this->blockedSettingsWrites;
            $this->last_error = 'CI injected settings write failure';
            return false;
        }

        return parent::query($query);
    }
}

global $wpdb;
$rootWpdb = $wpdb;
$prefix = $wpdb->prefix . 'wpcb_';
$settingsBefore = get_option('wpcb_settings', []);
$timeVersionBefore = (int)get_option('wpcb_time_storage_version', 0);
$secretVersionBefore = (int)get_option('wpcb_secret_storage_version', 0);
$reentryBefore = get_option('wpcb_secret_reentry_required', null);
$exceptionIds = [];

try {
    // This test intentionally runs immediately after plugin activation on the
    // otherwise fresh CI install, before the general smoke fixtures are seeded.
    wpcb_data_recovery_assert(
        0 === (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}bookings"),
        'Data-migration retry test starts before booking smoke fixtures are created.'
    );

    $settings = (array)$settingsBefore;
    $settings['timezone'] = 'Europe/Vienna';
    update_option('wpcb_settings', $settings);

    $now = gmdate('Y-m-d H:i:s');
    foreach ([
        ['Legacy time A', '2026-01-15 10:00:00', '2026-01-15 10:30:00'],
        ['Legacy time B', '2026-01-15 11:00:00', '2026-01-15 11:30:00'],
    ] as $fixture) {
        $ok = $wpdb->insert($prefix . 'exceptions', [
            'type' => 'blocked_range',
            'title' => $fixture[0],
            'date_start' => $fixture[1],
            'date_end' => $fixture[2],
            'all_day' => 0,
            'booking_type_id' => null,
            'resource_id' => null,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        wpcb_data_recovery_assert($ok !== false, 'Legacy time fixture row is created.');
        $exceptionIds[] = (int)$wpdb->insert_id;
    }

    update_option('wpcb_time_storage_version', 1, false);

    $fault = new WpcbDataMigrationFaultWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $fault->set_prefix($rootWpdb->prefix);
    $fault->suppress_errors(true);
    $fault->faultMode = 'time_second_exception_update';
    $GLOBALS['wpdb'] = $fault;
    $wpdb = $fault;
    wp_cache_flush();

    wpcb_data_recovery_assert(
        Wpcb\Support\TimeMigration::maybeRun() === false,
        'A partial time-row write reports migration failure.'
    );
    wpcb_data_recovery_assert(
        $fault->exceptionUpdates === 2,
        'Fault injector reached the second legacy time row.'
    );

    $GLOBALS['wpdb'] = $rootWpdb;
    $wpdb = $rootWpdb;
    wp_cache_flush();

    $afterFailure = $wpdb->get_results(
        "SELECT id, date_start, date_end FROM {$prefix}exceptions WHERE id IN ("
        . implode(',', array_map('intval', $exceptionIds))
        . ') ORDER BY id ASC'
    );
    wpcb_data_recovery_assert(
        count($afterFailure) === 2
            && $afterFailure[0]->date_start === '2026-01-15 10:00:00'
            && $afterFailure[1]->date_start === '2026-01-15 11:00:00',
        'Failed time migration rolls back earlier converted rows instead of leaving a double-conversion hazard.'
    );
    wpcb_data_recovery_assert(
        (int)get_option('wpcb_time_storage_version', 0) < Wpcb\Support\TimeMigration::currentVersion(),
        'Failed time migration does not advance its completion marker.'
    );

    wpcb_data_recovery_assert(
        Wpcb\Support\TimeMigration::maybeRun(),
        'Time migration succeeds when retried after the injected row failure.'
    );
    $afterRetry = $wpdb->get_results(
        "SELECT id, date_start, date_end FROM {$prefix}exceptions WHERE id IN ("
        . implode(',', array_map('intval', $exceptionIds))
        . ') ORDER BY id ASC'
    );
    wpcb_data_recovery_assert(
        count($afterRetry) === 2
            && $afterRetry[0]->date_start === '2026-01-15 09:00:00'
            && $afterRetry[1]->date_start === '2026-01-15 10:00:00',
        'Retry converts each legacy Vienna wall-clock value to UTC exactly once.'
    );

    foreach ($exceptionIds as $id) {
        $wpdb->delete($prefix . 'exceptions', ['id' => $id]);
    }
    $exceptionIds = [];

    $legacySettings = (array)$settings;
    $legacySettings['icloud_sync_enabled'] = 1;
    $legacySettings['icloud_sync_password_enc'] = 'legacy-ci-ciphertext';
    update_option('wpcb_settings', $legacySettings);
    update_option('wpcb_secret_storage_version', 1, false);
    update_option('wpcb_secret_reentry_required', 0, false);

    $secretFault = new WpcbDataMigrationFaultWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $secretFault->set_prefix($rootWpdb->prefix);
    $secretFault->suppress_errors(true);
    $secretFault->faultMode = 'secret_settings_write';
    $GLOBALS['wpdb'] = $secretFault;
    $wpdb = $secretFault;
    wp_cache_flush();

    wpcb_data_recovery_assert(
        Wpcb\Security\SecretMigration::maybeRun() === false,
        'Legacy-secret migration reports failure when credential clearing cannot be persisted.'
    );
    wpcb_data_recovery_assert(
        $secretFault->blockedSettingsWrites === 1,
        'Secret fault injector proves the settings write was denied.'
    );
    wpcb_data_recovery_assert(
        (int)get_option('wpcb_secret_reentry_required', 0) === 1,
        'Administrator re-entry requirement is persisted before destructive secret clearing.'
    );
    wpcb_data_recovery_assert(
        (int)get_option('wpcb_secret_storage_version', 0) < Wpcb\Security\SecretMigration::currentVersion(),
        'Failed secret migration does not advance its completion marker.'
    );

    $GLOBALS['wpdb'] = $rootWpdb;
    $wpdb = $rootWpdb;
    wp_cache_flush();

    wpcb_data_recovery_assert(
        Wpcb\Security\SecretMigration::maybeRun(),
        'Legacy-secret migration succeeds on retry after storage recovers.'
    );
    $recoveredSettings = (array)get_option('wpcb_settings', []);
    wpcb_data_recovery_assert(
        empty($recoveredSettings['icloud_sync_password_enc'])
            && empty($recoveredSettings['icloud_sync_enabled']),
        'Retry removes the unauthenticated legacy credential and disables write-back.'
    );
    wpcb_data_recovery_assert(
        (int)get_option('wpcb_secret_reentry_required', 0) === 1,
        'Retry preserves the administrator credential re-entry requirement.'
    );

    WP_CLI::success('Post-schema data migration retry safety passed.');
} finally {
    $GLOBALS['wpdb'] = $rootWpdb;
    $wpdb = $rootWpdb;
    wp_cache_flush();

    foreach ($exceptionIds as $id) {
        $wpdb->delete($prefix . 'exceptions', ['id' => $id]);
    }
    update_option('wpcb_settings', $settingsBefore);
    update_option('wpcb_time_storage_version', $timeVersionBefore, false);
    update_option('wpcb_secret_storage_version', $secretVersionBefore, false);
    if ($reentryBefore === null || $reentryBefore === false) {
        delete_option('wpcb_secret_reentry_required');
    } else {
        update_option('wpcb_secret_reentry_required', $reentryBefore, false);
    }
}
