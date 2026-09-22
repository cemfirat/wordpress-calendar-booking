<?php
namespace Cemb\Mail;

use Cemb\Admin\Settings;
use Cemb\ICS\IcsGenerator;
use Cemb\Support\BookingFormatter;
use Cemb\Booking\BookingTypeRepository;
use Cemb\Support\Time;

class Mailer {
    private BookingFormatter $formatter;

    public function __construct() {
        $this->formatter = new BookingFormatter();
    }

    public function sendTemplate(string $key, array $booking, array $meta, array $links = [], bool $attachIcs = false): bool {
        $templates = get_option('cemb_email_templates', []);
        $subject = $templates[$key . '_subject'] ?? $key;
        $body = $templates[$key . '_body'] ?? '';
        $typeRepo = new BookingTypeRepository();
        $type = $typeRepo->find((int)$booking['booking_type_id']);
        $settings = Settings::get();
        $displayName = $this->formatter->displayName($booking, $meta);
        $resolvedLocation = $this->formatter->location($booking, $meta, $settings);
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
            $path = wp_upload_dir()['basedir'] . '/cemb-' . md5(($booking['booking_uuid'] ?? '') . time()) . '.ics';
            file_put_contents($path, $ics);
            $attachments[] = $path;
        }
        $sent = wp_mail($booking['email'], $subject, $body, $headers, $attachments);
        foreach ($attachments as $file) {
            @unlink($file);
        }
        return $sent;
    }

    public function sendInternal(array $booking, array $meta): void {
        $settings = Settings::get();
        if (empty($settings['notifications_enabled']) || empty($settings['notification_emails'])) return;
        $templates = get_option('cemb_email_templates', []);
        $typeRepo = new BookingTypeRepository();
        $type = $typeRepo->find((int)$booking['booking_type_id']);
        $map = [
            '{status}' => $booking['status'] ?? '',
            '{name}' => $this->formatter->displayName($booking, $meta),
            '{email}' => $booking['email'] ?? '',
            '{terminart}' => $type->name ?? '',
            '{datum}' => Time::display((string)$booking['slot_start'], $settings['date_format']),
            '{uhrzeit}' => Time::display((string)$booking['slot_start'], $settings['time_format']),
            '{betreff}' => (string)($meta['subject'] ?? ''),
            '{ort}' => $this->formatter->location($booking, $meta, $settings),
        ];
        $subject = strtr($templates['internal_subject'] ?? 'Neue Termin-Aktion', $map);
        $body = nl2br(esc_html(strtr($templates['internal_body'] ?? '', $map)));
        wp_mail(array_map('trim', explode(',', $settings['notification_emails'])), $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }
}
