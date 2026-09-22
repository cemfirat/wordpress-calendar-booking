<?php
namespace Cemb\Security;

/**
 * Legacy credential policy for the 2.0 security boundary.
 *
 * Historic credentials used unauthenticated AES-CBC or base64 fallback. Their
 * authenticity cannot be established, so they are revoked and must be entered
 * again rather than silently trusted and re-encrypted.
 */
final class SecretMigration {
    private const OPTION = 'cemb_secret_storage_version';
    private const VERSION = 2;

    public static function maybeRun(): void {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return;
        }

        $settings = (array)get_option('cemb_settings', []);
        $stored = (string)($settings['icloud_sync_password_enc'] ?? '');

        if ($stored !== '' && strpos($stored, 'v2:') !== 0) {
            $settings['icloud_sync_password_enc'] = '';
            $settings['icloud_sync_enabled'] = 0;
            update_option('cemb_settings', $settings);
            update_option('cemb_secret_reentry_required', 1, false);
        }

        update_option(self::OPTION, self::VERSION, false);
    }

    public static function currentVersion(): int {
        return self::VERSION;
    }
}
