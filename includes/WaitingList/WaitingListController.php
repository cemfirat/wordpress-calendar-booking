<?php
namespace Wpcb\WaitingList;

final class WaitingListController {
    public function boot(): void {
        add_shortcode('wpcb_waiting_list', [$this, 'shortcode']);
        add_action('admin_post_nopriv_wpcb_waitlist_join', [$this, 'join']);
        add_action('admin_post_wpcb_waitlist_join', [$this, 'join']);
        add_action('admin_post_nopriv_wpcb_waitlist_accept', [$this, 'accept']);
        add_action('admin_post_wpcb_waitlist_accept', [$this, 'accept']);
    }

    public function shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'booking_type_id' => 0,
            'resource_id' => 0,
            'start' => '',
            'end' => '',
            'party_size' => 1,
        ], $atts, 'wpcb_waiting_list');

        $returnUrl = $this->currentUrl();
        $notice = sanitize_key(wp_unslash($_GET['wpcb_waitlist_notice'] ?? ''));
        $html = '<div class="wpcb-waiting-list uk-card uk-card-default uk-card-body">';
        if ($notice === 'joined') {
            $html .= '<div class="uk-alert-success" uk-alert>Sie stehen auf der Warteliste.</div>';
        } elseif ($notice === 'accepted') {
            $html .= '<div class="uk-alert-success" uk-alert>Der Termin wurde reserviert. Bitte bestätigen Sie Ihre E-Mail.</div>';
        } elseif ($notice === 'error') {
            $html .= '<div class="uk-alert-danger" uk-alert>Die Wartelisten-Aktion konnte nicht ausgeführt werden.</div>';
        }

        $offerToken = sanitize_text_field(wp_unslash($_GET['wpcb_waitlist_offer'] ?? ''));
        if ($offerToken !== '') {
            $token = new WaitingListToken();
            $entry = (new WaitingListRepository())->findOffer($token->selector($offerToken));
            if ($entry && (string)$entry->status === 'offered' && $token->verify($offerToken, $entry)) {
                $html .= '<h3>Terminangebot</h3><p>Für den gewünschten Termin ist Kapazität frei. Dieses Angebot ist zeitlich begrenzt.</p>';
                $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                $html .= '<input type="hidden" name="action" value="wpcb_waitlist_accept">';
                $html .= '<input type="hidden" name="token" value="' . esc_attr($offerToken) . '">';
                $html .= '<input type="hidden" name="return_url" value="' . esc_attr($returnUrl) . '">';
                $html .= wp_nonce_field('wpcb_waitlist_accept', '_wpnonce', true, false);
                $html .= '<button class="uk-button uk-button-primary" type="submit">Termin reservieren</button></form></div>';
                return $html;
            }
            return $html . '<p>Dieses Terminangebot ist ungültig oder abgelaufen.</p></div>';
        }

        $typeId = absint($atts['booking_type_id']);
        $resourceId = absint($atts['resource_id']);
        $start = sanitize_text_field((string)$atts['start']);
        $end = sanitize_text_field((string)$atts['end']);
        $partySize = max(1, absint($atts['party_size']));

        if (!$typeId || !$resourceId || !$start || !$end) {
            return $html . '<p class="uk-text-muted">Warteliste ist für diesen Block nicht konfiguriert.</p></div>';
        }

        $html .= '<h3>Warteliste</h3><p>Wir benachrichtigen Sie, wenn für diesen Termin Kapazität frei wird.</p>';
        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $html .= '<input type="hidden" name="action" value="wpcb_waitlist_join">';
        $html .= '<input type="hidden" name="booking_type_id" value="' . $typeId . '">';
        $html .= '<input type="hidden" name="resource_id" value="' . $resourceId . '">';
        $html .= '<input type="hidden" name="slot_start" value="' . esc_attr($start) . '">';
        $html .= '<input type="hidden" name="slot_end" value="' . esc_attr($end) . '">';
        $html .= '<input type="hidden" name="party_size" value="' . $partySize . '">';
        $html .= '<input type="hidden" name="return_url" value="' . esc_attr($returnUrl) . '">';
        $html .= wp_nonce_field('wpcb_waitlist_join', '_wpnonce', true, false);
        $html .= '<label class="uk-form-label">E-Mail-Adresse<input class="uk-input" type="email" name="email" required autocomplete="email"></label>';
        $html .= '<button class="uk-button uk-button-default uk-margin-small-top" type="submit">Auf Warteliste setzen</button></form></div>';
        return $html;
    }

    public function join(): void {
        check_admin_referer('wpcb_waitlist_join');
        $returnUrl = $this->safeReturn((string)($_POST['return_url'] ?? ''));
        $result = (new WaitingListService())->join(
            absint($_POST['booking_type_id'] ?? 0),
            absint($_POST['resource_id'] ?? 0),
            sanitize_text_field(wp_unslash($_POST['slot_start'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['slot_end'] ?? '')),
            max(1, absint($_POST['party_size'] ?? 1)),
            sanitize_email(wp_unslash($_POST['email'] ?? '')),
            $returnUrl
        );
        wp_safe_redirect(add_query_arg('wpcb_waitlist_notice', is_wp_error($result) ? 'error' : 'joined', $returnUrl));
        exit;
    }

    public function accept(): void {
        check_admin_referer('wpcb_waitlist_accept');
        $returnUrl = $this->safeReturn((string)($_POST['return_url'] ?? ''));
        $result = (new WaitingListService())->accept(sanitize_text_field(wp_unslash($_POST['token'] ?? '')));
        $clean = remove_query_arg('wpcb_waitlist_offer', $returnUrl);
        wp_safe_redirect(add_query_arg('wpcb_waitlist_notice', is_wp_error($result) ? 'error' : 'accepted', $clean));
        exit;
    }

    private function currentUrl(): string {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = sanitize_text_field((string)($_SERVER['HTTP_HOST'] ?? wp_parse_url(home_url('/'), PHP_URL_HOST)));
        return esc_url_raw($scheme . '://' . $host . (string)($_SERVER['REQUEST_URI'] ?? '/'));
    }

    private function safeReturn(string $url): string {
        return wp_validate_redirect(esc_url_raw($url), home_url('/'));
    }
}
