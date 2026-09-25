<?php
namespace Wpcb\Booking;

use Wpcb\Availability\BufferPolicy;
use Wpcb\Resources\ResourceRepository;
use Wpcb\Support\Time;

final class CapacityService {
    private BookingRepository $bookings;
    private BookingTypeRepository $types;
    private ResourceRepository $resources;
    private BufferPolicy $buffers;

    public function __construct(
        ?BookingRepository $bookings = null,
        ?BookingTypeRepository $types = null,
        ?ResourceRepository $resources = null,
        ?BufferPolicy $buffers = null
    ) {
        $this->bookings = $bookings ?: new BookingRepository();
        $this->types = $types ?: new BookingTypeRepository();
        $this->resources = $resources ?: new ResourceRepository();
        $this->buffers = $buffers ?: new BufferPolicy(null, $this->types);
    }

    public function effectiveCapacity(int $typeId, int $resourceId): int {
        $type = $this->types->find($typeId);
        $resource = $this->resources->find($resourceId);
        if (!$type || !$resource) {
            return 0;
        }
        $typeCapacity = max(1, (int)($type->capacity ?? 1));
        $resourceCapacity = max(1, (int)($resource->capacity ?? 1));
        return min($typeCapacity, $resourceCapacity);
    }

    /**
     * Capacity always receives the raw appointment interval. Both the
     * candidate and every existing booking are expanded by the same effective
     * buffer policy before half-open overlap is evaluated.
     *
     * @param array{before:int,after:int}|null $candidateBuffers
     */
    public function remaining(
        int $typeId,
        int $resourceId,
        string $start,
        string $end,
        ?int $ignoreBookingId = null,
        ?array $candidateBuffers = null,
        ?int $ignoreWaitingListEntryId = null
    ): int {
        $capacity = $this->effectiveCapacity($typeId, $resourceId);
        $startUtc = Time::parseUtc($start);
        $endUtc = Time::parseUtc($end);
        if ($capacity < 1 || !$startUtc || !$endUtc || $endUtc <= $startUtc) {
            return 0;
        }

        $candidateBuffers = $candidateBuffers ?: $this->buffers->forSlot($typeId, $resourceId, $start, $end);
        $candidateBefore = max(0, (int)($candidateBuffers['before'] ?? 0));
        $candidateAfter = max(0, (int)($candidateBuffers['after'] ?? 0));
        $candidateStart = Time::addMinutes($start, -$candidateBefore);
        $candidateEnd = Time::addMinutes($end, $candidateAfter);
        if (!$candidateStart || !$candidateEnd) {
            return 0;
        }

        $used = 0;
        $max = $this->buffers->maxConfigured();
        $searchFrom = Time::addMinutes($candidateStart, -max(0, (int)($max['after'] ?? 0)));
        $searchTo = Time::addMinutes($candidateEnd, max(0, (int)($max['before'] ?? 0)));
        if (!$searchFrom || !$searchTo) {
            return 0;
        }

        $candidateStartUtc = Time::parseUtc($candidateStart);
        $candidateEndUtc = Time::parseUtc($candidateEnd);
        foreach ($this->bookings->blockingBookings($searchFrom, $searchTo, $resourceId, $ignoreBookingId) as $booking) {
            $existingResourceId = (int)($booking->resource_id ?? 0);
            if ($existingResourceId < 1) {
                // Legacy unscoped rows conservatively block every resource.
                $existingResourceId = $resourceId;
            }
            $existingBuffers = $this->buffers->forSlot(
                (int)$booking->booking_type_id,
                $existingResourceId,
                (string)$booking->slot_start,
                (string)$booking->slot_end
            );
            $existingStart = Time::parseUtc((string)Time::addMinutes(
                (string)$booking->slot_start,
                -max(0, (int)($existingBuffers['before'] ?? 0))
            ));
            $existingEnd = Time::parseUtc((string)Time::addMinutes(
                (string)$booking->slot_end,
                max(0, (int)($existingBuffers['after'] ?? 0))
            ));
            if ($existingStart && $existingEnd
                && $existingStart < $candidateEndUtc
                && $existingEnd > $candidateStartUtc
            ) {
                $used += max(1, (int)($booking->party_size ?? 1));
            }
        }

        $held = $this->waitingListHeldSeats(
            $resourceId,
            $candidateStartUtc,
            $candidateEndUtc,
            $searchFrom,
            $searchTo,
            $ignoreWaitingListEntryId
        );
        return max(0, $capacity - $used - $held);
    }

    private function waitingListHeldSeats(
        int $resourceId,
        \DateTimeImmutable $candidateStartUtc,
        \DateTimeImmutable $candidateEndUtc,
        string $searchFrom,
        string $searchTo,
        ?int $ignoreWaitingListEntryId = null
    ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_waiting_list';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return 0;
        }

        $sql = "SELECT id, booking_type_id, resource_id, slot_start, slot_end, party_size
                FROM {$table}
                WHERE resource_id = %d
                  AND status IN ('offered','claiming')
                  AND offer_expires_at >= %s
                  AND slot_start < %s
                  AND slot_end > %s";
        $params = [
            $resourceId,
            Time::formatUtc(Time::nowUtc()),
            $searchTo,
            $searchFrom,
        ];
        if ($ignoreWaitingListEntryId && $ignoreWaitingListEntryId > 0) {
            $sql .= ' AND id != %d';
            $params[] = $ignoreWaitingListEntryId;
        }

        $held = 0;
        foreach ($wpdb->get_results($wpdb->prepare($sql, ...$params)) as $offer) {
            $offerBuffers = $this->buffers->forSlot(
                (int)$offer->booking_type_id,
                $resourceId,
                (string)$offer->slot_start,
                (string)$offer->slot_end
            );
            $offerStart = Time::parseUtc((string)Time::addMinutes(
                (string)$offer->slot_start,
                -max(0, (int)($offerBuffers['before'] ?? 0))
            ));
            $offerEnd = Time::parseUtc((string)Time::addMinutes(
                (string)$offer->slot_end,
                max(0, (int)($offerBuffers['after'] ?? 0))
            ));
            if ($offerStart && $offerEnd
                && $offerStart < $candidateEndUtc
                && $offerEnd > $candidateStartUtc
            ) {
                $held += max(1, (int)($offer->party_size ?? 1));
            }
        }
        return $held;
    }

    /**
     * @param array{before:int,after:int}|null $candidateBuffers
     */
    public function canFit(
        int $typeId,
        int $resourceId,
        string $start,
        string $end,
        int $partySize,
        ?int $ignoreBookingId = null,
        ?array $candidateBuffers = null,
        ?int $ignoreWaitingListEntryId = null
    ): bool {
        return $partySize > 0
            && $partySize <= $this->remaining(
                $typeId,
                $resourceId,
                $start,
                $end,
                $ignoreBookingId,
                $candidateBuffers,
                $ignoreWaitingListEntryId
            );
    }
}
