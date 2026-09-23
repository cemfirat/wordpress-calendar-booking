<?php
namespace Wpcb\Admin;

use Wpcb\WaitingList\WaitingListRepository;

final class WaitingListAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void {
        $title = __('Warteliste', 'wordpress-calendar-booking');
        add_submenu_page('wpcb_dashboard', $title, $title, 'manage_options', 'wpcb_waiting_list', [$this, 'render']);
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), '', ['response' => 403]);
        }
        $rows = (new WaitingListRepository())->recent(200);
        echo '<div class="wrap wpcb-admin"><h1>' . esc_html__('Warteliste', 'wordpress-calendar-booking') . '</h1>';
        echo '<p class="description">' . esc_html__('Einträge werden FIFO angeboten; pro frei gewordenem Platz gibt es höchstens ein aktives Angebot.', 'wordpress-calendar-booking') . '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('ID', 'wordpress-calendar-booking'),
            __('Status', 'wordpress-calendar-booking'),
            __('Terminart', 'wordpress-calendar-booking'),
            __('Ressource', 'wordpress-calendar-booking'),
            __('Slot (UTC)', 'wordpress-calendar-booking'),
            __('Teilnehmer', 'wordpress-calendar-booking'),
            __('Name', 'wordpress-calendar-booking'),
            __('E-Mail', 'wordpress-calendar-booking'),
            __('Angebot bis', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . (int)$row->id . '</td><td>' . esc_html((string)$row->status) . '</td><td>#' . (int)$row->booking_type_id . '</td><td>#' . (int)$row->resource_id . '</td><td>' . esc_html((string)$row->slot_start) . ' – ' . esc_html((string)$row->slot_end) . '</td><td>' . (int)$row->party_size . '</td><td>' . esc_html((string)$row->full_name) . '</td><td>' . esc_html((string)$row->email) . '</td><td>' . esc_html((string)$row->offer_expires_at) . '</td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="9">' . esc_html__('Keine Wartelisteneinträge.', 'wordpress-calendar-booking') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
