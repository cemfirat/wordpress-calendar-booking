<?php
namespace Wpcb\Availability;

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Calendar\IcloudProvider;
use Wpcb\Calendar\PublicBusyPresenter;
use Wpcb\Calendar\ConnectionBusyService;
use Wpcb\Support\Time;

class SlotService {
    private AvailabilityRepository $repo;
    private BookingRepository $bookings;
    private IcloudProvider $calendar;
    private BookingTypeRepository $types;
    private PublicBusyPresenter $publicBusy;
    private ConnectionBusyService $connectionBusy;

    public function __construct() {
        $this->repo = new AvailabilityRepository();
        $this->bookings = new BookingRepository();
        $this->calendar = new IcloudProvider();
        $this->types = new BookingTypeRepository();
        $this->publicBusy = new PublicBusyPresenter();
        $this->connectionBusy = new ConnectionBusyService();
    }

    public function getSlots(int $typeId, int $days = 14, ?int $ignoreBookingId = null): array {
        $type = $this->types->find($typeId);
        if (!$type) return [];
        $rules = $this->repo->rulesForType($typeId);
        if (!$rules) return [];

        $days = max(1, $days);
        $nowUtc = Time::nowUtc();
        $nowLocal = $nowUtc->setTimezone(Time::bookingTimezone());
        $windowEndLocal = $nowLocal->setTime(23, 59, 59)->modify('+' . $days . ' days');
        $from = Time::formatUtc($nowUtc);
        $to = Time::formatUtc($windowEndLocal);

        $calendarEvents = array_merge(
            $this->calendar->events($from, $to),
            $this->connectionBusy->busyForBookingType($typeId, $from, $to)
        );
        $exceptions = $this->repo->exceptions($from, $to, $typeId);
        $out = [];

        foreach ($rules as $rule) {
            $limit = min($days, (int)$rule->max_days_in_advance);
            for ($i = 0; $i <= $limit; $i++) {
                $day = $nowLocal->setTime(0, 0, 0)->modify('+' . $i . ' days');
                if ((int)$day->format('N') !== (int)$rule->weekday) continue;
                $out = array_merge(
                    $out,
                    $this->buildDaySlots(
                        $type,
                        $rule,
                        $day->format('Y-m-d'),
                        $calendarEvents,
                        $exceptions,
                        $ignoreBookingId
                    )
                );
            }
        }

        usort($out, static fn($a, $b) => strcmp((string)$a['start'], (string)$b['start']));
        $unique = [];
        foreach ($out as $slot) {
            $unique[$slot['start']] = $slot;
        }
        return array_values($unique);
    }

    public function getAllTypeSlots(int $days = 21): array {
        $all = [];
        foreach ($this->types->all(true) as $type) {
            $all[(int)$type->id] = $this->getSlots((int)$type->id, $days);
        }
        return $all;
    }

    public function getMonthDisplay(string $month): array {
        $timezone = Time::bookingTimezone();
        $base = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $month . '-01 00:00:00', $timezone);
        if (!$base || $base->format('Y-m') !== $month) {
            $base = Time::nowLocal()->modify('first day of this month')->setTime(0, 0, 0);
        }

        $gridStart = $base->modify('monday this week')->setTime(0, 0, 0);
        $monthEnd = $base->modify('last day of this month')->setTime(23, 59, 59);
        $gridEnd = $monthEnd->modify('sunday this week')->setTime(23, 59, 59);

        $from = Time::formatUtc($gridStart->modify('-1 day'));
        $to = Time::formatUtc($gridEnd->modify('+1 day'));

        $externalEvents = $this->calendar->events($from, $to);
        $internalBookings = $this->bookings->displayableBetween($from, $to);
        $itemsByDay = [];

        foreach ($externalEvents as $event) {
            $day = Time::localDate((string)$event['start']);
            if ($day !== '') {
                $itemsByDay[$day][] = $this->publicBusy->externalEvent($event);
            }
        }
        foreach ($internalBookings as $booking) {
            $day = Time::localDate((string)$booking->slot_start);
            if ($day !== '') {
                $itemsByDay[$day][] = $this->publicBusy->booking($booking);
            }
        }
        foreach ($itemsByDay as &$items) {
            usort($items, static fn($a, $b) => strcmp((string)$a['start'], (string)$b['start']));
        }
        unset($items);

        $days = [];
        $today = Time::nowLocal()->format('Y-m-d');
        for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $isWeekend = in_array((int)$day->format('N'), [6, 7], true);
            $days[] = [
                'date' => $date,
                'in_month' => $day->format('Y-m') === $base->format('Y-m'),
                'is_weekend' => $isWeekend,
                'is_past' => $date < $today,
                'items' => $isWeekend ? [] : ($itemsByDay[$date] ?? []),
            ];
        }

        return [
            'month' => $base->format('Y-m'),
            'days' => $days,
            'prev' => $base->modify('-1 month')->format('Y-m'),
            'next' => $base->modify('+1 month')->format('Y-m'),
            'title' => $base->format('F Y'),
            'timezone' => Time::bookingTimezoneName(),
        ];
    }

    public function isCanonicalSlot(int $typeId, string $start, string $end, ?int $ignoreBookingId = null): bool {
        $startUtc = Time::parseUtc($start);
        $endUtc = Time::parseUtc($end);
        if (!$startUtc || !$endUtc || $endUtc <= $startUtc) {
            return false;
        }

        $today = Time::nowLocal()->setTime(0, 0, 0);
        $startLocal = $startUtc->setTimezone(Time::bookingTimezone());
        $daysFromToday = (int)$today->diff($startLocal->setTime(0, 0, 0))->format('%r%a');
        if ($daysFromToday < 0 || $daysFromToday > 366) {
            return false;
        }

        $slots = $this->getSlots($typeId, $daysFromToday + 1, $ignoreBookingId);
        foreach ($slots as $slot) {
            if (($slot['start'] ?? null) === $start && ($slot['end'] ?? null) === $end) {
                return true;
            }
        }
        return false;
    }

    public function slotAvailable(int $typeId, string $start, string $end, ?int $ignoreId = null): bool {
        $type = $this->types->find($typeId);
        if (!$type || !Time::parseUtc($start) || !Time::parseUtc($end)) return false;

        $bufferBefore = (int)$type->buffer_before_minutes;
        $bufferAfter = (int)$type->buffer_after_minutes;
        $bufferedStart = Time::addMinutes($start, -$bufferBefore);
        $bufferedEnd = Time::addMinutes($end, $bufferAfter);
        if (!$bufferedStart || !$bufferedEnd) return false;

        if ($this->bookings->hasConflict($bufferedStart, $bufferedEnd, $ignoreId)) {
            return false;
        }

        $calendarFrom = Time::addMinutes($start, -1440);
        $calendarTo = Time::addMinutes($end, 1440);
        $events = [];
        if ($calendarFrom && $calendarTo) {
            $events = array_merge(
                $this->calendar->events($calendarFrom, $calendarTo),
                $this->connectionBusy->busyForBookingType($typeId, $calendarFrom, $calendarTo)
            );
        }
        return !$this->isBlockedByCalendar($start, $end, $bufferBefore, $bufferAfter, $events);
    }

    private function buildDaySlots(
        object $type,
        object $rule,
        string $date,
        array $calendarEvents,
        array $exceptions,
        ?int $ignoreBookingId = null
    ): array {
        $duration = (int)($type->duration_minutes ?: $rule->slot_duration_minutes);
        $bufferBefore = (int)($type->buffer_before_minutes ?: $rule->buffer_before_minutes);
        $bufferAfter = (int)($type->buffer_after_minutes ?: $rule->buffer_after_minutes);
        $minNotice = (int)$rule->min_notice_minutes;
        $free = [];

        $startMinute = $this->clockMinutes((string)$rule->start_time);
        $endMinute = $this->clockMinutes((string)$rule->end_time);
        if ($duration < 1 || $startMinute === null || $endMinute === null || $endMinute <= $startMinute) {
            return [];
        }

        $noticeCutoff = Time::nowUtc()->modify('+' . max(0, $minNotice) . ' minutes')->getTimestamp();
        for ($minute = $startMinute; $minute + $duration <= $endMinute; $minute += $duration) {
            $localStart = Time::parseLocal($date . ' ' . $this->clockString($minute));
            $localEnd = Time::parseLocal($date . ' ' . $this->clockString($minute + $duration));
            if (!$localStart || !$localEnd) {
                continue;
            }

            // A slot that crosses a DST transition can have a different elapsed
            // duration than its wall-clock duration. Reject it rather than
            // surprising either party.
            if (($localEnd->getTimestamp() - $localStart->getTimestamp()) !== ($duration * 60)) {
                continue;
            }
            if ($localStart->getTimestamp() < $noticeCutoff) {
                continue;
            }

            $slotStart = Time::formatUtc($localStart);
            $slotEnd = Time::formatUtc($localEnd);
            if ($this->isBlockedByExceptions($slotStart, $slotEnd, $exceptions)) continue;
            if ($this->isBlockedByBookings($slotStart, $slotEnd, $bufferBefore, $bufferAfter, $ignoreBookingId)) continue;
            if ($this->isBlockedByCalendar($slotStart, $slotEnd, $bufferBefore, $bufferAfter, $calendarEvents)) continue;

            $free[] = [
                'start' => $slotStart,
                'end' => $slotEnd,
                'label' => $localStart->format('d.m.Y H:i') . ' ' . Time::bookingTimezoneName(),
                'timezone' => Time::bookingTimezoneName(),
            ];
        }

        return $free;
    }

    private function isBlockedByBookings(
        string $start,
        string $end,
        int $bufferBefore,
        int $bufferAfter,
        ?int $ignoreBookingId = null
    ): bool {
        $bufferedStart = Time::addMinutes($start, -$bufferBefore);
        $bufferedEnd = Time::addMinutes($end, $bufferAfter);
        return !$bufferedStart || !$bufferedEnd
            ? true
            : $this->bookings->hasConflict($bufferedStart, $bufferedEnd, $ignoreBookingId);
    }

    private function isBlockedByCalendar(
        string $start,
        string $end,
        int $bufferBefore,
        int $bufferAfter,
        array $events
    ): bool {
        $startUtc = Time::parseUtc((string)Time::addMinutes($start, -$bufferBefore));
        $endUtc = Time::parseUtc((string)Time::addMinutes($end, $bufferAfter));
        if (!$startUtc || !$endUtc) return true;

        foreach ($events as $event) {
            $eventStart = Time::parseUtc((string)($event['start'] ?? ''));
            $eventEnd = Time::parseUtc((string)($event['end'] ?? ''));
            if ($eventStart && $eventEnd && $eventStart < $endUtc && $eventEnd > $startUtc) {
                return true;
            }
        }
        return false;
    }

    private function isBlockedByExceptions(string $start, string $end, array $exceptions): bool {
        $slotStart = Time::parseUtc($start);
        $slotEnd = Time::parseUtc($end);
        if (!$slotStart || !$slotEnd) return true;

        foreach ($exceptions as $exception) {
            $exceptionStart = Time::parseUtc((string)$exception->date_start);
            $exceptionEnd = Time::parseUtc((string)$exception->date_end);
            if ($exceptionStart && $exceptionEnd && $exceptionStart < $slotEnd && $exceptionEnd > $slotStart) {
                return true;
            }
        }
        return false;
    }

    private function clockMinutes(string $time): ?int {
        if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $match)) {
            return null;
        }
        $hour = (int)$match[1];
        $minute = (int)$match[2];
        return ($hour <= 23 && $minute <= 59) ? ($hour * 60 + $minute) : null;
    }

    private function clockString(int $minutes): string {
        $hour = intdiv($minutes, 60);
        $minute = $minutes % 60;
        return sprintf('%02d:%02d:00', $hour, $minute);
    }
}
