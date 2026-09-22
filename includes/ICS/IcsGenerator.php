<?php
namespace Cemb\ICS;

use Cemb\Support\Time;

class IcsGenerator {
    public function generate(array $booking, array $meta, string $title, string $location = '', string $uid = ''): string {
        $uid = $uid !== '' ? $uid : (!empty($booking['booking_uuid']) ? $booking['booking_uuid'] . '@' . wp_parse_url(home_url(), PHP_URL_HOST) : wp_generate_uuid4());
        $dtstamp = gmdate('Ymd\THis\Z');
        $dtstart = gmdate('Ymd\THis\Z', strtotime((string)$booking['slot_start']));
        $dtend = gmdate('Ymd\THis\Z', strtotime((string)$booking['slot_end']));
        $description = (string)($meta['message'] ?? ($booking['notes'] ?? ''));
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CEMB//Calendar Booking//DE\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\nUID:" . $this->escape($uid) . "\r\nDTSTAMP:{$dtstamp}\r\nDTSTART:{$dtstart}\r\nDTEND:{$dtend}\r\nSUMMARY:" . $this->escape($title) . "\r\nDESCRIPTION:" . $this->escape($description) . "\r\nLOCATION:" . $this->escape($location) . "\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function escape(string $value): string {
        return str_replace(["\\", ";", ",", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", ''], $value);
    }
}
