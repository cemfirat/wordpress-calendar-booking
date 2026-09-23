<?php
namespace Wpcb\Booking;

use Wpcb\Admin\Settings;
use Wpcb\Availability\SlotSelectionService;
use Wpcb\Resources\ResourceLock;
use Wpcb\Support\Time;

/**
 * Creates the initial booking reservation inside a serialized critical section.
 *
 * Reservation writers are serialized per resource so independent staff/resources
 * can accept bookings concurrently without weakening overlap protection.
 */
class ReservationService {
    private BookingRepository $bookings;
    private SlotSelectionService $selection;
    private ResourceLock $locks;

    public function __construct(
        ?BookingRepository $bookings = null,
        ?SlotSelectionService $selection = null,
        ?ResourceLock $locks = null
    ) {
        $this->bookings = $bookings ?: new BookingRepository();
        $this->selection = $selection ?: new SlotSelectionService();
        $this->locks = $locks ?: new ResourceLock();
    }

    /**
     * @return int|\WP_Error Booking ID on success.
     */
    public function reserve(string $slotToken, int $expectedTypeId, array $customer, array $meta = []) {
        $initial = $expectedTypeId > 0
            ? $this->selection->resolve($slotToken, $expectedTypeId)
            : null;
        if (!$initial || empty($initial['resource_id'])) {
            return new \WP_Error('wpcb_slot_unavailable', 'The selected slot is invalid, expired or no longer available.');
        }

        $resourceId = (int)$initial['resource_id'];
        if (!$this->locks->acquire($resourceId, 5)) {
            return new \WP_Error('wpcb_reservation_busy', 'The selected resource is busy. Please try again.');
        }

        try {
            // The second check is the important one: it runs after all other
            // reservation writers using this service have been serialized.
            $slot = $this->selection->resolve($slotToken, $expectedTypeId);
            if (!$slot) {
                return new \WP_Error('wpcb_slot_unavailable', 'The selected slot is no longer available.');
            }

            $settings = Settings::get();
            $now = Time::formatUtc(Time::nowUtc());
            $bookingId = $this->bookings->create([
                'booking_uuid' => wp_generate_uuid4(),
                'booking_type_id' => (int)$slot['type_id'],
                'resource_id' => (int)$slot['resource_id'],
                'slot_start' => (string)$slot['start'],
                'slot_end' => (string)$slot['end'],
                'status' => BookingStatus::RESERVED_UNCONFIRMED,
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
            ], $meta);

            if ($bookingId < 1) {
                return new \WP_Error('wpcb_reservation_storage', 'The booking reservation could not be stored.');
            }
            return $bookingId;
        } finally {
            $this->locks->release($resourceId);
        }
    }
}
