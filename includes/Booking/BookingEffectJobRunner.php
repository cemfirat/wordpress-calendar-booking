<?php
namespace Wpcb\Booking;

/**
 * Replays one committed lifecycle effect from a privacy-minimal technical
 * snapshot while reading current customer fields from the booking repository.
 */
final class BookingEffectJobRunner {
    private BookingRepository $bookings;

    public function __construct(?BookingRepository $bookings = null) {
        $this->bookings = $bookings ?: new BookingRepository();
    }

    public function run(array $payload, int $bookingId): array {
        $kind = sanitize_key((string)($payload['kind'] ?? ''));
        $effectKey = sanitize_text_field((string)($payload['effect_key'] ?? ''));
        $snapshot = is_array($payload['booking'] ?? null) ? $payload['booking'] : [];
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : [];
        if ($bookingId < 1 || $effectKey === '' || !in_array($kind, ['created', 'transition', 'event'], true)) {
            return ['ok' => false, 'message' => 'Invalid booking effect intent.'];
        }

        $current = $this->bookings->find($bookingId);
        if (!$current) {
            return ['ok' => true, 'message' => 'Booking no longer exists; lifecycle effect is obsolete.'];
        }

        $booking = clone $current;
        foreach ([
            'id', 'booking_uuid', 'booking_type_id', 'resource_id',
            'series_id', 'series_occurrence', 'slot_start', 'slot_end',
            'status', 'party_size', 'source', 'lang', 'reserved_until',
            'confirmed_at', 'approved_at', 'cancelled_at', 'created_at', 'updated_at',
        ] as $field) {
            if (array_key_exists($field, $snapshot)) {
                $booking->{$field} = $snapshot[$field];
            }
        }
        $event['effect_key'] = $effectKey;

        if ($kind === 'created') {
            do_action('wpcb_booking_created', $booking);
        } elseif ($kind === 'transition') {
            do_action('wpcb_booking_transitioned', $event, $booking);
        } else {
            do_action('wpcb_booking_event_recorded', $event, $booking);
        }

        return ['ok' => true, 'message' => 'Booking lifecycle effect dispatched.'];
    }
}
