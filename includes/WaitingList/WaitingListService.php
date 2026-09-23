<?php
namespace Wpcb\WaitingList;

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingStatus;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Booking\CapacityService;
use Wpcb\Admin\Settings;
use Wpcb\Mail\Mailer;
use Wpcb\Payments\PaymentService;
use Wpcb\Resources\ResourceLock;
use Wpcb\Support\Time;
use Wpcb\Sync\JobRepository;
use Wpcb\Tokens\TokenService;

final class WaitingListService {
    private WaitingListRepository $entries;
    private CapacityService $capacity;
    private ResourceLock $locks;

    public function __construct(
        ?WaitingListRepository $entries = null,
        ?CapacityService $capacity = null,
        ?ResourceLock $locks = null
    ) {
        $this->entries = $entries ?: new WaitingListRepository();
        $this->capacity = $capacity ?: new CapacityService();
        $this->locks = $locks ?: new ResourceLock();
    }

    public function boot(): void {
        add_action('wpcb_booking_transitioned', [$this, 'onBookingTransition'], 30, 2);
        add_action('wpcb_booking_event_recorded', [$this, 'onBookingEvent'], 30, 2);
        add_action('wpcb_waiting_list_maintenance', [$this, 'maintenance']);
        if (!wp_next_scheduled('wpcb_waiting_list_maintenance')) {
            wp_schedule_event(time() + 600, 'hourly', 'wpcb_waiting_list_maintenance');
        }
    }

    /** @return int|\WP_Error */
    public function join(int $typeId, int $resourceId, string $start, string $end, int $partySize, string $email, string $returnUrl = '') {
        $type = (new BookingTypeRepository())->find($typeId);
        if (!$type || empty($type->waiting_list_enabled)) {
            return new \WP_Error('wpcb_waitlist_disabled', 'Waiting list is not enabled for this booking type.');
        }
        $partySize = max(1, $partySize);
        if ($this->capacity->canFit($typeId, $resourceId, $start, $end, $partySize)) {
            return new \WP_Error('wpcb_waitlist_capacity_available', 'This slot still has enough capacity and can be booked directly.');
        }
        $id = $this->entries->join([
            'booking_type_id' => $typeId,
            'resource_id' => $resourceId,
            'slot_start' => $start,
            'slot_end' => $end,
            'party_size' => $partySize,
            'email' => $email,
            'return_url' => $returnUrl,
        ]);
        return $id > 0 ? $id : new \WP_Error('wpcb_waitlist_storage', 'Waiting-list entry could not be stored.');
    }

    public function promoteForSlot(int $typeId, int $resourceId, string $start, string $end): int {
        if ($resourceId < 1 || !$this->locks->acquire($resourceId, 5)) return 0;
        try {
            $this->entries->expireOffers(100);
            $remaining = $this->capacity->remaining($typeId, $resourceId, $start, $end);
            if ($remaining < 1) return 0;

            $promoted = 0;
            foreach ($this->entries->waitingForSlot($typeId, $resourceId, $start, $end, 100) as $entry) {
                $partySize = max(1, (int)$entry->party_size);
                if ($partySize > $remaining) continue;
                $token = (new WaitingListToken())->issue();
                $minutes = max(5, (int)((new BookingTypeRepository())->find($typeId)->waiting_list_offer_minutes ?? 30));
                $expires = Time::formatUtc(Time::nowUtc()->modify('+' . $minutes . ' minutes'));
                if (!$this->entries->offer((int)$entry->id, $token['selector'], $token['hash'], $expires)) continue;

                $jobId = (new JobRepository())->enqueue(
                    'waiting_list_offer',
                    0,
                    ['entry_id' => (int)$entry->id, 'token' => $token['token']],
                    'waitlist:offer:' . (int)$entry->id . ':' . substr(hash('sha256', $expires), 0, 24)
                );
                if ($jobId < 1) {
                    // Keep the hold: queue retries/operations can recover it through maintenance.
                }
                $remaining -= $partySize;
                ++$promoted;
                if ($remaining < 1) break;
            }
            return $promoted;
        } finally {
            $this->locks->release($resourceId);
        }
    }

    public function promoteWaiting(int $limit = 50): int {
        $count = 0;
        foreach ($this->entries->waitingSlots($limit) as $slot) {
            $count += $this->promoteForSlot(
                (int)$slot->booking_type_id,
                (int)$slot->resource_id,
                (string)$slot->slot_start,
                (string)$slot->slot_end
            );
        }
        return $count;
    }

    public function maintenance(): void {
        $expired = $this->entries->expireOffers(200);
        $this->promoteWaiting(100);
        $this->entries->cleanup(90);
        if ($expired) {
            $this->promoteWaiting(100);
        }
    }

    public function onBookingTransition(array $event, $booking): void {
        if (!$booking || empty($event['changed'])) return;
        if (!in_array((string)($event['to'] ?? ''), [
            BookingStatus::CANCELLED,
            BookingStatus::REJECTED,
            BookingStatus::EXPIRED,
        ], true)) return;
        $this->promoteForSlot(
            (int)$booking->booking_type_id,
            (int)$booking->resource_id,
            (string)$booking->slot_start,
            (string)$booking->slot_end
        );
    }

    public function onBookingEvent(array $event, $booking): void {
        if (!$booking || empty($event['changed'])) return;
        // A reschedule frees capacity somewhere. Bounded scan avoids relying on
        // customer-provided old slot data and also handles capacity config changes.
        $this->promoteWaiting(50);
    }

    /** @return int|\WP_Error */
    public function accept(string $token) {
        $tokenService = new WaitingListToken();
        $selector = $tokenService->selector($token);
        $entry = $selector !== '' ? $this->entries->findOffer($selector) : null;
        if (!$entry || (string)$entry->status !== 'offered' || !$tokenService->verify($token, $entry)) {
            return new \WP_Error('wpcb_waitlist_offer_invalid', 'This waiting-list offer is invalid or already used.');
        }
        $expires = Time::parseUtc((string)$entry->offer_expires_at);
        if (!$expires || $expires < Time::nowUtc()) {
            return new \WP_Error('wpcb_waitlist_offer_expired', 'This waiting-list offer has expired.');
        }

        $resourceId = (int)$entry->resource_id;
        if (!$this->locks->acquire($resourceId, 5)) {
            return new \WP_Error('wpcb_waitlist_busy', 'This offer is currently being processed.');
        }
        try {
            $fresh = $this->entries->find((int)$entry->id);
            if (!$fresh || (string)$fresh->status !== 'offered' || !$tokenService->verify($token, $fresh)) {
                return new \WP_Error('wpcb_waitlist_offer_invalid', 'This waiting-list offer is invalid or already used.');
            }

            // The active offer itself already occupies these held seats. Check
            // real booking occupancy separately to ensure the released capacity
            // was not consumed through another path.
            $bookings = new BookingRepository();
            $capacity = (new CapacityService())->effectiveCapacity((int)$fresh->booking_type_id, $resourceId);
            $occupied = $bookings->occupiedSeats((string)$fresh->slot_start, (string)$fresh->slot_end, $resourceId);
            if ($occupied + max(1, (int)$fresh->party_size) > $capacity) {
                return new \WP_Error('wpcb_waitlist_capacity_lost', 'The offered capacity is no longer available.');
            }

            $now = Time::formatUtc(Time::nowUtc());
            $bookingId = $bookings->create([
                'booking_uuid' => wp_generate_uuid4(),
                'booking_type_id' => (int)$fresh->booking_type_id,
                'resource_id' => $resourceId,
                'slot_start' => (string)$fresh->slot_start,
                'slot_end' => (string)$fresh->slot_end,
                'status' => BookingStatus::RESERVED_UNCONFIRMED,
                'party_size' => max(1, (int)$fresh->party_size),
                'full_name' => '',
                'email' => (string)$fresh->email,
                'phone' => '',
                'notes' => '',
                'source' => 'waiting_list',
                'lang' => 'de',
                'reserved_until' => Time::formatUtc(Time::nowUtc()->modify('+30 minutes')),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($bookingId < 1 || !$this->entries->accept((int)$fresh->id, $bookingId)) {
                if ($bookingId > 0) {
                    global $wpdb;
                    $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
                    $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
                }
                return new \WP_Error('wpcb_waitlist_accept_failed', 'The offered slot could not be reserved.');
            }

            $payment = (new PaymentService())->ensureForBooking($bookingId);
            if (is_wp_error($payment)) {
                return $payment;
            }

            $settings = Settings::get();
            $doi = (new TokenService())->create($bookingId, 'doi', (int)$settings['token_ttl_minutes']);
            $booking = (array)$bookings->find($bookingId);
            $confirmUrl = add_query_arg(
                ['wpcb_action' => 'confirm', 'wpcb_token' => rawurlencode($doi)],
                home_url('/')
            );
            (new Mailer())->sendTemplateOnce(
                'mail:user:' . $bookingId . ':doi',
                'doi',
                $booking,
                [],
                ['confirm' => $confirmUrl],
                false
            );

            do_action('wpcb_waiting_list_accepted', $this->entries->find((int)$fresh->id), $bookings->find($bookingId));
            return $bookingId;
        } finally {
            $this->locks->release($resourceId);
        }
    }
}
