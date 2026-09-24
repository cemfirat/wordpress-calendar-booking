<?php
namespace Wpcb\Admin;

final class ConfigurationBackupPage {
    private const RESULT_PREFIX = 'wpcb_config_backup_result_';

    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcb_configuration_export', [$this, 'export']);
        add_action('admin_post_wpcb_configuration_import', [$this, 'import']);
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            __('Sicherung & Wiederherstellung', 'wordpress-calendar-booking'),
            __('Sicherung & Wiederherstellung', 'wordpress-calendar-booking'),
            'manage_options',
            'wpcb_configuration_backup',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), 403);
        }

        $result = get_transient(self::RESULT_PREFIX . get_current_user_id());
        delete_transient(self::RESULT_PREFIX . get_current_user_id());

        echo '<div class="wrap wpcb-admin">';
        echo '<h1>' . esc_html__('Sicherung & Wiederherstellung', 'wordpress-calendar-booking') . '</h1>';
        echo '<p>' . esc_html__('Exportiert ausschließlich Plugin-Konfiguration. Buchungen, Kundendaten, Tokens, Zahlungen, Protokolle und Zugangsdaten werden nicht exportiert.', 'wordpress-calendar-booking') . '</p>';

        if (is_array($result)) {
            $class = !empty($result['ok']) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html((string)($result['message'] ?? '')) . '</p>';
            if (!empty($result['plan']) && is_array($result['plan'])) {
                $plan = $result['plan'];
                $creates = array_sum(array_map('intval', (array)($plan['create'] ?? [])));
                $updates = array_sum(array_map('intval', (array)($plan['update'] ?? [])));
                $conflictCount = (int)($plan['conflict_count'] ?? 0);
                echo '<p>' . esc_html(sprintf(
                    /* translators: 1: creates, 2: updates, 3: conflicts, 4: relationship count */
                    __('Vorschau: %1$d neue Datensätze, %2$d Aktualisierungen, %3$d Konflikte, %4$d Zuordnungen.', 'wordpress-calendar-booking'),
                    $creates,
                    $updates,
                    $conflictCount,
                    (int)($plan['relationships'] ?? 0)
                )) . '</p>';
                if ($conflictCount > 0) {
                    echo '<p><strong>' . esc_html__('Diese bestehenden Konfigurationswerte würden beim Import geändert:', 'wordpress-calendar-booking') . '</strong></p><ul>';
                    foreach (array_slice((array)($plan['conflicts'] ?? []), 0, 20) as $conflict) {
                        $section = sanitize_key((string)($conflict['section'] ?? ''));
                        $identity = sanitize_text_field((string)($conflict['identity'] ?? ''));
                        $fields = array_map('sanitize_key', (array)($conflict['fields'] ?? []));
                        echo '<li><code>' . esc_html($section . ':' . $identity) . '</code> — ' . esc_html(implode(', ', $fields)) . '</li>';
                    }
                    echo '</ul>';
                    if ($conflictCount > 20) {
                        echo '<p>' . esc_html(sprintf(
                            /* translators: %d: number of additional conflicts */
                            __('… und %d weitere Konflikte.', 'wordpress-calendar-booking'),
                            $conflictCount - 20
                        )) . '</p>';
                    }
                }
                foreach ((array)($plan['warnings'] ?? []) as $warning) {
                    echo '<p><strong>' . esc_html((string)$warning) . '</strong></p>';
                }
            }
            echo '</div>';
        }

        echo '<h2>' . esc_html__('Konfiguration exportieren', 'wordpress-calendar-booking') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wpcb_configuration_export');
        echo '<input type="hidden" name="action" value="wpcb_configuration_export">';
        echo '<button class="button button-primary">' . esc_html__('JSON-Sicherung herunterladen', 'wordpress-calendar-booking') . '</button>';
        echo '</form>';

        echo '<hr><h2>' . esc_html__('Konfiguration importieren', 'wordpress-calendar-booking') . '</h2>';
        echo '<p>' . esc_html__('Führe zuerst eine Vorschau aus. Der Import arbeitet zusammenführend: bestehende Buchungen und Historie werden niemals gelöscht oder umgeschrieben.', 'wordpress-calendar-booking') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wpcb_configuration_import');
        echo '<input type="hidden" name="action" value="wpcb_configuration_import">';
        echo '<textarea name="snapshot_json" rows="18" class="large-text code" required></textarea><p>';
        echo '<button class="button" name="import_mode" value="dry_run">' . esc_html__('Vorschau prüfen', 'wordpress-calendar-booking') . '</button> ';
        echo '<button class="button button-primary" name="import_mode" value="apply">' . esc_html__('Validierte Konfiguration importieren', 'wordpress-calendar-booking') . '</button>';
        echo '</p></form></div>';
    }

    public function export(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), 403);
        }
        check_admin_referer('wpcb_configuration_export');

        $snapshot = (new ConfigurationBackupService())->exportSnapshot();
        $json = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            wp_die(esc_html__('Die Konfiguration konnte nicht serialisiert werden.', 'wordpress-calendar-booking'));
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="wordpress-calendar-booking-configuration-' . gmdate('Y-m-d-His') . '.json"');
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
        exit;
    }

    public function import(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), 403);
        }
        check_admin_referer('wpcb_configuration_import');

        $json = (string)wp_unslash($_POST['snapshot_json'] ?? '');
        $mode = sanitize_key((string)wp_unslash($_POST['import_mode'] ?? 'dry_run'));
        $service = new ConfigurationBackupService();
        $snapshot = $service->decode($json);

        if (is_wp_error($snapshot)) {
            $this->finish(false, $snapshot->get_error_message());
        }

        $result = $service->import($snapshot, $mode !== 'apply');
        if (is_wp_error($result)) {
            $this->finish(false, $result->get_error_message());
        }

        $this->finish(
            true,
            $mode === 'apply'
                ? __('Die Konfiguration wurde erfolgreich importiert.', 'wordpress-calendar-booking')
                : __('Die Sicherung ist gültig. Es wurden keine Änderungen geschrieben.', 'wordpress-calendar-booking'),
            $result
        );
    }

    private function finish(bool $ok, string $message, array $plan = []): void {
        set_transient(self::RESULT_PREFIX . get_current_user_id(), [
            'ok' => $ok,
            'message' => sanitize_text_field($message),
            'plan' => $plan,
        ], MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=wpcb_configuration_backup'));
        exit;
    }
}
