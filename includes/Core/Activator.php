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
    public static function activate(): void {
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

    public static function deactivate(): void {
        wp_clear_scheduled_hook('wpcb_portal_session_cleanup');
        flush_rewrite_rules();
    }
}
