<?php
namespace Cemb\ICS;

use Cemb\Support\Time;

class IcsGenerator {
    public function generate(array $booking, array $meta, string $title, string $location = '', string $uid = ''): string {
        $uid = $uid !== '' ? $uid : (!empty($booking['booking_uuid']) ? $booking['booking_uuid'] . '@' . wp_parse_url(home_url(), PHP_URL_HOST) : wp_generate_uuid4());
        $dtstamp = Time::nowUtc()->format('Ymd\THis\Z');
        $start = Time::parseUtc((string)($booking['slot_start'] ?? ''));
        $end = Time::parseUtc((string)($booking['slot_end'] ?? ''));
        if (!$start || !$end || $end <= $start) {
            throw new \InvalidArgumentException('Booking contains invalid UTC slot times.');
        }
        $dtstart = $start->format('Ymd\THis\Z');
        $dtend = $end->format('Ymd\THis\Z');
        $description = (string)($meta['message'] ?? ($booking['notes'] ?? ''));
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CEMB//Calendar Booking//DE\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\nUID:" . $this->escape($uid) . "\r\nDTSTAMP:{$dtstamp}\r\nDTSTART:{$dtstart}\r\nDTEND:{$dtend}\r\nSUMMARY:" . $this->escape($title) . "\r\nDESCRIPTION:" . $this->escape($description) . "\r\nLOCATION:" . $this->escape($location) . "\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function escape(string $value): string {
        return str_replace(["\\", ";", ",", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", ''], $value);
    }
}
