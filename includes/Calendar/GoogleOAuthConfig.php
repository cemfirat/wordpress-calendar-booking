<?php
namespace Wpcb\Calendar;

use Wpcb\Security\SecretBox;

final class GoogleOAuthConfig {
    private const CLIENT_ID_OPTION = 'wpcb_google_oauth_client_id';
    private const CLIENT_SECRET_OPTION = 'wpcb_google_oauth_client_secret_enc';

    private SecretBox $secrets;

    public function __construct(?SecretBox $secrets = null) {
        $this->secrets = $secrets ?: new SecretBox();
    }

    public function clientId(): string {
        if (defined('WPCB_GOOGLE_CLIENT_ID') && WPCB_GOOGLE_CLIENT_ID) {
            return trim((string)WPCB_GOOGLE_CLIENT_ID);
        }
        return trim((string)get_option(self::CLIENT_ID_OPTION, ''));
    }

    public function clientSecret(): string {
        if (defined('WPCB_GOOGLE_CLIENT_SECRET') && WPCB_GOOGLE_CLIENT_SECRET) {
            return trim((string)WPCB_GOOGLE_CLIENT_SECRET);
        }

        $encoded = (string)get_option(self::CLIENT_SECRET_OPTION, '');
        if ($encoded === '') {
            return '';
        }
        $plain = $this->secrets->decrypt($encoded);
        return $plain === null ? '' : $plain;
    }

    /** @return true|\WP_Error */
    public function save(string $clientId, string $clientSecret) {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return new \WP_Error('wpcb_google_client_id', 'Google OAuth client ID is required.');
        }

        update_option(self::CLIENT_ID_OPTION, sanitize_text_field($clientId), false);

        if ($clientSecret !== '') {
            $encrypted = $this->secrets->encrypt($clientSecret);
            if (is_wp_error($encrypted)) {
                return $encrypted;
            }
            update_option(self::CLIENT_SECRET_OPTION, $encrypted, false);
        }

        return true;
    }

    public function configured(): bool {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function redirectUri(): string {
        return admin_url('admin-post.php?action=wpcb_google_oauth_callback');
    }

    /** @return string[] */
    public function scopes(bool $blocksAvailability, bool $receivesBookings): array {
        $scopes = [];
        if ($blocksAvailability) {
            $scopes[] = 'https://www.googleapis.com/auth/calendar.freebusy';
        }
        if ($receivesBookings) {
            $scopes[] = 'https://www.googleapis.com/auth/calendar.events';
        }
        return array_values(array_unique($scopes));
    }
}
