<?php
namespace Wpcb\Database;

final class BookingChannelMigration {
    public static function migrate(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_booking_types';
        foreach ([
            ['telefon', 'Telefon', 'phone'],
            ['online-meeting', 'Online-Meeting', 'online'],
            ['mich-besuchen', 'Mich besuchen', 'in_person'],
        ] as [$slug, $name, $channel]) {
            $matches = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE slug=%s AND name=%s",
                $slug,
                $name
            ));
            if ($matches !== 1) {
                continue;
            }
            if ($wpdb->update(
                $table,
                ['channel' => $channel],
                ['slug' => $slug, 'name' => $name, 'channel' => 'review']
            ) === false) {
                return false;
            }
        }
        return true;
    }
}
