<?php
namespace Wpcb\ICS;

use Wpcb\Support\Time;

class IcsGenerator {
    public function generate(array $booking, array $meta, string $title, string $location = '', string $uid = ''): string {
        $uid = $uid !== '' ? $uid : (!empty($booking['booking_uuid']) ? $booking['booking_uuid'] . '@' . wp_parse_url(home_url(), PHP_URL_HOST) : wp_generate_uuid4());
        $dtstamp = gmdate('Ymd\THis\Z');
        $dtstart = gmdate('Ymd\THis\Z', strtotime((string)$booking['slot_start']));
        $dtend = gmdate('Ymd\THis\Z', strtotime((string)$booking['slot_end']));
        $description = (string)($meta['message'] ?? ($booking['notes'] ?? ''));
        $partySize = max(1, (int)($booking['party_size'] ?? 1));
        if ($partySize > 1) {
            $description = trim($description . "\nTeilnehmer: " . $partySize);
        }
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WPCB//Calendar Booking//DE\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\nUID:" . $this->escape($uid) . "\r\nDTSTAMP:{$dtstamp}\r\nDTSTART:{$dtstart}\r\nDTEND:{$dtend}\r\nSUMMARY:" . $this->escape($title) . "\r\nDESCRIPTION:" . $this->escape($description) . "\r\nLOCATION:" . $this->escape($location) . "\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * Generate one stable VEVENT per affected series occurrence. Cancellation
     * messages use METHOD:CANCEL and retain each occurrence's original UID.
     *
     * @param array<int,array{booking:array,meta:array,title:string,location:string}> $occurrences
     */
    public function generateSeries(array $occurrences): string {
        $occurrences = array_values(array_filter($occurrences, static fn($item): bool =>
            is_array($item)
            && is_array($item['booking'] ?? null)
            && is_array($item['meta'] ?? null)
            && !empty($item['booking']['booking_uuid'])
        ));
        if (!$occurrences) {
            return '';
        }

        $cancel = true;
        foreach ($occurrences as $item) {
            if ((string)($item['booking']['status'] ?? '') !== 'cancelled') {
                $cancel = false;
                break;
            }
        }
        $method = $cancel ? 'CANCEL' : 'PUBLISH';
        $host = (string)wp_parse_url(home_url(), PHP_URL_HOST);
        $dtstamp = gmdate('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//WPCB//Calendar Booking//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
        ];
        foreach ($occurrences as $item) {
            $booking = $item['booking'];
            $meta = $item['meta'];
            $start = strtotime((string)($booking['slot_start'] ?? ''));
            $end = strtotime((string)($booking['slot_end'] ?? ''));
            if ($start === false || $end === false || $end <= $start) {
                continue;
            }
            $uid = (string)$booking['booking_uuid'] . '@' . $host;
            $description = (string)($meta['message'] ?? ($booking['notes'] ?? ''));
            $partySize = max(1, (int)($booking['party_size'] ?? 1));
            if ($partySize > 1) {
                $description = trim($description . "\nTeilnehmer: " . $partySize);
            }
            $status = $cancel ? 'CANCELLED' : 'CONFIRMED';
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $this->escape($uid);
            $lines[] = 'DTSTAMP:' . $dtstamp;
            $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $start);
            $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', $end);
            $lines[] = 'SUMMARY:' . $this->escape((string)($item['title'] ?? 'Termin'));
            $lines[] = 'DESCRIPTION:' . $this->escape($description);
            $lines[] = 'LOCATION:' . $this->escape((string)($item['location'] ?? ''));
            $lines[] = 'STATUS:' . $status;
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    private function escape(string $value): string {
        return str_replace(["\\", ";", ",", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", ''], $value);
    }
}
