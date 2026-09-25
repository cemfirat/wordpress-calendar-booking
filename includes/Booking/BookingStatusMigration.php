<?php
namespace Wpcb\Booking;

final class BookingStatusMigration {
    private const OPTION = 'wpcb_booking_status_version';
    private const VERSION = 2;

    public static function maybeRun(): bool {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_bookings';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return false;
        }

        $map = [
            'email_unconfirmed' => BookingStatus::RESERVED_UNCONFIRMED,
            'pending_admin_approval' => BookingStatus::PENDING_APPROVAL,
            'updated' => BookingStatus::CONFIRMED,
            'spam_blocked' => BookingStatus::REJECTED,
        ];

        foreach ($map as $legacy => $canonical) {
            if ($wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET status = %s WHERE status = %s",
                    $canonical,
                    $legacy
                )
            ) === false) {
                return false;
            }
        }

        $logTable = $wpdb->prefix . 'wpcb_booking_status_log';
        $logExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logTable));
        if ($logExists !== $logTable) {
            return false;
        }
        foreach ($map as $legacy => $canonical) {
            if ($wpdb->query($wpdb->prepare("UPDATE {$logTable} SET old_status = %s WHERE old_status = %s", $canonical, $legacy)) === false
                || $wpdb->query($wpdb->prepare("UPDATE {$logTable} SET new_status = %s WHERE new_status = %s", $canonical, $legacy)) === false
            ) {
                return false;
            }
            if ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $legacy)) > 0
                || (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logTable} WHERE old_status = %s OR new_status = %s", $legacy, $legacy)) > 0
            ) {
                return false;
            }
        }

        update_option(self::OPTION, self::VERSION, false);
        return (int)get_option(self::OPTION, 0) >= self::VERSION;
    }

    public static function currentVersion(): int {
        return self::VERSION;
    }
}
