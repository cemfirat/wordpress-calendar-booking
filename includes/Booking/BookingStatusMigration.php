<?php
namespace Cemb\Booking;

final class BookingStatusMigration {
    private const OPTION = 'cemb_booking_status_version';
    private const VERSION = 2;

    public static function maybeRun(): void {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cemb_bookings';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        $map = [
            'email_unconfirmed' => BookingStatus::RESERVED_UNCONFIRMED,
            'pending_admin_approval' => BookingStatus::PENDING_APPROVAL,
            'updated' => BookingStatus::CONFIRMED,
            'spam_blocked' => BookingStatus::REJECTED,
        ];

        foreach ($map as $legacy => $canonical) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET status = %s WHERE status = %s",
                    $canonical,
                    $legacy
                )
            );
        }

        update_option(self::OPTION, self::VERSION, false);
    }
}
