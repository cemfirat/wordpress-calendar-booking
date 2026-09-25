<?php
namespace Wpcb\Calendar;

use Wpcb\Support\Time;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Reader;

class Parser {
    public function parse(string $ics, string $from, string $to): array {
        $result = $this->parseResult($ics, $from, $to);
        return is_wp_error($result) ? [] : $result;
    }

    /** @return array<int,array<string,mixed>>|\WP_Error */
    public function parseResult(string $ics, string $from, string $to) {
        $fromUtc = Time::parseUtc($from);
        $toUtc = Time::parseUtc($to);
        if (!$fromUtc || !$toUtc || $toUtc <= $fromUtc) {
            return new \WP_Error('wpcb_ics_range', 'Calendar feed range is invalid.');
        }
        if (!class_exists(Reader::class)) {
            return new \WP_Error('wpcb_ics_runtime', 'Calendar feed parser is unavailable.');
        }

        try {
            $calendar = Reader::read($ics, Reader::OPTION_FORGIVING);
            if (!method_exists($calendar, 'expand')) {
                return new \WP_Error('wpcb_ics_parse', 'Calendar feed could not be parsed.');
            }

            // Expansion is always bounded to the requested availability window.
            // This is both a correctness rule and a resource-use safety boundary.
            $expanded = $calendar->expand($fromUtc, $toUtc, Time::bookingTimezone());
        } catch (\Throwable $error) {
            return new \WP_Error('wpcb_ics_parse', 'Calendar feed could not be parsed.');
        }

        $events = [];
        foreach ($expanded->select('VEVENT') as $event) {
            $status = strtoupper(trim((string)($event->STATUS ?? '')));
            $transparency = strtoupper(trim((string)($event->TRANSP ?? '')));
            if ($status === 'CANCELLED' || $transparency === 'TRANSPARENT' || !isset($event->DTSTART)) {
                continue;
            }

            $range = $this->eventRange($event);
            if (!$range) {
                continue;
            }

            [$start, $end, $allDay] = $range;
            if ($start >= $to || $end <= $from) {
                continue;
            }

            $events[] = [
                'uid' => trim((string)($event->UID ?? '')),
                'recurrence_id' => isset($event->{'RECURRENCE-ID'}) ? trim((string)$event->{'RECURRENCE-ID'}) : '',
                'summary' => trim((string)($event->SUMMARY ?? '')),
                'description' => trim((string)($event->DESCRIPTION ?? '')),
                'location' => trim((string)($event->LOCATION ?? '')),
                'start' => $start,
                'end' => $end,
                'all_day' => $allDay,
            ];
        }

        usort($events, static fn(array $a, array $b): int => strcmp($a['start'], $b['start']));
        return $events;
    }

    private function eventRange($event): ?array {
        try {
            $referenceTimezone = Time::bookingTimezone();
            $startDate = $event->DTSTART->getDateTime($referenceTimezone);
            $allDay = !$event->DTSTART->hasTime();

            if (isset($event->DTEND)) {
                $endDate = $event->DTEND->getDateTime($referenceTimezone);
            } elseif (isset($event->DURATION)) {
                $endDate = $startDate->add(DateTimeParser::parseDuration((string)$event->DURATION));
            } elseif ($allDay) {
                $endDate = $startDate->modify('+1 day');
            } else {
                return null;
            }

            if ($endDate <= $startDate) {
                return null;
            }

            return [
                Time::formatUtc($startDate),
                Time::formatUtc($endDate),
                $allDay,
            ];
        } catch (\Throwable $error) {
            return null;
        }
    }
}
