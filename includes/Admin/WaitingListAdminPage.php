<?php
namespace Wpcb\Admin;

use Wpcb\WaitingList\WaitingListRepository;

final class WaitingListAdminPage {
    public function boot(): void { add_action('admin_menu', [$this, 'menu']); }

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
        $rows = (new WaitingListRepository())->all(200);
        echo '<div class="wrap"><h1>Warteliste</h1>';
        echo '<p class="description">Minimaler Datensatz: E-Mail, Slot, Teilnehmerzahl und Promotion-Status.</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Terminart</th><th>Ressource</th><th>Slot (UTC)</th><th>Personen</th><th>E-Mail</th><th>Status</th><th>Angebot bis</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>#' . (int)$row->id . '</td><td>#' . (int)$row->booking_type_id . '</td><td>#' . (int)$row->resource_id . '</td>';
            echo '<td>' . esc_html((string)$row->slot_start . ' – ' . (string)$row->slot_end) . '</td>';
            echo '<td>' . (int)$row->party_size . '</td><td>' . esc_html((string)$row->email) . '</td>';
            echo '<td>' . esc_html((string)$row->status) . '</td><td>' . esc_html((string)$row->offer_expires_at) . '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="8">Keine Einträge.</td></tr>';
        echo '</tbody></table></div>';
    }
}
