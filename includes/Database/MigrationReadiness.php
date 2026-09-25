<?php
namespace Wpcb\Database;

use Wpcb\Booking\BookingStatusMigration;
use Wpcb\Resources\ResourceMigration;
use Wpcb\Security\SecretMigration;
use Wpcb\Support\TimeMigration;
use Wpcb\Tokens\TokenMigration;

/**
 * Bounded view of post-schema data migrations that must complete before
 * booking/write services are exposed.
 */
final class MigrationReadiness {
    /**
     * @return string[]
     */
    public static function missing(): array {
        $checks = [
            'tokens' => [(int)get_option('wpcb_token_storage_version', 0), TokenMigration::currentVersion()],
            'secrets' => [(int)get_option('wpcb_secret_storage_version', 0), SecretMigration::currentVersion()],
            'time' => [(int)get_option('wpcb_time_storage_version', 0), TimeMigration::currentVersion()],
            'booking_status' => [(int)get_option('wpcb_booking_status_version', 0), BookingStatusMigration::currentVersion()],
            'default_seed' => [(int)get_option('wpcb_default_seed_version', 0), DefaultSeedMigration::currentVersion()],
            'resources' => [(int)get_option('wpcb_resource_model_version', 0), ResourceMigration::currentVersion()],
        ];

        $missing = [];
        foreach ($checks as $name => [$stored, $expected]) {
            if ($stored < $expected) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    public static function isReady(): bool {
        return self::missing() === [];
    }

    public static function renderAdminNotice(): void {
        if (!is_admin() || !current_user_can('manage_options') || self::isReady()) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('WordPress Calendar Booking: Datenmigration unvollständig', 'wordpress-calendar-booking')
            . '</strong></p><p>'
            . esc_html__('Eine erforderliche Datenmigration konnte nicht vollständig verifiziert werden. Neue Buchungen bleiben aus Sicherheitsgründen gesperrt. Prüfe den Systemstatus und die Datenbankverfügbarkeit; die Migration wird bei einem späteren Aufruf erneut versucht.', 'wordpress-calendar-booking')
            . '</p></div>';
    }
}
