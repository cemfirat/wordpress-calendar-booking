<?php
namespace Wpcb\Booking;

use Wpcb\Resources\ResourceRepository;
use Wpcb\WaitingList\WaitingListRepository;

final class CapacityService {
    private BookingRepository $bookings;
    private BookingTypeRepository $types;
    private ResourceRepository $resources;

    public function __construct(
        ?BookingRepository $bookings = null,
        ?BookingTypeRepository $types = null,
        ?ResourceRepository $resources = null
    ) {
        $this->bookings = $bookings ?: new BookingRepository();
        $this->types = $types ?: new BookingTypeRepository();
        $this->resources = $resources ?: new ResourceRepository();
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

    public function remaining(
        int $typeId,
        int $resourceId,
        string $start,
        string $end,
        ?int $ignoreBookingId = null
    ): int {
        $capacity = $this->effectiveCapacity($typeId, $resourceId);
        if ($capacity < 1) {
            return 0;
        }
        $used = $this->bookings->occupiedSeats($start, $end, $resourceId, $ignoreBookingId);
        $held = 0;
        global $wpdb;
        $waitTable = $wpdb->prefix . 'wpcb_waiting_list';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $waitTable)) === $waitTable) {
            $held = (new WaitingListRepository())->activeHeldSeats($typeId, $resourceId, $start, $end);
        }
        return max(0, $capacity - $used - $held);
    }

    public function canFit(
        int $typeId,
        int $resourceId,
        string $start,
        string $end,
        int $partySize,
        ?int $ignoreBookingId = null
    ): bool {
        return $partySize > 0
            && $partySize <= $this->remaining($typeId, $resourceId, $start, $end, $ignoreBookingId);
    }
}
