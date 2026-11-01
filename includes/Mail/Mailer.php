<?php
namespace Wpcb\Mail;

use Wpcb\Admin\Settings;
use Wpcb\ICS\IcsGenerator;
use Wpcb\Support\BookingFormatter;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Support\Time;
use Wpcb\Reliability\DeliveryRepository;

class Mailer {
    private BookingFormatter $formatter;

    public function __construct() {
        $this->formatter = new BookingFormatter();
    }


    public function sendTemplateOnce(
        string $idempotencyKey,
        string $key,
        array $booking,
        array $meta,
        array $links = [],
        bool $attachIcs = false,
        array $seriesBookingIds = []
    ): bool {
        return $this->sendTemplateTracked(
            $idempotencyKey,
            $key,
            $booking,
            $meta,
            $links,
            $attachIcs,
            true,
            $seriesBookingIds
        );
    }

    public function retryTemplateOnce(
        string $idempotencyKey,
        string $key,
        array $booking,
        array $meta,
        array $links = [],
        bool $attachIcs = false,
        array $seriesBookingIds = []
    ): bool {
        return $this->sendTemplateTracked(
            $idempotencyKey,
            $key,
            $booking,
            $meta,
            $links,
            $attachIcs,
            false,
            $seriesBookingIds
        );
    }

    public function sendInternalOnce(string $idempotencyKey, array $booking, array $meta, array $seriesBookingIds = []): bool {
        return $this->sendInternalTracked($idempotencyKey, $booking, $meta, true, $seriesBookingIds);
    }

    public function retryInternalOnce(string $idempotencyKey, array $booking, array $meta, array $seriesBookingIds = []): bool {
        return $this->sendInternalTracked($idempotencyKey, $booking, $meta, false, $seriesBookingIds);
    }

    private function sendTemplateTracked(
        string $idempotencyKey,
        string $key,
        array $booking,
        array $meta,
        array $links,
        bool $attachIcs,
        bool $enqueueRetry,
        array $seriesBookingIds
    ): bool {
        $bookingId = (int)($booking['id'] ?? 0);
        if ($bookingId < 1 || $idempotencyKey === '') {
            return false;
        }

        $deliveries = new DeliveryRepository();
        $delivery = $deliveries->begin(
            $bookingId,
            $idempotencyKey,
            'email',
            'template:' . $key,
            'customer',
            'wp_mail'
        );
        if (empty($delivery['should_run'])) {
            return in_array((string)($delivery['status'] ?? ''), ['sending', 'sent', 'uncertain'], true);
        }
        if (!$deliveries->markSending((int)$delivery['id'])) {
            $current = $deliveries->findByKey($idempotencyKey);
            return $current && in_array((string)$current->status, ['sending', 'sent', 'uncertain'], true);
        }

        try {
            $seriesBookings = $this->seriesBookings((int)($booking['id'] ?? 0), $seriesBookingIds);
            $sent = $this->sendTemplate($key, $booking, $meta, $links, $attachIcs, $seriesBookings);
            if ($sent) {
                $deliveries->markSent((int)$delivery['id']);
                return true;
            }

            $deliveries->markFailed(
                (int)$delivery['id'],
                'wp_mail returned false before accepting the message.',
                'wp_mail_false'
            );
            if ($enqueueRetry) {
                $queued = (new EmailRetryJobRunner())->enqueueTemplate(
                    $bookingId,
                    $idempotencyKey,
                    $key,
                    $attachIcs,
                    (string)($booking['status'] ?? ''),
                    $seriesBookingIds
                );
                if ($queued < 1) {
                    $deliveries->markFailed(
                        (int)$delivery['id'],
                        'wp_mail returned false and the retry job could not be queued.',
                        'mail_retry_queue_failed'
                    );
                }
            }
            return false;
        } catch (\Throwable $error) {
            $deliveries->markUncertain(
                (int)$delivery['id'],
                $error->getMessage(),
                'mail_transport_uncertain'
            );
            return false;
        }
    }

    private function sendInternalTracked(
        string $idempotencyKey,
        array $booking,
        array $meta,
        bool $enqueueRetry,
        array $seriesBookingIds
    ): bool {
        $bookingId = (int)($booking['id'] ?? 0);
        $settings = Settings::get();
        if (empty($settings['notifications_enabled']) || empty($settings['notification_emails'])) {
            return true;
        }
        if ($bookingId < 1 || $idempotencyKey === '') {
            return false;
        }

        $deliveries = new DeliveryRepository();
        $delivery = $deliveries->begin(
            $bookingId,
            $idempotencyKey,
            'email',
            'internal',
            'admin',
            'wp_mail'
        );
        if (empty($delivery['should_run'])) {
            return in_array((string)($delivery['status'] ?? ''), ['sending', 'sent', 'uncertain'], true);
        }
        if (!$deliveries->markSending((int)$delivery['id'])) {
            $current = $deliveries->findByKey($idempotencyKey);
            return $current && in_array((string)$current->status, ['sending', 'sent', 'uncertain'], true);
        }

        try {
            $seriesBookings = $this->seriesBookings((int)($booking['id'] ?? 0), $seriesBookingIds);
            $sent = $this->sendInternal($booking, $meta, $seriesBookings);
            if ($sent) {
                $deliveries->markSent((int)$delivery['id']);
                return true;
            }

            $deliveries->markFailed(
                (int)$delivery['id'],
                'wp_mail returned false before accepting the internal message.',
                'wp_mail_false'
            );
            if ($enqueueRetry) {
                $queued = (new EmailRetryJobRunner())->enqueueInternal(
                    $bookingId,
                    $idempotencyKey,
                    (string)($booking['status'] ?? ''),
                    $seriesBookingIds
                );
                if ($queued < 1) {
                    $deliveries->markFailed(
                        (int)$delivery['id'],
                        'wp_mail returned false and the internal retry job could not be queued.',
                        'mail_retry_queue_failed'
                    );
                }
            }
            return false;
        } catch (\Throwable $error) {
            $deliveries->markUncertain(
                (int)$delivery['id'],
                $error->getMessage(),
                'mail_transport_uncertain'
            );
            return false;
        }
    }

    public function sendTemplate(
        string $key,
        array $booking,
        array $meta,
        array $links = [],
        bool $attachIcs = false,
        array $seriesBookings = []
    ): bool {
        $templates = get_option('wpcb_email_templates', []);
        $subject = $templates[$key . '_subject'] ?? $key;
        $body = $templates[$key . '_body'] ?? '';
        $typeRepo = new BookingTypeRepository();
        $type = $typeRepo->find((int)$booking['booking_type_id']);
        $settings = Settings::get();
        $displayName = $this->formatter->displayName($booking, $meta);
        $resolvedLocation = $this->formatter->location($booking, $meta, $settings);
        $meetingLink = (new \Wpcb\VideoMeetings\VideoMeetingRepository())->firstJoinUrl((int)($booking['id'] ?? 0));
        $replacements = [
            '{name}' => $displayName,
            '{email}' => $booking['email'] ?? '',
            '{telefon}' => $booking['phone'] ?? '',
            '{terminart}' => $type->name ?? '',
            '{datum}' => Time::display((string)$booking['slot_start'], $settings['date_format']),
            '{uhrzeit}' => Time::display((string)$booking['slot_start'], $settings['time_format']),
            '{start}' => $booking['slot_start'] ?? '',
            '{ende}' => $booking['slot_end'] ?? '',
            '{slot}' => ($booking['slot_start'] ?? '') . ' - ' . ($booking['slot_end'] ?? ''),
            '{bestaetigungslink}' => $links['confirm'] ?? '',
            '{stornolink}' => $links['cancel'] ?? '',
            '{aenderungslink}' => $links['update'] ?? '',
            '{status}' => $booking['status'] ?? '',
            '{betreff}' => (string)($meta['subject'] ?? ''),
            '{ort}' => $resolvedLocation,
            '{teilnehmer}' => (string)max(1, (int)($booking['party_size'] ?? 1)),
            '{meeting_link}' => $meetingLink,
        ];
        $subject = strtr($subject, $replacements);
        $body = strtr($body, $replacements);
        if ($seriesBookings) {
            $body .= "\n\nBetroffene Termine:\n" . $this->seriesDateSummary($seriesBookings, $settings);
        }
        $body = nl2br(esc_html($body));
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (!empty($settings['sender_name']) && !empty($settings['sender_email'])) {
            $headers[] = 'From: ' . $settings['sender_name'] . ' <' . $settings['sender_email'] . '>';
        }
        $attachments = [];
        if ($attachIcs) {
            if ($seriesBookings) {
                $ics = (new IcsGenerator())->generateSeries($this->seriesIcsEvents($seriesBookings, $settings));
            } else {
                $summary = $this->formatter->summary($booking, $meta);
                $ics = (new IcsGenerator())->generate($booking, $meta, $summary, $resolvedLocation);
            }
            $path = wp_upload_dir()['basedir'] . '/wpcb-' . md5(($booking['booking_uuid'] ?? '') . time()) . '.ics';
            file_put_contents($path, $ics);
            $attachments[] = $path;
        }
        try {
            return wp_mail($booking['email'], $subject, $body, $headers, $attachments);
        } finally {
            foreach ($attachments as $file) {
                @unlink($file);
            }
        }
    }

    public function sendInternal(array $booking, array $meta, array $seriesBookings = []): bool {
        $settings = Settings::get();
        if (empty($settings['notifications_enabled']) || empty($settings['notification_emails'])) return true;
        $templates = get_option('wpcb_email_templates', []);
        $typeRepo = new BookingTypeRepository();
        $type = $typeRepo->find((int)$booking['booking_type_id']);
        $meetingLink = (new \Wpcb\VideoMeetings\VideoMeetingRepository())->firstJoinUrl((int)($booking['id'] ?? 0));
        $map = [
            '{status}' => $booking['status'] ?? '',
            '{name}' => $this->formatter->displayName($booking, $meta),
            '{email}' => $booking['email'] ?? '',
            '{terminart}' => $type->name ?? '',
            '{datum}' => Time::display((string)$booking['slot_start'], $settings['date_format']),
            '{uhrzeit}' => Time::display((string)$booking['slot_start'], $settings['time_format']),
            '{betreff}' => (string)($meta['subject'] ?? ''),
            '{ort}' => $this->formatter->location($booking, $meta, $settings),
            '{teilnehmer}' => (string)max(1, (int)($booking['party_size'] ?? 1)),
            '{meeting_link}' => $meetingLink,
        ];
        $subject = strtr($templates['internal_subject'] ?? 'Neue Termin-Aktion', $map);
        $body = strtr($templates['internal_body'] ?? '', $map);
        if ($seriesBookings) {
            $body .= "\n\nBetroffene Termine:\n" . $this->seriesDateSummary($seriesBookings, $settings);
        }
        $body = nl2br(esc_html($body));
        return wp_mail(array_map('trim', explode(',', $settings['notification_emails'])), $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    private function seriesBookings(int $bookingId, array $ids): array {
        if ($bookingId < 1 || !$ids) {
            return [];
        }
        $bookings = new \Wpcb\Booking\BookingRepository();
        $anchor = $bookings->find($bookingId);
        if (!$anchor || empty($anchor->series_id)) {
            return [];
        }
        $members = [];
        foreach (array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 24) as $id) {
            $member = $bookings->find($id);
            if ($member && (int)$member->series_id === (int)$anchor->series_id) {
                $members[] = ['booking' => (array)$member, 'meta' => $bookings->getMeta($id)];
            }
        }
        return $members;
    }

    private function seriesDateSummary(array $seriesBookings, array $settings): string {
        $timezone = Time::bookingTimezone()->getName();
        $lines = [];
        foreach ($seriesBookings as $item) {
            $booking = $item['booking'];
            $lines[] = Time::display((string)$booking['slot_start'], $settings['date_format'] . ' ' . $settings['time_format'])
                . '–' . Time::display((string)$booking['slot_end'], $settings['time_format'])
                . ' (' . $this->formatter->location($booking, $item['meta'], $settings) . ')';
        }
        return implode("\n", $lines) . "\nZeitzone: " . $timezone;
    }

    private function seriesIcsEvents(array $seriesBookings, array $settings): array {
        $events = [];
        foreach ($seriesBookings as $item) {
            $booking = $item['booking'];
            $events[] = [
                'booking' => $booking,
                'meta' => $item['meta'],
                'title' => $this->formatter->summary($booking, $item['meta']),
                'location' => $this->formatter->location($booking, $item['meta'], $settings),
            ];
        }
        return $events;
    }
}
