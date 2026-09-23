<?php
namespace Wpcb\Admin;

use Wpcb\Payments\PaymentRepository;

final class PaymentAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            'Zahlungen',
            'Zahlungen',
            'manage_options',
            'wpcb_payments',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Nicht erlaubt.', 403);
        }
        $rows = (new PaymentRepository())->recent(200);
        echo '<div class="wrap"><h1>Zahlungen</h1>';
        echo '<p class="description">Es werden nur Zahlungsstatus und technische Referenzen verwaltet. Karten-/Bank-Zugangsdaten werden nicht in WordPress gespeichert.</p>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Buchung</th><th>Anbieter</th><th>Betrag</th><th>Status</th><th>Aktualisiert</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $amount = number_format(((int)$row->amount_minor) / 100, 2, ',', '.');
            echo '<tr><td>#' . (int)$row->id . '</td><td>#' . (int)$row->booking_id . '</td>';
            echo '<td>' . esc_html((string)$row->provider ?: '—') . '</td>';
            echo '<td>' . esc_html($amount . ' ' . (string)$row->currency) . '</td>';
            echo '<td><code>' . esc_html((string)$row->status) . '</code></td>';
            echo '<td>' . esc_html((string)$row->updated_at) . '</td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="6">Keine Zahlungen vorhanden.</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
