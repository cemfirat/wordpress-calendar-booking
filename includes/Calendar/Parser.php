<?php
namespace Cemb\Calendar;

class Parser {
    public function parse(string $ics, string $from, string $to): array {
        $ics = preg_replace("/\r\n[ \t]/", '', $ics);
        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $matches);
        $events = [];
        foreach ($matches[1] as $block) {
            $event = $this->parseEventBlock($block);
            if (!$event || empty($event['start']) || empty($event['end'])) {
                continue;
            }
            if ($event['start'] < $to && $event['end'] > $from) {
                $events[] = $event;
            }
        }
        return $events;
    }

    private function parseEventBlock(string $block): ?array {
        $lines = preg_split('/\n/', trim($block));
        $data = [];
        foreach ($lines as $line) {
            $line = trim($line, "\r\n");
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $data[$key] = $value;
        }
        $start = $this->parseDate($data['DTSTART'] ?? ($this->findByPrefix($data, 'DTSTART') ?? ''));
        $end = $this->parseDate($data['DTEND'] ?? ($this->findByPrefix($data, 'DTEND') ?? ''));
        if (!$start || !$end) {
            return null;
        }
        return [
            'uid' => $data['UID'] ?? '',
            'summary' => $data['SUMMARY'] ?? '',
            'description' => $data['DESCRIPTION'] ?? '',
            'location' => $data['LOCATION'] ?? '',
            'start' => $start,
            'end' => $end,
        ];
    }

    private function findByPrefix(array $data, string $prefix): ?string {
        foreach ($data as $key => $value) {
            if (strpos($key, $prefix . ';') === 0) {
                return $value;
            }
        }
        return null;
    }

    private function parseDate(string $value): ?string {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{8})$/', $value, $m)) {
            $dt = \DateTime::createFromFormat('Ymd H:i:s', $m[1] . ' 00:00:00', wp_timezone());
            return $dt ? $dt->format('Y-m-d H:i:s') : null;
        }
        if (preg_match('/^(\d{8})T(\d{6})Z$/', $value, $m)) {
            $dt = \DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new \DateTimeZone('UTC'));
            if (!$dt) return null;
            $dt->setTimezone(wp_timezone());
            return $dt->format('Y-m-d H:i:s');
        }
        if (preg_match('/^(\d{8})T(\d{6})$/', $value, $m)) {
            $dt = \DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], wp_timezone());
            return $dt ? $dt->format('Y-m-d H:i:s') : null;
        }
        return null;
    }
}
