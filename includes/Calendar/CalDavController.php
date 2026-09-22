<?php
namespace Cemb\Calendar;

final class CalDavController {
    private CalendarConnectionRepository $connections;

    public function __construct(?CalendarConnectionRepository $connections = null) {
        $this->connections = $connections ?: new CalendarConnectionRepository();
    }

    public function boot(): void {
        add_filter('cemb_calendar_providers', [$this, 'registerProvider']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('wp_ajax_cemb_caldav_discover', [$this, 'discover']);
        add_action('admin_post_cemb_caldav_connect', [$this, 'connect']);
        add_action('admin_post_cemb_caldav_disconnect', [$this, 'disconnect']);
    }

    public function registerProvider(array $providers): array {
        $providers[] = new CalDavCalendarProvider($this->connections);
        return $providers;
    }

    public function menu(): void {
        add_submenu_page(
            'cemb_dashboard',
            'CalDAV / iCloud',
            'CalDAV / iCloud',
            'manage_options',
            'cemb_caldav_connections',
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
        ?>
        <div class="wrap">
            <h1>CalDAV / iCloud</h1>
            <?php if (isset($_GET['cemb_caldav_notice'])): ?>
                <div class="notice notice-success"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['cemb_caldav_notice']))); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['cemb_caldav_error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['cemb_caldav_error']))); ?></p></div>
            <?php endif; ?>

            <p>Connect any standards-based CalDAV account. The iCloud option is a preset using the same CalDAV adapter.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="cemb-caldav-connect-form">
                <?php wp_nonce_field('cemb_caldav_connect'); ?>
                <input type="hidden" name="action" value="cemb_caldav_connect">

                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="cemb_caldav_preset">Preset</label></th>
                        <td>
                            <select id="cemb_caldav_preset" name="preset">
                                <option value="generic">Generic CalDAV</option>
                                <option value="icloud">iCloud</option>
                            </select>
                            <p class="description">iCloud uses the same standards-based adapter with Apple's CalDAV endpoint prefilled.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="cemb_caldav_name">Connection name</label></th>
                        <td><input class="regular-text" id="cemb_caldav_name" name="connection_name" value="CalDAV" required></td>
                    </tr>
                    <tr>
                        <th><label for="cemb_caldav_endpoint">CalDAV endpoint</label></th>
                        <td><input class="regular-text code" type="url" id="cemb_caldav_endpoint" name="endpoint" placeholder="https://calendar.example.com/dav/" required></td>
                    </tr>
                    <tr>
                        <th><label for="cemb_caldav_username">Username / Apple Account</label></th>
                        <td><input class="regular-text" id="cemb_caldav_username" name="username" autocomplete="username" required></td>
                    </tr>
                    <tr>
                        <th><label for="cemb_caldav_password">Password</label></th>
                        <td>
                            <input class="regular-text" type="password" id="cemb_caldav_password" name="password" autocomplete="new-password" required>
                            <p class="description" id="cemb-caldav-password-help">Use a dedicated CalDAV password when your provider supports one.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Calendar</th>
                        <td>
                            <button type="button" class="button" id="cemb-caldav-discover">Discover calendars</button>
                            <span class="spinner" id="cemb-caldav-spinner" style="float:none"></span>
                            <select name="remote_calendar_id" id="cemb_caldav_calendar" style="display:none;min-width:320px;margin-left:8px"></select>
                            <p class="description" id="cemb-caldav-discovery-status">Discovery uses the credentials above without storing them.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Use</th>
                        <td>
                            <label><input type="checkbox" name="blocks_availability" value="1" checked> Block availability</label><br>
                            <label><input type="checkbox" name="receives_bookings" value="1"> Write confirmed bookings to this calendar</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Connect CalDAV calendar'); ?>
            </form>

            <hr>
            <h2>CalDAV connections</h2>
            <?php if (!$connections): ?>
                <p>No CalDAV connection yet.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead><tr><th>Name</th><th>Preset</th><th>Calendar</th><th>Use</th><th>Health</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($connections as $connection):
                        $config = $this->connections->config($connection->id);
                    ?>
                        <tr>
                            <td><?php echo esc_html($connection->name); ?></td>
                            <td><?php echo esc_html(($config['preset'] ?? '') === 'icloud' ? 'iCloud' : 'Generic'); ?></td>
                            <td><code><?php echo esc_html($connection->remoteCalendarId); ?></code></td>
                            <td><?php echo esc_html(trim(($connection->blocksAvailability ? 'Busy ' : '') . ($connection->receivesBookings ? 'Write-back' : ''))); ?></td>
                            <td><?php echo esc_html($connection->healthStatus); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('cemb_caldav_disconnect_' . $connection->id); ?>
                                    <input type="hidden" name="action" value="cemb_caldav_disconnect">
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
        <script>
        (function(){
            var preset = document.getElementById('cemb_caldav_preset');
            var endpoint = document.getElementById('cemb_caldav_endpoint');
            var name = document.getElementById('cemb_caldav_name');
            var help = document.getElementById('cemb-caldav-password-help');
            var discover = document.getElementById('cemb-caldav-discover');
            var spinner = document.getElementById('cemb-caldav-spinner');
            var select = document.getElementById('cemb_caldav_calendar');
            var status = document.getElementById('cemb-caldav-discovery-status');
            var nonce = <?php echo wp_json_encode(wp_create_nonce('cemb_caldav_discover')); ?>;

            function applyPreset(){
                if (preset.value === 'icloud') {
                    endpoint.value = 'https://caldav.icloud.com/';
                    if (!name.value || name.value === 'CalDAV') name.value = 'iCloud';
                    help.textContent = 'Use your Apple Account email and an app-specific password. Apple requires two-factor authentication to create app-specific passwords.';
                } else {
                    if (endpoint.value === 'https://caldav.icloud.com/') endpoint.value = '';
                    if (name.value === 'iCloud') name.value = 'CalDAV';
                    help.textContent = 'Use a dedicated CalDAV password when your provider supports one.';
                }
            }
            preset.addEventListener('change', applyPreset);
            applyPreset();

            discover.addEventListener('click', function(){
                var form = document.getElementById('cemb-caldav-connect-form');
                var data = new URLSearchParams();
                data.set('action', 'cemb_caldav_discover');
                data.set('nonce', nonce);
                data.set('endpoint', endpoint.value);
                data.set('username', document.getElementById('cemb_caldav_username').value);
                data.set('password', document.getElementById('cemb_caldav_password').value);
                spinner.classList.add('is-active');
                discover.disabled = true;
                status.textContent = 'Discovering calendars…';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: data.toString()
                }).then(function(response){ return response.json(); }).then(function(json){
                    select.innerHTML = '';
                    if (!json || !json.success || !json.data || !json.data.calendars || !json.data.calendars.length) {
                        throw new Error((json && json.data && json.data.message) || 'No calendars found.');
                    }
                    json.data.calendars.forEach(function(calendar){
                        var option = document.createElement('option');
                        option.value = calendar.url;
                        option.textContent = calendar.name + ' — ' + calendar.url;
                        select.appendChild(option);
                    });
                    select.style.display = '';
                    status.textContent = json.data.calendars.length + ' calendar(s) found. Select the destination.';
                }).catch(function(error){
                    select.style.display = 'none';
                    status.textContent = error.message || 'Calendar discovery failed.';
                }).finally(function(){
                    spinner.classList.remove('is-active');
                    discover.disabled = false;
                });
            });
        })();
        </script>
        <?php
    }

    public function discover(): void {
        $this->requireAdmin();
        check_ajax_referer('cemb_caldav_discover', 'nonce');

        $client = new CalDavClient(
            sanitize_url(wp_unslash($_POST['endpoint'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['username'] ?? '')),
            (string)wp_unslash($_POST['password'] ?? '')
        );
        $result = $client->discover();
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        $safe = [];
        foreach ($result['calendars'] as $calendar) {
            $safe[] = [
                'name' => sanitize_text_field((string)($calendar['name'] ?? 'Calendar')),
                'url' => esc_url_raw((string)($calendar['url'] ?? '')),
            ];
        }
        wp_send_json_success(['calendars' => $safe]);
    }

    public function connect(): void {
        $this->requireAdmin();
        check_admin_referer('cemb_caldav_connect');

        $preset = sanitize_key(wp_unslash($_POST['preset'] ?? 'generic'));
        if (!in_array($preset, ['generic', 'icloud'], true)) {
            $preset = 'generic';
        }

        $endpoint = esc_url_raw(wp_unslash($_POST['endpoint'] ?? ''));
        if ($preset === 'icloud') {
            $endpoint = 'https://caldav.icloud.com/';
        }

        $calendarUrl = esc_url_raw(wp_unslash($_POST['remote_calendar_id'] ?? ''));
        $username = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
        $password = (string)wp_unslash($_POST['password'] ?? '');

        if ($endpoint === '' || $calendarUrl === '' || $username === '' || $password === '') {
            $this->redirectError('Endpoint, credentials and a discovered calendar are required.');
        }

        $client = new CalDavClient($endpoint, $username, $password);
        $discovery = $client->discover();
        if (is_wp_error($discovery)) {
            $this->redirectError($discovery->get_error_message());
        }

        $allowedCalendars = array_column($discovery['calendars'], 'url');
        if (!in_array($calendarUrl, $allowedCalendars, true)) {
            $this->redirectError('Select a calendar returned by the current CalDAV account discovery.');
        }

        $connectionId = $this->connections->create(
            [
                'provider' => 'caldav',
                'name' => sanitize_text_field(wp_unslash($_POST['connection_name'] ?? ($preset === 'icloud' ? 'iCloud' : 'CalDAV'))),
                'remote_calendar_id' => $calendarUrl,
                'blocks_availability' => !empty($_POST['blocks_availability']),
                'receives_bookings' => !empty($_POST['receives_bookings']),
                'config' => [
                    'endpoint' => $endpoint,
                    'preset' => $preset,
                ],
            ],
            [
                'username' => $username,
                'password' => $password,
            ]
        );

        if (is_wp_error($connectionId)) {
            $this->redirectError($connectionId->get_error_message());
        }

        $this->redirectNotice(($preset === 'icloud' ? 'iCloud' : 'CalDAV') . ' calendar connected.');
    }

    public function disconnect(): void {
        $this->requireAdmin();
        $connectionId = absint($_POST['connection_id'] ?? 0);
        check_admin_referer('cemb_caldav_disconnect_' . $connectionId);

        $connection = $this->connections->find($connectionId);
        if (!$connection || $connection->provider !== 'caldav') {
            $this->redirectError('CalDAV connection was not found.');
        }

        $this->connections->delete($connectionId);
        $this->redirectNotice('CalDAV connection removed.');
    }

    private function requireAdmin(): void {
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to manage calendar connections.');
        }
    }

    private function redirectNotice(string $message): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'cemb_caldav_connections',
            'cemb_caldav_notice' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    private function redirectError(string $message): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'cemb_caldav_connections',
            'cemb_caldav_error' => $message,
        ], admin_url('admin.php')));
        exit;
    }
}
