<?php
namespace Wpcb\Database;

/**
 * Runs idempotent dbDelta schema upgrades and records completion only after
 * the required tables, columns and indexes are observed in MySQL.
 */
final class SchemaMigration {
    private const OPTION = 'wpcb_schema_version';
    private const VERIFIED_OPTION = 'wpcb_schema_verified_version';
    private const ERROR_OPTION = 'wpcb_schema_migration_error';
    private const VERSION = 15;
    private const LOCK_SECONDS = 5;

    public static function maybeRun(): bool {
        if (self::isReady()) {
            return true;
        }

        global $wpdb;
        $lockName = self::migrationLockName();
        $locked = (int)$wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, self::LOCK_SECONDS)
        );
        if ($locked !== 1) {
            self::recordFailure('lock_timeout', []);
            return false;
        }

        try {
            // A concurrent request may have completed the migration while this
            // request waited on the advisory lock.
            if (self::isReady()) {
                return true;
            }

            delete_option(self::VERIFIED_OPTION);
            Schema::install();

            $verification = SchemaVerifier::verify();
            if (empty($verification['ready'])) {
                self::recordFailure(
                    'schema_incomplete',
                    array_slice((array)($verification['issues'] ?? []), 0, 50)
                );
                return false;
            }

            if (!self::storeVersion(self::OPTION) || !self::storeVersion(self::VERIFIED_OPTION)) {
                self::recordFailure('marker_write_failed', []);
                return false;
            }

            delete_option(self::ERROR_OPTION);
            return true;
        } catch (\Throwable $error) {
            // Do not persist raw database or exception text. Hosting paths,
            // credentials and SQL belong in server logs, not admin output.
            self::recordFailure('migration_exception', []);
            return false;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    public static function isReady(): bool {
        return (int)get_option(self::OPTION, 0) >= self::VERSION
            && (int)get_option(self::VERIFIED_OPTION, 0) >= self::VERSION;
    }

    public static function currentVersion(): int {
        return self::VERSION;
    }

    public static function recordedVersion(): int {
        return (int)get_option(self::OPTION, 0);
    }

    public static function verifiedVersion(): int {
        return (int)get_option(self::VERIFIED_OPTION, 0);
    }

    /**
     * Stable per-site lock name. Exposed for integration tests and operations;
     * it contains no credentials or customer data.
     */
    public static function migrationLockName(): string {
        global $wpdb;
        $blogId = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
        return 'wpcb_schema_' . substr(hash('sha256', $wpdb->prefix . '|' . $blogId), 0, 40);
    }

    /**
     * @return array{code?:string,issues?:string[],at?:string}
     */
    public static function lastFailure(): array {
        $value = get_option(self::ERROR_OPTION, []);
        return is_array($value) ? $value : [];
    }

    public static function renderAdminNotice(): void {
        if (!is_admin() || !current_user_can('manage_options') || self::isReady()) {
            return;
        }

        $failure = self::lastFailure();
        $code = sanitize_key((string)($failure['code'] ?? 'schema_incomplete'));
        $message = $code === 'lock_timeout'
            ? __('Die Datenbank-Aktualisierung von WordPress Calendar Booking läuft bereits. Bitte lade die Seite in einigen Sekunden erneut.', 'wordpress-calendar-booking')
            : __('Die Datenbankstruktur von WordPress Calendar Booking ist unvollständig. Neue Buchungen bleiben aus Sicherheitsgründen gesperrt. Prüfe die Datenbankrechte für CREATE/ALTER/INDEX und lade danach eine Admin-Seite erneut; die Reparatur wird automatisch wiederholt.', 'wordpress-calendar-booking');

        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('WordPress Calendar Booking: Datenbank-Aktualisierung erforderlich', 'wordpress-calendar-booking')
            . '</strong></p><p>' . esc_html($message) . '</p></div>';
    }

    private static function storeVersion(string $option): bool {
        if ((int)get_option($option, 0) >= self::VERSION) {
            return true;
        }
        update_option($option, self::VERSION, false);
        return (int)get_option($option, 0) >= self::VERSION;
    }

    private static function recordFailure(string $code, array $issues): void {
        update_option(self::ERROR_OPTION, [
            'code' => sanitize_key($code),
            'issues' => array_values(array_map('sanitize_text_field', $issues)),
            'at' => gmdate('Y-m-d H:i:s'),
        ], false);
    }
}
