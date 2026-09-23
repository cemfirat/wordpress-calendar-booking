<?php
namespace Wpcb\Tokens;

use Wpcb\Support\Time;

/**
 * Migrates one-time token storage to indexed selector/verifier records.
 *
 * Legacy raw tokens cannot be converted because only their password hashes are
 * stored. Supporting them would require retaining the O(n) hash scan this
 * migration removes, so pending legacy links are intentionally revoked.
 */
final class TokenMigration {
    private const OPTION = 'wpcb_token_storage_version';
    private const VERSION = 2;

    public static function maybeRun(): void {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_tokens';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        $selectorColumn = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$table} LIKE %s",
                'token_selector'
            )
        );
        if (!$selectorColumn) {
            return;
        }

        $now = Time::formatUtc(Time::nowUtc());
        $revoked = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET used_at = COALESCE(used_at, %s)
                 WHERE token_selector IS NULL OR token_selector = ''",
                $now
            )
        );

        update_option(self::OPTION, self::VERSION, false);
        update_option('wpcb_legacy_tokens_revoked', max(0, (int)$revoked), false);
    }

    public static function currentVersion(): int {
        return self::VERSION;
    }
}
