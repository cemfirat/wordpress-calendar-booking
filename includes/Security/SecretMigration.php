<?php
namespace Wpcb\Security;

/**
 * Legacy credential policy for the 2.0 security boundary.
 *
 * Historic credentials used unauthenticated AES-CBC or base64 fallback. Their
 * authenticity cannot be established, so they are revoked and must be entered
 * again rather than silently trusted and re-encrypted.
 */
final class SecretMigration {
    private const OPTION = 'wpcb_secret_storage_version';
    private const VERSION = 2;

    public static function maybeRun(): bool {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return true;
        }

        $settings = (array)get_option('wpcb_settings', []);
        $stored = (string)($settings['icloud_sync_password_enc'] ?? '');

        if ($stored !== '' && strpos($stored, 'v2:') !== 0) {
            // Record the required administrator action before destroying the
            // only evidence that a legacy credential existed. If clearing the
            // old secret fails, a retry will still see the legacy value and
            // repeat safely. If the marker write itself fails, no secret is
            // changed.
            update_option('wpcb_secret_reentry_required', 1, false);
            if ((int)get_option('wpcb_secret_reentry_required', 0) !== 1) {
                return false;
            }

            $settings['icloud_sync_password_enc'] = '';
            $settings['icloud_sync_enabled'] = 0;
            update_option('wpcb_settings', $settings);
            $storedSettings = (array)get_option('wpcb_settings', []);
            if (!empty($storedSettings['icloud_sync_password_enc'])
                || !empty($storedSettings['icloud_sync_enabled'])
            ) {
                return false;
            }
        }

        update_option(self::OPTION, self::VERSION, false);
        return (int)get_option(self::OPTION, 0) >= self::VERSION;
    }

    public static function currentVersion(): int {
        return self::VERSION;
    }
}
