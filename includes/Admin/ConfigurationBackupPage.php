<?php
namespace Wpcb\Admin;

final class ConfigurationBackupPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcb_export_configuration', [$this, 'export']);
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            __('Konfiguration sichern', 'wordpress-calendar-booking'),
            __('Backup & Restore', 'wordpress-calendar-booking'),
            'manage_options',
            'wpcb_configuration_backup',
            [$this, 'render']
        );
    }

    public function export(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), '', ['response' => 403]);
        }
        check_admin_referer('wpcb_export_configuration');
        $json = (new ConfigurationBackupService())->exportJson();
        nocache_headers();
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="wordpress-calendar-booking-config-' . gmdate('Y-m-d-His') . '.json"');
        echo $json;
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), '', ['response' => 403]);
        }

        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpcb_configuration_restore'])) {
            check_admin_referer('wpcb_configuration_restore');
            $raw = '';
            if (!empty($_FILES['config_file']['tmp_name']) && is_uploaded_file($_FILES['config_file']['tmp_name'])) {
                $size = (int)($_FILES['config_file']['size'] ?? 0);
                if ($size > 0 && $size <= 2 * 1024 * 1024) {
                    $raw = (string)file_get_contents($_FILES['config_file']['tmp_name']);
                }
            }
            if ($raw === '') {
                $result = new \WP_Error('wpcb_backup_upload', __('Keine gültige JSON-Datei ausgewählt oder Datei größer als 2 MB.', 'wordpress-calendar-booking'));
            } else {
                $apply = isset($_POST['restore_mode']) && $_POST['restore_mode'] === 'apply';
                $result = (new ConfigurationBackupService())->importJson($raw, !$apply);
            }
        }

        echo '<div class="wrap wpcb-admin">';
        echo '<h1>' . esc_html__('Backup & Restore', 'wordpress-calendar-booking') . '</h1>';
        echo '<p>' . esc_html__('Exportiert ausschließlich Konfiguration. Buchungen, Kundendaten, Tokens, Zahlungen, Wartelisten, Logs und Zugangsdaten werden nicht exportiert.', 'wordpress-calendar-booking') . '</p>';

        $exportUrl = wp_nonce_url(
            admin_url('admin-post.php?action=wpcb_export_configuration'),
            'wpcb_export_configuration'
        );
        echo '<p><a class="button button-primary" href="' . esc_url($exportUrl) . '">' . esc_html__('Konfiguration als JSON exportieren', 'wordpress-calendar-booking') . '</a></p>';

        echo '<hr><h2>' . esc_html__('Konfiguration wiederherstellen', 'wordpress-calendar-booking') . '</h2>';
        echo '<p class="description">' . esc_html__('Kalender-Verbindungen werden ohne Credentials wiederhergestellt und bleiben deaktiviert, bis Zugangsdaten erneut eingegeben wurden.', 'wordpress-calendar-booking') . '</p>';

        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
        } elseif (is_array($result)) {
            $message = !empty($result['dry_run'])
                ? __('Dry-Run erfolgreich. Es wurden keine Änderungen geschrieben.', 'wordpress-calendar-booking')
                : __('Restore erfolgreich abgeschlossen.', 'wordpress-calendar-booking');
            echo '<div class="notice notice-success"><p><strong>' . esc_html($message) . '</strong></p>';
            echo '<p>' . esc_html(sprintf(
                __('Erstellen: %1$d · Aktualisieren: %2$d · Beziehungen: %3$d · deaktivierte Kalender-Verbindungen: %4$d', 'wordpress-calendar-booking'),
                (int)($result['creates'] ?? 0),
                (int)($result['updates'] ?? 0),
                (int)($result['relationships'] ?? 0),
                (int)($result['connections_disabled'] ?? 0)
            )) . '</p></div>';
        }

        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('wpcb_configuration_restore');
        echo '<input type="hidden" name="wpcb_configuration_restore" value="1">';
        echo '<p><input type="file" name="config_file" accept="application/json,.json" required></p>';
        echo '<p><label><input type="radio" name="restore_mode" value="dry_run" checked> ' . esc_html__('Nur prüfen (Dry-Run)', 'wordpress-calendar-booking') . '</label><br>';
        echo '<label><input type="radio" name="restore_mode" value="apply"> ' . esc_html__('Validieren und anwenden', 'wordpress-calendar-booking') . '</label></p>';
        echo '<p><button class="button button-primary">' . esc_html__('Backup prüfen / wiederherstellen', 'wordpress-calendar-booking') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }
}
