<?php
namespace Cemb\Frontend;

use Cemb\Security\Guard;
use Cemb\Forms\FieldRepository;
use Cemb\Booking\BookingRepository;
use Cemb\Booking\BookingStatus;
use Cemb\Booking\BookingStateMachine;
use Cemb\Booking\BookingTransitionService;
use Cemb\Booking\ReservationService;
use Cemb\Tokens\TokenService;
use Cemb\Tokens\SlotTokenService;
use Cemb\Mail\Mailer;
use Cemb\Availability\SlotService;
use Cemb\Availability\SlotSelectionService;
use Cemb\Admin\Settings;
use Cemb\Booking\BookingTypeRepository;
use Cemb\Support\BookingFormatter;
use Cemb\Support\Time;

class Actions {
    private BookingFormatter $formatter;

    public function __construct() {
        $this->formatter = new BookingFormatter();
    }

    public function boot(): void {
        add_action('admin_post_nopriv_cemb_submit_booking', [$this, 'submitBooking']);
        add_action('admin_post_cemb_submit_booking', [$this, 'submitBooking']);
        add_action('init', [$this, 'handleLinks']);
        add_filter('query_vars', [$this, 'queryVars']);
        add_action('wp_ajax_cemb_get_slots', [$this, 'ajaxSlots']);
        add_action('wp_ajax_nopriv_cemb_get_slots', [$this, 'ajaxSlots']);
        add_action('cemb_hourly_reminders', [$this, 'sendReminders']);
        if (!wp_next_scheduled('cemb_hourly_reminders')) {
            wp_schedule_event(time() + 300, 'hourly', 'cemb_hourly_reminders');
        }
    }

    public function queryVars(array $vars): array {
        $vars[] = 'cemb_action';
        $vars[] = 'cemb_token';
        return $vars;
    }

    public function ajaxSlots(): void {
        check_ajax_referer('cemb_frontend', 'nonce');
        $typeId = absint($_REQUEST['type_id'] ?? 0);
        if (!$typeId) {
            wp_send_json_error(['message' => 'Terminart fehlt.'], 400);
        }
        $slots = (new SlotService())->getSlots($typeId, 21);
        $tokens = new SlotTokenService();
        $data = array_map(static function ($slot) use ($typeId, $tokens) {
            return [
                'value' => $tokens->issue($typeId, $slot['start'], $slot['end']),
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
            'confirm' => add_query_arg(
                ['cemb_action' => 'confirm', 'cemb_token' => rawurlencode($doiToken)],
                home_url('/')
            ),
        ];
        (new Mailer())->sendTemplate('doi', $booking, $meta, $links, false);
        (new Mailer())->sendInternal($booking, $meta);
        wp_safe_redirect(add_query_arg('cemb_notice', rawurlencode('Bitte bestätige deine E-Mail über den Link in der Nachricht.'), wp_get_referer() ?: home_url('/')));
        exit;
    }

    public function handleLinks(): void {
        $action = get_query_var('cemb_action');
        $token = get_query_var('cemb_token');
        if (!$action || !$token) return;

        $tokenService = new TokenService();
        $repo = new BookingRepository();
        $settings = Settings::get();
        $transitions = new BookingTransitionService();

        if ($action === 'confirm') {
            $row = $tokenService->validate((string)$token, 'doi');
            if (!$row) wp_die('Bestätigungslink ungültig oder abgelaufen.');

            $event = $settings['mode'] === 'approval'
                ? BookingStateMachine::EMAIL_CONFIRMED_APPROVAL
                : BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC;

            $result = $transitions->apply(
                (int)$row->booking_id,
                $event,
                'user',
                'Double-Opt-In confirmed'
            );
            if (is_wp_error($result)) {
                wp_die(esc_html($result->get_error_message()));
            }

            $tokenService->markUsed((int)$row->id);
            wp_die('Danke. Deine E-Mail wurde bestätigt.');
        }

        if ($action === 'cancel') {
            $row = $tokenService->validate((string)$token, 'cancel');
            if (!$row) wp_die('Stornolink ungültig oder abgelaufen.');
            $booking = $repo->find((int)$row->booking_id);
            if (!$booking) wp_die('Buchung nicht gefunden.');

            $cancelCutoff = Time::nowUtc()->modify('+' . max(0, (int)$settings['cancel_min_hours']) . ' hours');
            $bookingStart = Time::parseUtc((string)$booking->slot_start);
            if (!$bookingStart || $bookingStart < $cancelCutoff) {
                wp_die('Stornierung ist für diesen Termin nicht mehr möglich.');
            }

            $result = $transitions->apply(
                (int)$booking->id,
                BookingStateMachine::USER_CANCELLED,
                'user',
                'Booking cancelled by visitor'
            );
            if (is_wp_error($result)) {
                wp_die(esc_html($result->get_error_message()));
            }

            $tokenService->markUsed((int)$row->id);
            wp_die('Dein Termin wurde storniert.');
        }

        if ($action === 'update') {
            $row = $tokenService->validate((string)$token, 'update');
            if (!$row) wp_die('Änderungslink ungültig oder abgelaufen.');
            $booking = $repo->find((int)$row->booking_id);
            if (!$booking) wp_die('Buchung nicht gefunden.');

            $changeCutoff = Time::nowUtc()->modify('+' . max(0, (int)$settings['change_min_hours']) . ' hours');
            $bookingStart = Time::parseUtc((string)$booking->slot_start);
            if (!$bookingStart || $bookingStart < $changeCutoff) {
                wp_die('Änderung ist für diesen Termin nicht mehr möglich.');
            }

            if (!empty($_POST['cemb_update_slot'])) {
                check_admin_referer('cemb_update_booking');
                $newSlotToken = sanitize_text_field(wp_unslash($_POST['new_slot_token'] ?? ''));
                $selection = (new SlotSelectionService())->resolve(
                    $newSlotToken,
                    (int)$booking->booking_type_id,
                    (int)$booking->id
                );
                if (!$selection) {
                    wp_die('Der neue Slot ist ungültig, abgelaufen oder nicht mehr verfügbar.');
                }

                $result = $transitions->reschedule(
                    (int)$booking->id,
                    (string)$selection['start'],
                    (string)$selection['end'],
                    'user',
                    'Booking rescheduled by visitor'
                );
                if (is_wp_error($result)) {
                    wp_die(esc_html($result->get_error_message()));
                }

                wp_die('Termin erfolgreich geändert.');
            }

            if (!in_array((string)$booking->status, [BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED], true)) {
                wp_die('Dieser Termin kann in seinem aktuellen Status nicht geändert werden.');
            }

            $slots = (new SlotService())->getSlots((int)$booking->booking_type_id, 14, (int)$booking->id);
            $slotTokens = new SlotTokenService();
            echo '<div style="max-width:700px;margin:40px auto;font-family:sans-serif"><h1>Termin ändern</h1><form method="post">';
            wp_nonce_field('cemb_update_booking');
            echo '<select name="new_slot_token" required>';
            foreach ($slots as $slot) {
                $optionToken = $slotTokens->issue((int)$booking->booking_type_id, $slot['start'], $slot['end']);
                echo '<option value="' . esc_attr($optionToken) . '">' . esc_html($slot['label']) . '</option>';
            }
            echo '</select><p><button type="submit" name="cemb_update_slot" value="1">Termin ändern</button></p></form></div>';
            exit;
        }
    }

    public function sendReminders(): void {
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
                (new Mailer())->sendTemplate('reminder', (array)$booking, $meta, [], false);
            }
        }
    }
}
