<?php
namespace Wpcb\Booking;

use Wpcb\Availability\SlotService;
use Wpcb\Resources\ResourceLock;
use Wpcb\Support\Time;
use Wpcb\Payments\PaymentService;

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
    public function apply(int $bookingId, string $event, string $actor = 'system', string $note = '', bool $allowPaidSeriesCancellation = false) {
        $results = $this->applyBatch([$bookingId], $event, $actor, $note, $allowPaidSeriesCancellation);
        return is_wp_error($results) ? $results : $results[0];
    }

    /**
     * Apply a bounded group under sorted resource locks. All state writes in a
     * group commit together; callbacks run only after commit and lock release.
     * This does not replace the durable side-effect outbox tracked in #159.
     *
     * @param int[] $bookingIds
     * @return array|\WP_Error List of transition results, or one group failure.
     */
    public function applyBatch(
        array $bookingIds,
        string $event,
        string $actor = 'system',
        string $note = '',
        bool $allowPaidSeriesCancellation = false,
        bool $onlyIfExpired = false
    ) {
        $bookingIds = array_values(array_unique(array_map('intval', $bookingIds)));
        if (!$bookingIds || count($bookingIds) > 24 || min($bookingIds) < 1) {
            return new \WP_Error('wpcb_transition_batch_invalid', 'Invalid booking transition group.');
        }
        $resourcesByBooking = [];
        foreach ($bookingIds as $id) {
            $booking = $this->bookings->find($id);
            if (!$booking) {
                return new \WP_Error('wpcb_booking_missing', 'Booking not found.');
            }
            $resourceId = (int)($booking->resource_id ?? 0);
            // Legacy resource-less rows may be expired/cancelled (capacity
            // decreases), but must never acquire new capacity by confirmation.
            if ($resourceId < 1 && $this->requiresAvailabilityRevalidation($event)
                && (string)$booking->status !== $this->machine->targetForEvent($event)
            ) {
                return new \WP_Error('wpcb_resource_missing', 'The booking has no valid resource.');
            }
            $resourcesByBooking[$id] = $resourceId;
        }
        $resourceIds = array_values(array_unique(array_filter(array_values($resourcesByBooking))));
        sort($resourceIds, SORT_NUMERIC);
        $held = [];
        $results = [];
        $notifications = [];
        $transaction = false;
        global $wpdb;
        try {
            foreach ($resourceIds as $resourceId) {
                if (!$this->locks->acquire($resourceId, 5)) {
                    return new \WP_Error('wpcb_reservation_busy', 'The selected resource is busy. Please try again.');
                }
                $held[] = $resourceId;
            }
            // Start the transaction AFTER all advisory locks. Under InnoDB's
            // default isolation the first consistent read must see the winner
            // of any operation we waited for, not a pre-lock snapshot.
            if (count($bookingIds) > 1) {
                if ($wpdb->query('START TRANSACTION') === false) {
                    return new \WP_Error('wpcb_transition_storage', 'The booking transition could not be stored.');
                }
                $transaction = true;
            }
            foreach ($bookingIds as $id) {
                $booking = $this->bookings->find($id);
                // A concurrent cross-resource move may have happened while we
                // waited. Never chase a newly discovered lock out of order.
                if (!$booking || (int)($booking->resource_id ?? 0) !== $resourcesByBooking[$id]) {
                    return new \WP_Error('wpcb_transition_race', 'The booking changed while the transition was being applied.');
                }
                $result = $this->applyLocked($booking, $event, $actor, $note,
                    $allowPaidSeriesCancellation, $onlyIfExpired);
                if (is_wp_error($result)) {
                    return $result;
                }
                $results[] = $result;
                if (!empty($result['changed'])) {
                    $notifications[] = [$result, $this->bookings->find($id)];
                }
            }
            if ($transaction) {
                if ($wpdb->query('COMMIT') === false) {
                    return new \WP_Error('wpcb_transition_storage', 'The booking transition could not be stored.');
                }
                $transaction = false;
            }
        } catch (\Throwable $error) {
            return new \WP_Error('wpcb_transition_storage', 'The booking transition could not be stored.');
        } finally {
            if ($transaction) {
                $wpdb->query('ROLLBACK');
            }
            foreach (array_reverse($held) as $resourceId) {
                $this->locks->release($resourceId);
            }
        }
        foreach ($notifications as [$result, $booking]) {
            do_action('wpcb_booking_transitioned', $result, $booking);
        }
        return $results;
    }

    /** @return array|\WP_Error Caller owns the booking's resource lock. */
    private function applyLocked(
        object $booking,
        string $event,
        string $actor,
        string $note,
        bool $allowPaidSeriesCancellation,
        bool $onlyIfExpired
    ) {
        $bookingId = (int)$booking->id;
        if (in_array($event, [
            BookingStateMachine::USER_CANCELLED,
            BookingStateMachine::ADMIN_CANCELLED,
            BookingStateMachine::ADMIN_REJECTED,
        ], true)) {
            $cancellation = (new PaymentService())->validateSeriesCancellation(
                $bookingId,
                $allowPaidSeriesCancellation
            );
            if (is_wp_error($cancellation)) {
                return $cancellation;
            }
        }

        $current = (string)$booking->status;
        $target = $this->machine->targetForEvent($event);
        if ($target === null || !$this->machine->canApply($current, $event)) {
            return new \WP_Error('wpcb_transition_illegal', 'This booking transition is not allowed.');
        }

        if ($current === $target) {
            return $this->result($bookingId, $event, $current, $target, $actor, false);
        }

        $deadline = !empty($booking->reserved_until)
            ? Time::parseUtc((string)$booking->reserved_until)
            : null;
        if ($this->requiresAvailabilityRevalidation($event)
            && $current === BookingStatus::RESERVED_UNCONFIRMED
            && !empty($booking->reserved_until)
            && (!$deadline || $deadline <= Time::nowUtc())
        ) {
            return new \WP_Error('wpcb_reservation_expired', __('The reservation has expired. Please select a new slot.', 'wordpress-calendar-booking'));
        }
        // Scheduled expiry rechecks eligibility after acquiring the lock.
        // Explicit compensation calls (e.g. payment setup failure) may still
        // expire a hold early, and therefore leave onlyIfExpired at false.
        if ($onlyIfExpired && $event === BookingStateMachine::RESERVATION_EXPIRED
            && (!$deadline || $deadline > Time::nowUtc())
        ) {
            return $this->result($bookingId, $event, $current, $current, $actor, false);
        }

        if (in_array($event, [
            BookingStateMachine::EMAIL_CONFIRMED_APPROVAL,
            BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
            BookingStateMachine::ADMIN_APPROVED,
        ], true) && !(new PaymentService())->canConfirm($bookingId)) {
            return new \WP_Error('wpcb_payment_required', 'Payment must be completed before this booking can be confirmed.');
        }

        if ($this->requiresAvailabilityRevalidation($event)
            && !$this->slots->slotAvailable(
                (int)$booking->booking_type_id,
                (string)$booking->slot_start,
                (string)$booking->slot_end,
                $bookingId,
                !empty($booking->resource_id) ? (int)$booking->resource_id : null,
                max(1, (int)($booking->party_size ?? 1))
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

        return $this->result($bookingId, $event, $current, $target, $actor, true);
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

        $resourceIds = array_values(array_unique(array_filter([(int)$currentResourceId, $targetResourceId])));
        sort($resourceIds, SORT_NUMERIC);
        $held = [];
        try {
            foreach ($resourceIds as $resourceId) {
                if (!$this->locks->acquire($resourceId, 5)) {
                    return new \WP_Error('wpcb_reservation_busy', 'The selected resource is busy. Please try again.');
                }
                $held[] = $resourceId;
            }
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
                $targetResourceId,
                max(1, (int)($booking->party_size ?? 1))
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
            foreach (array_reverse($held) as $resourceId) {
                $this->locks->release($resourceId);
            }
        }

        $this->bookings->logEvent($bookingId, $status, self::RESCHEDULED, $actor, $note);
        $fresh = $this->bookings->find($bookingId);
        $result = $this->result($bookingId, self::RESCHEDULED, $status, $status, $actor, true);
        $result['previous_resource_id'] = (int)($currentResourceId ?? 0);
        $result['previous_slot_start'] = (string)$booking->slot_start;
        $result['previous_slot_end'] = (string)$booking->slot_end;
        do_action('wpcb_booking_event_recorded', $result, $fresh);
        return $result;
    }

    public function expireReservations(int $limit = 100): int {
        $expired = 0;
        foreach ($this->bookings->expiredReservationIds($limit) as $bookingId) {
            $results = $this->applyBatch(
                [$bookingId],
                BookingStateMachine::RESERVATION_EXPIRED,
                'system',
                'Unconfirmed reservation expired',
                false,
                true
            );
            if (is_array($results) && !empty($results[0]['changed'])) {
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
