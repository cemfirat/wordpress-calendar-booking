<?php
namespace Wpcb\Admin;

use Wpcb\Payments\PaymentRepository;
use Wpcb\Payments\PaymentService;
use Wpcb\Payments\PaymentStatus;
use Wpcb\Payments\StripeAdapter;
use Wpcb\Payments\StripeConfig;

final class PaymentAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpcb_stripe_save', [$this, 'saveStripe']);
        add_action('admin_post_wpcb_stripe_refund', [$this, 'refundStripe']);
    }

    public function menu(): void {
        $title = __('Zahlungen', 'wordpress-calendar-booking');
        add_submenu_page('wpcb_dashboard', $title, $title, 'manage_options', 'wpcb_payments', [$this, 'render']);
    }

    public function saveStripe(): void {
        $this->requireAdmin();
        check_admin_referer('wpcb_stripe_save');
        $result = (new StripeConfig())->save([
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'secret_key' => sanitize_text_field(wp_unslash($_POST['secret_key'] ?? '')),
            'webhook_secret' => sanitize_text_field(wp_unslash($_POST['webhook_secret'] ?? '')),
        ]);
        $notice = is_wp_error($result) ? $result->get_error_message() : __('Stripe-Einstellungen gespeichert.', 'wordpress-calendar-booking');
        wp_safe_redirect(add_query_arg(['page' => 'wpcb_payments', 'wpcb_notice' => rawurlencode($notice)], admin_url('admin.php')));
        exit;
    }

    public function refundStripe(): void {
        $this->requireAdmin();
        $paymentId = absint($_POST['payment_id'] ?? 0);
        check_admin_referer('wpcb_stripe_refund_' . $paymentId);
        $result = (new PaymentService())->refund($paymentId, new StripeAdapter());
        $notice = is_wp_error($result) ? $result->get_error_message() : __('Stripe-Rückerstattung verarbeitet.', 'wordpress-calendar-booking');
        wp_safe_redirect(add_query_arg(['page' => 'wpcb_payments', 'wpcb_notice' => rawurlencode($notice)], admin_url('admin.php')));
        exit;
    }

    public function render(): void {
        $this->requireAdmin();
        $repo = new PaymentRepository();
        $rows = $repo->recent(200);
        $config = new StripeConfig();
        $stripe = $config->status();

        echo '<div class="wrap"><h1>' . esc_html__('Zahlungen', 'wordpress-calendar-booking') . '</h1>';
        if (!empty($_GET['wpcb_notice'])) {
            echo '<div class="notice notice-info"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_notice']))) . '</p></div>';
        }
        echo '<p class="description">' . esc_html__('Es werden nur Zahlungsstatus und technische Referenzen verwaltet. Karten-/Bank-Zugangsdaten werden nicht in WordPress gespeichert.', 'wordpress-calendar-booking') . '</p>';

        echo '<h2>' . esc_html__('Stripe Checkout', 'wordpress-calendar-booking') . '</h2>';
        echo '<p>' . esc_html__('Checkout läuft vollständig bei Stripe. WordPress speichert nur verschlüsselte API-/Webhook-Secrets und technische Zahlungsreferenzen.', 'wordpress-calendar-booking') . '</p>';
        echo '<p><strong>' . esc_html__('Status:', 'wordpress-calendar-booking') . '</strong> '
            . esc_html($stripe['ready'] ? __('bereit', 'wordpress-calendar-booking') : __('nicht vollständig konfiguriert', 'wordpress-calendar-booking')) . '</p>';
        echo '<p><strong>' . esc_html__('Webhook-URL:', 'wordpress-calendar-booking') . '</strong> <code>'
            . esc_html(rest_url('wpcb/v1/payments/stripe/webhook')) . '</code></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:720px">';
        echo '<input type="hidden" name="action" value="wpcb_stripe_save">';
        wp_nonce_field('wpcb_stripe_save');
        echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked(!empty($stripe['enabled']), true, false) . '> '
            . esc_html__('Stripe für kostenpflichtige Terminarten aktivieren', 'wordpress-calendar-booking') . '</label></p>';
        echo '<p><label><strong>' . esc_html__('Secret Key', 'wordpress-calendar-booking') . '</strong><br>'
            . '<input class="regular-text" type="password" name="secret_key" value="" autocomplete="new-password" placeholder="'
            . esc_attr($stripe['secret_key'] === 'stored' ? __('gespeichert – leer lassen zum Beibehalten', 'wordpress-calendar-booking') : 'sk_...') . '"></label></p>';
        echo '<p><label><strong>' . esc_html__('Webhook Signing Secret', 'wordpress-calendar-booking') . '</strong><br>'
            . '<input class="regular-text" type="password" name="webhook_secret" value="" autocomplete="new-password" placeholder="'
            . esc_attr($stripe['webhook_secret'] === 'stored' ? __('gespeichert – leer lassen zum Beibehalten', 'wordpress-calendar-booking') : 'whsec_...') . '"></label></p>';
        submit_button(__('Stripe speichern', 'wordpress-calendar-booking'));
        echo '</form>';

        echo '<hr><h2>' . esc_html__('Zahlungsverlauf', 'wordpress-calendar-booking') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('ID', 'wordpress-calendar-booking'),
            __('Buchung', 'wordpress-calendar-booking'),
            __('Anbieter', 'wordpress-calendar-booking'),
            __('Betrag', 'wordpress-calendar-booking'),
            __('Status', 'wordpress-calendar-booking'),
            __('Aktualisiert', 'wordpress-calendar-booking'),
            __('Aktion', 'wordpress-calendar-booking'),
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
            echo '<td>' . esc_html((string)$row->updated_at) . '</td><td>';
            if ((string)$row->provider === 'stripe' && (string)$row->status === PaymentStatus::REFUND_PENDING) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="wpcb_stripe_refund">';
                echo '<input type="hidden" name="payment_id" value="' . (int)$row->id . '">';
                wp_nonce_field('wpcb_stripe_refund_' . (int)$row->id);
                echo '<button class="button button-secondary" type="submit">' . esc_html__('Rückerstatten', 'wordpress-calendar-booking') . '</button></form>';
            } else {
                echo '—';
            }
            echo '</td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="7">' . esc_html__('Keine Zahlungen vorhanden.', 'wordpress-calendar-booking') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function requireAdmin(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), '', ['response' => 403]);
        }
    }
}
