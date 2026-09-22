<?php
namespace Cemb\Calendar;

use Cemb\Security\SecretBox;

final class MicrosoftOAuthConfig {
    private const CLIENT_ID_OPTION = 'cemb_microsoft_oauth_client_id';
    private const CLIENT_SECRET_OPTION = 'cemb_microsoft_oauth_client_secret_enc';

    private SecretBox $secrets;

    public function __construct(?SecretBox $secrets = null) {
        $this->secrets = $secrets ?: new SecretBox();
    }

    public function clientId(): string {
        if (defined('CEMB_MICROSOFT_CLIENT_ID') && CEMB_MICROSOFT_CLIENT_ID) {
            return trim((string)CEMB_MICROSOFT_CLIENT_ID);
        }
        return trim((string)get_option(self::CLIENT_ID_OPTION, ''));
    }

    public function clientSecret(): string {
        if (defined('CEMB_MICROSOFT_CLIENT_SECRET') && CEMB_MICROSOFT_CLIENT_SECRET) {
            return trim((string)CEMB_MICROSOFT_CLIENT_SECRET);
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
            return new \WP_Error('cemb_microsoft_client_id', 'Microsoft OAuth client ID is required.');
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
        return admin_url('admin-post.php?action=cemb_microsoft_oauth_callback');
    }

    /** @return string[] */
    public function scopes(bool $blocksAvailability, bool $receivesBookings): array {
        $scopes = ['openid', 'profile', 'email', 'offline_access'];
        if ($receivesBookings) {
            $scopes[] = 'Calendars.ReadWrite';
        } elseif ($blocksAvailability) {
            $scopes[] = 'Calendars.ReadBasic';
        }
        return array_values(array_unique($scopes));
    }
}
