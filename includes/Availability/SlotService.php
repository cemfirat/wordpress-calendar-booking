<?php
namespace Cemb\Availability;

use Cemb\Booking\BookingRepository;
use Cemb\Booking\BookingTypeRepository;
use Cemb\Calendar\IcloudProvider;
use Cemb\Support\BookingFormatter;

class SlotService {
    private AvailabilityRepository $repo;
    private BookingRepository $bookings;
    private IcloudProvider $calendar;
    private BookingTypeRepository $types;
    private BookingFormatter $formatter;

    public function __construct() {
        $this->repo = new AvailabilityRepository();
        $this->bookings = new BookingRepository();
        $this->calendar = new IcloudProvider();
        $this->types = new BookingTypeRepository();
        $this->formatter = new BookingFormatter();
    }

    public function getSlots(int $typeId, int $days = 14): array {
        $type = $this->types->find($typeId);
        if (!$type) return [];
        $rules = $this->repo->rulesForType($typeId);
        if (!$rules) return [];

        $from = current_time('Y-m-d H:i:s');
        $to = date_i18n('Y-m-d H:i:s', strtotime('+' . max(1, $days) . ' days', current_time('timestamp')));
        $calendarEvents = $this->calendar->events($from, $to);
        $exceptions = $this->repo->exceptions($from, $to, $typeId);
        $out = [];
        $nowTs = current_time('timestamp');
        foreach ($rules as $rule) {
            for ($i = 0; $i <= min($days, (int)$rule->max_days_in_advance); $i++) {
                $dayTs = strtotime('+' . $i . ' days', $nowTs);
                if ((int)date_i18n('N', $dayTs) !== (int)$rule->weekday) continue;
                $date = date_i18n('Y-m-d', $dayTs);
                $out = array_merge($out, $this->buildDaySlots($type, $rule, $date, $calendarEvents, $exceptions));
            }
        }
        usort($out, static fn($a, $b) => strcmp($a['start'], $b['start']));
        $unique = [];
        foreach ($out as $slot) $unique[$slot['start']] = $slot;
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
        $baseTs = strtotime($month . '-01 00:00:00');
        if (!$baseTs) $baseTs = current_time('timestamp');
        $gridStart = strtotime('monday this week', $baseTs);
        $monthEnd = strtotime(date('Y-m-t 23:59:59', $baseTs));
        $gridEnd = strtotime('sunday this week', $monthEnd);
        $from = date('Y-m-d H:i:s', strtotime('-1 day', $gridStart));
        $to = date('Y-m-d H:i:s', strtotime('+1 day', $gridEnd));

        $externalEvents = $this->calendar->events($from, $to);
        $internalBookings = $this->bookings->displayableBetween($from, $to);
        $itemsByDay = [];
        foreach ($externalEvents as $event) {
            $day = substr((string)$event['start'], 0, 10);
            $itemsByDay[$day][] = [
                'start' => $event['start'],
                'end' => $event['end'],
                'title' => $event['summary'] ?: 'Besetzt',
                'label' => wp_date('H:i', strtotime((string)$event['start'])) . ' Besetzt',
                'type_id' => 0,
                'class' => 'cemb-event-external',
                'source' => 'external',
            ];
        }
        foreach ($internalBookings as $booking) {
            $meta = $this->bookings->getMeta((int)$booking->id);
            $day = substr((string)$booking->slot_start, 0, 10);
            $itemsByDay[$day][] = [
                'start' => $booking->slot_start,
                'end' => $booking->slot_end,
                'title' => $this->formatter->summary((array)$booking, $meta),
                'label' => wp_date('H:i', strtotime((string)$booking->slot_start)) . ' ' . $this->formatter->summary((array)$booking, $meta),
                'type_id' => (int)$booking->booking_type_id,
                'class' => 'cemb-event-booking ' . $this->formatter->typeColorClass((int)$booking->booking_type_id),
                'source' => 'booking',
            ];
        }
        foreach ($itemsByDay as &$items) {
            usort($items, static fn($a, $b) => strcmp((string)$a['start'], (string)$b['start']));
        }
        unset($items);

        $days = [];
        for ($dayTs = $gridStart; $dayTs <= $gridEnd; $dayTs = strtotime('+1 day', $dayTs)) {
            $date = date('Y-m-d', $dayTs);
            $isWeekend = in_array((int)date('N', $dayTs), [6,7], true);
            $isPast = $date < current_time('Y-m-d');
            $days[] = [
                'date' => $date,
                'in_month' => date('Y-m', $dayTs) === date('Y-m', $baseTs),
                'is_weekend' => $isWeekend,
                'is_past' => $isPast,
                'items' => $isWeekend ? [] : ($itemsByDay[$date] ?? []),
            ];
        }

        return [
            'month' => date('Y-m', $baseTs),
            'days' => $days,
            'prev' => wp_date('Y-m', strtotime('-1 month', $baseTs)),
            'next' => wp_date('Y-m', strtotime('+1 month', $baseTs)),
            'title' => wp_date('F Y', $baseTs),
        ];
    }

    public function slotAvailable(int $typeId, string $start, string $end, ?int $ignoreId = null): bool {
        $type = $this->types->find($typeId);
        if (!$type) return false;
        $bufferBefore = (int)$type->buffer_before_minutes;
        $bufferAfter = (int)$type->buffer_after_minutes;
        if ($this->bookings->hasConflict(date('Y-m-d H:i:s', strtotime($start . ' -' . $bufferBefore . ' minutes')), date('Y-m-d H:i:s', strtotime($end . ' +' . $bufferAfter . ' minutes')), $ignoreId)) {
            return false;
        }
        $events = $this->calendar->events(date('Y-m-d H:i:s', strtotime($start . ' -1 day')), date('Y-m-d H:i:s', strtotime($end . ' +1 day')));
        return !$this->isBlockedByCalendar($start, $end, $bufferBefore, $bufferAfter, $events);
    }

    private function buildDaySlots(object $type, object $rule, string $date, array $calendarEvents, array $exceptions): array {
        $startTs = strtotime($date . ' ' . $rule->start_time);
        $endTs = strtotime($date . ' ' . $rule->end_time);
        $duration = (int)($type->duration_minutes ?: $rule->slot_duration_minutes);
        $bufferBefore = (int)($type->buffer_before_minutes ?: $rule->buffer_before_minutes);
        $bufferAfter = (int)($type->buffer_after_minutes ?: $rule->buffer_after_minutes);
        $minNotice = (int)$rule->min_notice_minutes;
        $nowTs = current_time('timestamp');
        $free = [];

        for ($slotTs = $startTs; $slotTs + ($duration * 60) <= $endTs; $slotTs += $duration * 60) {
            if ($slotTs < strtotime('+' . $minNotice . ' minutes', $nowTs)) continue;
            $slotStart = date('Y-m-d H:i:s', $slotTs);
            $slotEnd = date('Y-m-d H:i:s', $slotTs + ($duration * 60));
            if ($this->isBlockedByExceptions($slotStart, $slotEnd, $exceptions)) continue;
            if ($this->isBlockedByBookings($slotStart, $slotEnd, $bufferBefore, $bufferAfter)) continue;
            if ($this->isBlockedByCalendar($slotStart, $slotEnd, $bufferBefore, $bufferAfter, $calendarEvents)) continue;
            $free[] = [
                'start' => $slotStart,
                'end' => $slotEnd,
                'label' => wp_date('d.m.Y H:i', strtotime($slotStart)),
            ];
        }
        return $free;
    }

    private function isBlockedByBookings(string $start, string $end, int $bufferBefore, int $bufferAfter): bool {
        $start = date('Y-m-d H:i:s', strtotime($start . ' -' . $bufferBefore . ' minutes'));
        $end = date('Y-m-d H:i:s', strtotime($end . ' +' . $bufferAfter . ' minutes'));
        return $this->bookings->hasConflict($start, $end);
    }

    private function isBlockedByCalendar(string $start, string $end, int $bufferBefore, int $bufferAfter, array $events): bool {
        $startTs = strtotime($start . ' -' . $bufferBefore . ' minutes');
        $endTs = strtotime($end . ' +' . $bufferAfter . ' minutes');
        foreach ($events as $event) {
            if (strtotime((string)$event['start']) < $endTs && strtotime((string)$event['end']) > $startTs) return true;
        }
        return false;
    }

    private function isBlockedByExceptions(string $start, string $end, array $exceptions): bool {
        foreach ($exceptions as $ex) {
            if (strtotime((string)$ex->date_start) < strtotime($end) && strtotime((string)$ex->date_end) > strtotime($start)) return true;
        }
        return false;
    }
}
