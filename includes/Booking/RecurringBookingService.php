<?php
namespace Wpcb\Booking;

use Wpcb\Admin\Settings;
use Wpcb\Availability\SlotService;
use Wpcb\Tokens\SlotTokenService;
use Wpcb\Resources\ResourceLock;
use Wpcb\Support\Time;
use Wpcb\Payments\PaymentService;

final class RecurringBookingService {
    private BookingRepository $bookings;
    private BookingSeriesRepository $series;
    private BookingTypeRepository $types;
    private SlotTokenService $tokens;
    private SlotService $slots;
    private ResourceLock $locks;

    public function __construct(
        ?BookingRepository $bookings = null,
        ?BookingSeriesRepository $series = null,
        ?BookingTypeRepository $types = null,
        ?SlotTokenService $tokens = null,
        ?SlotService $slots = null,
        ?ResourceLock $locks = null
    ) {
        $this->bookings = $bookings ?: new BookingRepository();
        $this->series = $series ?: new BookingSeriesRepository();
        $this->types = $types ?: new BookingTypeRepository();
        $this->tokens = $tokens ?: new SlotTokenService();
        $this->slots = $slots ?: new SlotService();
        $this->locks = $locks ?: new ResourceLock();
    }

    /**
     * Reserve a bounded weekly series. The first signed slot anchors the local
     * wall-clock cadence; subsequent occurrences are regenerated server-side.
     *
     * @return array|\WP_Error
     */
    public function reserveWeekly(
        string $slotToken,
        int $typeId,
        array $customer,
        array $meta,
        int $count,
        int $intervalWeeks = 1
    ) {
        $count = max(2, min(24, $count));
        $intervalWeeks = max(1, min(4, $intervalWeeks));
        $partySize = max(1, (int)($customer['party_size'] ?? 1));

        $type = $this->types->find($typeId);
        if (!$type) {
            return new \WP_Error('wpcb_series_type_missing', 'Booking type not found.');
        }
        $anchor = $this->tokens->verify($slotToken);
        if (!$anchor || (int)$anchor['type_id'] !== $typeId) {
            return new \WP_Error('wpcb_series_anchor_invalid', 'The first recurring slot is invalid or expired.');
        }

        $resourceId = (int)$anchor['resource_id'];
        $occurrences = $this->weeklyOccurrences(
            (string)$anchor['start'],
            (string)$anchor['end'],
            $count,
            $intervalWeeks
        );
        if (is_wp_error($occurrences)) {
            return $occurrences;
        }

        if (!$this->locks->acquire($resourceId, 8)) {
            return new \WP_Error('wpcb_series_busy', 'The selected resource is busy. Please try again.');
        }

        global $wpdb;
        $created = [];
        try {
            foreach ($occurrences as $occurrence) {
                if (!$this->slots->isCanonicalSlot(
                    $typeId,
                    $occurrence['start'],
                    $occurrence['end'],
                    null,
                    $resourceId,
                    $partySize
                )) {
                    return new \WP_Error(
                        'wpcb_series_occurrence_unavailable',
                        'At least one occurrence in the recurring series is no longer available.'
                    );
                }
            }

            $wpdb->query('START TRANSACTION');
            $seriesId = $this->series->create([
                'booking_type_id' => $typeId,
                'resource_id' => $resourceId,
                'frequency' => 'weekly',
                'interval_count' => $intervalWeeks,
                'occurrence_count' => $count,
                'timezone' => Time::bookingTimezoneName(),
            ]);
            if ($seriesId < 1) {
                $wpdb->query('ROLLBACK');
                return new \WP_Error('wpcb_series_storage', 'The recurring series could not be stored.');
            }

            $settings = Settings::get();
            $now = Time::formatUtc(Time::nowUtc());
            foreach ($occurrences as $index => $occurrence) {
                $bookingId = $this->bookings->create([
                    'booking_uuid' => wp_generate_uuid4(),
                    'booking_type_id' => $typeId,
                    'resource_id' => $resourceId,
                    'series_id' => $seriesId,
                    'series_occurrence' => $index,
                    'slot_start' => $occurrence['start'],
                    'slot_end' => $occurrence['end'],
                    'status' => BookingStatus::RESERVED_UNCONFIRMED,
                    'party_size' => $partySize,
                    'full_name' => (string)($customer['full_name'] ?? ''),
                    'email' => (string)($customer['email'] ?? ''),
                    'phone' => (string)($customer['phone'] ?? ''),
                    'notes' => (string)($customer['notes'] ?? ''),
                    'source' => (string)($customer['source'] ?? 'frontend'),
                    'lang' => (string)($customer['lang'] ?? 'de'),
                    'reserved_until' => Time::formatUtc(
                        Time::nowUtc()->modify('+' . max(1, (int)$settings['reservation_ttl_minutes']) . ' minutes')
                    ),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $meta, false);
                if ($bookingId < 1) {
                    $wpdb->query('ROLLBACK');
                    return new \WP_Error('wpcb_series_storage', 'One occurrence could not be stored.');
                }
                $created[] = $bookingId;
            }

            if ((string)($type->payment_mode ?? 'free') === 'required') {
                $payment = (new PaymentService())->ensureForBooking((int)$created[0]);
                if (is_wp_error($payment) || !$payment) {
                    $wpdb->query('ROLLBACK');
                    return is_wp_error($payment)
                        ? $payment
                        : new \WP_Error('wpcb_series_payment_storage', 'The recurring series payment could not be stored.');
                }
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('wpcb_series_storage', $error->getMessage());
        } finally {
            $this->locks->release($resourceId);
        }

        foreach ($created as $bookingId) {
            $booking = $this->bookings->find($bookingId);
            if ($booking) {
                do_action('wpcb_booking_created', $booking);
            }
        }

        return [
            'series_id' => $seriesId,
            'booking_ids' => $created,
            'primary_booking_id' => (int)$created[0],
            'occurrence_count' => count($created),
        ];
    }

    /**
     * Apply a lifecycle event to all occurrences from the selected booking onward.
     * Existing terminal occurrences are skipped.
     *
     * @return array|\WP_Error
     */
    public function applyRemaining(int $bookingId, string $event, string $actor = 'user', string $note = '') {
        $booking = $this->bookings->find($bookingId);
        if (!$booking || empty($booking->series_id)) {
            return new \WP_Error('wpcb_series_missing', 'Recurring series not found.');
        }

        $members = $this->series->members((int)$booking->series_id, (int)$booking->series_occurrence);
        $transitions = new BookingTransitionService($this->bookings);
        $changed = [];
        $isCancellation = in_array($event, [
            BookingStateMachine::USER_CANCELLED,
            BookingStateMachine::ADMIN_CANCELLED,
        ], true);
        $requiresWholePaidSeries = $isCancellation || $event === BookingStateMachine::ADMIN_REJECTED;
        $allowPaidSeriesCancellation = false;
        if ($requiresWholePaidSeries) {
            $type = $this->types->find((int)$booking->booking_type_id);
            $allowPaidSeriesCancellation = $type
                && (string)($type->payment_mode ?? 'free') === 'required';
            $validation = (new PaymentService())->validateSeriesCancellation(
                $bookingId,
                $allowPaidSeriesCancellation
            );
            if (is_wp_error($validation)) {
                return $validation;
            }
        }
        foreach ($members as $member) {
            if (in_array((string)$member->status, BookingStatus::terminalStatuses(), true)) {
                continue;
            }
            $result = $transitions->apply(
                (int)$member->id,
                $event,
                $actor,
                $note,
                $allowPaidSeriesCancellation
            );
            if (is_wp_error($result)) {
                return $result;
            }
            if (!empty($result['changed'])) {
                $changed[] = (int)$member->id;
            }
        }

        if ($isCancellation) {
            $allMembers = $this->series->members((int)$booking->series_id, 0);
            $allCancelled = $allMembers && count(array_filter(
                $allMembers,
                static fn($member) => (string)$member->status === BookingStatus::CANCELLED
            )) === count($allMembers);
            $this->series->markStatus(
                (int)$booking->series_id,
                $allCancelled ? 'cancelled' : 'partially_cancelled'
            );
        } elseif ($event === BookingStateMachine::ADMIN_REJECTED) {
            $this->series->markStatus((int)$booking->series_id, 'rejected');
        } elseif ($event === BookingStateMachine::RESERVATION_EXPIRED) {
            $this->series->markStatus((int)$booking->series_id, 'expired');
        }
        return ['series_id' => (int)$booking->series_id, 'changed_booking_ids' => $changed];
    }

    /**
     * Move this occurrence and all later occurrences, preserving the weekly cadence.
     *
     * @return array|\WP_Error
     */
    public function rescheduleRemaining(
        int $bookingId,
        string $newStart,
        string $newEnd,
        int $newResourceId,
        string $actor = 'user'
    ) {
        $booking = $this->bookings->find($bookingId);
        if (!$booking || empty($booking->series_id)) {
            return new \WP_Error('wpcb_series_missing', 'Recurring series not found.');
        }
        $series = $this->series->find((int)$booking->series_id);
        if (!$series) {
            return new \WP_Error('wpcb_series_missing', 'Recurring series not found.');
        }

        $members = $this->series->members((int)$series->id, (int)$booking->series_occurrence);
        if (!$members) {
            return new \WP_Error('wpcb_series_empty', 'No remaining recurring bookings were found.');
        }

        $baseStart = Time::parseUtc($newStart);
        $baseEnd = Time::parseUtc($newEnd);
        if (!$baseStart || !$baseEnd || $baseEnd <= $baseStart || $newResourceId < 1) {
            return new \WP_Error('wpcb_series_slot_invalid', 'The replacement recurring slot is invalid.');
        }

        $tz = Time::bookingTimezone();
        $localStart = $baseStart->setTimezone($tz);
        $localEnd = $baseEnd->setTimezone($tz);
        $interval = max(1, (int)$series->interval_count);
        $partySize = max(1, (int)($booking->party_size ?? 1));

        $targets = [];
        foreach ($members as $offset => $member) {
            $weeks = $offset * $interval;
            $targetStartLocal = $localStart->modify('+' . $weeks . ' weeks');
            $targetEndLocal = $localEnd->modify('+' . $weeks . ' weeks');
            $targets[] = [
                'member' => $member,
                'start' => Time::formatUtc($targetStartLocal),
                'end' => Time::formatUtc($targetEndLocal),
            ];
        }

        $resourceIds = array_values(array_unique(array_filter([
            (int)($booking->resource_id ?? 0),
            $newResourceId,
        ])));
        sort($resourceIds);
        foreach ($resourceIds as $resourceId) {
            if (!$this->locks->acquire($resourceId, 8)) {
                $this->locks->releaseAll();
                return new \WP_Error('wpcb_series_busy', 'A resource in the recurring series is busy. Please try again.');
            }
        }

        global $wpdb;
        try {
            foreach ($targets as $target) {
                $member = $target['member'];
                if (!$this->slots->isCanonicalSlot(
                    (int)$member->booking_type_id,
                    $target['start'],
                    $target['end'],
                    (int)$member->id,
                    $newResourceId,
                    $partySize
                )) {
                    return new \WP_Error(
                        'wpcb_series_occurrence_unavailable',
                        'At least one replacement occurrence is unavailable.'
                    );
                }
            }

            $wpdb->query('START TRANSACTION');
            foreach ($targets as $target) {
                $member = $target['member'];
                $moved = $this->bookings->moveWhenPositionMatches(
                    (int)$member->id,
                    (string)$member->status,
                    !empty($member->resource_id) ? (int)$member->resource_id : null,
                    (string)$member->slot_start,
                    (string)$member->slot_end,
                    $newResourceId,
                    $target['start'],
                    $target['end']
                );
                if (!$moved) {
                    $wpdb->query('ROLLBACK');
                    return new \WP_Error('wpcb_series_race', 'The recurring series changed while it was being rescheduled.');
                }
            }
            $wpdb->query('COMMIT');
        } finally {
            $this->locks->releaseAll();
        }

        foreach ($targets as $target) {
            $member = $target['member'];
            $this->bookings->logEvent(
                (int)$member->id,
                (string)$member->status,
                BookingTransitionService::RESCHEDULED,
                $actor,
                'Recurring booking rescheduled'
            );
            $fresh = $this->bookings->find((int)$member->id);
            do_action('wpcb_booking_event_recorded', [
                'booking_id' => (int)$member->id,
                'event' => BookingTransitionService::RESCHEDULED,
                'from' => (string)$member->status,
                'to' => (string)$member->status,
                'actor' => $actor,
                'changed' => true,
                'previous_resource_id' => (int)($member->resource_id ?? 0),
                'previous_slot_start' => (string)$member->slot_start,
                'previous_slot_end' => (string)$member->slot_end,
            ], $fresh);
        }

        return ['series_id' => (int)$series->id, 'changed_booking_ids' => array_map(
            static fn($target) => (int)$target['member']->id,
            $targets
        )];
    }

    private function weeklyOccurrences(string $startUtc, string $endUtc, int $count, int $intervalWeeks) {
        $start = Time::parseUtc($startUtc);
        $end = Time::parseUtc($endUtc);
        if (!$start || !$end || $end <= $start) {
            return new \WP_Error('wpcb_series_anchor_invalid', 'The recurring anchor time is invalid.');
        }

        $tz = Time::bookingTimezone();
        $localStart = $start->setTimezone($tz);
        $localEnd = $end->setTimezone($tz);
        $out = [];

        for ($i = 0; $i < $count; ++$i) {
            $weeks = $i * $intervalWeeks;
            $candidateStart = $localStart->modify('+' . $weeks . ' weeks');
            $candidateEnd = $localEnd->modify('+' . $weeks . ' weeks');

            $localStartString = $candidateStart->format(Time::STORAGE_FORMAT);
            $localEndString = $candidateEnd->format(Time::STORAGE_FORMAT);
            if (!Time::parseLocal($localStartString) || !Time::parseLocal($localEndString)) {
                return new \WP_Error(
                    'wpcb_series_dst_invalid',
                    'One recurring occurrence falls into an ambiguous or non-existent local time.'
                );
            }

            $out[] = [
                'start' => Time::formatUtc($candidateStart),
                'end' => Time::formatUtc($candidateEnd),
            ];
        }

        return $out;
    }
}
