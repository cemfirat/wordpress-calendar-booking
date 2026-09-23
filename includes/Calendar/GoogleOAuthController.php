<?php
namespace Wpcb\Calendar;

final class GoogleOAuthController {
    private const STATE_PREFIX = 'wpcb_google_oauth_state_';

    private GoogleOAuthConfig $config;
    private CalendarConnectionRepository $connections;

    public function __construct(
        ?GoogleOAuthConfig $config = null,
        ?CalendarConnectionRepository $connections = null
    ) {
        $this->config = $config ?: new GoogleOAuthConfig();
        $this->connections = $connections ?: new CalendarConnectionRepository();
    }

    public function boot(): void {
        add_filter('wpcb_calendar_providers', [$this, 'registerProvider']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcb_google_save_oauth', [$this, 'saveOAuthConfig']);
        add_action('admin_post_wpcb_google_connect', [$this, 'startConnect']);
        add_action('admin_post_wpcb_google_oauth_callback', [$this, 'callback']);
        add_action('admin_post_wpcb_google_disconnect', [$this, 'disconnect']);
    }

    public function registerProvider(array $providers): array {
        $providers[] = new GoogleCalendarProvider($this->connections, $this->config);
        return $providers;
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            'Calendar Connections',
            'Calendar Connections',
            'manage_options',
            'wpcb_calendar_connections',
            [$this, 'page']
        );
    }

    public function page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $connections = array_values(array_filter(
            $this->connections->all(false),
            static fn(CalendarConnection $connection): bool => $connection->provider === 'google'
        ));
        ?>
        <div class="wrap">
            <h1>Calendar Connections</h1>
            <?php if (isset($_GET['wpcb_google_notice'])): ?>
                <div class="notice notice-success"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_google_notice']))); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['wpcb_google_error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_google_error']))); ?></p></div>
            <?php endif; ?>

            <h2>Google OAuth application</h2>
            <p>Create a Web application OAuth client in Google Cloud and register this exact redirect URI:</p>
            <p><code><?php echo esc_html($this->config->redirectUri()); ?></code></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wpcb_google_save_oauth'); ?>
                <input type="hidden" name="action" value="wpcb_google_save_oauth">
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="wpcb_google_client_id">Client ID</label></th>
                        <td><input class="regular-text" id="wpcb_google_client_id" name="client_id" value="<?php echo esc_attr($this->config->clientId()); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th><label for="wpcb_google_client_secret">Client secret</label></th>
                        <td><input class="regular-text" type="password" id="wpcb_google_client_secret" name="client_secret" value="" autocomplete="new-password"><p class="description">Leave blank to keep the currently encrypted secret. Constants WPCB_GOOGLE_CLIENT_ID / WPCB_GOOGLE_CLIENT_SECRET override these fields.</p></td>
                    </tr>
                </table>
                <?php submit_button('Save Google OAuth settings'); ?>
            </form>

            <hr>
            <h2>Connect Google Calendar</h2>
            <?php if (!$this->config->configured()): ?>
                <p>Save a Google OAuth client ID and client secret first.</p>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wpcb_google_connect'); ?>
                    <input type="hidden" name="action" value="wpcb_google_connect">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="wpcb_google_connection_name">Connection name</label></th>
                            <td><input class="regular-text" id="wpcb_google_connection_name" name="connection_name" value="Google Calendar" required></td>
                        </tr>
                        <tr>
                            <th><label for="wpcb_google_calendar_id">Calendar ID</label></th>
                            <td><input class="regular-text" id="wpcb_google_calendar_id" name="remote_calendar_id" value="primary" required><p class="description">Use <code>primary</code> for the signed-in account's main calendar.</p></td>
                        </tr>
                        <tr>
                            <th>Capabilities</th>
                            <td>
                                <label><input type="checkbox" name="blocks_availability" value="1" checked> Block availability using FreeBusy</label><br>
                                <label><input type="checkbox" name="receives_bookings" value="1"> Write confirmed bookings to this calendar</label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Connect Google Calendar'); ?>
                </form>
            <?php endif; ?>

            <hr>
            <h2>Google connections</h2>
            <?php if (!$connections): ?>
                <p>No Google Calendar connection yet.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead><tr><th>Name</th><th>Calendar</th><th>Use</th><th>Health</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($connections as $connection): ?>
                        <tr>
                            <td><?php echo esc_html($connection->name); ?></td>
                            <td><code><?php echo esc_html($connection->remoteCalendarId); ?></code></td>
                            <td><?php echo esc_html(trim(($connection->blocksAvailability ? 'FreeBusy ' : '') . ($connection->receivesBookings ? 'Write-back' : ''))); ?></td>
                            <td><?php echo esc_html($connection->healthStatus); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('wpcb_google_disconnect_' . $connection->id); ?>
                                    <input type="hidden" name="action" value="wpcb_google_disconnect">
                                    <input type="hidden" name="connection_id" value="<?php echo (int)$connection->id; ?>">
                                    <?php submit_button('Disconnect', 'secondary', 'submit', false); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function saveOAuthConfig(): void {
        $this->requireAdmin();
        check_admin_referer('wpcb_google_save_oauth');

        $result = $this->config->save(
            sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')),
            (string)wp_unslash($_POST['client_secret'] ?? '')
        );

        if (is_wp_error($result)) {
            $this->redirectError($result->get_error_message());
        }
        $this->redirectNotice('Google OAuth settings saved.');
    }

    public function startConnect(): void {
        $this->requireAdmin();
        check_admin_referer('wpcb_google_connect');

        if (!$this->config->configured()) {
            $this->redirectError('Google OAuth client is not configured.');
        }

        $blocks = !empty($_POST['blocks_availability']);
        $writes = !empty($_POST['receives_bookings']);
        if (!$blocks && !$writes) {
            $this->redirectError('Select at least one Google Calendar capability.');
        }

        $state = $this->issueState([
            'connection_name' => sanitize_text_field(wp_unslash($_POST['connection_name'] ?? 'Google Calendar')),
            'remote_calendar_id' => sanitize_text_field(wp_unslash($_POST['remote_calendar_id'] ?? 'primary')),
            'blocks_availability' => $blocks ? 1 : 0,
            'receives_bookings' => $writes ? 1 : 0,
        ]);

        $url = $this->authorizationUrl($blocks, $writes, $state);

        wp_redirect(esc_url_raw($url), 302, 'WordPress Calendar Booking');
        exit;
    }

    public function callback(): void {
        $this->requireAdmin();

        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $stored = $this->consumeState($state);

        if (!is_array($stored) || isset($_GET['error'])) {
            $this->redirectError('Google authorization state is invalid or expired.');
        }

        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if ($code === '') {
            $this->redirectError('Google did not return an authorization code.');
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 20,
            'redirection' => 0,
            'body' => [
                'code' => $code,
                'client_id' => $this->config->clientId(),
                'client_secret' => $this->config->clientSecret(),
                'redirect_uri' => $this->config->redirectUri(),
                'grant_type' => 'authorization_code',
            ],
        ]);
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) {
            $this->redirectError('Google token exchange failed. Check the OAuth client and redirect URI.');
        }

        $tokens = json_decode((string)wp_remote_retrieve_body($response), true);
        if (!is_array($tokens) || empty($tokens['access_token'])) {
            $this->redirectError('Google returned an invalid token response.');
        }

        $credentials = [
            'access_token' => (string)$tokens['access_token'],
            'refresh_token' => (string)($tokens['refresh_token'] ?? ''),
            'expires_at' => time() + max(60, (int)($tokens['expires_in'] ?? 3600)),
            'scope' => (string)($tokens['scope'] ?? ''),
            'token_type' => (string)($tokens['token_type'] ?? 'Bearer'),
        ];

        $connectionId = $this->connections->create(
            [
                'provider' => 'google',
                'name' => (string)$stored['connection_name'],
                'remote_calendar_id' => (string)$stored['remote_calendar_id'],
                'blocks_availability' => !empty($stored['blocks_availability']),
                'receives_bookings' => !empty($stored['receives_bookings']),
                'config' => [
                    'scope' => (string)($tokens['scope'] ?? ''),
                ],
            ],
            $credentials
        );
        if (is_wp_error($connectionId)) {
            $this->redirectError($connectionId->get_error_message());
        }

        $this->redirectNotice('Google Calendar connected.');
    }

    public function issueState(array $intent): string {
        $state = bin2hex(random_bytes(24));
        $intent['user_id'] = get_current_user_id();
        set_transient(
            self::STATE_PREFIX . hash('sha256', $state),
            $intent,
            10 * MINUTE_IN_SECONDS
        );
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
            'redirect_uri' => $this->config->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->config->scopes($blocksAvailability, $receivesBookings)),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ], 'https://accounts.google.com/o/oauth2/v2/auth');
    }

    public function disconnect(): void {
        $this->requireAdmin();
        $connectionId = absint($_POST['connection_id'] ?? 0);
        check_admin_referer('wpcb_google_disconnect_' . $connectionId);

        $connection = $this->connections->find($connectionId);
        if (!$connection || $connection->provider !== 'google') {
            $this->redirectError('Google Calendar connection was not found.');
        }

        (new GoogleCalendarProvider($this->connections, $this->config))->revoke($connection);
        $this->connections->delete($connectionId);
        $this->redirectNotice('Google Calendar disconnected.');
    }

    private function requireAdmin(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage calendar connections.', 'wordpress-calendar-booking'));
        }
    }

    private function redirectNotice(string $message): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'wpcb_calendar_connections',
            'wpcb_google_notice' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    private function redirectError(string $message): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'wpcb_calendar_connections',
            'wpcb_google_error' => $message,
        ], admin_url('admin.php')));
        exit;
    }
}
