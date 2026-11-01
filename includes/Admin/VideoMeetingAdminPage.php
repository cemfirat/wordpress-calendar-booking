<?php
namespace Wpcb\Admin;

use Wpcb\Booking\BookingTypeRepository;
use Wpcb\VideoMeetings\VideoMeetingConnectionRepository;
use Wpcb\VideoMeetings\VideoMeetingProviderRegistry;

final class VideoMeetingAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handlePost']);
    }

    public function menu(): void {
        $title = __('Video-Meetings', 'wordpress-calendar-booking');
        add_submenu_page('wpcb_dashboard', $title, $title, 'manage_options', 'wpcb_video_meetings', [$this, 'render']);
    }

    public function handlePost(): void {
        if (!current_user_can('manage_options') || empty($_POST['wpcb_video_action'])) return;
        check_admin_referer('wpcb_video_action');
        $action = sanitize_key(wp_unslash($_POST['wpcb_video_action']));
        $repo = new VideoMeetingConnectionRepository();
        $error = '';

        if ($action === 'save_connection') {
            $result = $repo->save([
                'provider' => sanitize_key(wp_unslash($_POST['provider'] ?? '')),
                'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                'access_token' => (string)wp_unslash($_POST['access_token'] ?? ''),
                'refresh_token' => (string)wp_unslash($_POST['refresh_token'] ?? ''),
                'client_id' => (string)wp_unslash($_POST['client_id'] ?? ''),
                'client_secret' => (string)wp_unslash($_POST['client_secret'] ?? ''),
                'account_id' => (string)wp_unslash($_POST['account_id'] ?? ''),
                'tenant_id' => (string)wp_unslash($_POST['tenant_id'] ?? ''),
                'config' => [
                    'user_id' => sanitize_text_field(wp_unslash($_POST['user_id'] ?? '')),
                ],
                'is_active' => !empty($_POST['is_active']),
            ], absint($_POST['connection_id'] ?? 0));
            if (is_wp_error($result)) $error = $result->get_error_message();
        } elseif ($action === 'delete_connection') {
            $result = $repo->delete(absint($_POST['connection_id'] ?? 0));
            if (is_wp_error($result)) $error = $result->get_error_message();
        } elseif ($action === 'retry_meeting') {
            $queued = (new \Wpcb\VideoMeetings\VideoMeetingService())->retry(absint($_POST['booking_id'] ?? 0), absint($_POST['connection_id'] ?? 0));
            if (!$queued) $error = __('Meeting konnte nicht erneut eingeplant werden.', 'wordpress-calendar-booking');
        } elseif ($action === 'assign_type') {
            $selections = [];
            foreach ((array)($_POST['connections'] ?? []) as $connectionId => $flags) {
                if (!is_array($flags) || empty($flags['enabled'])) continue;
                $selections[] = [
                    'connection_id' => absint($connectionId),
                    'is_required' => !empty($flags['required']),
                ];
            }
            $repo->setForBookingType(absint($_POST['booking_type_id'] ?? 0), $selections);
        }

        $args = ['page'=>'wpcb_video_meetings','updated'=>1];
        if ($error !== '') $args['wpcb_error'] = $error;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), '', ['response' => 403]);
        }

        $repo = new VideoMeetingConnectionRepository();
        $connections = $repo->all(false);
        $types = (new BookingTypeRepository())->all(false);

        echo '<div class="wrap wpcb-admin"><h1>' . esc_html__('Video-Meetings', 'wordpress-calendar-booking') . '</h1>';
        echo '<p class="description">' . esc_html__('Zugangsdaten werden authentifiziert verschlüsselt gespeichert und niemals wieder angezeigt. Leere Felder behalten das bestehende Secret. Mit Client-Zugangsdaten (siehe Hinweise je Provider) erneuert das Plugin Access-Tokens automatisch; ein einzeln eingefügter Access-Token läuft ab und ist nur für Tests gedacht.', 'wordpress-calendar-booking') . '</p>';
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Gespeichert.', 'wordpress-calendar-booking') . '</p></div>';
        }
        if (!empty($_GET['wpcb_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_error']))) . '</p></div>';
        }

        echo '<h2>' . esc_html__('Verbindungen', 'wordpress-calendar-booking') . '</h2>';
        foreach ($connections as $connection) {
            $this->connectionForm($connection, $repo->credentialStatus((int)$connection->id));
        }
        echo '<h3>' . esc_html__('Neue Verbindung', 'wordpress-calendar-booking') . '</h3>';
        $this->connectionForm(null, 'empty');

        echo '<hr><h2>' . esc_html__('Terminarten → Video-Meeting', 'wordpress-calendar-booking') . '</h2>';
        foreach ($types as $type) {
            $selected = [];
            $requiredMap = [];
            foreach ($repo->forBookingType((int)$type->id) as $mapped) {
                $selected[(int)$mapped->id] = true;
                $requiredMap[(int)$mapped->id] = !empty($mapped->is_required);
            }
            echo '<form method="post" style="margin:12px 0;padding:12px;background:#fff;border:1px solid #ccd0d4">';
            wp_nonce_field('wpcb_video_action');
            echo '<input type="hidden" name="wpcb_video_action" value="assign_type">';
            echo '<input type="hidden" name="booking_type_id" value="' . (int)$type->id . '">';
            echo '<strong>' . esc_html((string)$type->name) . '</strong>';
            if (!$connections) {
                echo '<p>' . esc_html__('Noch keine Video-Meeting-Verbindung vorhanden.', 'wordpress-calendar-booking') . '</p>';
            }
            foreach ($connections as $connection) {
                $id=(int)$connection->id;
                echo '<p><label><input type="checkbox" name="connections['.absint($id).'][enabled]" value="1" ' . checked(isset($selected[$id]),true,false) . '> ';
                echo esc_html((string)$connection->name . ' (' . (string)$connection->provider . ')') . '</label> ';
                echo '<label><input type="checkbox" name="connections['.absint($id).'][required]" value="1" ' . checked(!empty($requiredMap[$id]),true,false) . '> ' . esc_html__('Pflicht (Fehler hervorheben)', 'wordpress-calendar-booking') . '</label></p>';
            }
            echo '<p class="description">' . esc_html__('Für bestätigte Buchungen wird das Meeting asynchron über die Retry-Queue erzeugt. Ein Provider-Ausfall setzt die Buchung nicht zurück, auch nicht bei „Pflicht“; Pflicht-Meetings mit Fehler werden unten hervorgehoben und lassen sich manuell erneut einplanen.', 'wordpress-calendar-booking') . '</p>';
            echo '<p><button class="button button-primary">' . esc_html__('Zuordnung speichern', 'wordpress-calendar-booking') . '</button></p></form>';
        }

        echo '<hr><h2>' . esc_html__('Meeting-Status', 'wordpress-calendar-booking') . '</h2>';
        global $wpdb;
        $rows=$wpdb->get_results("SELECT vm.*, COALESCE(m.is_required, 0) AS is_required
            FROM {$wpdb->prefix}wpcb_video_meetings vm
            LEFT JOIN {$wpdb->prefix}wpcb_bookings b ON b.id = vm.booking_id
            LEFT JOIN {$wpdb->prefix}wpcb_booking_type_video_connections m ON m.booking_type_id = b.booking_type_id AND m.connection_id = vm.connection_id
            ORDER BY vm.id DESC LIMIT 100"); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prefixed table names only; no user input.
        $failedRequired = array_filter($rows, static fn($row) => !empty($row->is_required) && (string)$row->status === 'error');
        if ($failedRequired) {
            echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(
                /* translators: %d: number of required video meetings with an error */
                _n('%d Pflicht-Meeting ist fehlgeschlagen. Die Buchung bleibt bestätigt; bitte Verbindung prüfen und erneut einplanen.', '%d Pflicht-Meetings sind fehlgeschlagen. Die Buchungen bleiben bestätigt; bitte Verbindung prüfen und erneut einplanen.', count($failedRequired), 'wordpress-calendar-booking'),
                count($failedRequired)
            )) . '</p></div>';
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Buchung', 'wordpress-calendar-booking'),
            __('Provider', 'wordpress-calendar-booking'),
            __('Status', 'wordpress-calendar-booking'),
            __('Remote-ID', 'wordpress-calendar-booking'),
            __('Join-Link', 'wordpress-calendar-booking'),
            __('Pflicht', 'wordpress-calendar-booking'),
            __('Fehler', 'wordpress-calendar-booking'),
            __('Aktion', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach($rows as $row){
            echo '<tr><td>#'.(int)$row->booking_id.'</td><td>'.esc_html((string)$row->provider).'</td><td>'.esc_html((string)$row->status).'</td><td>'.esc_html((string)$row->remote_id).'</td><td>';
            echo $row->join_url ? '<a href="'.esc_url((string)$row->join_url).'" target="_blank" rel="noopener noreferrer">' . esc_html__('öffnen', 'wordpress-calendar-booking') . '</a>' : '–';
            echo '</td><td>' . ($row->is_required ? esc_html__('ja', 'wordpress-calendar-booking') : '–') . '</td>';
            echo '<td>' . esc_html((string)$row->last_error) . esc_html($this->hint((string)$row->last_error)) . '</td><td>';
            if ((string)$row->status !== 'active' && (string)$row->status !== 'deleted') {
                echo '<form method="post">';
                wp_nonce_field('wpcb_video_action');
                echo '<input type="hidden" name="wpcb_video_action" value="retry_meeting"><input type="hidden" name="booking_id" value="' . (int)$row->booking_id . '"><input type="hidden" name="connection_id" value="' . (int)$row->connection_id . '">';
                echo '<button class="button">' . esc_html__('Erneut versuchen', 'wordpress-calendar-booking') . '</button></form>';
            }
            echo '</td></tr>';
        }
        if(!$rows) {
            echo '<tr><td colspan="8">' . esc_html__('Noch keine Meetings.', 'wordpress-calendar-booking') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function hint(string $error): string {
        if (strpos($error, 'HTTP 403') !== false) {
            return ' — ' . __('Berechtigung fehlt: Scope, Admin-Zustimmung oder Kontorichtlinie des Providers prüfen.', 'wordpress-calendar-booking');
        }
        if (strpos($error, 'HTTP 401') !== false || stripos($error, 'reconnect') !== false) {
            return ' — ' . __('Verbindung neu herstellen (Zugangsdaten widerrufen oder abgelaufen).', 'wordpress-calendar-booking');
        }
        return '';
    }

    private function connectionForm(?object $connection, string $secretStatus): void {
        $id=(int)($connection->id ?? 0);
        $config=$connection ? json_decode((string)($connection->config_json ?? '{}'),true) : [];
        if(!is_array($config)) $config=[];
        echo '<form method="post" style="margin:10px 0;padding:12px;background:#fff;border:1px solid #ccd0d4">';
        wp_nonce_field('wpcb_video_action');
        echo '<input type="hidden" name="wpcb_video_action" value="save_connection"><input type="hidden" name="connection_id" value="'.absint($id).'">';
        echo '<p><label>' . esc_html__('Name', 'wordpress-calendar-booking') . ' <input type="text" name="name" required value="'.esc_attr((string)($connection->name ?? '')).'"></label> ';
        echo '<label>' . esc_html__('Provider', 'wordpress-calendar-booking') . ' <select name="provider">';
        foreach((new VideoMeetingProviderRegistry())->codes() as $code) {
            echo '<option value="'.esc_attr($code).'" '.selected((string)($connection->provider ?? ''),$code,false).'>'.esc_html($code).'</option>';
        }
        echo '</select></label></p>';
        echo '<p class="description">' . esc_html__('Benötigte Felder: Zoom (Server-to-Server OAuth): Account-ID, Client-ID, Client-Secret; Scope meeting:write:meeting:admin. Google Meet: Refresh-Token, Client-ID, Client-Secret; Scope https://www.googleapis.com/auth/meetings.space.created. Microsoft Teams: Tenant-ID, Client-ID, Client-Secret (Anwendungsrecht OnlineMeetings.ReadWrite.All plus Application Access Policy und User-ID) oder zusätzlich Refresh-Token (delegiert, OnlineMeetings.ReadWrite).', 'wordpress-calendar-booking') . '</p>';
        foreach ([
            'account_id' => __('Account-ID (Zoom)', 'wordpress-calendar-booking'),
            'tenant_id' => __('Tenant-ID (Teams)', 'wordpress-calendar-booking'),
            'client_id' => __('Client-ID', 'wordpress-calendar-booking'),
        ] as $field => $label) {
            echo '<p><label>' . esc_html($label) . ' <input type="text" name="' . esc_attr($field) . '" autocomplete="off"></label></p>';
        }
        foreach ([
            'client_secret' => __('Client-Secret', 'wordpress-calendar-booking'),
            'refresh_token' => __('Refresh-Token (Google/Teams)', 'wordpress-calendar-booking'),
            'access_token' => __('Access Token (nur Test, läuft ab)', 'wordpress-calendar-booking'),
        ] as $field => $label) {
            echo '<p><label>' . esc_html($label) . ' <input type="password" name="' . esc_attr($field) . '" autocomplete="new-password"></label></p>';
        }
        echo '<p class="description">' . esc_html__('Secret:', 'wordpress-calendar-booking') . ' ' . esc_html($secretStatus) . ' · ' . esc_html__('Verbindungsstatus:', 'wordpress-calendar-booking') . ' ';
        $health = (string)($config['auth_status'] ?? '');
        if ($health === 'reauth_required') {
            echo '<strong style="color:#b32d2e">' . esc_html__('Neu verbinden erforderlich', 'wordpress-calendar-booking') . '</strong> ' . esc_html((string)($config['auth_error'] ?? ''));
            echo ' ' . esc_html__('Neue Zugangsdaten eintragen und speichern, um die Verbindung wiederherzustellen.', 'wordpress-calendar-booking');
        } else {
            echo esc_html($health === 'ok' ? __('in Ordnung', 'wordpress-calendar-booking') : __('noch nicht geprüft', 'wordpress-calendar-booking'));
        }
        echo '</p>';
        echo '<p><label>' . esc_html__('User-ID (optional)', 'wordpress-calendar-booking') . ' <input type="text" name="user_id" value="'.esc_attr((string)($config['user_id'] ?? '')).'"></label></p>';
        echo '<p><label><input type="checkbox" name="is_active" value="1" '.checked($connection ? (int)$connection->is_active : 1,1,false).'> ' . esc_html__('aktiv', 'wordpress-calendar-booking') . '</label></p>';
        echo '<p><button class="button button-primary">' . esc_html__('Speichern', 'wordpress-calendar-booking') . '</button></p></form>';
        if($id>0){
            echo '<form method="post" style="margin-bottom:16px">';
            wp_nonce_field('wpcb_video_action');
            echo '<input type="hidden" name="wpcb_video_action" value="delete_connection"><input type="hidden" name="connection_id" value="'.absint($id).'">';
            echo '<button class="button button-link-delete">' . esc_html__('Verbindung löschen', 'wordpress-calendar-booking') . '</button></form>';
        }
    }
}
