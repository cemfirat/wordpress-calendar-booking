<?php
namespace Wpcb\WaitingList;

use Wpcb\Availability\BufferPolicy;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Booking\CapacityService;
use Wpcb\Booking\ReservationService;
use Wpcb\Resources\ResourceLock;
use Wpcb\Resources\ResourceRepository;
use Wpcb\Security\SecretBox;
use Wpcb\Support\Time;
use Wpcb\Tokens\SlotTokenService;
use Wpcb\Mail\SpecialNotificationMailer;
use Wpcb\Payments\CheckoutHandoffService;

final class WaitingListService {
    private WaitingListRepository $repo;
    private CapacityService $capacity;
    private ResourceLock $locks;
    private BookingTypeRepository $types;
    private ResourceRepository $resources;
    private BufferPolicy $buffers;

    public function __construct(
        ?WaitingListRepository $repo = null,
        ?CapacityService $capacity = null,
        ?ResourceLock $locks = null,
        ?BookingTypeRepository $types = null,
        ?ResourceRepository $resources = null,
        ?BufferPolicy $buffers = null
    ) {
        $this->repo = $repo ?: new WaitingListRepository();
        $this->capacity = $capacity ?: new CapacityService();
        $this->locks = $locks ?: new ResourceLock();
        $this->types = $types ?: new BookingTypeRepository();
        $this->resources = $resources ?: new ResourceRepository();
        $this->buffers = $buffers ?: new BufferPolicy(null, $this->types);
    }

    public function join(array $data) {
        $typeId = (int)($data['booking_type_id'] ?? 0);
        $resourceId = (int)($data['resource_id'] ?? 0);
        $start = (string)($data['slot_start'] ?? '');
        $end = (string)($data['slot_end'] ?? '');
        $partySize = max(1, (int)($data['party_size'] ?? 1));
        $email = sanitize_email((string)($data['email'] ?? ''));

        $startUtc = Time::parseUtc($start);
        $endUtc = Time::parseUtc($end);
        $type = $this->types->find($typeId);
        $resource = $this->resources->find($resourceId);
        if ($typeId < 1 || $resourceId < 1 || !$startUtc || !$endUtc || !is_email($email)
            || $endUtc <= $startUtc || $startUtc <= Time::nowUtc()
            || !$type || empty($type->is_active) || empty($type->is_public)
            || !$resource || empty($resource->is_active)
            || !$this->resources->isAssignedToBookingType($resourceId, $typeId)
            || !$this->buffers->matchingRuleForSlot($typeId, $resourceId, $start, $end)
            || $partySize > $this->capacity->effectiveCapacity($typeId, $resourceId)
        ) {
            return new \WP_Error('wpcb_waitlist_invalid', 'Waiting-list request is invalid.');
        }
        if ($this->capacity->canFit($typeId, $resourceId, $start, $end, $partySize)) {
            return new \WP_Error('wpcb_waitlist_not_full', 'This slot still has enough capacity and can be booked directly.');
        }
        $duplicate = $this->repo->findDuplicate($typeId, $resourceId, $start, $end, $email);
        if ($duplicate) {
            return (int)$duplicate->id;
        }

        $id = $this->repo->create([
            'booking_type_id' => $typeId,
            'resource_id' => $resourceId,
            'slot_start' => $start,
            'slot_end' => $end,
            'party_size' => $partySize,
            'full_name' => sanitize_text_field((string)($data['full_name'] ?? '')),
            'email' => $email,
            'phone' => sanitize_text_field((string)($data['phone'] ?? '')),
        ]);
        return $id > 0 ? $id : new \WP_Error('wpcb_waitlist_storage', 'Waiting-list entry could not be stored.');
    }

    public function promoteSlot(int $typeId, int $resourceId, string $start, string $end): int {
        if (!$this->locks->acquire($resourceId, 5)) {
            return 0;
        }
        try {
            $this->repo->expireOffers();
            $remaining = $this->capacity->remaining($typeId, $resourceId, $start, $end);
            if ($remaining < 1) {
                return 0;
            }
            $entry = $this->repo->nextWaiting($typeId, $resourceId, $start, $end, $remaining);
            if (!$entry) {
                return 0;
            }

            $selector = bin2hex(random_bytes(8));
            $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $encrypted = (new SecretBox())->encrypt($verifier);
            if (is_wp_error($encrypted)) {
                return 0;
            }
            $expires = Time::formatUtc(Time::nowUtc()->modify('+30 minutes'));
            if (!$this->repo->markOffered((int)$entry->id, $selector, hash('sha256', $verifier), $encrypted, $expires)) {
                return 0;
            }

            wp_schedule_single_event(time() + 5, 'wpcb_waitlist_send_offer', [(int)$entry->id]);
            return (int)$entry->id;
        } finally {
            $this->locks->release($resourceId);
        }
    }

    public function sendOffer(int $entryId): void {
        (new SpecialNotificationMailer())->sendWaitingListOffer($entryId);
    }

    public function accept(int $entryId, string $token) {
        [$selector, $verifier] = array_pad(explode('.', $token, 2), 2, '');
        $candidate = $this->repo->acceptIfTokenMatches($entryId, $selector, $verifier);
        if (!$candidate) {
            return new \WP_Error('wpcb_waitlist_token_invalid', 'This waiting-list offer is invalid, expired or already being claimed.');
        }

        $paymentPreflight = (new CheckoutHandoffService())->preflightType((int)$candidate->booking_type_id);
        if (is_wp_error($paymentPreflight)) {
            return $paymentPreflight;
        }

        $resourceId = (int)$candidate->resource_id;
        if (!$this->locks->acquire($resourceId, 5)) {
            return new \WP_Error('wpcb_waitlist_busy', 'The offered resource is busy. Please try again.');
        }

        try {
            // Re-read and claim only after the resource serialization boundary.
            // The claiming row remains a capacity hold for every other caller.
            $fresh = $this->repo->claimOffer($entryId, $selector, $verifier);
            if (!$fresh) {
                return new \WP_Error('wpcb_waitlist_token_invalid', 'This waiting-list offer is invalid, expired or already being claimed.');
            }

            $slotToken = (new SlotTokenService())->issue(
                (int)$fresh->booking_type_id,
                (string)$fresh->slot_start,
                (string)$fresh->slot_end,
                $resourceId
            );
            $bookingId = (new ReservationService())->reserve(
                $slotToken,
                (int)$fresh->booking_type_id,
                [
                    'full_name' => (string)$fresh->full_name,
                    'email' => (string)$fresh->email,
                    'phone' => (string)$fresh->phone,
                    'party_size' => (int)$fresh->party_size,
                    'source' => 'waiting_list',
                    'lang' => 'de',
                ],
                ['waiting_list_entry_id' => (int)$fresh->id],
                fn(int $createdBookingId): bool => $this->repo->markAccepted(
                    (int)$fresh->id,
                    $createdBookingId
                )
            );

            if (is_wp_error($bookingId)) {
                $this->repo->resetClaim($entryId);
                return $bookingId;
            }
            return (int)$bookingId;
        } finally {
            $this->locks->release($resourceId);
        }
    }

    public function expireAndRepromote(): int {
        $this->repo->expireOffers();
        $promoted = 0;
        foreach ($this->repo->waitingSlots(100) as $entry) {
            if ($this->promoteSlot(
                (int)$entry->booking_type_id,
                (int)$entry->resource_id,
                (string)$entry->slot_start,
                (string)$entry->slot_end
            ) > 0) {
                ++$promoted;
            }
        }
        return $promoted;
    }
}
