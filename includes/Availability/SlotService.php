<?php
namespace Wpcb\Availability;

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Booking\CapacityService;
use Wpcb\Calendar\IcloudProvider;
use Wpcb\Calendar\PublicBusyPresenter;
use Wpcb\Calendar\ConnectionBusyService;
use Wpcb\Resources\ResourceRepository;
use Wpcb\Support\Time;

class SlotService {
    private AvailabilityRepository $repo;
    private BookingRepository $bookings;
    private IcloudProvider $calendar;
    private BookingTypeRepository $types;
    private PublicBusyPresenter $publicBusy;
    private ConnectionBusyService $connectionBusy;
    private ResourceRepository $resources;
    private CapacityService $capacity;

    public function __construct() {
        $this->repo = new AvailabilityRepository();
        $this->bookings = new BookingRepository();
        $this->calendar = new IcloudProvider();
        $this->types = new BookingTypeRepository();
        $this->publicBusy = new PublicBusyPresenter();
        $this->connectionBusy = new ConnectionBusyService();
        $this->resources = new ResourceRepository();
        $this->capacity = new CapacityService($this->bookings, $this->types, $this->resources);
    }

    /**
     * Public slot list. Private resource topology is collapsed so visitors do
     * not learn staff/resource names or capacity unless every assigned
     * resource has an explicitly enabled public label.
     */
    public function getSlots(int $typeId, int $days = 14, ?int $ignoreBookingId = null): array {
        if (!$this->types->find($typeId)) {
            return [];
        }

        $resources = $this->resources->forBookingType($typeId, true);
        if (!$resources) {
            return [];
        }

        $labels = [];
        foreach ($resources as $resource) {
            $labels[(int)$resource->id] = $this->resources->publicLabel($resource);
        }
        $exposeResourceLabels = count($resources) > 1
            && !in_array('', array_values($labels), true);

        $out = [];
        foreach ($resources as $resource) {
            $resourceId = (int)$resource->id;
            foreach ($this->getSlotsForResource($typeId, $resourceId, $days, $ignoreBookingId) as $slot) {
                if ($exposeResourceLabels) {
                    $slot['resource_label'] = $labels[$resourceId];
                    $slot['label'] .= ' — ' . $labels[$resourceId];
                    $out[$slot['start'] . '|' . $resourceId] = $slot;
                    continue;
                }

                // One generic slot per start hides private resource count/name.
                if (!isset($out[$slot['start']])) {
                    $out[$slot['start']] = $slot;
                }
            }
        }

        $out = array_values($out);
        usort($out, static function (array $a, array $b): int {
            $byStart = strcmp((string)$a['start'], (string)$b['start']);
            return $byStart !== 0
                ? $byStart
                : ((int)($a['resource_id'] ?? 0) <=> (int)($b['resource_id'] ?? 0));
        });
        return $out;
    }

    public function getSlotsForResource(
        int $typeId,
        int $resourceId,
        int $days = 14,
        ?int $ignoreBookingId = null
    ): array {
        $type = $this->types->find($typeId);
        if (!$type || !$this->resources->isAssignedToBookingType($resourceId, $typeId)) {
            return [];
        }

        $rules = $this->repo->rulesForTypeAndResource($typeId, $resourceId);
        if (!$rules) {
            return [];
        }

        $days = max(1, $days);
        $nowUtc = Time::nowUtc();
        $nowLocal = $nowUtc->setTimezone(Time::bookingTimezone());
        $windowEndLocal = $nowLocal->setTime(23, 59, 59)->modify('+' . $days . ' days');
        $from = Time::formatUtc($nowUtc);
        $to = Time::formatUtc($windowEndLocal);

        $calendarEvents = array_merge(
            $this->calendar->events($from, $to),
            $this->connectionBusy->busyForResource($typeId, $resourceId, $from, $to)
        );
        $exceptions = $this->repo->exceptions($from, $to, $typeId, $resourceId);
        $out = [];

        foreach ($rules as $rule) {
            $limit = min($days, (int)$rule->max_days_in_advance);
            for ($i = 0; $i <= $limit; $i++) {
                $day = $nowLocal->setTime(0, 0, 0)->modify('+' . $i . ' days');
                if ((int)$day->format('N') !== (int)$rule->weekday) {
                    continue;
                }
                $out = array_merge(
                    $out,
                    $this->buildDaySlots(
                        $type,
                        $resourceId,
                        $rule,
                        $day->format('Y-m-d'),
                        $calendarEvents,
                        $exceptions,
                        $ignoreBookingId
                    )
                );
            }
        }

        usort($out, static fn(array $a, array $b): int => strcmp((string)$a['start'], (string)$b['start']));
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
            usort($items, static fn(array $a, array $b): int => strcmp((string)$a['start'], (string)$b['start']));
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

    public function isCanonicalSlot(
        int $typeId,
        string $start,
        string $end,
        ?int $ignoreBookingId = null,
        ?int $resourceId = null
    ): bool {
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

        $slots = $resourceId
            ? $this->getSlotsForResource($typeId, $resourceId, $daysFromToday + 1, $ignoreBookingId)
            : $this->getSlots($typeId, $daysFromToday + 1, $ignoreBookingId);

        foreach ($slots as $slot) {
            if (($slot['start'] ?? null) === $start
                && ($slot['end'] ?? null) === $end
                && (!$resourceId || (int)($slot['resource_id'] ?? 0) === $resourceId)
            ) {
                return true;
            }
        }
        return false;
    }

    public function slotAvailable(
        int $typeId,
        string $start,
        string $end,
        ?int $ignoreId = null,
        ?int $resourceId = null,
        int $partySize = 1
    ): bool {
        $type = $this->types->find($typeId);
        if (!$type || !Time::parseUtc($start) || !Time::parseUtc($end)) {
            return false;
        }

        if (!$resourceId) {
            foreach ($this->resources->forBookingType($typeId, true) as $resource) {
                if ($this->slotAvailable($typeId, $start, $end, $ignoreId, (int)$resource->id, $partySize)) {
                    return true;
                }
            }
            return false;
        }
        if (!$this->resources->isAssignedToBookingType($resourceId, $typeId)) {
            return false;
        }

        $bufferBefore = (int)$type->buffer_before_minutes;
        $bufferAfter = (int)$type->buffer_after_minutes;
        $bufferedStart = Time::addMinutes($start, -$bufferBefore);
        $bufferedEnd = Time::addMinutes($end, $bufferAfter);
        if (!$bufferedStart || !$bufferedEnd) {
            return false;
        }

        if (!$this->capacity->canFit($typeId, $resourceId, $bufferedStart, $bufferedEnd, max(1, $partySize), $ignoreId)) {
            return false;
        }

        $calendarFrom = Time::addMinutes($start, -1440);
        $calendarTo = Time::addMinutes($end, 1440);
        $events = [];
        if ($calendarFrom && $calendarTo) {
            $events = array_merge(
                $this->calendar->events($calendarFrom, $calendarTo),
                $this->connectionBusy->busyForResource($typeId, $resourceId, $calendarFrom, $calendarTo)
            );
        }
        return !$this->isBlockedByCalendar($start, $end, $bufferBefore, $bufferAfter, $events);
    }

    private function buildDaySlots(
        object $type,
        int $resourceId,
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

            if (($localEnd->getTimestamp() - $localStart->getTimestamp()) !== ($duration * 60)) {
                continue;
            }
            if ($localStart->getTimestamp() < $noticeCutoff) {
                continue;
            }

            $slotStart = Time::formatUtc($localStart);
            $slotEnd = Time::formatUtc($localEnd);
            if ($this->isBlockedByExceptions($slotStart, $slotEnd, $exceptions)) {
                continue;
            }
            if ($this->isBlockedByBookings(
                (int)$type->id,
                $slotStart,
                $slotEnd,
                $bufferBefore,
                $bufferAfter,
                $ignoreBookingId,
                $resourceId
            )) {
                continue;
            }
            if ($this->isBlockedByCalendar($slotStart, $slotEnd, $bufferBefore, $bufferAfter, $calendarEvents)) {
                continue;
            }

            $slot = [
                'resource_id' => $resourceId,
                'start' => $slotStart,
                'end' => $slotEnd,
                'label' => $localStart->format('d.m.Y H:i') . ' ' . Time::bookingTimezoneName(),
                'timezone' => Time::bookingTimezoneName(),
            ];
            if (!empty($type->show_remaining_capacity)) {
                $bufferedStart = Time::addMinutes($slotStart, -$bufferBefore);
                $bufferedEnd = Time::addMinutes($slotEnd, $bufferAfter);
                if ($bufferedStart && $bufferedEnd) {
                    $slot['remaining_capacity'] = $this->capacity->remaining(
                        (int)$type->id,
                        $resourceId,
                        $bufferedStart,
                        $bufferedEnd,
                        $ignoreBookingId
                    );
                    $slot['label'] .= ' — ' . (int)$slot['remaining_capacity'] . ' frei';
                }
            }
            $free[] = $slot;
        }

        return $free;
    }

    private function isBlockedByBookings(
        int $typeId,
        string $start,
        string $end,
        int $bufferBefore,
        int $bufferAfter,
        ?int $ignoreBookingId = null,
        ?int $resourceId = null
    ): bool {
        $bufferedStart = Time::addMinutes($start, -$bufferBefore);
        $bufferedEnd = Time::addMinutes($end, $bufferAfter);
        return !$bufferedStart || !$bufferedEnd || !$resourceId
            ? true
            : !$this->capacity->canFit($typeId, $resourceId, $bufferedStart, $bufferedEnd, 1, $ignoreBookingId);
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
        if (!$startUtc || !$endUtc) {
            return true;
        }

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
        if (!$slotStart || !$slotEnd) {
            return true;
        }

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
