<?php
namespace Wpcb\Booking;

use Wpcb\Admin\Settings;
use Wpcb\Availability\SlotSelectionService;
use Wpcb\Support\Time;

/**
 * Creates the initial booking reservation inside a serialized critical section.
 *
 * The lock is intentionally site-wide for 2.0's single logical resource model.
 * This is conservative but correct. A future resource model can narrow the lock
 * key without changing the reservation invariant.
 */
class ReservationService {
    private BookingRepository $bookings;
    private SlotSelectionService $selection;

    public function __construct(?BookingRepository $bookings = null, ?SlotSelectionService $selection = null) {
        $this->bookings = $bookings ?: new BookingRepository();
        $this->selection = $selection ?: new SlotSelectionService();
    }

    /**
     * @return int|\WP_Error Booking ID on success.
     */
    public function reserve(string $slotToken, int $expectedTypeId, array $customer, array $meta = []) {
        if ($expectedTypeId < 1 || !$this->selection->resolve($slotToken, $expectedTypeId)) {
            return new \WP_Error('wpcb_slot_unavailable', 'The selected slot is invalid, expired or no longer available.');
        }

        $lockName = $this->lockName();
        if (!$this->acquireLock($lockName, 5)) {
            return new \WP_Error('wpcb_reservation_busy', 'The booking system is busy. Please try again.');
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
            $this->releaseLock($lockName);
        }
    }

    private function lockName(): string {
        return 'wpcb_reserve_' . md5(home_url('/'));
    }

    private function acquireLock(string $name, int $timeoutSeconds): bool {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, max(0, $timeoutSeconds)));
        return (int)$result === 1;
    }

    private function releaseLock(string $name): void {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
}
