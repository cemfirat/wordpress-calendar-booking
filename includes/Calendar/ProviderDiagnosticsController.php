<?php
namespace Wpcb\Calendar;

final class ProviderDiagnosticsController {
    private ProviderDiagnosticsService $diagnostics;

    public function __construct(?ProviderDiagnosticsService $diagnostics = null) {
        $this->diagnostics = $diagnostics ?: new ProviderDiagnosticsService();
    }

    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcb_provider_test_read', [$this, 'testRead']);
        add_action('admin_post_wpcb_provider_test_write', [$this, 'testWrite']);
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            'Calendar Diagnostics',
            'Calendar Diagnostics',
            'manage_options',
            'wpcb_provider_diagnostics',
            [$this, 'page']
        );
    }

    public function page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $rows = $this->diagnostics->all();
        ?>
        <div class="wrap">
            <h1>Calendar Diagnostics</h1>
            <p>Connection health only. OAuth tokens, passwords and encrypted credential payloads are never displayed here.</p>

            <?php if (isset($_GET['wpcb_diag_notice'])): ?>
                <div class="notice notice-success"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_diag_notice']))); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['wpcb_diag_error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_diag_error']))); ?></p></div>
            <?php endif; ?>

            <?php if (!$rows): ?>
                <p>No calendar connections configured.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Connection</th>
                            <th>Provider / calendar</th>
                            <th>Capabilities</th>
                            <th>Credentials</th>
                            <th>Health</th>
                            <th>Last read</th>
                            <th>Last write</th>
                            <th>Last error</th>
                            <th>Tests</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?php echo esc_html((string)$row['name']); ?></strong><br><span class="description"><?php echo !empty($row['active']) ? 'Active' : 'Inactive'; ?></span></td>
                            <td><?php echo esc_html((string)$row['provider_label']); ?><br><code><?php echo esc_html((string)$row['calendar']); ?></code></td>
                            <td><?php echo esc_html(implode(', ', (array)$row['capabilities'])); ?></td>
                            <td><?php echo esc_html($this->credentialLabel((string)$row['credential_state'])); ?></td>
                            <td><?php echo esc_html((string)$row['health_status']); ?></td>
                            <td><?php echo esc_html((string)($row['last_read_at'] ?: '—')); ?></td>
                            <td><?php echo esc_html((string)($row['last_write_at'] ?: '—')); ?></td>
                            <td>
                                <?php if (!empty($row['last_error'])): ?>
                                    <?php echo esc_html((string)$row['last_error']); ?><br>
                                    <span class="description"><?php echo esc_html((string)($row['last_error_at'] ?: '')); ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (in_array(ProviderCapabilities::BUSY_READ, (array)$row['capabilities'], true)): ?>
                                    <?php $this->testForm('wpcb_provider_test_read', (int)$row['id'], 'Test read'); ?>
                                <?php endif; ?>
                                <?php if (!empty($row['receives_bookings'])
                                    && in_array(ProviderCapabilities::EVENT_CREATE, (array)$row['capabilities'], true)
                                    && in_array(ProviderCapabilities::EVENT_CANCEL, (array)$row['capabilities'], true)): ?>
                                    <?php $this->testForm('wpcb_provider_test_write', (int)$row['id'], 'Test write'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">Write tests create one neutral five-minute test event about 180 days in the future and delete it immediately afterwards.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function testRead(): void {
        $this->requireAdmin();
        $connectionId = absint($_POST['connection_id'] ?? 0);
        check_admin_referer('wpcb_provider_test_read_' . $connectionId);
        $result = $this->diagnostics->testRead($connectionId);
        $suffix = !empty($result['ok']) && isset($result['count'])
            ? ' Busy intervals returned: ' . (int)$result['count'] . '.'
            : '';
        $this->redirect($result, $suffix);
    }

    public function testWrite(): void {
        $this->requireAdmin();
        $connectionId = absint($_POST['connection_id'] ?? 0);
        check_admin_referer('wpcb_provider_test_write_' . $connectionId);
        $result = $this->diagnostics->testWrite($connectionId);
        $this->redirect($result);
    }

    private function testForm(string $action, int $connectionId, string $label): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0 6px 4px 0">
            <?php wp_nonce_field($action . '_' . $connectionId); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="connection_id" value="<?php echo (int)$connectionId; ?>">
            <?php submit_button($label, 'secondary small', 'submit', false); ?>
        </form>
        <?php
    }

    private function credentialLabel(string $state): string {
        $labels = [
            'ok' => 'OK',
            'present' => 'Present',
            'missing' => 'Missing',
            'invalid' => 'Invalid encrypted data',
            'reauth_required' => 'Reconnect required',
        ];
        return $labels[$state] ?? 'Unknown';
    }

    private function requireAdmin(): void {
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to test calendar connections.');
        }
    }

    private function redirect(array $result, string $suffix = ''): void {
        $ok = !empty($result['ok']);
        $key = $ok ? 'wpcb_diag_notice' : 'wpcb_diag_error';
        $message = sanitize_text_field((string)($result['message'] ?? ($ok ? 'Test succeeded.' : 'Test failed.'))) . $suffix;
        wp_safe_redirect(add_query_arg([
            'page' => 'wpcb_provider_diagnostics',
            $key => $message,
        ], admin_url('admin.php')));
        exit;
    }
}
