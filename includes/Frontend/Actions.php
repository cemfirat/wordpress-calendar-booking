<?php
namespace Cemb\Frontend;

use Cemb\Security\Guard;
use Cemb\Forms\FieldRepository;
use Cemb\Booking\BookingRepository;
use Cemb\Booking\BookingStatus;
use Cemb\Tokens\TokenService;
use Cemb\Mail\Mailer;
use Cemb\Availability\SlotService;
use Cemb\Admin\Settings;
use Cemb\Booking\BookingTypeRepository;
use Cemb\Sync\QueueService;
use Cemb\Support\BookingFormatter;

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
        $data = array_map(static function ($slot) {
            return [
                'value' => $slot['start'],
                'label' => $slot['label'],
                'end' => $slot['end'],
            ];
        }, $slots);
        wp_send_json_success(['slots' => $data]);
    }

    public function submitBooking(): void {
        [$ok, $message] = (new Guard())->checkSubmission($_POST);
        if (!$ok) wp_die(esc_html($message));

        $typeId = isset($_POST['booking_type_id']) ? absint($_POST['booking_type_id']) : 0;
        $start = isset($_POST['slot_start']) ? sanitize_text_field(wp_unslash($_POST['slot_start'])) : '';
        if (!$typeId || !$start) wp_die('Ungültiger Termin.');

        $type = (new BookingTypeRepository())->find($typeId);
        if (!$type) wp_die('Terminart nicht gefunden.');
        $duration = max(1, (int)$type->duration_minutes);
        $end = date('Y-m-d H:i:s', strtotime($start . ' +' . $duration . ' minutes'));
        if (!(new SlotService())->slotAvailable($typeId, $start, $end)) wp_die('Der gewählte Slot ist leider nicht mehr verfügbar.');

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

        $repo = new BookingRepository();
        $bookingId = $repo->create([
            'booking_uuid' => wp_generate_uuid4(),
            'booking_type_id' => $typeId,
            'slot_start' => $start,
            'slot_end' => $end,
            'status' => BookingStatus::EMAIL_UNCONFIRMED,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'notes' => isset($meta['message']) ? (string)$meta['message'] : '',
            'source' => 'frontend',
            'lang' => 'de',
            'reserved_until' => date('Y-m-d H:i:s', strtotime('+' . (int)$settings['reservation_ttl_minutes'] . ' minutes', current_time('timestamp'))),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], $meta);
        $booking = (array)$repo->find($bookingId);
        $tokenService = new TokenService();
        $doiToken = $tokenService->create($bookingId, 'doi', (int)$settings['token_ttl_minutes']);
        $cancelToken = $tokenService->create($bookingId, 'cancel', 60 * 24 * 30);
        $updateToken = $tokenService->create($bookingId, 'update', 60 * 24 * 30);
        $links = [
            'confirm' => add_query_arg(['cemb_action' => 'confirm', 'cemb_token' => rawurlencode($doiToken)], home_url('/')),
            'cancel' => add_query_arg(['cemb_action' => 'cancel', 'cemb_token' => rawurlencode($cancelToken)], home_url('/')),
            'update' => add_query_arg(['cemb_action' => 'update', 'cemb_token' => rawurlencode($updateToken)], home_url('/')),
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
        $mailer = new Mailer();
        $settings = Settings::get();
        $queue = new QueueService();

        if ($action === 'confirm') {
            $row = $tokenService->validate((string)$token, 'doi');
            if (!$row) wp_die('Bestätigungslink ungültig oder abgelaufen.');
            $booking = $repo->find((int)$row->booking_id);
            if (!$booking) wp_die('Buchung nicht gefunden.');
            if (!(new SlotService())->slotAvailable((int)$booking->booking_type_id, $booking->slot_start, $booking->slot_end, (int)$booking->id)) wp_die('Der Slot ist leider nicht mehr verfügbar.');
            $tokenService->markUsed((int)$row->id);
            $meta = $repo->getMeta((int)$booking->id);
            $cancelToken = $tokenService->create((int)$booking->id, 'cancel', 60 * 24 * 30);
            $updateToken = $tokenService->create((int)$booking->id, 'update', 60 * 24 * 30);
            $links = [
                'cancel' => add_query_arg(['cemb_action' => 'cancel', 'cemb_token' => rawurlencode($cancelToken)], home_url('/')),
                'update' => add_query_arg(['cemb_action' => 'update', 'cemb_token' => rawurlencode($updateToken)], home_url('/')),
            ];
            if ($settings['mode'] === 'approval') {
                $repo->update((int)$booking->id, ['confirmed_at' => current_time('mysql'), 'reserved_until' => null]);
                $repo->updateStatus((int)$booking->id, BookingStatus::PENDING_ADMIN_APPROVAL, 'doi_confirmed', 'user', 'Double-Opt-In bestätigt');
                $booking = (array)$repo->find((int)$booking->id);
                $mailer->sendTemplate('pending', $booking, $meta, $links, false);
            } else {
                $repo->update((int)$booking->id, ['confirmed_at' => current_time('mysql'), 'approved_at' => current_time('mysql'), 'reserved_until' => null]);
                $repo->updateStatus((int)$booking->id, BookingStatus::CONFIRMED, 'doi_confirmed', 'user', 'Automatisch bestätigt');
                $booking = (array)$repo->find((int)$booking->id);
                $queue->enqueueCreate((int)$booking['id']);
                $queue->runNow();
                $mailer->sendTemplate('confirmed', $booking, $meta, $links, true);
            }
            $mailer->sendInternal((array)$repo->find((int)$booking['id']), $meta);
            wp_die('Danke. Deine E-Mail wurde bestätigt.');
        }

        if ($action === 'cancel') {
            $row = $tokenService->validate((string)$token, 'cancel');
            if (!$row) wp_die('Stornolink ungültig oder abgelaufen.');
            $booking = $repo->find((int)$row->booking_id);
            if (!$booking) wp_die('Buchung nicht gefunden.');
            if (strtotime($booking->slot_start) < strtotime('+' . (int)$settings['cancel_min_hours'] . ' hours', current_time('timestamp'))) wp_die('Stornierung ist für diesen Termin nicht mehr möglich.');
            $tokenService->markUsed((int)$row->id);
            $repo->update((int)$booking->id, ['cancelled_at' => current_time('mysql'), 'reserved_until' => null]);
            $repo->updateStatus((int)$booking->id, BookingStatus::CANCELLED, 'cancel', 'user', 'Vom Nutzer storniert');
            $meta = $repo->getMeta((int)$booking->id);
            if (!empty($settings['icloud_sync_cancellations'])) {
                $queue->enqueueCancel((int)$booking->id);
                $queue->runNow();
            }
            $mailer->sendTemplate('cancelled', (array)$repo->find((int)$booking->id), $meta, [], false);
            $mailer->sendInternal((array)$repo->find((int)$booking->id), $meta);
            wp_die('Dein Termin wurde storniert.');
        }

        if ($action === 'update') {
            $row = $tokenService->validate((string)$token, 'update');
            if (!$row) wp_die('Änderungslink ungültig oder abgelaufen.');
            $booking = $repo->find((int)$row->booking_id);
            if (!$booking) wp_die('Buchung nicht gefunden.');
            if (strtotime($booking->slot_start) < strtotime('+' . (int)$settings['change_min_hours'] . ' hours', current_time('timestamp'))) wp_die('Änderung ist für diesen Termin nicht mehr möglich.');
            if (!empty($_POST['cemb_update_slot'])) {
                check_admin_referer('cemb_update_booking');
                $newStart = sanitize_text_field(wp_unslash($_POST['new_slot'] ?? ''));
                $type = (new BookingTypeRepository())->find((int)$booking->booking_type_id);
                $duration = max(1, (int)($type->duration_minutes ?? 30));
                $newEnd = date('Y-m-d H:i:s', strtotime($newStart . ' +' . $duration . ' minutes'));
                if (!(new SlotService())->slotAvailable((int)$booking->booking_type_id, $newStart, $newEnd, (int)$booking->id)) wp_die('Der neue Slot ist nicht mehr verfügbar.');
                $repo->update((int)$booking->id, ['slot_start' => $newStart, 'slot_end' => $newEnd, 'updated_at_user' => current_time('mysql')]);
                $repo->updateStatus((int)$booking->id, BookingStatus::UPDATED, 'update', 'user', 'Termin geändert');
                $meta = $repo->getMeta((int)$booking->id);
                if (!empty($settings['icloud_sync_updates'])) {
                    $queue->enqueueUpdate((int)$booking->id);
                    $queue->runNow();
                }
                $mailer->sendTemplate('updated', (array)$repo->find((int)$booking->id), $meta, [], true);
                $mailer->sendInternal((array)$repo->find((int)$booking->id), $meta);
                wp_die('Termin erfolgreich geändert.');
            }
            $slots = (new SlotService())->getSlots((int)$booking->booking_type_id, 14);
            echo '<div style="max-width:700px;margin:40px auto;font-family:sans-serif"><h1>Termin ändern</h1><form method="post">';
            wp_nonce_field('cemb_update_booking');
            echo '<select name="new_slot" required>';
            foreach ($slots as $slot) echo '<option value="' . esc_attr($slot['start']) . '">' . esc_html($slot['label']) . '</option>';
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
            $diffHours = (strtotime($booking->slot_start) - current_time('timestamp')) / 3600;
            if ($diffHours <= (int)$settings['reminder_hours'] && $diffHours > ((int)$settings['reminder_hours'] - 1)) {
                $meta = $repo->getMeta((int)$booking->id);
                (new Mailer())->sendTemplate('reminder', (array)$booking, $meta, [], false);
            }
        }
    }
}
