<?php
namespace Cemb\Support;

/**
 * One-time migration from the imported 1.x local DATETIME convention to UTC.
 */
final class TimeMigration {
    private const OPTION = 'cemb_time_storage_version';
    private const VERSION = 2;

    public static function maybeRun(): void {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'cemb_';

        self::migrateTable(
            $prefix . 'bookings',
            'id',
            [
                'slot_start', 'slot_end', 'confirmed_at', 'approved_at',
                'cancelled_at', 'updated_at_user', 'reserved_until',
                'created_at', 'updated_at',
            ]
        );
        self::migrateTable($prefix . 'exceptions', 'id', ['date_start', 'date_end', 'created_at', 'updated_at']);
        self::migrateTable($prefix . 'tokens', 'id', ['expires_at', 'used_at', 'created_at']);
        self::migrateTable($prefix . 'booking_status_log', 'id', ['created_at']);
        self::migrateTable($prefix . 'sync_jobs', 'id', ['available_at', 'created_at', 'updated_at']);
        self::migrateTable($prefix . 'sync_log', 'id', ['created_at']);

        update_option(self::OPTION, self::VERSION, false);
    }

    private static function migrateTable(string $table, string $primaryKey, array $columns): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        $select = array_merge([$primaryKey], $columns);
        $rows = $wpdb->get_results('SELECT ' . implode(', ', $select) . ' FROM ' . $table);
        foreach ($rows as $row) {
            $changes = [];
            foreach ($columns as $column) {
                $value = isset($row->$column) ? (string)$row->$column : '';
                if ($value === '' || $value === '0000-00-00 00:00:00') {
                    continue;
                }

                $utc = Time::localToUtc($value);
                if ($utc !== null && $utc !== $value) {
                    $changes[$column] = $utc;
                }
            }

            if ($changes) {
                $wpdb->update($table, $changes, [$primaryKey => (int)$row->$primaryKey]);
            }
        }
    }
}
