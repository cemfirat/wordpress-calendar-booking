<?php
namespace Wpcb\Database;

/**
 * Runs idempotent dbDelta schema upgrades when plugin code changes.
 */
final class SchemaMigration {
    private const OPTION = 'wpcb_schema_version';
    private const VERSION = 13;

    public static function maybeRun(): void {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return;
        }

        Schema::install();
        update_option(self::OPTION, self::VERSION, false);
    }

    public static function currentVersion(): int {
        return self::VERSION;
    }
}
