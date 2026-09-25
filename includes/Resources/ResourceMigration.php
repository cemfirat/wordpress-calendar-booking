<?php
namespace Wpcb\Resources;

final class ResourceMigration {
    private const OPTION = 'wpcb_resource_model_version';
    private const VERSION = 1;

    public static function maybeRun(): bool {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return true;
        }

        global $wpdb;
        $repo = new ResourceRepository();
        $defaultId = $repo->ensureDefault();
        if ($defaultId < 1) {
            return false;
        }

        if ($wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}wpcb_bookings
             SET resource_id = %d
             WHERE resource_id IS NULL OR resource_id = 0",
            $defaultId
        )) === false) {
            return false;
        }

        $typeIds = array_map(
            'intval',
            $wpdb->get_col("SELECT id FROM {$wpdb->prefix}wpcb_booking_types ORDER BY id ASC")
        );
        foreach ($typeIds as $typeId) {
            $mapped = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_type_resources WHERE booking_type_id = %d",
                $typeId
            ));
            if ($mapped === 0) {
                $repo->setForBookingType($typeId, [$defaultId]);
                $mapped = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_type_resources WHERE booking_type_id = %d",
                    $typeId
                ));
                if ($mapped === 0) {
                    return false;
                }
            }
        }

        if ((int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_bookings WHERE resource_id IS NULL OR resource_id = 0"
        ) > 0) {
            return false;
        }

        update_option(self::OPTION, self::VERSION, false);
        return (int)get_option(self::OPTION, 0) >= self::VERSION;
    }

    public static function currentVersion(): int {
        return self::VERSION;
    }
}
