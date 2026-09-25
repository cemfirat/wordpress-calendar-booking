<?php
namespace Wpcb\Frontend;

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingSeriesRepository;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Payments\StripeConfig;
use Wpcb\Support\Time;
use Wpcb\Tokens\TokenService;

/** Read-only entry checks; the domain's final payment/capacity checks remain mandatory. */
final class BookingEntryGuard {
    public function boot(): void {
        add_filter('wpcb_render_booking_form_markup', [$this, 'paymentChoices']);
        add_action('admin_notices', [$this, 'paymentNotice']);
        foreach (['admin_post_wpcb_submit_booking', 'admin_post_nopriv_wpcb_submit_booking'] as $hook) {
            add_action($hook, [$this, 'beforeSubmission'], 5);
        }
        foreach (['wp_ajax_wpcb_get_slots', 'wp_ajax_nopriv_wpcb_get_slots'] as $hook) {
            add_action($hook, [$this, 'beforeSlots'], 5);
        }
        add_action('template_redirect', [$this, 'recoveryRoute'], -1);
    }

    public static function paymentMessage(): string {
        return __('Zahlungspflichtige Terminarten sind derzeit nicht buchbar. Bitte wähle eine andere Terminart oder versuche es später erneut.', 'wordpress-calendar-booking');
    }

    /** The shared form renderer serves shortcode, block and modal surfaces. */
    public function paymentChoices(string $html): string {
        if ((new StripeConfig())->ready()) {
            return $html;
        }
        $tags = new \WP_HTML_Tag_Processor($html);
        $inTypes = false;
        $blocked = false;
        $available = false;
        while ($tags->next_tag(['tag_closers' => 'visit'])) {
            if ($tags->get_tag() === 'SELECT') {
                $inTypes = !$tags->is_tag_closer() && $tags->get_attribute('data-wpcb-type-select') !== null;
            }
            if ($inTypes && $tags->get_tag() === 'OPTION' && !$tags->is_tag_closer()
                && (string)$tags->get_attribute('value') !== '') {
                if ($tags->get_attribute('data-payment-mode') === 'required') {
                    $tags->set_attribute('disabled', true);
                    $tags->remove_attribute('selected');
                    $blocked = true;
                } elseif ($tags->get_attribute('disabled') === null) {
                    $available = true;
                }
            }
        }
        if (!$blocked) {
            return $html;
        }
        $html = $tags->get_updated_html();
        if (!$available) {
            $tags = new \WP_HTML_Tag_Processor($html);
            while ($tags->next_tag()) {
                if (in_array($tags->get_tag(), ['BUTTON', 'INPUT'], true)
                    && $tags->get_attribute('type') === 'submit') {
                    $tags->set_attribute('disabled', true);
                    $tags->set_attribute('data-wpcb-payment-blocked', '1');
                }
            }
            $html = $tags->get_updated_html();
        }
        return '<p class="uk-alert-warning" role="status" data-wpcb-payment-unavailable>'
            . esc_html(self::paymentMessage()) . '</p>' . $html;
    }

    public function paymentNotice(): void {
        if (!current_user_can('manage_options') || !isset($_GET['page']) || !is_string($_GET['page'])
            || !in_array($_GET['page'], ['wpcb_dashboard', 'wpcb_types', 'wpcb_payments', 'wpcb_system_health'], true)) {
            return;
        }
        $status = (new StripeConfig())->status();
        if ($status['ready']) return;
        $required = false;
        foreach ((new BookingTypeRepository())->all(true) as $type) {
            if (($type->payment_mode ?? 'free') === 'required') { $required = true; break; }
        }
        if (!$required) return;
        $reasons = [];
        if (!$status['enabled']) $reasons[] = __('Stripe ist deaktiviert.', 'wordpress-calendar-booking');
        if ($status['secret_key'] !== 'stored') $reasons[] = __('Der geheime API-Schlüssel fehlt oder kann nicht entschlüsselt werden.', 'wordpress-calendar-booking');
        if ($status['webhook_secret'] !== 'stored') $reasons[] = __('Das Webhook Signing Secret fehlt oder kann nicht entschlüsselt werden.', 'wordpress-calendar-booking');
        echo '<div class="notice notice-warning"><p><strong>'
            . esc_html__('Zahlungspflichtige Terminarten sind gesperrt.', 'wordpress-calendar-booking')
            . '</strong> ' . esc_html(implode(' ', $reasons)) . ' <a href="'
            . esc_url(admin_url('admin.php?page=wpcb_payments')) . '">'
            . esc_html__('Zahlungen einrichten', 'wordpress-calendar-booking') . '</a></p><p>'
            . esc_html__('Kostenlose Terminarten bleiben nutzbar. Diese Prüfung betrifft die lokale Konfiguration, nicht die Erreichbarkeit von Stripe.', 'wordpress-calendar-booking') . '</p></div>';
    }

    private function unavailableType(int $id): bool {
        $type = $id > 0 ? (new BookingTypeRepository())->find($id) : null;
        return $type && !empty($type->is_active) && !empty($type->is_public)
            && ($type->payment_mode ?? 'free') === 'required' && !(new StripeConfig())->ready();
    }

    public function beforeSlots(): void {
        $nonce = $_REQUEST['nonce'] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'wpcb_frontend')) return;
        $id = $_REQUEST['type_id'] ?? 0;
        if (is_scalar($id) && $this->unavailableType(absint($id))) {
            $budget = (new \Wpcb\Security\AvailabilityRequestGuard())->browserBudget();
            if (empty($budget['allowed'])) {
                if (!headers_sent()) header('Retry-After: ' . (int)$budget['retry_after']);
                wp_send_json_error(['message' => __('Zu viele Verfügbarkeitsanfragen. Bitte kurz warten und erneut versuchen.', 'wordpress-calendar-booking'), 'retry_after' => (int)$budget['retry_after']], 429);
            }
            wp_send_json_error(['code' => 'wpcb_payment_unavailable', 'message' => self::paymentMessage()], 409);
        }
    }

    public function beforeSubmission(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            wp_die(esc_html__('Method not allowed.', 'wordpress-calendar-booking'), '', ['response' => 405]);
        }
        // Invalid/non-public requests still enter the existing security/domain checks.
        // This early rejection performs no reservation, payment or notification work.
        $nonce = $_POST['wpcb_nonce'] ?? '';
        $id = $_POST['booking_type_id'] ?? 0;
        if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'wpcb_booking')) return;
        if (is_scalar($id) && $this->unavailableType(absint($id))) {
            [$ok, $message] = (new \Wpcb\Security\Guard())->checkSubmission($_POST);
            if (!$ok) wp_die(esc_html($message));
            $this->screen(__('Zahlung derzeit nicht möglich', 'wordpress-calendar-booking'), self::paymentMessage(), 409);
        }
    }

    public static function freshBookingUrl(): string {
        // Deliberately do not copy the request URL, Referer, token or customer fields.
        return add_query_arg('wpcb_action', 'book', home_url('/'));
    }

    /** One deadline policy for this read-only presentation; mutation is rechecked under the resource lock. */
    public function expiredReservation(object $booking): bool {
        $members = !empty($booking->series_id)
            ? (new BookingSeriesRepository())->members((int)$booking->series_id, (int)$booking->series_occurrence)
            : [$booking];
        $now = Time::nowUtc();
        foreach ($members as $member) {
            if ($member->status === 'expired') return true;
            if ($member->status === 'reserved_unconfirmed' && $member->reserved_until !== null) {
                $deadline = Time::parseUtc((string)$member->reserved_until);
                if (!$deadline || $deadline <= $now) return true;
            }
        }
        return false;
    }

    public function recoveryRoute(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') return;
        $action = get_query_var('wpcb_action');
        if ($action === 'book') {
            (new AssetManager())->register();
            $this->screen(__('Neue Terminauswahl', 'wordpress-calendar-booking'),
                __('Bitte wähle einen neuen Termin und sende die Buchung ausdrücklich ab.', 'wordpress-calendar-booking'),
                200, (new ComponentRenderer())->bookingForm());
        }
        if ($action !== 'confirm') return;
        $token = get_query_var('wpcb_token');
        if (!is_string($token) || $token === '') return;
        $inspection = (new TokenService())->inspect($token, 'doi');
        if ($inspection['state'] !== 'valid' || !$inspection['row']) return;
        $booking = (new BookingRepository())->find((int)$inspection['row']->booking_id);
        if ($booking && $this->expiredReservation($booking)) {
            $this->screen(__('Reservierung abgelaufen', 'wordpress-calendar-booking'),
                __('Mindestens eine Reservierungsfrist ist abgelaufen. Es wurde keine Buchung bestätigt. Bereits bestätigte Termine bleiben unverändert. Bitte wähle bei Bedarf einen neuen Termin. Bei bereits geleisteter Zahlung kläre den Status zuerst mit dem Veranstalter; diese Seite löst keine Zahlung oder Erstattung aus.', 'wordpress-calendar-booking'), 410);
        }
    }

    private function screen(string $title, string $message, int $status, string $form = ''): void {
        status_header($status);
        nocache_headers();
        if (!headers_sent()) {
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');
        }
        $assets = new AssetManager();
        $assets->register();
        $assets->enqueue(true);
        get_header();
        echo '<main class="wpcb-public-action uk-section"><div class="uk-container uk-container-small">'
            . '<h1>' . esc_html($title) . '</h1><p>' . esc_html($message) . '</p>';
        if ($form !== '') {
            echo $form; // Escaped by the shared component renderer, never a request value.
        } else {
            echo '<p><a class="uk-button uk-button-primary" data-wpcb-rebook href="'
                . esc_url(self::freshBookingUrl()) . '">'
                . esc_html__('Neue Terminauswahl öffnen', 'wordpress-calendar-booking') . '</a></p>';
        }
        echo '</div></main>';
        get_footer();
        exit;
    }
}
