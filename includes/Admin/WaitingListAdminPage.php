<?php
namespace Wpcb\Admin;

use Wpcb\WaitingList\WaitingListRepository;

final class WaitingListAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            'Warteliste',
            'Warteliste',
            'manage_options',
            'wpcb_waiting_list',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) wp_die('Nicht erlaubt.', 403);
        $rows = (new WaitingListRepository())->recent(200);
        echo '<div class="wrap wpcb-admin"><h1>Warteliste</h1>';
        echo '<p class="description">Einträge werden FIFO angeboten; pro frei gewordenem Platz gibt es höchstens ein aktives Angebot.</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Status</th><th>Terminart</th><th>Ressource</th><th>Slot (UTC)</th><th>Teilnehmer</th><th>Name</th><th>E-Mail</th><th>Angebot bis</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . (int)$row->id . '</td><td>' . esc_html((string)$row->status) . '</td><td>#' . (int)$row->booking_type_id . '</td><td>#' . (int)$row->resource_id . '</td><td>' . esc_html((string)$row->slot_start) . ' – ' . esc_html((string)$row->slot_end) . '</td><td>' . (int)$row->party_size . '</td><td>' . esc_html((string)$row->full_name) . '</td><td>' . esc_html((string)$row->email) . '</td><td>' . esc_html((string)$row->offer_expires_at) . '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="9">Keine Wartelisteneinträge.</td></tr>';
        echo '</tbody></table></div>';
    }
}
