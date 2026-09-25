<?php
namespace Wpcb\Support;

/**
 * One-time migration from the imported 1.x local DATETIME convention to UTC.
 */
final class TimeMigration {
    private const OPTION = 'wpcb_time_storage_version';
    private const VERSION = 2;

    public static function maybeRun(): bool {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return true;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'wpcb_';

        if (!self::migrateTable(
            $prefix . 'bookings',
            'id',
            [
                'slot_start', 'slot_end', 'confirmed_at', 'approved_at',
                'cancelled_at', 'updated_at_user', 'reserved_until',
                'created_at', 'updated_at',
            ]
        )
            || !self::migrateTable($prefix . 'exceptions', 'id', ['date_start', 'date_end', 'created_at', 'updated_at'])
            || !self::migrateTable($prefix . 'tokens', 'id', ['expires_at', 'used_at', 'created_at'])
            || !self::migrateTable($prefix . 'booking_status_log', 'id', ['created_at'])
            || !self::migrateTable($prefix . 'sync_jobs', 'id', ['available_at', 'created_at', 'updated_at'])
            || !self::migrateTable($prefix . 'sync_log', 'id', ['created_at'])
        ) {
            return false;
        }

        update_option(self::OPTION, self::VERSION, false);
        return (int)get_option(self::OPTION, 0) >= self::VERSION;
    }

    public static function currentVersion(): int {
        return self::VERSION;
    }

    private static function legacyLocalToUtc(string $value): ?string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return null;
        }

        // Legacy 1.x did not record an offset/fold flag. Use PHP's deterministic
        // interpretation in the configured IANA zone for those historic rows.
        // New 2.0 input uses Time::parseLocal(), which rejects ambiguous/gap times.
        $date = \DateTimeImmutable::createFromFormat('!' . Time::STORAGE_FORMAT, $value, Time::bookingTimezone());
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && $errors['error_count'])) {
            return null;
        }
        return Time::formatUtc($date);
    }

    private static function migrateTable(string $table, string $primaryKey, array $columns): bool {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return false;
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

                $utc = self::legacyLocalToUtc($value);
                if ($utc !== null && $utc !== $value) {
                    $changes[$column] = $utc;
                }
            }

            if ($changes && $wpdb->update($table, $changes, [$primaryKey => (int)$row->$primaryKey]) === false) {
                return false;
            }
        }
        return true;
    }
}
