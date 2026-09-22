<?php
namespace Cemb\Calendar;

use Cemb\Support\Time;

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
        $properties = [];
        foreach ($lines as $line) {
            $line = trim($line, "\r\n");
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $properties[] = [$key, $value];
        }

        $startProperty = $this->findProperty($properties, 'DTSTART');
        $endProperty = $this->findProperty($properties, 'DTEND');
        if (!$startProperty || !$endProperty) {
            return null;
        }

        $start = $this->parseDate($startProperty[0], $startProperty[1]);
        $end = $this->parseDate($endProperty[0], $endProperty[1]);
        if (!$start || !$end) {
            return null;
        }

        return [
            'uid' => $this->propertyValue($properties, 'UID'),
            'summary' => $this->propertyValue($properties, 'SUMMARY'),
            'description' => $this->propertyValue($properties, 'DESCRIPTION'),
            'location' => $this->propertyValue($properties, 'LOCATION'),
            'start' => $start,
            'end' => $end,
        ];
    }

    private function findProperty(array $properties, string $name): ?array {
        foreach ($properties as $property) {
            $key = (string)$property[0];
            if ($key === $name || strpos($key, $name . ';') === 0) {
                return [$key, (string)$property[1]];
            }
        }
        return null;
    }

    private function propertyValue(array $properties, string $name): string {
        $property = $this->findProperty($properties, $name);
        return $property ? (string)$property[1] : '';
    }

    private function parseDate(string $key, string $value): ?string {
        if ($value === '') {
            return null;
        }

        $timezone = Time::bookingTimezone();
        if (preg_match('/;TZID=([^;:]+)/i', $key, $tzid)) {
            try {
                $timezone = new \DateTimeZone($tzid[1]);
            } catch (\Exception $e) {
                return null;
            }
        }

        if (preg_match('/^(\d{8})$/', $value, $match)) {
            $local = \DateTimeImmutable::createFromFormat('!Ymd H:i:s', $match[1] . ' 00:00:00', $timezone);
            return $local ? Time::formatUtc($local) : null;
        }

        if (preg_match('/^(\d{8})T(\d{6})Z$/', $value, $match)) {
            $utc = \DateTimeImmutable::createFromFormat('!Ymd His', $match[1] . ' ' . $match[2], Time::utc());
            return $utc ? Time::formatUtc($utc) : null;
        }

        if (preg_match('/^(\d{8})T(\d{6})$/', $value, $match)) {
            $local = \DateTimeImmutable::createFromFormat('!Ymd His', $match[1] . ' ' . $match[2], $timezone);
            if (!$local) {
                return null;
            }
            return Time::formatUtc($local);
        }

        return null;
    }
}
