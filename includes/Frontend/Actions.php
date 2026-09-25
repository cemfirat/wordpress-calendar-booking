<?php
namespace Wpcb\Frontend;

use Wpcb\Security\Guard;
use Wpcb\Security\AvailabilityRequestGuard;
use Wpcb\Forms\FieldRepository;
use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingStatus;
use Wpcb\Booking\BookingStateMachine;
use Wpcb\Booking\BookingTransitionService;
use Wpcb\Booking\ReservationService;
use Wpcb\Booking\RecurringBookingService;
use Wpcb\Tokens\TokenService;
use Wpcb\Tokens\SlotTokenService;
use Wpcb\Mail\Mailer;
use Wpcb\Availability\SlotService;
use Wpcb\Availability\SlotSelectionService;
use Wpcb\Admin\Settings;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Payments\PaymentService;
use Wpcb\Payments\StripeAdapter;
use Wpcb\Payments\StripeConfig;
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

        $guard = new AvailabilityRequestGuard();
        $budget = $guard->browserBudget();
        if (empty($budget['allowed'])) {
            if (!headers_sent()) {
                header('Retry-After: ' . (int)$budget['retry_after']);
            }
            wp_send_json_error([
                'message' => __('Zu viele Verfügbarkeitsanfragen. Bitte kurz warten und erneut versuchen.', 'wordpress-calendar-booking'),
                'retry_after' => (int)$budget['retry_after'],
            ], 429);
        }

        $typeId = absint($_REQUEST['type_id'] ?? 0);
        $partySize = max(1, absint($_REQUEST['party_size'] ?? 1));
        $query = $guard->validateQuery($typeId, 21, $partySize);
        if (is_wp_error($query)) {
            $errorData = $query->get_error_data();
            $status = is_array($errorData) && isset($errorData['status'])
                ? (int)$errorData['status']
                : 400;
            wp_send_json_error(['message' => $query->get_error_message()], $status);
        }

        $slotService = new SlotService();
        $slots = $slotService->getSlotsResult(
            $typeId,
            (int)$query['days'],
            null,
            (int)$query['party_size']
        );
        if (is_wp_error($slots)) {
            wp_send_json_error(['message' => $slots->get_error_message()], 503);
        }
        $slots = array_slice($slots, 0, (int)$query['max_slots']);

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
        if (!$submittedTypeId || !$slotToken) wp_die(esc_html__('Ungültiger Termin.', 'wordpress-calendar-booking'));

        $selection = (new SlotSelectionService())->resolve($slotToken, $submittedTypeId);
        if (!$selection) wp_die(esc_html__('Der gewählte Slot ist ungültig, abgelaufen oder nicht mehr verfügbar.', 'wordpress-calendar-booking'));

        $typeId = (int)$selection['type_id'];
        $type = (new BookingTypeRepository())->find($typeId);
        if (!$type) wp_die(esc_html__('Terminart nicht gefunden.', 'wordpress-calendar-booking'));
        $requiresPayment = (string)($type->payment_mode ?? 'free') === 'required';
        $stripe = new StripeConfig();
        if ($requiresPayment && !$stripe->ready()) {
            wp_die(esc_html__('Für diese Terminart ist eine Zahlung erforderlich, der Zahlungsanbieter ist derzeit aber nicht verfügbar.', 'wordpress-calendar-booking'));
        }

        $fields = (new FieldRepository())->active();
        $meta = [];
        $email = '';
        $phone = '';
        foreach ($fields as $field) {
            $key = $field->field_key;
            $raw = $_POST[$key] ?? '';
            $value = is_array($raw) ? array_map('sanitize_text_field', wp_unslash($raw)) : sanitize_text_field(wp_unslash($raw));
            if ($field->field_type === 'email') $value = sanitize_email(wp_unslash($raw));
            if ($field->is_required && (empty($value) || $value === '0')) wp_die(esc_html__('Bitte alle Pflichtfelder ausfüllen.', 'wordpress-calendar-booking'));
            if ($field->field_type === 'checkbox' && $field->is_required && empty($value)) wp_die(esc_html__('Bitte alle Pflichtfelder bestätigen.', 'wordpress-calendar-booking'));
            $meta[$key] = $value;
            if ($key === 'email') $email = (string)$value;
            if ($key === 'phone') $phone = (string)$value;
        }
        if (!is_email($email)) wp_die(esc_html__('Bitte eine gültige E-Mail-Adresse eingeben.', 'wordpress-calendar-booking'));

        $settings = Settings::get();
        $meta['computed_location'] = $this->formatter->location(['booking_type_id' => $typeId], $meta, $settings);
        $fullName = $this->formatter->displayName([], $meta);
        if (!$phone && !empty($meta['who_calls']) && $meta['who_calls'] === 'Ich rufe an') $phone = (string)$settings['own_phone'];

        $partySize = max(1, min(10000, absint($_POST['party_size'] ?? 1)));

        $customer = [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'notes' => isset($meta['message']) ? (string)$meta['message'] : '',
            'source' => 'frontend',
            'lang' => 'de',
            'party_size' => $partySize,
        ];
        $recurrenceCount = max(1, min(24, absint($_POST['recurrence_count'] ?? 1)));
        $recurrenceInterval = max(1, min(4, absint($_POST['recurrence_interval'] ?? 1)));

        if ($recurrenceCount > 1) {
            $series = (new RecurringBookingService())->reserveWeekly(
                $slotToken,
                $typeId,
                $customer,
                $meta,
                $recurrenceCount,
                $recurrenceInterval
            );
            if (is_wp_error($series)) {
                wp_die(esc_html($series->get_error_message()));
            }
            $bookingId = (int)$series['primary_booking_id'];
        } else {
            $bookingId = (new ReservationService())->reserve(
                $slotToken,
                $typeId,
                $customer,
                $meta
            );
            if (is_wp_error($bookingId)) {
                wp_die(esc_html($bookingId->get_error_message()));
            }
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

        if ($requiresPayment) {
            $started = (new PaymentService())->begin((int)$bookingId, new StripeAdapter($stripe));
            if (is_wp_error($started) || empty($started->checkout_url)) {
                $message = is_wp_error($started)
                    ? $started->get_error_message()
                    : __('Die Zahlung konnte nicht gestartet werden.', 'wordpress-calendar-booking');
                wp_die(esc_html($message));
            }
            $checkoutUrl = esc_url_raw((string)$started->checkout_url);
            $host = strtolower((string)wp_parse_url($checkoutUrl, PHP_URL_HOST));
            if ($host !== 'checkout.stripe.com' && !str_ends_with($host, '.stripe.com')) {
                wp_die(esc_html__('Der Zahlungsanbieter hat eine ungültige Weiterleitungsadresse geliefert.', 'wordpress-calendar-booking'));
            }
            wp_redirect($checkoutUrl, 303);
            exit;
        }

        wp_safe_redirect(add_query_arg('wpcb_notice', rawurlencode(__('Bitte bestätige deine E-Mail über den Link in der Nachricht.', 'wordpress-calendar-booking')), wp_get_referer() ?: home_url('/')));
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
                __('Link ungültig', 'wordpress-calendar-booking'),
                __('Dieser Termin-Link ist ungültig oder gehört nicht mehr zu einer vorhandenen Buchung.', 'wordpress-calendar-booking')
            );
        }

        if ($state === 'expired') {
            $this->renderActionScreen(
                __('Link abgelaufen', 'wordpress-calendar-booking'),
                __('Dieser Termin-Link ist abgelaufen. Es wurde keine Änderung an der Buchung vorgenommen.', 'wordpress-calendar-booking')
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
            wp_die(esc_html__('Method not allowed.', 'wordpress-calendar-booking'), esc_html__('Method not allowed', 'wordpress-calendar-booking'), ['response' => 405]);
        }

        $action = sanitize_key(wp_unslash($_POST['wpcb_link_action'] ?? ''));
        $token = sanitize_text_field(wp_unslash($_POST['wpcb_token'] ?? ''));
        $tokenType = $this->tokenTypeForAction($action);
        if (!$tokenType || $token === '') {
            wp_die(esc_html__('Ungültige Termin-Aktion.', 'wordpress-calendar-booking'), esc_html__('Ungültige Anfrage', 'wordpress-calendar-booking'), ['response' => 400]);
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['wpcb_action_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, $this->nonceAction($action, $token))) {
            wp_die(
                esc_html__('Die Sicherheitsprüfung ist fehlgeschlagen. Es wurde nichts geändert.', 'wordpress-calendar-booking'),
                esc_html__('Sicherheitsprüfung fehlgeschlagen', 'wordpress-calendar-booking'),
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
            return new \WP_Error('wpcb_booking_missing', __('Buchung nicht gefunden.', 'wordpress-calendar-booking'));
        }

        $settings = Settings::get();
        $transitions = new BookingTransitionService();

        if ($action === 'confirm') {
            $event = $settings['mode'] === 'approval'
                ? BookingStateMachine::EMAIL_CONFIRMED_APPROVAL
                : BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC;

            if (!empty($booking->series_id)) {
                return (new RecurringBookingService())->applyRemaining(
                    (int)$booking->id,
                    $event,
                    'user',
                    'Recurring series Double-Opt-In confirmed'
                );
            }

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
                return new \WP_Error('wpcb_cancel_too_late', __('Stornierung ist für diesen Termin nicht mehr möglich.', 'wordpress-calendar-booking'));
            }

            $seriesScope = sanitize_key(wp_unslash($_POST['series_scope'] ?? 'single'));
            if (!empty($booking->series_id) && $seriesScope === 'remaining') {
                return (new RecurringBookingService())->applyRemaining(
                    (int)$booking->id,
                    BookingStateMachine::USER_CANCELLED,
                    'user',
                    'Recurring series cancelled by visitor'
                );
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
                return new \WP_Error('wpcb_update_too_late', __('Änderung ist für diesen Termin nicht mehr möglich.', 'wordpress-calendar-booking'));
            }

            $newSlotToken = sanitize_text_field(wp_unslash($_POST['new_slot_token'] ?? ''));
            $selection = (new SlotSelectionService())->resolve(
                $newSlotToken,
                (int)$booking->booking_type_id,
                (int)$booking->id,
                max(1, (int)($booking->party_size ?? 1))
            );
            if (!$selection) {
                return new \WP_Error('wpcb_slot_unavailable', __('Der neue Slot ist ungültig, abgelaufen oder nicht mehr verfügbar.', 'wordpress-calendar-booking'));
            }

            $seriesScope = sanitize_key(wp_unslash($_POST['series_scope'] ?? 'single'));
            if (!empty($booking->series_id) && $seriesScope === 'remaining') {
                return (new RecurringBookingService())->rescheduleRemaining(
                    (int)$booking->id,
                    (string)$selection['start'],
                    (string)$selection['end'],
                    (int)$selection['resource_id'],
                    'user'
                );
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

        return new \WP_Error('wpcb_action_unknown', __('Unbekannte Termin-Aktion.', 'wordpress-calendar-booking'));
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
                $this->renderActionScreen(__('Status', 'wordpress-calendar-booking'), __('Diese E-Mail-Bestätigung ist für den aktuellen Buchungsstatus nicht verfügbar.', 'wordpress-calendar-booking'));
            }

            $form = $this->actionFormStart($action, $token)
                . '<p>' . esc_html__('Termin:', 'wordpress-calendar-booking') . ' <strong>' . esc_html($date . ' ' . $time) . '</strong></p>'
                . '<button class="uk-button uk-button-primary" type="submit">' . esc_html__('E-Mail bestätigen', 'wordpress-calendar-booking') . '</button></form>';
            $this->renderActionScreen(__('Terminbuchung bestätigen', 'wordpress-calendar-booking'), __('Bitte bestätige deine E-Mail-Adresse und damit die Terminbuchung.', 'wordpress-calendar-booking'), $form);
        }

        if ($action === 'cancel') {
            if (!(new BookingStateMachine())->canApply((string)$booking->status, BookingStateMachine::USER_CANCELLED)) {
                $this->renderActionScreen(__('Status', 'wordpress-calendar-booking'), __('Dieser Termin kann in seinem aktuellen Status nicht storniert werden.', 'wordpress-calendar-booking'));
            }
            $cutoff = Time::nowUtc()->modify('+' . max(0, (int)$settings['cancel_min_hours']) . ' hours');
            $start = Time::parseUtc((string)$booking->slot_start);
            if (!$start || $start < $cutoff) {
                $this->renderActionScreen(__('Stornierung nicht mehr möglich', 'wordpress-calendar-booking'), __('Die Stornofrist für diesen Termin ist abgelaufen.', 'wordpress-calendar-booking'));
            }

            $seriesControl = '';
            if (!empty($booking->series_id)) {
                $seriesPayment = (new PaymentService())->paymentForBooking((int)$booking->id);
                if ($seriesPayment) {
                    if ((int)($booking->series_occurrence ?? -1) !== 0) {
                        $this->renderActionScreen(
                            __('Bezahlte Terminserie', 'wordpress-calendar-booking'),
                            __('Eine bezahlte Terminserie kann derzeit nur vollständig über den ersten Termin der Serie storniert werden.', 'wordpress-calendar-booking')
                        );
                    }
                    $seriesControl = '<input type="hidden" name="series_scope" value="remaining">'
                        . '<p class="uk-alert-warning" uk-alert>'
                        . esc_html__('Diese Zahlung deckt die gesamte Terminserie ab. Die Stornierung betrifft daher die komplette Serie und löst die Rückerstattungsprüfung für die Gesamtzahlung aus.', 'wordpress-calendar-booking')
                        . '</p>';
                } else {
                    $seriesControl = '<label class="uk-form-label" for="wpcb-series-cancel-scope">' . esc_html__('Serienumfang', 'wordpress-calendar-booking') . '</label>'
                        . '<div class="uk-form-controls"><select class="uk-select" id="wpcb-series-cancel-scope" name="series_scope">'
                        . '<option value="single">' . esc_html__('Nur diesen Termin', 'wordpress-calendar-booking') . '</option>'
                        . '<option value="remaining">' . esc_html__('Diesen und alle folgenden Termine', 'wordpress-calendar-booking') . '</option>'
                        . '</select></div>';
                }
            }
            $form = $this->actionFormStart($action, $token)
                . '<p>' . esc_html__('Termin:', 'wordpress-calendar-booking') . ' <strong>' . esc_html($date . ' ' . $time) . '</strong></p>'
                . $seriesControl
                . '<button class="uk-button uk-button-danger uk-margin-top" type="submit">' . esc_html__('Termin verbindlich stornieren', 'wordpress-calendar-booking') . '</button></form>';
            $this->renderActionScreen(__('Termin stornieren', 'wordpress-calendar-booking'), __('Der Termin wird erst nach dem Klick auf den Button storniert.', 'wordpress-calendar-booking'), $form);
        }

        if ($action === 'update') {
            if (!in_array((string)$booking->status, [BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED], true)) {
                $this->renderActionScreen(__('Status', 'wordpress-calendar-booking'), __('Dieser Termin kann in seinem aktuellen Status nicht geändert werden.', 'wordpress-calendar-booking'));
            }
            $cutoff = Time::nowUtc()->modify('+' . max(0, (int)$settings['change_min_hours']) . ' hours');
            $start = Time::parseUtc((string)$booking->slot_start);
            if (!$start || $start < $cutoff) {
                $this->renderActionScreen(__('Änderung nicht mehr möglich', 'wordpress-calendar-booking'), __('Die Änderungsfrist für diesen Termin ist abgelaufen.', 'wordpress-calendar-booking'));
            }

            $slots = (new SlotService())->getSlots(
                (int)$booking->booking_type_id,
                14,
                (int)$booking->id,
                max(1, (int)($booking->party_size ?? 1))
            );
            if (!$slots) {
                $this->renderActionScreen(__('Keine freien Alternativen', 'wordpress-calendar-booking'), __('Aktuell ist kein alternativer Termin verfügbar.', 'wordpress-calendar-booking'));
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
                $this->renderActionScreen(__('Keine freien Alternativen', 'wordpress-calendar-booking'), __('Aktuell ist kein alternativer Termin verfügbar.', 'wordpress-calendar-booking'));
            }

            $seriesControl = '';
            if (!empty($booking->series_id)) {
                $seriesControl = '<label class="uk-form-label" for="wpcb-series-update-scope">' . esc_html__('Serienumfang', 'wordpress-calendar-booking') . '</label>'
                    . '<div class="uk-form-controls"><select class="uk-select" id="wpcb-series-update-scope" name="series_scope">'
                    . '<option value="single">' . esc_html__('Nur diesen Termin', 'wordpress-calendar-booking') . '</option>'
                    . '<option value="remaining">' . esc_html__('Diesen und alle folgenden Termine', 'wordpress-calendar-booking') . '</option>'
                    . '</select></div>';
            }
            $form = $this->actionFormStart($action, $token)
                . '<p>' . esc_html__('Aktuell:', 'wordpress-calendar-booking') . ' <strong>' . esc_html($date . ' ' . $time) . '</strong></p>'
                . $seriesControl
                . '<label class="uk-form-label uk-margin-top" for="wpcb-new-slot">' . esc_html__('Neuer Termin', 'wordpress-calendar-booking') . '</label>'
                . '<div class="uk-form-controls"><select class="uk-select" id="wpcb-new-slot" name="new_slot_token" required>'
                . '<option value="">' . esc_html__('Bitte wählen', 'wordpress-calendar-booking') . '</option>' . $options . '</select></div>'
                . '<p><button class="uk-button uk-button-primary" type="submit">' . esc_html__('Termin ändern', 'wordpress-calendar-booking') . '</button></p></form>';
            $this->renderActionScreen(__('Termin ändern', 'wordpress-calendar-booking'), __('Die Änderung wird erst nach dem Absenden gespeichert.', 'wordpress-calendar-booking'), $form);
        }

        $this->renderActionScreen(__('Link ungültig', 'wordpress-calendar-booking'), __('Diese Termin-Aktion ist unbekannt.', 'wordpress-calendar-booking'));
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
            return __('Termin bereits storniert', 'wordpress-calendar-booking');
        }
        if ($action === 'confirm' && in_array($status, [BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED], true)) {
            return __('E-Mail bereits bestätigt', 'wordpress-calendar-booking');
        }
        if ($action === 'update') {
            return __('Änderungslink bereits verwendet', 'wordpress-calendar-booking');
        }
        return __('Link bereits verwendet', 'wordpress-calendar-booking');
    }

    private function usedMessage(string $action, string $status, object $booking): string {
        $settings = Settings::get();
        $when = Time::display((string)$booking->slot_start, $settings['date_format'] . ' ' . $settings['time_format']);
        if ($action === 'cancel' && $status === BookingStatus::CANCELLED) {
            return __('Die Buchung ist bereits storniert. Es wurde keine weitere Änderung vorgenommen.', 'wordpress-calendar-booking');
        }
        if ($action === 'confirm' && $status === BookingStatus::PENDING_APPROVAL) {
            return __('Die E-Mail ist bereits bestätigt. Der Termin wartet auf Freigabe.', 'wordpress-calendar-booking');
        }
        if ($action === 'confirm' && $status === BookingStatus::CONFIRMED) {
            return __('Die E-Mail ist bereits bestätigt und der Termin ist bestätigt.', 'wordpress-calendar-booking');
        }
        if ($action === 'update') {
            return sprintf(__('Dieser Änderungslink wurde bereits verwendet. Aktueller Termin: %s.', 'wordpress-calendar-booking'), $when);
        }
        return __('Dieser Link wurde bereits verwendet. Es wurde keine weitere Änderung vorgenommen.', 'wordpress-calendar-booking');
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
