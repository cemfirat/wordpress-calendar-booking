<?php
namespace Wpcb\Booking;

use Wpcb\Availability\SlotService;
use Wpcb\Resources\ResourceLock;
use Wpcb\Support\Time;

/**
 * Applies legal lifecycle events and performs the status write atomically.
 */
final class BookingTransitionService {
    public const RESCHEDULED = 'rescheduled';

    private BookingRepository $bookings;
    private BookingStateMachine $machine;
    private SlotService $slots;
    private ResourceLock $locks;

    public function __construct(
        ?BookingRepository $bookings = null,
        ?BookingStateMachine $machine = null,
        ?SlotService $slots = null,
        ?ResourceLock $locks = null
    ) {
        $this->bookings = $bookings ?: new BookingRepository();
        $this->machine = $machine ?: new BookingStateMachine();
        $this->slots = $slots ?: new SlotService();
        $this->locks = $locks ?: new ResourceLock();
    }

    /**
     * @return array|\WP_Error
     */
    public function apply(int $bookingId, string $event, string $actor = 'system', string $note = '') {
        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return new \WP_Error('wpcb_booking_missing', 'Booking not found.');
        }

        $current = (string)$booking->status;
        $target = $this->machine->targetForEvent($event);
        if ($target === null || !$this->machine->canApply($current, $event)) {
            return new \WP_Error('wpcb_transition_illegal', 'This booking transition is not allowed.');
        }

        if ($current === $target) {
            return $this->result($bookingId, $event, $current, $target, $actor, false);
        }

        if ($this->requiresAvailabilityRevalidation($event)
            && !$this->slots->slotAvailable(
                (int)$booking->booking_type_id,
                (string)$booking->slot_start,
                (string)$booking->slot_end,
                $bookingId,
                !empty($booking->resource_id) ? (int)$booking->resource_id : null
            )
        ) {
            return new \WP_Error('wpcb_slot_unavailable', 'The booked slot is no longer available.');
        }

        $now = Time::formatUtc(Time::nowUtc());
        $fields = [];

        if (in_array($event, [
            BookingStateMachine::EMAIL_CONFIRMED_APPROVAL,
            BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
        ], true)) {
            $fields['confirmed_at'] = $now;
            $fields['reserved_until'] = null;
        }

        if ($target === BookingStatus::CONFIRMED) {
            $fields['approved_at'] = $now;
            $fields['reserved_until'] = null;
        } elseif ($target === BookingStatus::CANCELLED) {
            $fields['cancelled_at'] = $now;
            $fields['reserved_until'] = null;
        } elseif (in_array($target, [BookingStatus::REJECTED, BookingStatus::EXPIRED], true)) {
            $fields['reserved_until'] = null;
        }

        $changed = $this->bookings->transitionStatus(
            $bookingId,
            $current,
            $target,
            $fields,
            $event,
            $actor,
            $note
        );

        if (!$changed) {
            $fresh = $this->bookings->find($bookingId);
            if ($fresh && (string)$fresh->status === $target) {
                return $this->result($bookingId, $event, $current, $target, $actor, false);
            }
            return new \WP_Error('wpcb_transition_race', 'The booking changed while the transition was being applied.');
        }

        $fresh = $this->bookings->find($bookingId);
        $result = $this->result($bookingId, $event, $current, $target, $actor, true);
        do_action('wpcb_booking_transitioned', $result, $fresh);
        return $result;
    }

    /**
     * Atomically move a booking while keeping its lifecycle state.
     *
     * @return array|\WP_Error
     */
    public function reschedule(
        int $bookingId,
        string $newStart,
        string $newEnd,
        string $actor = 'user',
        string $note = 'Booking rescheduled',
        ?int $newResourceId = null
    ) {
        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return new \WP_Error('wpcb_booking_missing', 'Booking not found.');
        }

        $status = (string)$booking->status;
        if (!in_array($status, [BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED], true)) {
            return new \WP_Error('wpcb_event_illegal', 'This booking cannot be rescheduled in its current state.');
        }

        $start = Time::parseUtc($newStart);
        $end = Time::parseUtc($newEnd);
        if (!$start || !$end || $end <= $start) {
            return new \WP_Error('wpcb_slot_invalid', 'The replacement slot is invalid.');
        }

        $currentResourceId = !empty($booking->resource_id) ? (int)$booking->resource_id : null;
        $targetResourceId = $newResourceId ?: (int)($currentResourceId ?? 0);
        if ($targetResourceId < 1) {
            return new \WP_Error('wpcb_resource_missing', 'The replacement resource is invalid.');
        }

        if (!$this->locks->acquire($targetResourceId, 5)) {
            return new \WP_Error('wpcb_reservation_busy', 'The selected resource is busy. Please try again.');
        }

        try {
            $freshBeforeMove = $this->bookings->find($bookingId);
            if (!$freshBeforeMove
                || (string)$freshBeforeMove->status !== $status
                || (string)$freshBeforeMove->slot_start !== (string)$booking->slot_start
                || (string)$freshBeforeMove->slot_end !== (string)$booking->slot_end
                || (int)($freshBeforeMove->resource_id ?? 0) !== (int)($currentResourceId ?? 0)
            ) {
                return new \WP_Error('wpcb_event_race', 'The booking changed while it was being rescheduled.');
            }

            if (!$this->slots->slotAvailable(
                (int)$booking->booking_type_id,
                $newStart,
                $newEnd,
                $bookingId,
                $targetResourceId
            )) {
                return new \WP_Error('wpcb_slot_unavailable', 'The replacement slot is no longer available.');
            }

            $updated = $this->bookings->moveWhenPositionMatches(
                $bookingId,
                $status,
                $currentResourceId,
                (string)$booking->slot_start,
                (string)$booking->slot_end,
                $targetResourceId,
                $newStart,
                $newEnd
            );
            if (!$updated) {
                return new \WP_Error('wpcb_event_race', 'The booking changed while it was being rescheduled.');
            }
        } finally {
            $this->locks->release($targetResourceId);
        }

        $this->bookings->logEvent($bookingId, $status, self::RESCHEDULED, $actor, $note);
        $fresh = $this->bookings->find($bookingId);
        $result = $this->result($bookingId, self::RESCHEDULED, $status, $status, $actor, true);
        do_action('wpcb_booking_event_recorded', $result, $fresh);
        return $result;
    }

    public function expireReservations(int $limit = 100): int {
        $expired = 0;
        foreach ($this->bookings->expiredReservationIds($limit) as $bookingId) {
            $result = $this->apply(
                $bookingId,
                BookingStateMachine::RESERVATION_EXPIRED,
                'system',
                'Unconfirmed reservation expired'
            );
            if (is_array($result) && !empty($result['changed'])) {
                ++$expired;
            }
        }
        return $expired;
    }

    private function requiresAvailabilityRevalidation(string $event): bool {
        return in_array($event, [
            BookingStateMachine::EMAIL_CONFIRMED_APPROVAL,
            BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
            BookingStateMachine::ADMIN_APPROVED,
        ], true);
    }

    private function result(
        int $bookingId,
        string $event,
        string $from,
        string $to,
        string $actor,
        bool $changed
    ): array {
        return [
            'booking_id' => $bookingId,
            'event' => $event,
            'from' => $from,
            'to' => $to,
            'actor' => $actor,
            'changed' => $changed,
        ];
    }
}
