<?php
namespace Wpcb\Admin;

use Wpcb\Webhooks\WebhookDeliveryRepository;
use Wpcb\Webhooks\WebhookEndpointRepository;

final class WebhookAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void {
        $title = __('Webhooks', 'wordpress-calendar-booking');
        add_submenu_page('wpcb_dashboard', $title, $title, 'manage_options', 'wpcb_webhooks', [$this, 'render']);
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), '', ['response' => 403]);
        }

        $endpoints = (new WebhookEndpointRepository())->all();
        $deliveries = (new WebhookDeliveryRepository())->recent(100);

        echo '<div class="wrap"><h1>' . esc_html__('Webhooks', 'wordpress-calendar-booking') . '</h1>';
        echo '<p>' . wp_kses_post(__('Webhook-Endpunkte werden über die REST API <code>/wp-json/wpcb/v1/webhooks/endpoints</code> verwaltet. Secrets werden nur beim Erstellen oder Rotieren zurückgegeben.', 'wordpress-calendar-booking')) . '</p>';
        echo '<h2>' . esc_html__('Endpunkte', 'wordpress-calendar-booking') . '</h2><table class="widefat striped"><thead><tr>';
        foreach ([
            __('ID', 'wordpress-calendar-booking'),
            __('Name', 'wordpress-calendar-booking'),
            __('URL', 'wordpress-calendar-booking'),
            __('Aktiv', 'wordpress-calendar-booking'),
            __('Ereignisse', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($endpoints as $endpoint) {
            $events = json_decode((string)$endpoint->events_json, true);
            echo '<tr><td>' . (int)$endpoint->id . '</td><td>' . esc_html((string)$endpoint->name) . '</td><td><code>' . esc_html((string)$endpoint->url) . '</code></td><td>' . esc_html(!empty($endpoint->is_active) ? __('Ja', 'wordpress-calendar-booking') : __('Nein', 'wordpress-calendar-booking')) . '</td><td>' . esc_html(implode(', ', is_array($events) ? $events : [])) . '</td></tr>';
        }
        if (!$endpoints) {
            echo '<tr><td colspan="5">' . esc_html__('Keine Endpunkte konfiguriert.', 'wordpress-calendar-booking') . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px">' . esc_html__('Letzte Zustellungen', 'wordpress-calendar-booking') . '</h2><table class="widefat striped"><thead><tr>';
        foreach ([
            __('Delivery', 'wordpress-calendar-booking'),
            __('Ereignis', 'wordpress-calendar-booking'),
            __('Status', 'wordpress-calendar-booking'),
            __('Versuche', 'wordpress-calendar-booking'),
            __('HTTP', 'wordpress-calendar-booking'),
            __('Fehler', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($deliveries as $delivery) {
            echo '<tr><td><code>' . esc_html((string)$delivery->delivery_id) . '</code></td><td>' . esc_html((string)$delivery->event_type) . '</td><td>' . esc_html((string)$delivery->status) . '</td><td>' . (int)$delivery->attempts . '</td><td>' . esc_html((string)($delivery->response_code ?? '')) . '</td><td>' . esc_html((string)($delivery->last_error ?? '')) . '</td></tr>';
        }
        if (!$deliveries) {
            echo '<tr><td colspan="6">' . esc_html__('Noch keine Zustellungen.', 'wordpress-calendar-booking') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
