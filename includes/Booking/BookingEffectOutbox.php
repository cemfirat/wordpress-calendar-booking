<?php
namespace Wpcb\Booking;

use Wpcb\Sync\JobRepository;
use Wpcb\Sync\QueueService;

/**
 * Durable lifecycle-effect intent stored in the existing leased queue.
 *
 * Payloads intentionally contain only technical booking state. Customer fields,
 * one-time tokens, secret URLs and message bodies are re-read only when the
 * effect is delivered and are never copied into the outbox payload.
 */
final class BookingEffectOutbox {
    public const JOB_TYPE = 'booking_effect';

    private JobRepository $jobs;

    public function __construct(?JobRepository $jobs = null) {
        $this->jobs = $jobs ?: new JobRepository();
    }

    public function recordCreated(object $booking): int {
        return $this->record('created', [], $booking);
    }

    public function recordTransition(array $event, object $booking): int {
        return $this->record('transition', $event, $booking);
    }

    public function recordEvent(array $event, object $booking): int {
        return $this->record('event', $event, $booking);
    }

    public function kick(): void {
        try {
            (new QueueService())->runNow(10);
        } catch (\Throwable $error) {
            // The durable row is already committed. Cron/manual recovery owns
            // delivery if an immediate opportunistic drain cannot start.
        }
    }

    private function record(string $kind, array $event, object $booking): int {
        $bookingId = (int)($booking->id ?? 0);
        $uuid = (string)($booking->booking_uuid ?? '');
        if ($bookingId < 1 || $uuid === '' || !in_array($kind, ['created', 'transition', 'event'], true)) {
            return 0;
        }

        $snapshot = $this->snapshot($booking);
        $safeEvent = $this->eventSnapshot($event);
        $fingerprint = hash('sha256', wp_json_encode([
            'kind' => $kind,
            'booking' => $snapshot,
            'event' => $safeEvent,
        ]));
        $effectKey = 'booking-effect:' . $kind . ':' . substr($fingerprint, 0, 48);

        return $this->jobs->enqueue(
            self::JOB_TYPE,
            $bookingId,
            [
                'kind' => $kind,
                'effect_key' => $effectKey,
                'booking' => $snapshot,
                'event' => $safeEvent,
            ],
            $effectKey
        );
    }

    private function snapshot(object $booking): array {
        $fields = [
            'id', 'booking_uuid', 'booking_type_id', 'resource_id',
            'series_id', 'series_occurrence', 'slot_start', 'slot_end',
            'status', 'party_size', 'source', 'lang', 'reserved_until',
            'confirmed_at', 'approved_at', 'cancelled_at', 'created_at', 'updated_at',
        ];
        $result = [];
        foreach ($fields as $field) {
            $value = $booking->{$field} ?? null;
            if (in_array($field, ['id', 'booking_type_id', 'resource_id', 'series_id', 'series_occurrence', 'party_size'], true)) {
                $result[$field] = $value === null ? null : (int)$value;
            } else {
                $result[$field] = $value === null ? null : (string)$value;
            }
        }
        return $result;
    }

    private function eventSnapshot(array $event): array {
        $safe = [];
        foreach ([
            'booking_id', 'event', 'from', 'to', 'actor', 'changed',
            'previous_resource_id', 'previous_slot_start', 'previous_slot_end',
        ] as $field) {
            if (!array_key_exists($field, $event)) {
                continue;
            }
            if (in_array($field, ['booking_id', 'previous_resource_id'], true)) {
                $safe[$field] = (int)$event[$field];
            } elseif ($field === 'changed') {
                $safe[$field] = (bool)$event[$field];
            } else {
                $safe[$field] = sanitize_text_field((string)$event[$field]);
            }
        }
        return $safe;
    }
}
