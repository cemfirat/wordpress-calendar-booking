<?php
namespace Wpcb\Availability;

use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Support\Time;

/**
 * Resolves the effective scheduling buffers for one concrete slot.
 *
 * Policy: booking-type buffers override availability-rule buffers. A zero
 * booking-type value inherits the matching rule value. Buffers are deliberately
 * recalculated from the current configuration rather than snapshotted on a
 * booking, so administrator edits take effect consistently for both existing
 * occupancy and new candidate slots without a data migration.
 */
final class BufferPolicy {
    private AvailabilityRepository $availability;
    private BookingTypeRepository $types;
    /** @var array<string,array{before:int,after:int}> */
    private array $slotCache = [];
    /** @var array{before:int,after:int}|null */
    private ?array $maxCache = null;

    public function __construct(
        ?AvailabilityRepository $availability = null,
        ?BookingTypeRepository $types = null
    ) {
        $this->availability = $availability ?: new AvailabilityRepository();
        $this->types = $types ?: new BookingTypeRepository();
    }

    /** @return array{before:int,after:int} */
    public function forSlot(int $typeId, int $resourceId, string $start, string $end): array {
        $key = $typeId . '|' . $resourceId . '|' . $start . '|' . $end;
        if (isset($this->slotCache[$key])) {
            return $this->slotCache[$key];
        }

        $type = $this->types->find($typeId);
        if (!$type) {
            return $this->slotCache[$key] = ['before' => 0, 'after' => 0];
        }
        $rule = $this->matchingRule($type, $resourceId, $start, $end);
        return $this->slotCache[$key] = $this->fromTypeAndRule($type, $rule);
    }

    /** @return array{before:int,after:int} */
    public function fromTypeAndRule(object $type, ?object $rule): array {
        $typeBefore = max(0, (int)($type->buffer_before_minutes ?? 0));
        $typeAfter = max(0, (int)($type->buffer_after_minutes ?? 0));
        return [
            'before' => $typeBefore > 0 ? $typeBefore : max(0, (int)($rule->buffer_before_minutes ?? 0)),
            'after' => $typeAfter > 0 ? $typeAfter : max(0, (int)($rule->buffer_after_minutes ?? 0)),
        ];
    }

    public function matchingRuleForSlot(int $typeId, int $resourceId, string $start, string $end): ?object {
        $type = $this->types->find($typeId);
        return $type ? $this->matchingRule($type, $resourceId, $start, $end) : null;
    }

    /**
     * Upper bounds used only to select a safe candidate set of existing rows.
     * Exact overlap is always checked again with each booking's effective buffer.
     *
     * @return array{before:int,after:int}
     */
    public function maxConfigured(): array {
        if ($this->maxCache !== null) {
            return $this->maxCache;
        }
        global $wpdb;
        $types = $wpdb->prefix . 'wpcb_booking_types';
        $rules = $wpdb->prefix . 'wpcb_availability_rules';
        $before = max(
            0,
            (int)$wpdb->get_var("SELECT COALESCE(MAX(buffer_before_minutes), 0) FROM {$types}"),
            (int)$wpdb->get_var("SELECT COALESCE(MAX(buffer_before_minutes), 0) FROM {$rules} WHERE is_active = 1")
        );
        $after = max(
            0,
            (int)$wpdb->get_var("SELECT COALESCE(MAX(buffer_after_minutes), 0) FROM {$types}"),
            (int)$wpdb->get_var("SELECT COALESCE(MAX(buffer_after_minutes), 0) FROM {$rules} WHERE is_active = 1")
        );
        return $this->maxCache = ['before' => $before, 'after' => $after];
    }

    private function matchingRule(object $type, int $resourceId, string $start, string $end): ?object {
        $startUtc = Time::parseUtc($start);
        $endUtc = Time::parseUtc($end);
        if (!$startUtc || !$endUtc || $endUtc <= $startUtc || $resourceId < 1) {
            return null;
        }

        $timezone = Time::bookingTimezone();
        $startLocal = $startUtc->setTimezone($timezone);
        $endLocal = $endUtc->setTimezone($timezone);
        if ($startLocal->format('Y-m-d') !== $endLocal->format('Y-m-d')) {
            return null;
        }

        $weekday = (int)$startLocal->format('N');
        $startMinute = ((int)$startLocal->format('H') * 60) + (int)$startLocal->format('i');
        $endMinute = ((int)$endLocal->format('H') * 60) + (int)$endLocal->format('i');
        $actualDuration = (int)(($endUtc->getTimestamp() - $startUtc->getTimestamp()) / 60);

        foreach ($this->availability->rulesForTypeAndResource((int)$type->id, $resourceId) as $rule) {
            if ((int)$rule->weekday !== $weekday) {
                continue;
            }
            $ruleStart = $this->clockMinutes((string)$rule->start_time);
            $ruleEnd = $this->clockMinutes((string)$rule->end_time);
            $duration = max(1, (int)($type->duration_minutes ?: $rule->slot_duration_minutes));
            if ($ruleStart === null || $ruleEnd === null || $actualDuration !== $duration) {
                continue;
            }
            if ($startMinute < $ruleStart || $endMinute > $ruleEnd || $endMinute <= $startMinute) {
                continue;
            }
            if ((($startMinute - $ruleStart) % $duration) !== 0) {
                continue;
            }
            return $rule;
        }
        return null;
    }

    private function clockMinutes(string $time): ?int {
        if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $match)) {
            return null;
        }
        $hour = (int)$match[1];
        $minute = (int)$match[2];
        return ($hour <= 23 && $minute <= 59) ? ($hour * 60 + $minute) : null;
    }
}
