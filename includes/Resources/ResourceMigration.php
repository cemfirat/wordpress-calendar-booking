<?php
namespace Wpcb\Resources;

final class ResourceMigration {
    private const OPTION = 'wpcb_resource_model_version';
    private const VERSION = 1;

    public static function maybeRun(): void {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return;
        }

        global $wpdb;
        $repo = new ResourceRepository();
        $defaultId = $repo->ensureDefault();
        if ($defaultId < 1) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}wpcb_bookings
             SET resource_id = %d
             WHERE resource_id IS NULL OR resource_id = 0",
            $defaultId
        ));

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
            }
        }

        update_option(self::OPTION, self::VERSION, false);
    }

    public static function currentVersion(): int {
        return self::VERSION;
    }
}
