<?php
namespace Wpcb\Admin;

use Wpcb\Webhooks\WebhookDeliveryRepository;
use Wpcb\Webhooks\WebhookEndpointRepository;

final class WebhookAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
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

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Nicht erlaubt.', 403);
        }

        $endpoints = (new WebhookEndpointRepository())->all();
        $deliveries = (new WebhookDeliveryRepository())->recent(100);

        echo '<div class="wrap"><h1>Webhooks</h1>';
        echo '<p>Webhook-Endpunkte werden über die REST API <code>/wp-json/wpcb/v1/webhooks/endpoints</code> verwaltet. Secrets werden nur beim Erstellen oder Rotieren zurückgegeben.</p>';
        echo '<h2>Endpunkte</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Name</th><th>URL</th><th>Aktiv</th><th>Ereignisse</th></tr></thead><tbody>';
        foreach ($endpoints as $endpoint) {
            $events = json_decode((string)$endpoint->events_json, true);
            echo '<tr><td>' . (int)$endpoint->id . '</td><td>' . esc_html((string)$endpoint->name) . '</td><td><code>' . esc_html((string)$endpoint->url) . '</code></td><td>' . (!empty($endpoint->is_active) ? 'Ja' : 'Nein') . '</td><td>' . esc_html(implode(', ', is_array($events) ? $events : [])) . '</td></tr>';
        }
        if (!$endpoints) {
            echo '<tr><td colspan="5">Keine Endpunkte konfiguriert.</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px">Letzte Zustellungen</h2><table class="widefat striped"><thead><tr><th>Delivery</th><th>Ereignis</th><th>Status</th><th>Versuche</th><th>HTTP</th><th>Fehler</th></tr></thead><tbody>';
        foreach ($deliveries as $delivery) {
            echo '<tr><td><code>' . esc_html((string)$delivery->delivery_id) . '</code></td><td>' . esc_html((string)$delivery->event_type) . '</td><td>' . esc_html((string)$delivery->status) . '</td><td>' . (int)$delivery->attempts . '</td><td>' . esc_html((string)($delivery->response_code ?? '')) . '</td><td>' . esc_html((string)($delivery->last_error ?? '')) . '</td></tr>';
        }
        if (!$deliveries) {
            echo '<tr><td colspan="6">Noch keine Zustellungen.</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
