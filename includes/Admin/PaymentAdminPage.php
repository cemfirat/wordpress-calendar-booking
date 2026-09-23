<?php
namespace Wpcb\Admin;

use Wpcb\Payments\PaymentRepository;

final class PaymentAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void {
        $title = __('Zahlungen', 'wordpress-calendar-booking');
        add_submenu_page('wpcb_dashboard', $title, $title, 'manage_options', 'wpcb_payments', [$this, 'render']);
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), '', ['response' => 403]);
        }
        $rows = (new PaymentRepository())->recent(200);
        echo '<div class="wrap"><h1>' . esc_html__('Zahlungen', 'wordpress-calendar-booking') . '</h1>';
        echo '<p class="description">' . esc_html__('Es werden nur Zahlungsstatus und technische Referenzen verwaltet. Karten-/Bank-Zugangsdaten werden nicht in WordPress gespeichert.', 'wordpress-calendar-booking') . '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('ID', 'wordpress-calendar-booking'),
            __('Buchung', 'wordpress-calendar-booking'),
            __('Anbieter', 'wordpress-calendar-booking'),
            __('Betrag', 'wordpress-calendar-booking'),
            __('Status', 'wordpress-calendar-booking'),
            __('Aktualisiert', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $amount = number_format(((int)$row->amount_minor) / 100, 2, ',', '.');
            echo '<tr><td>#' . (int)$row->id . '</td><td>#' . (int)$row->booking_id . '</td>';
            echo '<td>' . esc_html((string)$row->provider ?: '—') . '</td>';
            echo '<td>' . esc_html($amount . ' ' . (string)$row->currency) . '</td>';
            echo '<td><code>' . esc_html((string)$row->status) . '</code></td>';
            echo '<td>' . esc_html((string)$row->updated_at) . '</td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="6">' . esc_html__('Keine Zahlungen vorhanden.', 'wordpress-calendar-booking') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
