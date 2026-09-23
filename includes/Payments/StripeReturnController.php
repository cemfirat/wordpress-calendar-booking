<?php
namespace Wpcb\Payments;

final class StripeReturnController {
    public function boot(): void {
        add_action('template_redirect', [$this, 'render'], 0);
    }

    public function render(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }

        $return = sanitize_key(wp_unslash($_GET['wpcb_payment_return'] ?? ''));
        if (!in_array($return, ['success', 'cancelled'], true)) {
            return;
        }

        $uuid = sanitize_text_field(wp_unslash($_GET['wpcb_payment_uuid'] ?? ''));
        $view = $this->viewModel($uuid, $return);

        status_header(200);
        nocache_headers();
        wp_enqueue_style('wpcb-frontend', WPCB_URL . 'assets/css/frontend.css', [], WPCB_VERSION);
        get_header();
        echo '<main class="wpcb-public-action uk-section"><div class="uk-container uk-container-small">';
        echo '<div class="uk-card uk-card-default uk-card-body">';
        echo '<h1 class="uk-card-title">' . esc_html($view['title']) . '</h1>';
        echo '<p>' . esc_html($view['message']) . '</p>';
        echo '<p><a class="uk-button uk-button-primary" href="' . esc_url(home_url('/')) . '">' . esc_html__('Zur Website', 'wordpress-calendar-booking') . '</a></p>';
        echo '</div></div></main>';
        get_footer();
        exit;
    }

    public function viewModel(string $uuid, string $return = 'success'): array {
        $uuid = sanitize_text_field($uuid);
        if ($uuid === '' || !preg_match('/^[a-f0-9-]{36}$/i', $uuid)) {
            return $this->unknown();
        }

        $payment = (new PaymentRepository())->findByUuid($uuid);
        if (!$payment) {
            return $this->unknown();
        }

        $status = (string)$payment->status;
        if ($status === PaymentStatus::PAID) {
            return [
                'state' => 'paid',
                'title' => __('Zahlung bestätigt', 'wordpress-calendar-booking'),
                'message' => __('Die Zahlung wurde bestätigt. Die Buchung wird anhand ihres aktuellen Buchungsstatus weiterverarbeitet.', 'wordpress-calendar-booking'),
            ];
        }
        if ($status === PaymentStatus::FAILED) {
            return [
                'state' => 'failed',
                'title' => __('Zahlung fehlgeschlagen', 'wordpress-calendar-booking'),
                'message' => __('Die Zahlung wurde nicht abgeschlossen. Es wurde keine Zahlung durch diese Rückkehrseite bestätigt.', 'wordpress-calendar-booking'),
            ];
        }
        if ($status === PaymentStatus::EXPIRED) {
            return [
                'state' => 'expired',
                'title' => __('Zahlung abgelaufen', 'wordpress-calendar-booking'),
                'message' => __('Die Zahlungsfrist ist abgelaufen. Die reservierte Buchung kann dadurch wieder freigegeben werden.', 'wordpress-calendar-booking'),
            ];
        }
        if ($status === PaymentStatus::REFUND_PENDING) {
            return [
                'state' => 'refund_pending',
                'title' => __('Rückerstattung wird verarbeitet', 'wordpress-calendar-booking'),
                'message' => __('Für diese Zahlung ist eine Rückerstattung vorgemerkt. Der endgültige Status wird serverseitig verarbeitet.', 'wordpress-calendar-booking'),
            ];
        }
        if ($status === PaymentStatus::REFUNDED) {
            return [
                'state' => 'refunded',
                'title' => __('Zahlung zurückerstattet', 'wordpress-calendar-booking'),
                'message' => __('Die Zahlung ist als zurückerstattet vermerkt.', 'wordpress-calendar-booking'),
            ];
        }

        if ($return === 'cancelled') {
            return [
                'state' => 'pending',
                'title' => __('Zahlung nicht abgeschlossen', 'wordpress-calendar-booking'),
                'message' => __('Der Checkout wurde verlassen, ohne dass diese Rückkehrseite eine Zahlung bestätigt. Der verbindliche Zahlungsstatus kommt ausschließlich vom Zahlungsanbieter-Webhook.', 'wordpress-calendar-booking'),
            ];
        }

        return [
            'state' => 'pending',
            'title' => __('Zahlung wird geprüft', 'wordpress-calendar-booking'),
            'message' => __('Die Rückkehr von Stripe ist kein Zahlungsnachweis. Wir warten auf die serverseitig verifizierte Zahlungsbestätigung.', 'wordpress-calendar-booking'),
        ];
    }

    private function unknown(): array {
        return [
            'state' => 'unknown',
            'title' => __('Zahlungsstatus nicht verfügbar', 'wordpress-calendar-booking'),
            'message' => __('Für diese Rückkehradresse konnte kein Zahlungsstatus sicher ermittelt werden.', 'wordpress-calendar-booking'),
        ];
    }
}
