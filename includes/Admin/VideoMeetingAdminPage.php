<?php
namespace Wpcb\Admin;

use Wpcb\Booking\BookingTypeRepository;
use Wpcb\VideoMeetings\VideoMeetingConnectionRepository;
use Wpcb\VideoMeetings\VideoMeetingProviderRegistry;
use Wpcb\VideoMeetings\VideoMeetingRepository;

final class VideoMeetingAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handlePost']);
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            'Video-Meetings',
            'Video-Meetings',
            'manage_options',
            'wpcb_video_meetings',
            [$this, 'render']
        );
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
                'config' => [
                    'user_id' => sanitize_text_field(wp_unslash($_POST['user_id'] ?? '')),
                ],
                'is_active' => !empty($_POST['is_active']),
            ], absint($_POST['connection_id'] ?? 0));
            if (is_wp_error($result)) $error = $result->get_error_message();
        } elseif ($action === 'delete_connection') {
            $result = $repo->delete(absint($_POST['connection_id'] ?? 0));
            if (is_wp_error($result)) $error = $result->get_error_message();
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
        if (!current_user_can('manage_options')) wp_die('Nicht erlaubt.', 403);

        $repo = new VideoMeetingConnectionRepository();
        $connections = $repo->all(false);
        $types = (new BookingTypeRepository())->all(false);

        echo '<div class="wrap wpcb-admin"><h1>Video-Meetings</h1>';
        echo '<p class="description">Access-Tokens werden authentifiziert verschlüsselt gespeichert und niemals wieder angezeigt. Leeres Token-Feld behält das bestehende Secret.</p>';
        if (!empty($_GET['updated'])) echo '<div class="notice notice-success"><p>Gespeichert.</p></div>';
        if (!empty($_GET['wpcb_error'])) echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_error']))) . '</p></div>';

        echo '<h2>Verbindungen</h2>';
        foreach ($connections as $connection) {
            $this->connectionForm($connection, $repo->credentialStatus((int)$connection->id));
        }
        echo '<h3>Neue Verbindung</h3>';
        $this->connectionForm(null, 'empty');

        echo '<hr><h2>Terminarten → Video-Meeting</h2>';
        foreach ($types as $type) {
            $selected = [];
            foreach ($repo->forBookingType((int)$type->id) as $mapped) {
                $selected[(int)$mapped->id] = (bool)$mapped->is_required;
            }
            echo '<form method="post" style="margin:12px 0;padding:12px;background:#fff;border:1px solid #ccd0d4">';
            wp_nonce_field('wpcb_video_action');
            echo '<input type="hidden" name="wpcb_video_action" value="assign_type">';
            echo '<input type="hidden" name="booking_type_id" value="' . (int)$type->id . '">';
            echo '<strong>' . esc_html((string)$type->name) . '</strong>';
            if (!$connections) echo '<p>Noch keine Video-Meeting-Verbindung vorhanden.</p>';
            foreach ($connections as $connection) {
                $id=(int)$connection->id;
                echo '<p><label><input type="checkbox" name="connections['.$id.'][enabled]" value="1" ' . checked(array_key_exists($id,$selected),true,false) . '> ';
                echo esc_html((string)$connection->name . ' (' . (string)$connection->provider . ')') . '</label> ';
                echo '<label><input type="checkbox" name="connections['.$id.'][required]" value="1" ' . checked($selected[$id] ?? false,true,false) . '> erforderlich</label></p>';
            }
            echo '<p class="description">„Erforderlich“ bedeutet: Für bestätigte Buchungen wird das Meeting zuverlässig über die Retry-Queue erzeugt. Ein temporärer Provider-Ausfall setzt die Buchung nicht zurück.</p>';
            echo '<p><button class="button button-primary">Zuordnung speichern</button></p></form>';
        }

        echo '<hr><h2>Meeting-Status</h2>';
        global $wpdb;
        $rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpcb_video_meetings ORDER BY id DESC LIMIT 100");
        echo '<table class="widefat striped"><thead><tr><th>Buchung</th><th>Provider</th><th>Status</th><th>Remote-ID</th><th>Join-Link</th><th>Fehler</th></tr></thead><tbody>';
        foreach($rows as $row){
            echo '<tr><td>#'.(int)$row->booking_id.'</td><td>'.esc_html((string)$row->provider).'</td><td>'.esc_html((string)$row->status).'</td><td>'.esc_html((string)$row->remote_id).'</td><td>';
            echo $row->join_url ? '<a href="'.esc_url((string)$row->join_url).'" target="_blank" rel="noopener noreferrer">öffnen</a>' : '–';
            echo '</td><td>'.esc_html((string)$row->last_error).'</td></tr>';
        }
        if(!$rows) echo '<tr><td colspan="6">Noch keine Meetings.</td></tr>';
        echo '</tbody></table></div>';
    }

    private function connectionForm(?object $connection, string $secretStatus): void {
        $id=(int)($connection->id ?? 0);
        $config=$connection ? json_decode((string)($connection->config_json ?? '{}'),true) : [];
        if(!is_array($config)) $config=[];
        echo '<form method="post" style="margin:10px 0;padding:12px;background:#fff;border:1px solid #ccd0d4">';
        wp_nonce_field('wpcb_video_action');
        echo '<input type="hidden" name="wpcb_video_action" value="save_connection"><input type="hidden" name="connection_id" value="'.$id.'">';
        echo '<p><label>Name <input type="text" name="name" required value="'.esc_attr((string)($connection->name ?? '')).'"></label> ';
        echo '<label>Provider <select name="provider">';
        foreach((new VideoMeetingProviderRegistry())->codes() as $code) echo '<option value="'.esc_attr($code).'" '.selected((string)($connection->provider ?? ''),$code,false).'>'.esc_html($code).'</option>';
        echo '</select></label></p>';
        echo '<p><label>Access Token <input type="password" name="access_token" autocomplete="new-password"></label> <span class="description">Secret: '.esc_html($secretStatus).'</span></p>';
        echo '<p><label>User-ID (optional) <input type="text" name="user_id" value="'.esc_attr((string)($config['user_id'] ?? '')).'"></label></p>';
        echo '<p><label><input type="checkbox" name="is_active" value="1" '.checked($connection ? (int)$connection->is_active : 1,1,false).'> aktiv</label></p>';
        echo '<p><button class="button button-primary">Speichern</button></p></form>';
        if($id>0){
            echo '<form method="post" style="margin-bottom:16px">';
            wp_nonce_field('wpcb_video_action');
            echo '<input type="hidden" name="wpcb_video_action" value="delete_connection"><input type="hidden" name="connection_id" value="'.$id.'">';
            echo '<button class="button button-link-delete">Verbindung löschen</button></form>';
        }
    }
}
