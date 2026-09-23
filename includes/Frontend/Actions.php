<?php
namespace Wpcb\Frontend;

use Wpcb\Security\Guard;
use Wpcb\Forms\FieldRepository;
use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingStatus;
use Wpcb\Booking\BookingStateMachine;
use Wpcb\Booking\BookingTransitionService;
use Wpcb\Booking\ReservationService;
use Wpcb\Tokens\TokenService;
use Wpcb\Tokens\SlotTokenService;
use Wpcb\Mail\Mailer;
use Wpcb\Availability\SlotService;
use Wpcb\Availability\SlotSelectionService;
use Wpcb\Admin\Settings;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Support\BookingFormatter;
use Wpcb\Support\Time;

class Actions {
    private BookingFormatter $formatter;

    public function __construct() {
        $this->formatter = new BookingFormatter();
    }

    public function boot(): void {
        add_action('admin_post_nopriv_wpcb_submit_booking', [$this, 'submitBooking']);
        add_action('admin_post_wpcb_submit_booking', [$this, 'submitBooking']);
        add_action('admin_post_nopriv_wpcb_booking_action', [$this, 'handleActionPost']);
        add_action('admin_post_wpcb_booking_action', [$this, 'handleActionPost']);
        add_action('template_redirect', [$this, 'renderLinkAction'], 0);
        add_filter('query_vars', [$this, 'queryVars']);
        add_action('wp_ajax_wpcb_get_slots', [$this, 'ajaxSlots']);
        add_action('wp_ajax_nopriv_wpcb_get_slots', [$this, 'ajaxSlots']);
        add_action('wpcb_hourly_reminders', [$this, 'expireReservations'], 5);
        add_action('wpcb_hourly_reminders', [$this, 'sendReminders'], 10);
        add_action('wpcb_hourly_reminders', [$this, 'cleanupTokens'], 20);
        add_action('wpcb_hourly_reminders', [$this, 'cleanupDeliveryLog'], 30);
        if (!wp_next_scheduled('wpcb_hourly_reminders')) {
            wp_schedule_event(time() + 300, 'hourly', 'wpcb_hourly_reminders');
        }
    }

    public function queryVars(array $vars): array {
        $vars[] = 'wpcb_action';
        $vars[] = 'wpcb_token';
        return $vars;
    }

    public function ajaxSlots(): void {
        check_ajax_referer('wpcb_frontend', 'nonce');
        $typeId = absint($_REQUEST['type_id'] ?? 0);
        if (!$typeId) {
            wp_send_json_error(['message' => 'Terminart fehlt.'], 400);
        }
        $slots = (new SlotService())->getSlots($typeId, 21);
        $tokens = new SlotTokenService();
        $data = array_map(static function ($slot) use ($typeId, $tokens) {
            return [
                'value' => $tokens->issue(
                    $typeId,
                    (string)$slot['start'],
                    (string)$slot['end'],
                    (int)$slot['resource_id']
                ),
                'label' => $slot['label'],
            ];
        }, $slots);
        wp_send_json_success(['slots' => $data]);
    }

    public function submitBooking(): void {
        [$ok, $message] = (new Guard())->checkSubmission($_POST);
        if (!$ok) wp_die(esc_html($message));

        $submittedTypeId = isset($_POST['booking_type_id']) ? absint($_POST['booking_type_id']) : 0;
        $slotToken = isset($_POST['slot_token']) ? sanitize_text_field(wp_unslash($_POST['slot_token'])) : '';
        if (!$submittedTypeId || !$slotToken) wp_die('Ungültiger Termin.');

        $selection = (new SlotSelectionService())->resolve($slotToken, $submittedTypeId);
        if (!$selection) wp_die('Der gewählte Slot ist ungültig, abgelaufen oder nicht mehr verfügbar.');

        $typeId = (int)$selection['type_id'];
        $type = (new BookingTypeRepository())->find($typeId);
        if (!$type) wp_die('Terminart nicht gefunden.');

        $fields = (new FieldRepository())->active();
        $meta = [];
        $email = '';
        $phone = '';
        foreach ($fields as $field) {
            $key = $field->field_key;
            $raw = $_POST[$key] ?? '';
            $value = is_array($raw) ? array_map('sanitize_text_field', wp_unslash($raw)) : sanitize_text_field(wp_unslash($raw));
            if ($field->field_type === 'email') $value = sanitize_email(wp_unslash($raw));
            if ($field->is_required && (empty($value) || $value === '0')) wp_die('Bitte alle Pflichtfelder ausfüllen.');
            if ($field->field_type === 'checkbox' && $field->is_required && empty($value)) wp_die('Bitte alle Pflichtfelder bestätigen.');
            $meta[$key] = $value;
            if ($key === 'email') $email = (string)$value;
            if ($key === 'phone') $phone = (string)$value;
        }
        if (!is_email($email)) wp_die('Bitte eine gültige E-Mail-Adresse eingeben.');

        $settings = Settings::get();
        $meta['computed_location'] = $this->formatter->location(['booking_type_id' => $typeId], $meta, $settings);
        $fullName = $this->formatter->displayName([], $meta);
        if (!$phone && !empty($meta['who_calls']) && $meta['who_calls'] === 'Ich rufe an') $phone = (string)$settings['own_phone'];

        $bookingId = (new ReservationService())->reserve(
            $slotToken,
            $typeId,
            [
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'notes' => isset($meta['message']) ? (string)$meta['message'] : '',
                'source' => 'frontend',
                'lang' => 'de',
            ],
            $meta
        );
        if (is_wp_error($bookingId)) {
            wp_die(esc_html($bookingId->get_error_message()));
        }

        $repo = new BookingRepository();
        $booking = (array)$repo->find((int)$bookingId);
        $tokenService = new TokenService();
        $doiToken = $tokenService->create($bookingId, 'doi', (int)$settings['token_ttl_minutes']);
        $links = [
            'confirm' => $this->linkUrl('confirm', $doiToken),
        ];
        $mailer = new Mailer();
        $mailer->sendTemplateOnce('mail:user:' . $bookingId . ':doi', 'doi', $booking, $meta, $links, false);
        $mailer->sendInternalOnce('mail:internal:' . $bookingId . ':reserved', $booking, $meta);
        wp_safe_redirect(add_query_arg('wpcb_notice', rawurlencode('Bitte bestätige deine E-Mail über den Link in der Nachricht.'), wp_get_referer() ?: home_url('/')));
        exit;
    }

    /**
     * GET is deliberately read-only. Mail scanners/prefetchers may open links.
     */
    public function renderLinkAction(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }

        $action = sanitize_key((string)get_query_var('wpcb_action'));
        $token = sanitize_text_field((string)get_query_var('wpcb_token'));
        $tokenType = $this->tokenTypeForAction($action);
        if (!$tokenType || $token === '') {
            return;
        }

        $tokens = new TokenService();
        $inspection = $tokens->inspect($token, $tokenType);
        $state = (string)$inspection['state'];
        $row = $inspection['row'];
        $booking = $row ? (new BookingRepository())->find((int)$row->booking_id) : null;

        if ($state === 'invalid' || !$booking) {
            $this->renderActionScreen(
                'Link ungültig',
                'Dieser Termin-Link ist ungültig oder gehört nicht mehr zu einer vorhandenen Buchung.'
            );
        }

        if ($state === 'expired') {
            $this->renderActionScreen(
                'Link abgelaufen',
                'Dieser Termin-Link ist abgelaufen. Es wurde keine Änderung an der Buchung vorgenommen.'
            );
        }

        if ($state === 'used') {
            $this->renderActionScreen(
                $this->usedTitle($action, (string)$booking->status),
                $this->usedMessage($action, (string)$booking->status, $booking)
            );
        }

        $this->renderValidAction($action, $token, $booking);
    }

    /**
     * All public booking state changes enter through this POST-only endpoint.
     */
    public function handleActionPost(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            wp_die('Method not allowed.', 'Method not allowed', ['response' => 405]);
        }

        $action = sanitize_key(wp_unslash($_POST['wpcb_link_action'] ?? ''));
        $token = sanitize_text_field(wp_unslash($_POST['wpcb_token'] ?? ''));
        $tokenType = $this->tokenTypeForAction($action);
        if (!$tokenType || $token === '') {
            wp_die('Ungültige Termin-Aktion.', 'Ungültige Anfrage', ['response' => 400]);
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['wpcb_action_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, $this->nonceAction($action, $token))) {
            wp_die(
                'Die Sicherheitsprüfung ist fehlgeschlagen. Es wurde nichts geändert.',
                'Sicherheitsprüfung fehlgeschlagen',
                ['response' => 403]
            );
        }

        $tokens = new TokenService();
        $result = $tokens->consume(
            $token,
            $tokenType,
            function ($row) use ($action) {
                return $this->processAction($action, $row);
            }
        );

        if (is_wp_error($result)) {
            wp_safe_redirect($this->linkUrl($action, $token));
            exit;
        }

        wp_safe_redirect($this->linkUrl($action, $token));
        exit;
    }

    /**
     * @return array|\WP_Error
     */
    private function processAction(string $action, object $tokenRow) {
        $repo = new BookingRepository();
        $booking = $repo->find((int)$tokenRow->booking_id);
        if (!$booking) {
            return new \WP_Error('wpcb_booking_missing', 'Buchung nicht gefunden.');
        }

        $settings = Settings::get();
        $transitions = new BookingTransitionService();

        if ($action === 'confirm') {
            $event = $settings['mode'] === 'approval'
                ? BookingStateMachine::EMAIL_CONFIRMED_APPROVAL
                : BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC;

            return $transitions->apply(
                (int)$booking->id,
                $event,
                'user',
                'Double-Opt-In confirmed'
            );
        }

        if ($action === 'cancel') {
            $cutoff = Time::nowUtc()->modify('+' . max(0, (int)$settings['cancel_min_hours']) . ' hours');
            $start = Time::parseUtc((string)$booking->slot_start);
            if (!$start || $start < $cutoff) {
                return new \WP_Error('wpcb_cancel_too_late', 'Stornierung ist für diesen Termin nicht mehr möglich.');
            }

            return $transitions->apply(
                (int)$booking->id,
                BookingStateMachine::USER_CANCELLED,
                'user',
                'Booking cancelled by visitor'
            );
        }

        if ($action === 'update') {
            $cutoff = Time::nowUtc()->modify('+' . max(0, (int)$settings['change_min_hours']) . ' hours');
            $start = Time::parseUtc((string)$booking->slot_start);
            if (!$start || $start < $cutoff) {
                return new \WP_Error('wpcb_update_too_late', 'Änderung ist für diesen Termin nicht mehr möglich.');
            }

            $newSlotToken = sanitize_text_field(wp_unslash($_POST['new_slot_token'] ?? ''));
            $selection = (new SlotSelectionService())->resolve(
                $newSlotToken,
                (int)$booking->booking_type_id,
                (int)$booking->id
            );
            if (!$selection) {
                return new \WP_Error('wpcb_slot_unavailable', 'Der neue Slot ist ungültig, abgelaufen oder nicht mehr verfügbar.');
            }

            return $transitions->reschedule(
                (int)$booking->id,
                (string)$selection['start'],
                (string)$selection['end'],
                'user',
                'Booking rescheduled by visitor',
                (int)$selection['resource_id']
            );
        }

        return new \WP_Error('wpcb_action_unknown', 'Unbekannte Termin-Aktion.');
    }

    private function renderValidAction(string $action, string $token, object $booking): void {
        $settings = Settings::get();
        $date = Time::display((string)$booking->slot_start, $settings['date_format']);
        $time = Time::display((string)$booking->slot_start, $settings['time_format']);

        if ($action === 'confirm') {
            $event = $settings['mode'] === 'approval'
                ? BookingStateMachine::EMAIL_CONFIRMED_APPROVAL
                : BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC;
            if (!(new BookingStateMachine())->canApply((string)$booking->status, $event)) {
                $this->renderActionScreen('Status', 'Diese E-Mail-Bestätigung ist für den aktuellen Buchungsstatus nicht verfügbar.');
            }

            $form = $this->actionFormStart($action, $token)
                . '<p>Termin: <strong>' . esc_html($date . ' ' . $time) . '</strong></p>'
                . '<button class="uk-button uk-button-primary" type="submit">E-Mail bestätigen</button></form>';
            $this->renderActionScreen('Terminbuchung bestätigen', 'Bitte bestätige deine E-Mail-Adresse und damit die Terminbuchung.', $form);
        }

        if ($action === 'cancel') {
            if (!(new BookingStateMachine())->canApply((string)$booking->status, BookingStateMachine::USER_CANCELLED)) {
                $this->renderActionScreen('Status', 'Dieser Termin kann in seinem aktuellen Status nicht storniert werden.');
            }
            $cutoff = Time::nowUtc()->modify('+' . max(0, (int)$settings['cancel_min_hours']) . ' hours');
            $start = Time::parseUtc((string)$booking->slot_start);
            if (!$start || $start < $cutoff) {
                $this->renderActionScreen('Stornierung nicht mehr möglich', 'Die Stornofrist für diesen Termin ist abgelaufen.');
            }

            $form = $this->actionFormStart($action, $token)
                . '<p>Termin: <strong>' . esc_html($date . ' ' . $time) . '</strong></p>'
                . '<button class="uk-button uk-button-danger" type="submit">Termin verbindlich stornieren</button></form>';
            $this->renderActionScreen('Termin stornieren', 'Der Termin wird erst nach dem Klick auf den Button storniert.', $form);
        }

        if ($action === 'update') {
            if (!in_array((string)$booking->status, [BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED], true)) {
                $this->renderActionScreen('Status', 'Dieser Termin kann in seinem aktuellen Status nicht geändert werden.');
            }
            $cutoff = Time::nowUtc()->modify('+' . max(0, (int)$settings['change_min_hours']) . ' hours');
            $start = Time::parseUtc((string)$booking->slot_start);
            if (!$start || $start < $cutoff) {
                $this->renderActionScreen('Änderung nicht mehr möglich', 'Die Änderungsfrist für diesen Termin ist abgelaufen.');
            }

            $slots = (new SlotService())->getSlots((int)$booking->booking_type_id, 14, (int)$booking->id);
            if (!$slots) {
                $this->renderActionScreen('Keine freien Alternativen', 'Aktuell ist kein alternativer Termin verfügbar.');
            }

            $slotTokens = new SlotTokenService();
            $options = '';
            foreach ($slots as $slot) {
                if ((string)$slot['start'] === (string)$booking->slot_start) {
                    continue;
                }
                $value = $slotTokens->issue(
                    (int)$booking->booking_type_id,
                    (string)$slot['start'],
                    (string)$slot['end'],
                    (int)$slot['resource_id']
                );
                $options .= '<option value="' . esc_attr($value) . '">' . esc_html($slot['label']) . '</option>';
            }
            if ($options === '') {
                $this->renderActionScreen('Keine freien Alternativen', 'Aktuell ist kein alternativer Termin verfügbar.');
            }

            $form = $this->actionFormStart($action, $token)
                . '<p>Aktuell: <strong>' . esc_html($date . ' ' . $time) . '</strong></p>'
                . '<label class="uk-form-label" for="wpcb-new-slot">Neuer Termin</label>'
                . '<div class="uk-form-controls"><select class="uk-select" id="wpcb-new-slot" name="new_slot_token" required>'
                . '<option value="">Bitte wählen</option>' . $options . '</select></div>'
                . '<p><button class="uk-button uk-button-primary" type="submit">Termin ändern</button></p></form>';
            $this->renderActionScreen('Termin ändern', 'Die Änderung wird erst nach dem Absenden gespeichert.', $form);
        }

        $this->renderActionScreen('Link ungültig', 'Diese Termin-Aktion ist unbekannt.');
    }

    private function actionFormStart(string $action, string $token): string {
        $nonce = wp_create_nonce($this->nonceAction($action, $token));
        return '<form class="wpcb-public-action-form uk-form-stacked" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="wpcb_booking_action">'
            . '<input type="hidden" name="wpcb_link_action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="wpcb_token" value="' . esc_attr($token) . '">'
            . '<input type="hidden" name="wpcb_action_nonce" value="' . esc_attr($nonce) . '">';
    }

    private function renderActionScreen(string $title, string $message, string $form = ''): void {
        status_header(200);
        nocache_headers();
        wp_enqueue_style('wpcb-frontend', WPCB_URL . 'assets/css/frontend.css', [], WPCB_VERSION);
        get_header();
        echo '<main class="wpcb-public-action uk-section"><div class="uk-container uk-container-small">';
        echo '<div class="uk-card uk-card-default uk-card-body">';
        echo '<h1 class="uk-card-title">' . esc_html($title) . '</h1>';
        echo '<p>' . esc_html($message) . '</p>';
        echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form is assembled from escaped values above.
        echo '</div></div></main>';
        get_footer();
        exit;
    }

    private function tokenTypeForAction(string $action): ?string {
        return [
            'confirm' => 'doi',
            'cancel' => 'cancel',
            'update' => 'update',
        ][$action] ?? null;
    }

    private function nonceAction(string $action, string $token): string {
        return 'wpcb_booking_action|' . $action . '|' . hash('sha256', $token);
    }

    private function linkUrl(string $action, string $token): string {
        return add_query_arg(
            ['wpcb_action' => $action, 'wpcb_token' => rawurlencode($token)],
            home_url('/')
        );
    }

    private function usedTitle(string $action, string $status): string {
        if ($action === 'cancel' && $status === BookingStatus::CANCELLED) {
            return 'Termin bereits storniert';
        }
        if ($action === 'confirm' && in_array($status, [BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED], true)) {
            return 'E-Mail bereits bestätigt';
        }
        if ($action === 'update') {
            return 'Änderungslink bereits verwendet';
        }
        return 'Link bereits verwendet';
    }

    private function usedMessage(string $action, string $status, object $booking): string {
        $settings = Settings::get();
        $when = Time::display((string)$booking->slot_start, $settings['date_format'] . ' ' . $settings['time_format']);
        if ($action === 'cancel' && $status === BookingStatus::CANCELLED) {
            return 'Die Buchung ist bereits storniert. Es wurde keine weitere Änderung vorgenommen.';
        }
        if ($action === 'confirm' && $status === BookingStatus::PENDING_APPROVAL) {
            return 'Die E-Mail ist bereits bestätigt. Der Termin wartet auf Freigabe.';
        }
        if ($action === 'confirm' && $status === BookingStatus::CONFIRMED) {
            return 'Die E-Mail ist bereits bestätigt und der Termin ist bestätigt.';
        }
        if ($action === 'update') {
            return 'Dieser Änderungslink wurde bereits verwendet. Aktueller Termin: ' . $when . '.';
        }
        return 'Dieser Link wurde bereits verwendet. Es wurde keine weitere Änderung vorgenommen.';
    }

    public function expireReservations(): void {
        (new BookingTransitionService())->expireReservations();
    }

    public function cleanupTokens(): void {
        (new TokenService())->cleanup();
    }

    public function cleanupDeliveryLog(): void {
        $settings = Settings::get();
        (new \Wpcb\Reliability\DeliveryRepository())->cleanup(
            max(1, (int)($settings['delivery_log_retention_days'] ?? 90))
        );
    }

    public function sendReminders(): void {
        update_option('wpcb_hourly_reminders_last_run', Time::formatUtc(Time::nowUtc()), false);
        $settings = Settings::get();
        if (empty($settings['reminders_enabled'])) return;
        $repo = new BookingRepository();
        $bookings = $repo->all(['status' => BookingStatus::CONFIRMED]);
        foreach ($bookings as $booking) {
            $bookingStart = Time::parseUtc((string)$booking->slot_start);
            if (!$bookingStart) continue;
            $diffHours = ($bookingStart->getTimestamp() - Time::nowUtc()->getTimestamp()) / 3600;
            if ($diffHours <= (int)$settings['reminder_hours'] && $diffHours > ((int)$settings['reminder_hours'] - 1)) {
                $meta = $repo->getMeta((int)$booking->id);
                (new Mailer())->sendTemplateOnce(
                    'mail:user:' . (int)$booking->id . ':reminder:' . hash('sha256', (string)$booking->slot_start),
                    'reminder',
                    (array)$booking,
                    $meta,
                    [],
                    false
                );
            }
        }
    }
}
