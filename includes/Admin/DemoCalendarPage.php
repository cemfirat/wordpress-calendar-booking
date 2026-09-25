<?php
namespace Wpcb\Admin;

use Wpcb\Demo\DemoCalendar;
use Wpcb\Support\Time;

final class DemoCalendarPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_notices', [$this, 'setupLink']);
        add_action('admin_post_wpcb_demo_calendar', [$this, 'handle']);
    }

    public function menu(): void {
        add_submenu_page('wpcb_dashboard', __('Beispielkalender', 'wordpress-calendar-booking'),
            __('Beispielkalender', 'wordpress-calendar-booking'), 'manage_options', 'wpcb_demo_calendar', [$this, 'render']);
    }

    public function setupLink(): void {
        if (!current_user_can('manage_options') || ($_GET['page'] ?? '') !== 'wpcb_dashboard') return;
        echo '<div class="notice notice-info"><p>' . esc_html__('Optional: Kalenderdarstellung mit zehn getrennten Beispieleinträgen ansehen.', 'wordpress-calendar-booking')
            . ' <a href="' . esc_url(admin_url('admin.php?page=wpcb_demo_calendar')) . '">'
            . esc_html__('Beispielkalender öffnen', 'wordpress-calendar-booking') . '</a></p></div>';
    }

    public function handle(): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Keine Berechtigung.', 'wordpress-calendar-booking'), '', ['response' => 403]);
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_die('Method not allowed.', '', ['response' => 405]);
        check_admin_referer('wpcb_demo_calendar');
        $operation = $_POST['demo_operation'] ?? '';
        if (!is_string($operation) || !in_array($operation, ['create', 'remove'], true) || ($_POST['confirm_demo'] ?? '') !== '1') {
            wp_die(esc_html__('Bitte die Beispielaktion ausdrücklich bestätigen.', 'wordpress-calendar-booking'), '', ['response' => 400]);
        }
        $service = new DemoCalendar();
        $result = $operation === 'create' ? $service->create() : $service->remove();
        $notice = is_wp_error($result) ? 'failed' : ($operation === 'create' ? 'created' : 'removed');
        wp_safe_redirect(add_query_arg('demo_notice', $notice, admin_url('admin.php?page=wpcb_demo_calendar')), 303);
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;
        $data = (new DemoCalendar())->read();
        echo '<div class="wrap"><h1>' . esc_html__('Beispielkalender', 'wordpress-calendar-booking') . '</h1><p>'
            . esc_html__('Freiwillige, private Vorschau: keine echten Buchungen, keine belegten Ressourcen, keine E-Mails, Zahlungen oder externen Kalendereinträge. Die normale Buchung funktioniert ohne diese Beispiele.', 'wordpress-calendar-booking') . '</p>';
        $notices = ['created' => __('Zehn Beispieleinträge sind vorhanden. Erneutes Erzeugen legt keine Duplikate an.', 'wordpress-calendar-booking'),
            'removed' => __('Die Beispiele wurden entfernt. Echte Daten bleiben unverändert.', 'wordpress-calendar-booking'),
            'failed' => __('Die Beispielaktion ist fehlgeschlagen. Es wurde kein Erfolg bestätigt. Bitte erneut versuchen oder die Beispieldaten gezielt entfernen.', 'wordpress-calendar-booking')];
        $notice = $_GET['demo_notice'] ?? '';
        if (is_string($notice) && isset($notices[$notice])) echo '<div role="status" class="notice notice-info"><p>' . esc_html($notices[$notice]) . '</p></div>';
        if (is_wp_error($data)) {
            echo '<p role="alert">' . esc_html($data->get_error_message()) . '</p>';
            $this->actionForm('remove');
        } elseif ($data === []) {
            echo '<p data-wpcb-demo-count="0">' . esc_html__('Keine Beispieleinträge vorhanden.', 'wordpress-calendar-booking') . '</p>';
            $this->actionForm('create');
        } else {
            echo '<p data-wpcb-demo-count="10">' . esc_html__('10 Beispieleinträge, ausschließlich in dieser Vorschau.', 'wordpress-calendar-booking') . '</p><p>'
                . esc_html__('Zeitzone bei Erzeugung:', 'wordpress-calendar-booking') . ' ' . esc_html($data['timezone']) . '</p>';
            $this->preview($data);
            $this->actionForm('remove');
        }
        echo '</div>';
    }

    private function actionForm(string $operation): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wpcb_demo_calendar');
        echo '<input type="hidden" name="action" value="wpcb_demo_calendar"><input type="hidden" name="demo_operation" value="' . esc_attr($operation) . '">';
        echo '<p><label><input type="checkbox" name="confirm_demo" value="1" required> '
            . esc_html__('Ich bestätige die Aktion nur für die getrennten Beispieldaten.', 'wordpress-calendar-booking') . '</label></p><p><button type="submit" class="button button-primary">'
            . esc_html($operation === 'create' ? __('10 Beispieleinträge erzeugen', 'wordpress-calendar-booking') : __('Beispieleinträge entfernen', 'wordpress-calendar-booking')) . '</button></p></form>';
    }

    private function preview(array $data): void {
        $tz = new \DateTimeZone($data['timezone']);
        $entries = [];
        foreach ($data['entries'] as $entry) {
            $entries[] = ['id' => $entry['id'], 'start' => Time::parseUtc($entry['start'])->setTimezone($tz), 'end' => Time::parseUtc($entry['end'])->setTimezone($tz)];
        }
        $first = min(array_column($entries, 'start'));
        $last = max(array_column($entries, 'end'))->modify('-1 second');
        $month = $first->modify('first day of this month')->setTime(0, 0);
        for ($m = 0; $m < 3 && $month <= $last; $m++, $month = $month->modify('+1 month')) {
            echo '<table class="widefat striped" data-wpcb-demo-month><caption><h2>' . esc_html(wp_date('F Y', $month->getTimestamp(), $tz)) . '</h2></caption><thead><tr>';
            $monday = $month->modify('-' . ((int)$month->format('N') - 1) . ' days');
            for ($day = 0; $day < 7; $day++) echo '<th scope="col">' . esc_html(wp_date('D', $monday->modify('+' . $day . ' days')->getTimestamp(), $tz)) . '</th>';
            echo '</tr></thead><tbody>';
            $cursor = $monday;
            for ($week = 0; $week < 6 && $cursor->format('Y-m') <= $month->format('Y-m'); $week++) {
                echo '<tr>';
                for ($day = 0; $day < 7; $day++) {
                    $next = $cursor->modify('+1 day');
                    echo '<td data-wpcb-demo-day="' . esc_attr($cursor->format('Y-m-d')) . '">';
                    if ($cursor->format('Y-m') === $month->format('Y-m')) {
                        echo '<strong>' . esc_html($cursor->format('j')) . '</strong><ul>';
                        foreach ($entries as $entry) if ($entry['start'] < $next && $entry['end'] > $cursor) {
                            echo '<li><a href="#' . esc_attr($entry['id']) . '">' . esc_html($this->label($entry['id'])) . '</a></li>';
                        }
                        echo '</ul>';
                    }
                    echo '</td>'; $cursor = $next;
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h2>' . esc_html__('Alle zehn Beispiele', 'wordpress-calendar-booking') . '</h2><table class="widefat striped"><thead><tr><th scope="col">'
            . esc_html__('Beispiel', 'wordpress-calendar-booking') . '</th><th scope="col">' . esc_html__('Beginn', 'wordpress-calendar-booking') . '</th><th scope="col">'
            . esc_html__('Ende', 'wordpress-calendar-booking') . '</th></tr></thead><tbody>';
        foreach ($entries as $entry) {
            echo '<tr data-wpcb-demo-entry id="' . esc_attr($entry['id']) . '"><th scope="row">' . esc_html($this->label($entry['id'])) . '</th><td>'
                . esc_html(wp_date('D, d.m.Y H:i', $entry['start']->getTimestamp(), $tz)) . '</td><td>' . esc_html(wp_date('D, d.m.Y H:i', $entry['end']->getTimestamp(), $tz)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function label(string $id): string {
        return sprintf(__('Beispiel %d', 'wordpress-calendar-booking'), (int)substr($id, 5));
    }
}
