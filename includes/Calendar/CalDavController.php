<?php
namespace Wpcb\Calendar;

final class CalDavController {
    private const DISCOVERY_PREFIX = 'wpcb_caldav_discovery_';

    private CalendarConnectionRepository $connections;

    public function __construct(?CalendarConnectionRepository $connections = null) {
        $this->connections = $connections ?: new CalendarConnectionRepository();
    }

    public function boot(): void {
        add_filter('wpcb_calendar_providers', [$this, 'registerProvider']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcb_caldav_discover', [$this, 'discover']);
        add_action('admin_post_wpcb_caldav_save', [$this, 'save']);
        add_action('admin_post_wpcb_caldav_delete', [$this, 'delete']);
    }

    public function registerProvider(array $providers): array {
        $providers[] = new CalDavProvider($this->connections);
        return $providers;
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            'CalDAV / iCloud',
            'CalDAV / iCloud',
            'manage_options',
            'wpcb_caldav_connections',
            [$this, 'page']
        );
    }

    public function page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $connections = array_values(array_filter(
            $this->connections->all(false),
            static fn(CalendarConnection $connection): bool => $connection->provider === 'caldav'
        ));
        $discovered = get_transient(self::DISCOVERY_PREFIX . get_current_user_id());
        delete_transient(self::DISCOVERY_PREFIX . get_current_user_id());
        ?>
        <div class="wrap">
            <h1>CalDAV / iCloud</h1>
            <?php if (isset($_GET['wpcb_caldav_notice'])): ?>
                <div class="notice notice-success"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_caldav_notice']))); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['wpcb_caldav_error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_caldav_error']))); ?></p></div>
            <?php endif; ?>

            <h2>Discover calendars</h2>
            <p>Use a standards-based CalDAV endpoint. For iCloud use <code>https://caldav.icloud.com/</code>, your Apple Account email and an app-specific password when direct Apple authorization is not available.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wpcb_caldav_discover'); ?>
                <input type="hidden" name="action" value="wpcb_caldav_discover">
                <table class="form-table" role="presentation">
                    <tr><th><label for="wpcb_caldav_endpoint">Endpoint</label></th>
                    <td><input class="large-text" id="wpcb_caldav_endpoint" name="endpoint" value="https://caldav.icloud.com/" required></td></tr>
                    <tr><th><label for="wpcb_caldav_username">Username</label></th>
                    <td><input class="regular-text" id="wpcb_caldav_username" name="username" autocomplete="username" required></td></tr>
                    <tr><th><label for="wpcb_caldav_password">Password</label></th>
                    <td><input class="regular-text" type="password" id="wpcb_caldav_password" name="password" autocomplete="new-password" required></td></tr>
                </table>
                <?php submit_button('Discover calendars', 'secondary'); ?>
            </form>

            <?php if (is_array($discovered) && !empty($discovered['calendars'])): ?>
                <h3>Discovered calendars</h3>
                <table class="widefat striped"><thead><tr><th>Name</th><th>Calendar URL</th></tr></thead><tbody>
                <?php foreach ($discovered['calendars'] as $calendar): ?>
                    <tr><td><?php echo esc_html((string)$calendar['name']); ?></td><td><code><?php echo esc_html((string)$calendar['url']); ?></code></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>

            <hr>
            <h2>Add CalDAV connection</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wpcb_caldav_save'); ?>
                <input type="hidden" name="action" value="wpcb_caldav_save">
                <table class="form-table" role="presentation">
                    <tr><th><label for="wpcb_caldav_name">Connection name</label></th>
                    <td><input class="regular-text" id="wpcb_caldav_name" name="connection_name" value="iCloud Calendar" required></td></tr>
                    <tr><th><label for="wpcb_caldav_preset">Preset</label></th>
                    <td><select id="wpcb_caldav_preset" name="preset"><option value="generic">Generic CalDAV</option><option value="icloud">iCloud</option></select></td></tr>
                    <tr><th><label for="wpcb_caldav_save_endpoint">Endpoint</label></th>
                    <td><input class="large-text" id="wpcb_caldav_save_endpoint" name="endpoint" value="https://caldav.icloud.com/" required></td></tr>
                    <tr><th><label for="wpcb_caldav_calendar_url">Calendar URL</label></th>
                    <td><input class="large-text" id="wpcb_caldav_calendar_url" name="calendar_url" required><p class="description">Paste a discovered calendar collection URL.</p></td></tr>
                    <tr><th><label for="wpcb_caldav_save_username">Username</label></th>
                    <td><input class="regular-text" id="wpcb_caldav_save_username" name="username" autocomplete="username" required></td></tr>
                    <tr><th><label for="wpcb_caldav_save_password">Password</label></th>
                    <td><input class="regular-text" type="password" id="wpcb_caldav_save_password" name="password" autocomplete="new-password" required></td></tr>
                    <tr><th>Capabilities</th><td>
                        <label><input type="checkbox" name="blocks_availability" value="1" checked> Block availability</label><br>
                        <label><input type="checkbox" name="receives_bookings" value="1"> Write confirmed bookings</label>
                    </td></tr>
                </table>
                <?php submit_button('Save CalDAV connection'); ?>
            </form>

            <hr>
            <h2>CalDAV connections</h2>
            <?php if (!$connections): ?>
                <p>No CalDAV connection yet.</p>
            <?php else: ?>
                <table class="widefat striped"><thead><tr><th>Name</th><th>Calendar</th><th>Health</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($connections as $connection): ?>
                    <?php $config = $this->connections->config($connection->id); ?>
                    <tr>
                        <td><?php echo esc_html($connection->name); ?><?php if (($config['preset'] ?? '') === 'icloud') echo ' <span class="description">(iCloud)</span>'; ?></td>
                        <td><code><?php echo esc_html((string)($config['calendar_url'] ?? $connection->remoteCalendarId)); ?></code></td>
                        <td><?php echo esc_html($connection->healthStatus); ?></td>
                        <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('wpcb_caldav_delete_' . $connection->id); ?>
                            <input type="hidden" name="action" value="wpcb_caldav_delete">
                            <input type="hidden" name="connection_id" value="<?php echo (int)$connection->id; ?>">
                            <?php submit_button('Disconnect', 'secondary', 'submit', false); ?>
                        </form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function discover(): void {
        $this->requireAdmin();
        check_admin_referer('wpcb_caldav_discover');

        $endpoint = OutboundUrlPolicy::normalizeCalendarUrl((string)wp_unslash($_POST['endpoint'] ?? ''));
        $username = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
        $password = (string)wp_unslash($_POST['password'] ?? '');

        $client = new CalDavClient($endpoint, $username, $password);
        $calendars = $client->discoverCalendars();
        if (is_wp_error($calendars)) {
            $this->redirectError($calendars->get_error_message());
        }

        set_transient(
            self::DISCOVERY_PREFIX . get_current_user_id(),
            ['calendars' => $calendars],
            5 * MINUTE_IN_SECONDS
        );
        $this->redirectNotice(count($calendars) . ' calendar(s) discovered.');
    }

    public function save(): void {
        $this->requireAdmin();
        check_admin_referer('wpcb_caldav_save');

        $preset = sanitize_key(wp_unslash($_POST['preset'] ?? 'generic'));
        if (!in_array($preset, ['generic', 'icloud'], true)) {
            $preset = 'generic';
        }

        $endpoint = OutboundUrlPolicy::normalizeCalendarUrl((string)wp_unslash($_POST['endpoint'] ?? ''));
        if ($preset === 'icloud') {
            $endpoint = 'https://caldav.icloud.com/';
        }
        $calendarUrl = OutboundUrlPolicy::normalizeCalendarUrl((string)wp_unslash($_POST['calendar_url'] ?? ''));
        $username = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
        $password = (string)wp_unslash($_POST['password'] ?? '');

        if ($endpoint === '' || $calendarUrl === '' || $username === '' || $password === '') {
            $this->redirectError('Endpoint, calendar URL, username and password are required.');
        }

        $id = $this->connections->create(
            [
                'provider' => 'caldav',
                'name' => sanitize_text_field(wp_unslash($_POST['connection_name'] ?? 'CalDAV Calendar')),
                'remote_calendar_id' => $calendarUrl,
                'blocks_availability' => !empty($_POST['blocks_availability']),
                'receives_bookings' => !empty($_POST['receives_bookings']),
                'config' => [
                    'endpoint' => $endpoint,
                    'calendar_url' => $calendarUrl,
                    'preset' => $preset,
                ],
            ],
            [
                'username' => $username,
                'password' => $password,
            ]
        );
        if (is_wp_error($id)) {
            $this->redirectError($id->get_error_message());
        }
        $this->redirectNotice('CalDAV connection saved.');
    }

    public function delete(): void {
        $this->requireAdmin();
        $connectionId = absint($_POST['connection_id'] ?? 0);
        check_admin_referer('wpcb_caldav_delete_' . $connectionId);
        $connection = $this->connections->find($connectionId);
        if (!$connection || $connection->provider !== 'caldav') {
            $this->redirectError('CalDAV connection was not found.');
        }
        $this->connections->delete($connectionId);
        $this->redirectNotice('CalDAV connection disconnected.');
    }

    private function requireAdmin(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage calendar connections.', 'wordpress-calendar-booking'));
        }
    }

    private function redirectNotice(string $message): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'wpcb_caldav_connections',
            'wpcb_caldav_notice' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    private function redirectError(string $message): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'wpcb_caldav_connections',
            'wpcb_caldav_error' => $message,
        ], admin_url('admin.php')));
        exit;
    }
}
