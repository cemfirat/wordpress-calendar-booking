<?php
namespace Wpcb\Admin;

use Wpcb\Booking\BookingAuditRepository;
use Wpcb\Support\Time;

class BookingAuditPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            __('Buchungs-Historie', 'wordpress-calendar-booking'),
            __('Buchungs-Historie', 'wordpress-calendar-booking'),
            'manage_options',
            'wpcb_booking_audit',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), '', ['response' => 403]);
        }

        $repo = new BookingAuditRepository();
        $filters = $this->filters($_GET);
        $items = $repo->search($filters, 200);

        echo '<div class="wrap wpcb-admin">';
        echo '<h1>' . esc_html__('Buchungs-Historie', 'wordpress-calendar-booking') . '</h1>';
        echo '<p class="description">' . esc_html__('Datensparsames Lifecycle-Protokoll. Namen, E-Mail-Adressen, Telefonnummern und Formularantworten werden hier nicht geladen oder angezeigt.', 'wordpress-calendar-booking') . '</p>';

        echo '<form method="get" style="margin:12px 0;padding:12px;background:#fff;border:1px solid #ccd0d4">';
        echo '<input type="hidden" name="page" value="wpcb_booking_audit">';
        echo '<label>' . esc_html__('Buchung', 'wordpress-calendar-booking') . ' <input type="number" min="1" name="booking_id" value="' . esc_attr($filters['booking_id'] ?: '') . '"></label> ';
        echo '<label>' . esc_html__('Ereignis', 'wordpress-calendar-booking') . ' <select name="event"><option value="">' . esc_html__('alle', 'wordpress-calendar-booking') . '</option>';
        foreach ($repo->contexts() as $context) {
            echo '<option value="' . esc_attr($context) . '" ' . selected($filters['context'], $context, false) . '>' . esc_html($context) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>' . esc_html__('Akteur', 'wordpress-calendar-booking') . ' <select name="actor"><option value="">' . esc_html__('alle', 'wordpress-calendar-booking') . '</option>';
        foreach ($repo->actors() as $actor) {
            echo '<option value="' . esc_attr($actor) . '" ' . selected($filters['actor'], $actor, false) . '>' . esc_html($actor) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>' . esc_html__('Von', 'wordpress-calendar-booking') . ' <input type="date" name="from" value="' . esc_attr($filters['from_date']) . '"></label> ';
        echo '<label>' . esc_html__('Bis', 'wordpress-calendar-booking') . ' <input type="date" name="to" value="' . esc_attr($filters['to_date']) . '"></label> ';
        echo '<button class="button">' . esc_html__('Filtern', 'wordpress-calendar-booking') . '</button> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=wpcb_booking_audit')) . '">' . esc_html__('Zurücksetzen', 'wordpress-calendar-booking') . '</a>';
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Zeit (UTC)', 'wordpress-calendar-booking'),
            __('Buchung', 'wordpress-calendar-booking'),
            __('Ereignis', 'wordpress-calendar-booking'),
            __('Von', 'wordpress-calendar-booking'),
            __('Nach', 'wordpress-calendar-booking'),
            __('Akteur', 'wordpress-calendar-booking'),
            __('Notiz', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $item) {
            echo '<tr>';
            echo '<td>' . esc_html((string)$item->created_at) . '</td>';
            echo '<td>#' . (int)$item->booking_id . '</td>';
            echo '<td><code>' . esc_html((string)$item->context) . '</code></td>';
            echo '<td>' . esc_html((string)($item->old_status ?? '')) . '</td>';
            echo '<td>' . esc_html((string)$item->new_status) . '</td>';
            echo '<td>' . esc_html((string)$item->changed_by) . '</td>';
            echo '<td>' . esc_html((string)$item->note) . '</td>';
            echo '</tr>';
        }
        if (!$items) {
            echo '<tr><td colspan="7">' . esc_html__('Keine passenden Einträge.', 'wordpress-calendar-booking') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    private function filters(array $input): array {
        $filters = [
            'booking_id' => absint($input['booking_id'] ?? 0),
            'context' => sanitize_key(wp_unslash($input['event'] ?? '')),
            'actor' => sanitize_key(wp_unslash($input['actor'] ?? '')),
            'from_date' => '',
            'to_date' => '',
        ];

        $from = sanitize_text_field(wp_unslash($input['from'] ?? ''));
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $from)) {
            $filters['from_date'] = $from;
            $filters['from'] = Time::localToUtc($from . ' 00:00:00');
        }

        $to = sanitize_text_field(wp_unslash($input['to'] ?? ''));
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $to)) {
            $filters['to_date'] = $to;
            $filters['to'] = Time::localToUtc($to . ' 23:59:59');
        }

        return $filters;
    }
}
