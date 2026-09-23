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
        bool $attachIcs = false
    ): bool {
        return $this->sendTemplateTracked(
            $idempotencyKey,
            $key,
            $booking,
            $meta,
            $links,
            $attachIcs,
            true
        );
    }

    public function retryTemplateOnce(
        string $idempotencyKey,
        string $key,
        array $booking,
        array $meta,
        array $links = [],
        bool $attachIcs = false
    ): bool {
        return $this->sendTemplateTracked(
            $idempotencyKey,
            $key,
            $booking,
            $meta,
            $links,
            $attachIcs,
            false
        );
    }

    public function sendInternalOnce(string $idempotencyKey, array $booking, array $meta): bool {
        return $this->sendInternalTracked($idempotencyKey, $booking, $meta, true);
    }

    public function retryInternalOnce(string $idempotencyKey, array $booking, array $meta): bool {
        return $this->sendInternalTracked($idempotencyKey, $booking, $meta, false);
    }

    private function sendTemplateTracked(
        string $idempotencyKey,
        string $key,
        array $booking,
        array $meta,
        array $links,
        bool $attachIcs,
        bool $enqueueRetry
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
            $sent = $this->sendTemplate($key, $booking, $meta, $links, $attachIcs);
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
                    (string)($booking['status'] ?? '')
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
        bool $enqueueRetry
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
            $sent = $this->sendInternal($booking, $meta);
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
                    (string)($booking['status'] ?? '')
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

    public function sendTemplate(string $key, array $booking, array $meta, array $links = [], bool $attachIcs = false): bool {
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
        $body = nl2br(esc_html(strtr($body, $replacements)));
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (!empty($settings['sender_name']) && !empty($settings['sender_email'])) {
            $headers[] = 'From: ' . $settings['sender_name'] . ' <' . $settings['sender_email'] . '>';
        }
        $attachments = [];
        if ($attachIcs) {
            $summary = $this->formatter->summary($booking, $meta);
            $ics = (new IcsGenerator())->generate($booking, $meta, $summary, $resolvedLocation);
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

    public function sendInternal(array $booking, array $meta): bool {
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
        $body = nl2br(esc_html(strtr($templates['internal_body'] ?? '', $map)));
        return wp_mail(array_map('trim', explode(',', $settings['notification_emails'])), $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }
}
