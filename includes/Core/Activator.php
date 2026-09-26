<?php
namespace Wpcb\Core;

use Wpcb\Booking\BookingStatusMigration;
use Wpcb\Database\DefaultSeedMigration;
use Wpcb\Database\SchemaMigration;
use Wpcb\Resources\ResourceMigration;
use Wpcb\Security\SecretMigration;
use Wpcb\Support\TimeMigration;
use Wpcb\Tokens\TokenMigration;

class Activator {
    public static function activate(bool $networkWide = false): void {
        $scopeError = self::validateActivationScope($networkWide);
        if (is_wp_error($scopeError)) {
            wp_die(
                esc_html($scopeError->get_error_message()),
                esc_html__('WordPress Calendar Booking activation', 'wordpress-calendar-booking'),
                ['response' => 400, 'back_link' => true]
            );
        }
        if (!SchemaMigration::maybeRun()) {
            return;
        }
        if (!TokenMigration::maybeRun()
            || !SecretMigration::maybeRun()
            || !TimeMigration::maybeRun()
            || !BookingStatusMigration::maybeRun()
            || !DefaultSeedMigration::maybeRun()
            || !ResourceMigration::maybeRun()
        ) {
            return;
        }
        flush_rewrite_rules();
    }

    public static function validateActivationScope(bool $networkWide) {
        if (!$networkWide) {
            return null;
        }
        return new \WP_Error(
            'wpcb_network_activation_unsupported',
            __('Network-wide activation is not supported. Activate WordPress Calendar Booking separately on each site in the network.', 'wordpress-calendar-booking')
        );
    }

    public static function deactivate(): void {
        foreach ([
            'wpcb_sync_queue',
            'wpcb_hourly_reminders',
            'wpcb_privacy_retention',
            'wpcb_portal_session_cleanup',
            'wpcb_waitlist_send_offer',
        ] as $hook) {
            wp_unschedule_hook($hook);
        }
        flush_rewrite_rules();
    }
}
