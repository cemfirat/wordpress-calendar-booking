<?php
namespace Wpcb\Admin;

use Wpcb\Webhooks\WebhookEndpointRepository;
use Wpcb\Webhooks\WebhookJobRepository;

final class WebhookAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handlePost']);
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            'Webhooks',
            'Webhooks',
            'manage_options',
            'wpcb_webhooks',
            [$this, 'render']
        );
    }

    public function handlePost(): void {
        if (!current_user_can('manage_options') || empty($_POST['wpcb_webhook_action'])) {
            return;
        }
        check_admin_referer('wpcb_webhook_action');
        $action = sanitize_key(wp_unslash($_POST['wpcb_webhook_action']));
        $repo = new WebhookEndpointRepository();
        $error = '';

        if ($action === 'save') {
            $result = $repo->save([
                'name' => wp_unslash($_POST['name'] ?? ''),
                'url' => wp_unslash($_POST['url'] ?? ''),
                'secret' => wp_unslash($_POST['secret'] ?? ''),
                'events' => (array)($_POST['events'] ?? []),
                'is_active' => !empty($_POST['is_active']),
            ], absint($_POST['endpoint_id'] ?? 0));
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            }
        } elseif ($action === 'delete') {
            $repo->delete(absint($_POST['endpoint_id'] ?? 0));
        }

        $args = ['page' => 'wpcb_webhooks', 'updated' => 1];
        if ($error !== '') {
            $args['wpcb_error'] = $error;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Nicht erlaubt.', 403);
        }
        $repo = new WebhookEndpointRepository();
        $jobs = new WebhookJobRepository();

        echo '<div class="wrap wpcb-admin"><h1>Webhooks</h1>';
        echo '<p class="description">Ausgehende Lifecycle-Webhooks werden mit HMAC-SHA256 signiert. Gespeicherte Secrets werden niemals wieder angezeigt.</p>';
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>Gespeichert.</p></div>';
        }
        if (!empty($_GET['wpcb_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_error']))) . '</p></div>';
        }

        foreach ($repo->all(false) as $endpoint) {
            $this->form($endpoint);
        }
        echo '<h2>Neuer Endpoint</h2>';
        $this->form(null);

        echo '<hr><h2>Letzte Zustellungen</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Endpoint</th><th>Buchung</th><th>Status</th><th>Versuche</th><th>HTTP</th><th>Fehler</th></tr></thead><tbody>';
        foreach ($jobs->search([], 100) as $job) {
            echo '<tr><td><code>' . esc_html((string)$job->event_type) . '</code></td>';
            echo '<td>#' . (int)$job->endpoint_id . '</td><td>#' . (int)$job->booking_id . '</td>';
            echo '<td>' . esc_html((string)$job->status) . '</td><td>' . (int)$job->attempts . '</td>';
            echo '<td>' . esc_html((string)$job->last_http_code) . '</td><td>' . esc_html((string)$job->last_error) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function form(?object $endpoint): void {
        $id = $endpoint ? (int)$endpoint->id : 0;
        $events = $endpoint ? json_decode((string)$endpoint->events_json, true) : WebhookEndpointRepository::EVENTS;
        $events = is_array($events) ? $events : [];

        echo '<form method="post" style="margin:12px 0;padding:12px;background:#fff;border:1px solid #ccd0d4">';
        wp_nonce_field('wpcb_webhook_action');
        echo '<input type="hidden" name="wpcb_webhook_action" value="save">';
        echo '<input type="hidden" name="endpoint_id" value="' . $id . '">';
        echo '<p><label>Name<br><input class="regular-text" name="name" required value="' . esc_attr((string)($endpoint->name ?? '')) . '"></label></p>';
        echo '<p><label>HTTPS URL<br><input class="large-text" type="url" name="url" required value="' . esc_attr((string)($endpoint->url ?? '')) . '"></label></p>';
        echo '<p><label>Signing Secret' . ($endpoint ? ' (leer lassen = unverändert)' : '') . '<br><input class="large-text" type="password" name="secret" autocomplete="new-password" ' . ($endpoint ? '' : 'required') . '></label></p>';
        echo '<p>';
        foreach (WebhookEndpointRepository::EVENTS as $event) {
            echo '<label style="display:inline-block;margin-right:14px"><input type="checkbox" name="events[]" value="' . esc_attr($event) . '" ' . checked(in_array($event, $events, true), true, false) . '> ' . esc_html($event) . '</label>';
        }
        echo '</p><p><label><input type="checkbox" name="is_active" value="1" ' . checked($endpoint ? (int)$endpoint->is_active : 1, 1, false) . '> aktiv</label></p>';
        echo '<p><button class="button button-primary">Speichern</button></p></form>';

        if ($id > 0) {
            echo '<form method="post" style="margin:-8px 0 20px">';
            wp_nonce_field('wpcb_webhook_action');
            echo '<input type="hidden" name="wpcb_webhook_action" value="delete"><input type="hidden" name="endpoint_id" value="' . $id . '">';
            echo '<button class="button button-link-delete">Endpoint löschen</button></form>';
        }
    }
}
