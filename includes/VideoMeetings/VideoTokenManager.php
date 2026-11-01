<?php
namespace Wpcb\VideoMeetings;

/**
 * Provides provider access tokens for video connections.
 *
 * Renewable credentials (Zoom Server-to-Server OAuth, Google/Microsoft refresh
 * tokens, Microsoft client credentials) are renewed under a per-connection
 * MySQL lock so that concurrent jobs cannot burn a rotating refresh token.
 */
final class VideoTokenManager {
    private const SKEW_SECONDS = 120;
    private const LOCK_WAIT_SECONDS = 5;

    public function renewable(object $connection): bool {
        return $this->grant($connection) !== null;
    }

    /**
     * @return array{ok:true,token:string}|array{ok:false,message:string,code:string}
     */
    public function access(object $connection, string $staleToken = ''): array {
        $credentials = $this->credentials($connection);
        $token = trim((string)($credentials['access_token'] ?? ''));
        $grant = $this->grant($connection);

        if ($grant === null) {
            if ($token === '') {
                return $this->failure('missing', 'Video provider credentials are missing. Reconnect the connection.');
            }
            return ['ok' => true, 'token' => $token];
        }

        if ($this->usable($credentials) && $token !== $staleToken) {
            return ['ok' => true, 'token' => $token];
        }

        $id = (int)($connection->id ?? 0);
        if ($id < 1) {
            return $this->renew($connection, $credentials, $grant, false);
        }

        global $wpdb;
        $lock = 'wpcb_vidtok_' . substr(hash('sha256', (string)$id), 0, 40);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT_SECONDS)) !== 1) {
            return $this->failure('busy', 'Video provider token renewal is busy; retrying later.');
        }
        try {
            $fresh = (new VideoMeetingConnectionRepository())->find($id, true);
            $credentials = $fresh ? $fresh->credentials : $credentials;
            $token = trim((string)($credentials['access_token'] ?? ''));
            if ($this->usable($credentials) && $token !== $staleToken) {
                return ['ok' => true, 'token' => $token];
            }
            return $this->renew($connection, $credentials, $grant, true);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private function usable(array $credentials): bool {
        return trim((string)($credentials['access_token'] ?? '')) !== ''
            && (int)($credentials['expires_at'] ?? 0) > time() + self::SKEW_SECONDS;
    }

    private function credentials(object $connection): array {
        return is_array($connection->credentials ?? null) ? $connection->credentials : [];
    }

    /** Returns the grant type, or null when only a pasted static token exists. */
    private function grant(object $connection): ?string {
        $c = $this->credentials($connection);
        $has = static fn(string ...$keys): bool => !array_filter($keys, static fn($k) => trim((string)($c[$k] ?? '')) === '');
        switch ((string)($connection->provider ?? '')) {
            case 'zoom':
                return $has('account_id', 'client_id', 'client_secret') ? 'account_credentials' : null;
            case 'google_meet':
                return $has('refresh_token', 'client_id', 'client_secret') ? 'refresh_token' : null;
            case 'microsoft_teams':
                if ($has('refresh_token', 'client_id', 'client_secret')) return 'refresh_token';
                return $has('tenant_id', 'client_id', 'client_secret') ? 'client_credentials' : null;
        }
        return null;
    }

    private function renew(object $connection, array $credentials, string $grant, bool $persist): array {
        $provider = (string)$connection->provider;
        $headers = ['Accept' => 'application/json'];
        $body = [];
        if ($provider === 'zoom') {
            $url = 'https://zoom.us/oauth/token';
            $headers['Authorization'] = 'Basic ' . base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']);
            $body = ['grant_type' => 'account_credentials', 'account_id' => $credentials['account_id']];
        } elseif ($provider === 'google_meet') {
            $url = 'https://oauth2.googleapis.com/token';
            $body = [
                'grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token'],
                'client_id' => $credentials['client_id'], 'client_secret' => $credentials['client_secret'],
            ];
        } else {
            $tenant = trim((string)($credentials['tenant_id'] ?? '')) ?: 'organizations';
            $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
            $body = ['grant_type' => $grant, 'client_id' => $credentials['client_id'], 'client_secret' => $credentials['client_secret']];
            if ($grant === 'refresh_token') {
                $body['refresh_token'] = $credentials['refresh_token'];
                $body['scope'] = 'https://graph.microsoft.com/OnlineMeetings.ReadWrite offline_access';
            } else {
                $body['scope'] = 'https://graph.microsoft.com/.default';
            }
        }

        $response = wp_remote_post($url, ['timeout' => 15, 'redirection' => 0, 'headers' => $headers, 'body' => $body]);
        if (is_wp_error($response)) {
            return $this->failure('transient', 'Video provider token endpoint is unreachable; retrying later.');
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string)wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 500 || $status === 429) {
            return $this->failure('transient', 'Video provider token endpoint is temporarily unavailable (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300 || empty($decoded['access_token'])) {
            $error = sanitize_key((string)($decoded['error'] ?? ''));
            $message = 'Video provider rejected the credentials (HTTP ' . $status . ($error !== '' ? ', ' . $error : '') . '). Reconnect the connection and check its permissions.';
            if ($persist) {
                (new VideoMeetingConnectionRepository())->setAuthHealth((int)$connection->id, 'reauth_required', $message);
            }
            return $this->failure('reauth_required', $message);
        }

        $credentials['access_token'] = (string)$decoded['access_token'];
        $credentials['expires_at'] = time() + max(60, (int)($decoded['expires_in'] ?? 3600));
        if (!empty($decoded['refresh_token'])) {
            $credentials['refresh_token'] = (string)$decoded['refresh_token'];
        }
        if ($persist) {
            $repo = new VideoMeetingConnectionRepository();
            $saved = $repo->updateCredentials((int)$connection->id, $credentials);
            if (is_wp_error($saved)) {
                return $this->failure('transient', 'Renewed video provider token could not be stored; retrying later.');
            }
            $repo->setAuthHealth((int)$connection->id, 'ok', '');
        }
        return ['ok' => true, 'token' => $credentials['access_token']];
    }

    private function failure(string $code, string $message): array {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
