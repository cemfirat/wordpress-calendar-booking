<?php
namespace Cemb\Booking;

use Cemb\Availability\SlotService;
use Cemb\Support\Time;

/**
 * Applies legal lifecycle events and performs the status write atomically.
 */
final class BookingTransitionService {
    public const RESCHEDULED = 'rescheduled';

    private BookingRepository $bookings;
    private BookingStateMachine $machine;
    private SlotService $slots;

    public function __construct(
        ?BookingRepository $bookings = null,
        ?BookingStateMachine $machine = null,
        ?SlotService $slots = null
    ) {
        $this->bookings = $bookings ?: new BookingRepository();
        $this->machine = $machine ?: new BookingStateMachine();
        $this->slots = $slots ?: new SlotService();
    }

    /**
     * @return array|\WP_Error
     */
    public function apply(int $bookingId, string $event, string $actor = 'system', string $note = '') {
        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return new \WP_Error('cemb_booking_missing', 'Booking not found.');
        }

        $current = (string)$booking->status;
        $target = $this->machine->targetForEvent($event);
        if ($target === null || !$this->machine->canApply($current, $event)) {
            return new \WP_Error('cemb_transition_illegal', 'This booking transition is not allowed.');
        }

        if ($current === $target) {
            return $this->result($bookingId, $event, $current, $target, $actor, false);
        }

        if ($this->requiresAvailabilityRevalidation($event)
            && !$this->slots->slotAvailable(
                (int)$booking->booking_type_id,
                (string)$booking->slot_start,
                (string)$booking->slot_end,
                $bookingId
            )
        ) {
            return new \WP_Error('cemb_slot_unavailable', 'The booked slot is no longer available.');
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
            return new \WP_Error('cemb_transition_race', 'The booking changed while the transition was being applied.');
        }

        $fresh = $this->bookings->find($bookingId);
        $result = $this->result($bookingId, $event, $current, $target, $actor, true);
        do_action('cemb_booking_transitioned', $result, $fresh);
        return $result;
    }

    /**
     * Record a non-state lifecycle event such as rescheduling.
     *
     * @return array|\WP_Error
     */
    public function recordEvent(int $bookingId, string $event, string $actor = 'system', string $note = '') {
        if ($event !== self::RESCHEDULED) {
            return new \WP_Error('cemb_event_unknown', 'Unknown booking lifecycle event.');
        }

        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return new \WP_Error('cemb_booking_missing', 'Booking not found.');
        }

        $status = (string)$booking->status;
        if (!in_array($status, [BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED], true)) {
            return new \WP_Error('cemb_event_illegal', 'This lifecycle event is not allowed for the booking state.');
        }

        $this->bookings->logEvent($bookingId, $status, $event, $actor, $note);
        $result = $this->result($bookingId, $event, $status, $status, $actor, true);
        do_action('cemb_booking_event_recorded', $result, $booking);
        return $result;
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
