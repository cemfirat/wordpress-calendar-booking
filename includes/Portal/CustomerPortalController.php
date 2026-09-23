<?php
namespace Wpcb\Portal;

use Wpcb\Availability\SlotService;
use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingStateMachine;
use Wpcb\Booking\BookingStatus;
use Wpcb\Booking\BookingTransitionService;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Support\Time;
use Wpcb\Tokens\TokenService;

final class CustomerPortalController {
    private CustomerSessionRepository $sessions;
    private BookingRepository $bookings;
    private TokenService $tokens;

    public function __construct(
        ?CustomerSessionRepository $sessions = null,
        ?BookingRepository $bookings = null,
        ?TokenService $tokens = null
    ) {
        $this->sessions = $sessions ?: new CustomerSessionRepository();
        $this->bookings = $bookings ?: new BookingRepository();
        $this->tokens = $tokens ?: new TokenService();
    }

    public function boot(): void {
        add_shortcode('wpcb_customer_portal', [$this, 'shortcode']);
        add_action('template_redirect', [$this, 'confirmationPage']);

        foreach ([
            'wpcb_portal_request' => 'requestLogin',
            'wpcb_portal_login' => 'login',
            'wpcb_portal_logout' => 'logout',
            'wpcb_portal_cancel' => 'cancel',
            'wpcb_portal_reschedule' => 'reschedule',
            'wpcb_portal_contact' => 'updateContact',
            'wpcb_portal_email_change' => 'confirmEmailChange',
        ] as $action => $method) {
            add_action('admin_post_nopriv_' . $action, [$this, $method]);
            add_action('admin_post_' . $action, [$this, $method]);
        }

        add_action('wpcb_portal_session_cleanup', [$this->sessions, 'cleanup']);
        if (!wp_next_scheduled('wpcb_portal_session_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpcb_portal_session_cleanup');
        }
    }

    public function shortcode(array $atts = []): string {
        $session = $this->currentSession();
        $returnUrl = $this->currentUrl();

        if (!$session) {
            return $this->loginForm($returnUrl);
        }

        $email = $session['email'];
        $detailId = isset($_GET['wpcb_booking']) ? absint($_GET['wpcb_booking']) : 0;
        $notice = isset($_GET['wpcb_portal_notice']) ? sanitize_key(wp_unslash($_GET['wpcb_portal_notice'])) : '';

        ob_start();
        echo '<div class="wpcb-portal uk-container uk-container-small">';
        if ($notice !== '') {
            echo '<div class="uk-alert-primary" uk-alert><p>' . esc_html($this->noticeText($notice)) . '</p></div>';
        }
        echo '<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">';
        echo '<div><h2 class="uk-margin-remove">Meine Buchungen</h2><div class="uk-text-meta">' . esc_html($email) . '</div></div>';
        echo $this->postForm('wpcb_portal_logout', $returnUrl, $session, '<button class="uk-button uk-button-default" type="submit">Abmelden</button>');
        echo '</div>';

        if ($detailId > 0) {
            $booking = $this->ownedBooking($detailId, $email);
            if ($booking) {
                echo $this->bookingDetail($booking, $returnUrl, $session);
            } else {
                echo '<div class="uk-alert-warning" uk-alert><p>Buchung nicht gefunden.</p></div>';
            }
            echo '</div>';
            return (string)ob_get_clean();
        }

        $rows = $this->bookings->forEmail($email, 100);
        if (!$rows) {
            echo '<p>Für diese E-Mail-Adresse sind keine Buchungen vorhanden.</p></div>';
            return (string)ob_get_clean();
        }

        $now = Time::nowUtc();
        $upcoming = [];
        $past = [];
        foreach ($rows as $booking) {
            $end = Time::parseUtc((string)$booking->slot_end);
            if ($end && $end >= $now && !in_array((string)$booking->status, [BookingStatus::CANCELLED, BookingStatus::REJECTED, BookingStatus::EXPIRED], true)) {
                $upcoming[] = $booking;
            } else {
                $past[] = $booking;
            }
        }

        echo $this->bookingList('Bevorstehend', $upcoming, $returnUrl);
        echo $this->bookingList('Vergangen / beendet', $past, $returnUrl);
        echo '</div>';
        return (string)ob_get_clean();
    }

    public function confirmationPage(): void {
        $action = isset($_GET['wpcb_portal_action']) ? sanitize_key(wp_unslash($_GET['wpcb_portal_action'])) : '';
        if (!in_array($action, ['login', 'email-change'], true)) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['wpcb_token'] ?? ''));
        $returnUrl = $this->safeReturn((string)wp_unslash($_GET['return'] ?? home_url('/')));
        $type = $action === 'login' ? 'portal_login' : 'portal_email_change';
        $inspection = $this->tokens->inspect($token, $type);

        status_header(200);
        nocache_headers();
        echo '<!doctype html><html><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WordPress Calendar Booking</title></head><body>';
        echo '<main style="max-width:640px;margin:4rem auto;padding:1rem;font-family:system-ui,sans-serif">';
        echo '<h1>' . ($action === 'login' ? 'Kundenportal öffnen' : 'E-Mail-Adresse bestätigen') . '</h1>';

        if (($inspection['state'] ?? '') !== 'valid') {
            echo '<p>Dieser Link ist ungültig, abgelaufen oder wurde bereits verwendet.</p>';
        } else {
            echo '<p>Bitte bestätigen Sie diese Aktion ausdrücklich.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr($action === 'login' ? 'wpcb_portal_login' : 'wpcb_portal_email_change') . '">';
            echo '<input type="hidden" name="wpcb_token" value="' . esc_attr($token) . '">';
            echo '<input type="hidden" name="return_url" value="' . esc_attr($returnUrl) . '">';
            echo '<button type="submit" style="padding:.7rem 1rem">Bestätigen</button>';
            echo '</form>';
        }
        echo '</main></body></html>';
        exit;
    }

    public function requestLogin(): void {
        $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
        $returnUrl = $this->safeReturn((string)wp_unslash($_POST['return_url'] ?? home_url('/')));

        if ($email !== '' && $this->allowLoginRequest($email)) {
            $booking = $this->bookings->latestForEmail($email);
            if ($booking) {
                try {
                    $token = $this->tokens->rotate((int)$booking->id, 'portal_login', 30);
                    $url = add_query_arg([
                        'wpcb_portal_action' => 'login',
                        'wpcb_token' => rawurlencode($token),
                        'return' => $returnUrl,
                    ], home_url('/'));
                    wp_mail(
                        $email,
                        'Kundenportal – Anmeldelink',
                        "Öffnen Sie Ihr Kundenportal über diesen Link:\n\n" . $url . "\n\nDer Link ist 30 Minuten gültig und kann nur einmal verwendet werden."
                    );
                } catch (\Throwable $e) {
                    // Deliberately keep the response indistinguishable from unknown addresses.
                }
            }
        }

        $this->redirect($returnUrl, 'login_sent');
    }

    public function login(): void {
        $token = sanitize_text_field(wp_unslash($_POST['wpcb_token'] ?? ''));
        $returnUrl = $this->safeReturn((string)wp_unslash($_POST['return_url'] ?? home_url('/')));

        $result = $this->tokens->consume($token, 'portal_login', function ($row) {
            $booking = $this->bookings->find((int)$row->booking_id);
            if (!$booking || sanitize_email((string)$booking->email) === '') {
                return new \WP_Error('wpcb_portal_booking_missing', 'Booking no longer exists.');
            }
            return strtolower(sanitize_email((string)$booking->email));
        });

        if (is_wp_error($result) || !is_string($result)) {
            $this->redirect($returnUrl, 'login_invalid');
        }

        $sessionToken = $this->sessions->create($result);
        if (is_wp_error($sessionToken)) {
            $this->redirect($returnUrl, 'login_invalid');
        }

        $this->setSessionCookie((string)$sessionToken);
        $this->redirect($returnUrl, 'logged_in');
    }

    public function logout(): void {
        $session = $this->requireSession();
        $returnUrl = $this->safeReturn((string)wp_unslash($_POST['return_url'] ?? home_url('/')));
        if ($session) {
            $this->requireCsrf($session);
            $this->sessions->destroy((string)$session['token']);
        }
        $this->clearSessionCookie();
        $this->redirect($returnUrl, 'logged_out');
    }

    public function cancel(): void {
        $session = $this->requireSession();
        $returnUrl = $this->safeReturn((string)wp_unslash($_POST['return_url'] ?? home_url('/')));
        $this->requireCsrf($session);

        $bookingId = absint($_POST['booking_id'] ?? 0);
        $booking = $this->ownedBooking($bookingId, $session['email']);
        if (!$booking) {
            $this->redirect($returnUrl, 'not_allowed');
        }

        $result = (new BookingTransitionService())->apply(
            $bookingId,
            BookingStateMachine::USER_CANCELLED,
            'customer_portal',
            'Customer cancelled from portal'
        );
        $this->redirect($returnUrl, is_wp_error($result) ? 'action_failed' : 'cancelled');
    }

    public function reschedule(): void {
        $session = $this->requireSession();
        $returnUrl = $this->safeReturn((string)wp_unslash($_POST['return_url'] ?? home_url('/')));
        $this->requireCsrf($session);

        $bookingId = absint($_POST['booking_id'] ?? 0);
        $booking = $this->ownedBooking($bookingId, $session['email']);
        if (!$booking) {
            $this->redirect($returnUrl, 'not_allowed');
        }

        $parts = explode('|', sanitize_text_field(wp_unslash($_POST['slot'] ?? '')), 3);
        if (count($parts) !== 3) {
            $this->redirect($returnUrl, 'action_failed');
        }

        $resourceId = absint($parts[0]);
        $start = $parts[1];
        $end = $parts[2];
        $result = (new BookingTransitionService())->reschedule(
            $bookingId,
            $start,
            $end,
            'customer_portal',
            'Customer rescheduled from portal',
            $resourceId
        );

        $this->redirect($returnUrl, is_wp_error($result) ? 'action_failed' : 'rescheduled');
    }

    public function updateContact(): void {
        $session = $this->requireSession();
        $returnUrl = $this->safeReturn((string)wp_unslash($_POST['return_url'] ?? home_url('/')));
        $this->requireCsrf($session);

        $bookingId = absint($_POST['booking_id'] ?? 0);
        $booking = $this->ownedBooking($bookingId, $session['email']);
        if (!$booking) {
            $this->redirect($returnUrl, 'not_allowed');
        }

        $name = sanitize_text_field(wp_unslash($_POST['full_name'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $newEmail = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));

        $this->bookings->update($bookingId, ['full_name' => $name, 'phone' => $phone]);
        $this->bookings->logEvent($bookingId, (string)$booking->status, 'customer_contact_updated', 'customer_portal', 'Customer updated contact details');

        if ($newEmail !== '' && $newEmail !== strtolower((string)$booking->email)) {
            $this->bookings->updateMeta($bookingId, 'portal_pending_email', $newEmail);
            try {
                $token = $this->tokens->rotate($bookingId, 'portal_email_change', 60);
                $url = add_query_arg([
                    'wpcb_portal_action' => 'email-change',
                    'wpcb_token' => rawurlencode($token),
                    'return' => $returnUrl,
                ], home_url('/'));
                wp_mail(
                    $newEmail,
                    'Neue E-Mail-Adresse bestätigen',
                    "Bestätigen Sie Ihre neue E-Mail-Adresse über diesen Link:\n\n" . $url . "\n\nDer Link ist 60 Minuten gültig und kann nur einmal verwendet werden."
                );
                $this->redirect($returnUrl, 'email_verification_sent');
            } catch (\Throwable $e) {
                $this->bookings->deleteMeta($bookingId, 'portal_pending_email');
                $this->redirect($returnUrl, 'action_failed');
            }
        }

        $this->redirect($returnUrl, 'contact_updated');
    }

    public function confirmEmailChange(): void {
        $token = sanitize_text_field(wp_unslash($_POST['wpcb_token'] ?? ''));
        $returnUrl = $this->safeReturn((string)wp_unslash($_POST['return_url'] ?? home_url('/')));
        $oldEmail = '';

        $result = $this->tokens->consume($token, 'portal_email_change', function ($row) use (&$oldEmail) {
            $booking = $this->bookings->find((int)$row->booking_id);
            if (!$booking) {
                return new \WP_Error('wpcb_portal_booking_missing', 'Booking no longer exists.');
            }
            $meta = $this->bookings->getMeta((int)$booking->id);
            $newEmail = strtolower(sanitize_email((string)($meta['portal_pending_email'] ?? '')));
            if ($newEmail === '') {
                return new \WP_Error('wpcb_portal_email_missing', 'No pending email change exists.');
            }

            $oldEmail = strtolower(sanitize_email((string)$booking->email));
            $this->bookings->update((int)$booking->id, ['email' => $newEmail]);
            $this->bookings->deleteMeta((int)$booking->id, 'portal_pending_email');
            $this->bookings->logEvent((int)$booking->id, (string)$booking->status, 'customer_email_changed', 'customer_portal', 'Customer verified a new email address');
            return $newEmail;
        });

        if (is_wp_error($result) || !is_string($result)) {
            $this->redirect($returnUrl, 'action_failed');
        }

        if ($oldEmail !== '') {
            $this->sessions->deleteForEmail($oldEmail);
        }
        $sessionToken = $this->sessions->create($result);
        if (!is_wp_error($sessionToken)) {
            $this->setSessionCookie((string)$sessionToken);
        }
        $this->redirect($returnUrl, 'email_changed');
    }

    private function bookingList(string $title, array $rows, string $returnUrl): string {
        $html = '<h3>' . esc_html($title) . '</h3>';
        if (!$rows) {
            return $html . '<p class="uk-text-muted">Keine Buchungen.</p>';
        }

        $types = new BookingTypeRepository();
        $html .= '<div class="uk-grid-small uk-child-width-1-1" uk-grid>';
        foreach ($rows as $booking) {
            $type = $types->find((int)$booking->booking_type_id);
            $url = add_query_arg('wpcb_booking', (int)$booking->id, $returnUrl);
            $html .= '<div><a class="uk-card uk-card-default uk-card-body uk-display-block" href="' . esc_url($url) . '">';
            $html .= '<strong>' . esc_html($type ? (string)$type->name : ('Buchung #' . (int)$booking->id)) . '</strong>';
            $html .= '<div>' . esc_html(Time::display((string)$booking->slot_start, 'd.m.Y H:i')) . '</div>';
            $html .= '<div class="uk-text-meta">' . esc_html((string)$booking->status) . ' · ' . max(1, (int)$booking->party_size) . ' Person(en)</div>';
            $html .= '</a></div>';
        }
        return $html . '</div>';
    }

    private function bookingDetail(object $booking, string $returnUrl, array $session): string {
        $type = (new BookingTypeRepository())->find((int)$booking->booking_type_id);
        $back = remove_query_arg('wpcb_booking', $returnUrl);
        $html = '<p><a href="' . esc_url($back) . '">← Alle Buchungen</a></p>';
        $html .= '<div class="uk-card uk-card-default uk-card-body">';
        $html .= '<h3>' . esc_html($type ? (string)$type->name : ('Buchung #' . (int)$booking->id)) . '</h3>';
        $html .= '<dl class="uk-description-list">';
        $html .= '<dt>Termin</dt><dd>' . esc_html(Time::display((string)$booking->slot_start, 'd.m.Y H:i')) . ' – ' . esc_html(Time::display((string)$booking->slot_end, 'H:i')) . '</dd>';
        $html .= '<dt>Status</dt><dd>' . esc_html((string)$booking->status) . '</dd>';
        $html .= '<dt>Teilnehmer</dt><dd>' . max(1, (int)$booking->party_size) . '</dd>';
        $html .= '</dl>';

        if (in_array((string)$booking->status, [BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED], true)) {
            $slots = (new SlotService())->getSlots(
                (int)$booking->booking_type_id,
                14,
                (int)$booking->id,
                max(1, (int)$booking->party_size)
            );
            $fields = '<input type="hidden" name="booking_id" value="' . (int)$booking->id . '">';
            $fields .= '<select class="uk-select" name="slot" required><option value="">Neuen Termin wählen</option>';
            foreach ($slots as $slot) {
                $fields .= '<option value="' . esc_attr((int)$slot['resource_id'] . '|' . $slot['start'] . '|' . $slot['end']) . '">' . esc_html((string)$slot['label']) . '</option>';
            }
            $fields .= '</select><button class="uk-button uk-button-primary uk-margin-small-top" type="submit">Termin verschieben</button>';
            $html .= '<h4>Termin ändern</h4>' . $this->postForm('wpcb_portal_reschedule', $returnUrl, $session, $fields);

            $cancelFields = '<input type="hidden" name="booking_id" value="' . (int)$booking->id . '"><button class="uk-button uk-button-danger" type="submit">Buchung stornieren</button>';
            $html .= '<div class="uk-margin-top">' . $this->postForm('wpcb_portal_cancel', $returnUrl, $session, $cancelFields) . '</div>';
        }

        $contact = '<input type="hidden" name="booking_id" value="' . (int)$booking->id . '">';
        $contact .= '<div class="uk-margin-small"><input class="uk-input" name="full_name" value="' . esc_attr((string)$booking->full_name) . '" placeholder="Name"></div>';
        $contact .= '<div class="uk-margin-small"><input class="uk-input" name="email" type="email" required value="' . esc_attr((string)$booking->email) . '" placeholder="E-Mail"></div>';
        $contact .= '<div class="uk-margin-small"><input class="uk-input" name="phone" value="' . esc_attr((string)$booking->phone) . '" placeholder="Telefon"></div>';
        $contact .= '<button class="uk-button uk-button-default" type="submit">Kontaktdaten speichern</button>';
        $html .= '<h4 class="uk-margin-top">Kontaktdaten</h4>' . $this->postForm('wpcb_portal_contact', $returnUrl, $session, $contact);

        return $html . '</div>';
    }

    private function loginForm(string $returnUrl): string {
        return '<div class="wpcb-portal-login uk-card uk-card-default uk-card-body">'
            . '<h2>Kundenportal</h2><p>Geben Sie die E-Mail-Adresse Ihrer Buchung ein. Wenn Buchungen vorhanden sind, erhalten Sie einen einmal verwendbaren Anmeldelink.</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="wpcb_portal_request">'
            . '<input type="hidden" name="return_url" value="' . esc_attr($returnUrl) . '">'
            . '<input class="uk-input" type="email" name="email" required autocomplete="email">'
            . '<button class="uk-button uk-button-primary uk-margin-small-top" type="submit">Anmeldelink senden</button>'
            . '</form></div>';
    }

    private function postForm(string $action, string $returnUrl, array $session, string $inner): string {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="return_url" value="' . esc_attr($returnUrl) . '">'
            . '<input type="hidden" name="wpcb_portal_csrf" value="' . esc_attr((string)$session['csrf']) . '">'
            . $inner . '</form>';
    }

    private function ownedBooking(int $bookingId, string $email): ?object {
        $booking = $bookingId > 0 ? $this->bookings->find($bookingId) : null;
        if (!$booking || !hash_equals(strtolower((string)$booking->email), strtolower($email))) {
            return null;
        }
        return $booking;
    }

    private function currentSession(): ?array {
        $token = isset($_COOKIE[CustomerSessionRepository::COOKIE])
            ? sanitize_text_field(wp_unslash($_COOKIE[CustomerSessionRepository::COOKIE]))
            : '';
        return $token !== '' ? $this->sessions->authenticate($token) : null;
    }

    private function requireSession(): array {
        $session = $this->currentSession();
        if (!$session) {
            wp_die('Nicht angemeldet.', 403);
        }
        return $session;
    }

    private function requireCsrf(array $session): void {
        $provided = sanitize_text_field(wp_unslash($_POST['wpcb_portal_csrf'] ?? ''));
        if ($provided === '' || !hash_equals((string)$session['csrf'], $provided)) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.', 403);
        }
    }

    private function setSessionCookie(string $token): void {
        setcookie(CustomerSessionRepository::COOKIE, $token, [
            'expires' => time() + DAY_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[CustomerSessionRepository::COOKIE] = $token;
    }

    private function clearSessionCookie(): void {
        setcookie(CustomerSessionRepository::COOKIE, '', [
            'expires' => time() - HOUR_IN_SECONDS,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[CustomerSessionRepository::COOKIE]);
    }

    private function allowLoginRequest(string $email): bool {
        $ip = sanitize_text_field((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $key = 'wpcb_portal_rl_' . substr(hash_hmac('sha256', strtolower($email) . '|' . $ip, wp_salt('nonce')), 0, 32);
        $count = (int)get_transient($key);
        if ($count >= 5) {
            return false;
        }
        set_transient($key, $count + 1, 15 * MINUTE_IN_SECONDS);
        return true;
    }

    private function currentUrl(): string {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = sanitize_text_field((string)($_SERVER['HTTP_HOST'] ?? wp_parse_url(home_url('/'), PHP_URL_HOST)));
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        return esc_url_raw($scheme . '://' . $host . $uri);
    }

    private function safeReturn(string $url): string {
        return wp_validate_redirect(esc_url_raw($url), home_url('/'));
    }

    private function redirect(string $url, string $notice): void {
        wp_safe_redirect(add_query_arg('wpcb_portal_notice', $notice, $this->safeReturn($url)));
        exit;
    }

    private function noticeText(string $notice): string {
        return [
            'login_sent' => 'Wenn Buchungen vorhanden sind, wurde ein Anmeldelink versendet.',
            'login_invalid' => 'Der Anmeldelink ist ungültig oder abgelaufen.',
            'logged_in' => 'Sie sind angemeldet.',
            'logged_out' => 'Sie wurden abgemeldet.',
            'cancelled' => 'Die Buchung wurde storniert.',
            'rescheduled' => 'Der Termin wurde verschoben.',
            'contact_updated' => 'Die Kontaktdaten wurden gespeichert.',
            'email_verification_sent' => 'Bitte bestätigen Sie die neue E-Mail-Adresse über den zugesandten Link.',
            'email_changed' => 'Die neue E-Mail-Adresse wurde bestätigt.',
            'not_allowed' => 'Diese Buchung kann mit dieser Sitzung nicht verwaltet werden.',
            'action_failed' => 'Die Aktion konnte nicht ausgeführt werden.',
        ][$notice] ?? '';
    }
}
