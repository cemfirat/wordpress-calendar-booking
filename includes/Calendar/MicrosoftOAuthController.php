<?php
namespace Wpcb\Calendar;

final class MicrosoftOAuthController {
    private const STATE_PREFIX = 'wpcb_ms_oauth_state_';
    private const AUTHORIZE_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const PERSONAL_TENANT_ID = '9188040d-6c67-4c5b-b112-36a304b66dad';

    private MicrosoftOAuthConfig $config;
    private CalendarConnectionRepository $connections;

    public function __construct(
        ?MicrosoftOAuthConfig $config = null,
        ?CalendarConnectionRepository $connections = null
    ) {
        $this->config = $config ?: new MicrosoftOAuthConfig();
        $this->connections = $connections ?: new CalendarConnectionRepository();
    }

    public function boot(): void {
        add_filter('wpcb_calendar_providers', [$this, 'registerProvider']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcb_microsoft_save_oauth', [$this, 'saveOAuthConfig']);
        add_action('admin_post_wpcb_microsoft_connect', [$this, 'startConnect']);
        add_action('admin_post_wpcb_microsoft_oauth_callback', [$this, 'callback']);
        add_action('admin_post_wpcb_microsoft_disconnect', [$this, 'disconnect']);
    }

    public function registerProvider(array $providers): array {
        $providers[] = new MicrosoftGraphProvider($this->connections, $this->config);
        return $providers;
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            'Microsoft Calendar',
            'Microsoft Calendar',
            'manage_options',
            'wpcb_microsoft_connections',
            [$this, 'page']
        );
    }

    public function page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $connections = array_values(array_filter(
            $this->connections->all(false),
            static fn(CalendarConnection $connection): bool => $connection->provider === 'microsoft'
        ));
        ?>
        <div class="wrap">
            <h1>Microsoft 365 / Outlook</h1>
            <?php if (isset($_GET['wpcb_microsoft_notice'])): ?>
                <div class="notice notice-success"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_microsoft_notice']))); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['wpcb_microsoft_error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_microsoft_error']))); ?></p></div>
            <?php endif; ?>

            <h2>Microsoft Entra app registration</h2>
            <p>Register this Web redirect URI in the Microsoft identity platform:</p>
            <p><code><?php echo esc_html($this->config->redirectUri()); ?></code></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wpcb_microsoft_save_oauth'); ?>
                <input type="hidden" name="action" value="wpcb_microsoft_save_oauth">
                <table class="form-table" role="presentation">
                    <tr><th><label for="wpcb_ms_client_id">Client ID</label></th>
                    <td><input class="regular-text" id="wpcb_ms_client_id" name="client_id" value="<?php echo esc_attr($this->config->clientId()); ?>" autocomplete="off"></td></tr>
                    <tr><th><label for="wpcb_ms_client_secret">Client secret</label></th>
                    <td><input class="regular-text" type="password" id="wpcb_ms_client_secret" name="client_secret" value="" autocomplete="new-password"><p class="description">Leave blank to keep the encrypted secret. Constants WPCB_MICROSOFT_CLIENT_ID / WPCB_MICROSOFT_CLIENT_SECRET override these fields.</p></td></tr>
                </table>
                <?php submit_button('Save Microsoft OAuth settings'); ?>
            </form>

            <hr>
            <h2>Connect Microsoft Calendar</h2>
            <?php if (!$this->config->configured()): ?>
                <p>Save the Microsoft OAuth client ID and secret first.</p>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wpcb_microsoft_connect'); ?>
                    <input type="hidden" name="action" value="wpcb_microsoft_connect">
                    <table class="form-table" role="presentation">
                        <tr><th><label for="wpcb_ms_name">Connection name</label></th>
                        <td><input class="regular-text" id="wpcb_ms_name" name="connection_name" value="Microsoft Calendar" required></td></tr>
                        <tr><th><label for="wpcb_ms_calendar">Calendar ID</label></th>
                        <td><input class="regular-text" id="wpcb_ms_calendar" name="remote_calendar_id" value="primary" required><p class="description">Use <code>primary</code> for the signed-in default calendar. A specific Graph calendar ID uses calendarView availability.</p></td></tr>
                        <tr><th>Capabilities</th><td>
                            <label><input type="checkbox" name="blocks_availability" value="1" checked> Block availability</label><br>
                            <label><input type="checkbox" name="receives_bookings" value="1"> Write confirmed bookings</label>
                        </td></tr>
                    </table>
                    <?php submit_button('Connect Microsoft Calendar'); ?>
                </form>
            <?php endif; ?>

            <hr>
            <h2>Microsoft connections</h2>
            <?php if (!$connections): ?>
                <p>No Microsoft Calendar connection yet.</p>
            <?php else: ?>
                <table class="widefat striped"><thead><tr><th>Name</th><th>Calendar</th><th>Use</th><th>Health</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($connections as $connection): ?>
                    <tr>
                        <td><?php echo esc_html($connection->name); ?></td>
                        <td><code><?php echo esc_html($connection->remoteCalendarId); ?></code></td>
                        <td><?php echo esc_html(trim(($connection->blocksAvailability ? 'Busy ' : '') . ($connection->receivesBookings ? 'Write-back' : ''))); ?></td>
                        <td><?php echo esc_html($connection->healthStatus); ?></td>
                        <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('wpcb_microsoft_disconnect_' . $connection->id); ?>
                            <input type="hidden" name="action" value="wpcb_microsoft_disconnect">
                            <input type="hidden" name="connection_id" value="<?php echo (int)$connection->id; ?>">
                            <?php submit_button('Disconnect', 'secondary', 'submit', false); ?>
                        </form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <p class="description">Work/school default calendars use Graph getSchedule when available. Personal Microsoft accounts and specific calendars use calendarView.</p>
        </div>
        <?php
    }

    public function saveOAuthConfig(): void {
        $this->requireAdmin();
        check_admin_referer('wpcb_microsoft_save_oauth');
        $result = $this->config->save(
            sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')),
            (string)wp_unslash($_POST['client_secret'] ?? '')
        );
        if (is_wp_error($result)) {
            $this->redirectError($result->get_error_message());
        }
        $this->redirectNotice('Microsoft OAuth settings saved.');
    }

    public function startConnect(): void {
        $this->requireAdmin();
        check_admin_referer('wpcb_microsoft_connect');

        if (!$this->config->configured()) {
            $this->redirectError('Microsoft OAuth client is not configured.');
        }

        $blocks = !empty($_POST['blocks_availability']);
        $writes = !empty($_POST['receives_bookings']);
        if (!$blocks && !$writes) {
            $this->redirectError('Select at least one Microsoft calendar capability.');
        }

        $state = $this->issueState([
            'connection_name' => sanitize_text_field(wp_unslash($_POST['connection_name'] ?? 'Microsoft Calendar')),
            'remote_calendar_id' => sanitize_text_field(wp_unslash($_POST['remote_calendar_id'] ?? 'primary')),
            'blocks_availability' => $blocks ? 1 : 0,
            'receives_bookings' => $writes ? 1 : 0,
        ]);

        wp_redirect(esc_url_raw($this->authorizationUrl($blocks, $writes, $state)), 302, 'WordPress Calendar Booking');
        exit;
    }

    public function callback(): void {
        $this->requireAdmin();

        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $stored = $this->consumeState($state);
        if (!is_array($stored) || isset($_GET['error'])) {
            $this->redirectError('Microsoft authorization state is invalid or expired.');
        }

        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if ($code === '') {
            $this->redirectError('Microsoft did not return an authorization code.');
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'redirection' => 0,
            'body' => [
                'client_id' => $this->config->clientId(),
                'client_secret' => $this->config->clientSecret(),
                'code' => $code,
                'redirect_uri' => $this->config->redirectUri(),
                'grant_type' => 'authorization_code',
                'scope' => implode(' ', $this->config->scopes(
                    !empty($stored['blocks_availability']),
                    !empty($stored['receives_bookings'])
                )),
            ],
        ]);
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) {
            $this->redirectError('Microsoft token exchange failed. Check the app registration and redirect URI.');
        }

        $tokens = json_decode((string)wp_remote_retrieve_body($response), true);
        if (!is_array($tokens) || empty($tokens['access_token'])) {
            $this->redirectError('Microsoft returned an invalid token response.');
        }

        $claims = $this->jwtClaims((string)($tokens['id_token'] ?? ''));
        $tenantId = sanitize_text_field((string)($claims['tid'] ?? ''));
        $address = sanitize_email((string)($claims['preferred_username'] ?? $claims['email'] ?? ''));
        $accountType = $tenantId === self::PERSONAL_TENANT_ID ? 'personal' : 'work_school';

        $credentials = [
            'access_token' => (string)$tokens['access_token'],
            'refresh_token' => (string)($tokens['refresh_token'] ?? ''),
            'expires_at' => time() + max(60, (int)($tokens['expires_in'] ?? 3600)),
            'scope' => (string)($tokens['scope'] ?? ''),
            'token_type' => (string)($tokens['token_type'] ?? 'Bearer'),
            'tenant_id' => $tenantId,
            'account_type' => $accountType,
            'account_address' => $address,
        ];

        $connectionId = $this->connections->create(
            [
                'provider' => 'microsoft',
                'name' => (string)$stored['connection_name'],
                'remote_calendar_id' => (string)$stored['remote_calendar_id'],
                'blocks_availability' => !empty($stored['blocks_availability']),
                'receives_bookings' => !empty($stored['receives_bookings']),
                'config' => [
                    'account_type' => $accountType,
                    'tenant_id' => $tenantId,
                ],
            ],
            $credentials
        );
        if (is_wp_error($connectionId)) {
            $this->redirectError($connectionId->get_error_message());
        }

        $this->redirectNotice('Microsoft Calendar connected.');
    }

    public function disconnect(): void {
        $this->requireAdmin();
        $connectionId = absint($_POST['connection_id'] ?? 0);
        check_admin_referer('wpcb_microsoft_disconnect_' . $connectionId);
        $connection = $this->connections->find($connectionId);
        if (!$connection || $connection->provider !== 'microsoft') {
            $this->redirectError('Microsoft Calendar connection was not found.');
        }
        $this->connections->delete($connectionId);
        $this->redirectNotice('Microsoft Calendar disconnected.');
    }

    public function issueState(array $intent): string {
        $state = bin2hex(random_bytes(24));
        $intent['user_id'] = get_current_user_id();
        set_transient(self::STATE_PREFIX . hash('sha256', $state), $intent, 10 * MINUTE_IN_SECONDS);
        return $state;
    }

    public function consumeState(string $state): ?array {
        $state = trim($state);
        if ($state === '' || !preg_match('/^[a-f0-9]{48}$/', $state)) {
            return null;
        }
        $key = self::STATE_PREFIX . hash('sha256', $state);
        $stored = get_transient($key);
        delete_transient($key);
        if (!is_array($stored) || (int)($stored['user_id'] ?? 0) !== get_current_user_id()) {
            return null;
        }
        return $stored;
    }

    public function authorizationUrl(bool $blocksAvailability, bool $receivesBookings, string $state): string {
        return add_query_arg([
            'client_id' => $this->config->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->config->redirectUri(),
            'response_mode' => 'query',
            'scope' => implode(' ', $this->config->scopes($blocksAvailability, $receivesBookings)),
            'state' => $state,
            'prompt' => 'select_account',
        ], self::AUTHORIZE_URL);
    }

    private function jwtClaims(string $jwt): array {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }
        $payload = strtr($parts[1], '-_', '+/');
        $padding = strlen($payload) % 4;
        if ($padding) {
            $payload .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($payload, true);
        $claims = $decoded !== false ? json_decode($decoded, true) : null;
        return is_array($claims) ? $claims : [];
    }

    private function requireAdmin(): void {
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to manage calendar connections.');
        }
    }

    private function redirectNotice(string $message): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'wpcb_microsoft_connections',
            'wpcb_microsoft_notice' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    private function redirectError(string $message): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'wpcb_microsoft_connections',
            'wpcb_microsoft_error' => $message,
        ], admin_url('admin.php')));
        exit;
    }
}
