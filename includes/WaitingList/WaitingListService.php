<?php
namespace Wpcb\WaitingList;

use Wpcb\Booking\CapacityService;
use Wpcb\Booking\ReservationService;
use Wpcb\Resources\ResourceLock;
use Wpcb\Security\SecretBox;
use Wpcb\Support\Time;
use Wpcb\Tokens\SlotTokenService;

final class WaitingListService {
    private WaitingListRepository $repo;
    private CapacityService $capacity;
    private ResourceLock $locks;

    public function __construct(
        ?WaitingListRepository $repo = null,
        ?CapacityService $capacity = null,
        ?ResourceLock $locks = null
    ) {
        $this->repo = $repo ?: new WaitingListRepository();
        $this->capacity = $capacity ?: new CapacityService();
        $this->locks = $locks ?: new ResourceLock();
    }

    public function join(array $data) {
        $typeId = (int)($data['booking_type_id'] ?? 0);
        $resourceId = (int)($data['resource_id'] ?? 0);
        $start = (string)($data['slot_start'] ?? '');
        $end = (string)($data['slot_end'] ?? '');
        $partySize = max(1, (int)($data['party_size'] ?? 1));
        $email = sanitize_email((string)($data['email'] ?? ''));

        if ($typeId < 1 || $resourceId < 1 || !Time::parseUtc($start) || !Time::parseUtc($end) || !is_email($email)) {
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
        $entry = $this->repo->find($entryId);
        if (!$entry || (string)$entry->status !== 'offered' || empty($entry->offer_secret_enc)) {
            return;
        }
        $verifier = (new SecretBox())->decrypt((string)$entry->offer_secret_enc);
        if ($verifier === null) {
            return;
        }
        $token = (string)$entry->offer_selector . '.' . $verifier;
        $url = add_query_arg([
            'wpcb_waitlist_action' => 'accept',
            'wpcb_waitlist_id' => (int)$entry->id,
            'wpcb_waitlist_token' => rawurlencode($token),
        ], home_url('/'));
        $subject = __('A booking slot is available', 'wordpress-calendar-booking');
        $body = sprintf(
            __("Hello %s,\n\na place became available for your requested appointment. Confirm within 30 minutes:\n%s", 'wordpress-calendar-booking'),
            (string)$entry->full_name,
            $url
        );
        wp_mail((string)$entry->email, $subject, nl2br(esc_html($body)), ['Content-Type: text/html; charset=UTF-8']);
    }

    public function accept(int $entryId, string $token) {
        [$selector, $verifier] = array_pad(explode('.', $token, 2), 2, '');
        $fresh = $this->repo->claimOffer($entryId, $selector, $verifier);
        if (!$fresh) {
            return new \WP_Error('wpcb_waitlist_token_invalid', 'This waiting-list offer is invalid, expired or already being claimed.');
        }

        $resourceId = (int)$fresh->resource_id;
        $slotToken = (new SlotTokenService())->issue(
            (int)$fresh->booking_type_id,
            (string)$fresh->slot_start,
            (string)$fresh->slot_end,
            $resourceId
        );
        $bookingId = (new ReservationService())->reserve($slotToken, (int)$fresh->booking_type_id, [
            'full_name' => (string)$fresh->full_name,
            'email' => (string)$fresh->email,
            'phone' => (string)$fresh->phone,
            'party_size' => (int)$fresh->party_size,
            'source' => 'waiting_list',
            'lang' => 'de',
        ], ['waiting_list_entry_id' => (int)$fresh->id]);

        if (is_wp_error($bookingId)) {
            $this->repo->resetClaim($entryId);
            return $bookingId;
        }
        if (!$this->repo->markAccepted($entryId, (int)$bookingId)) {
            return new \WP_Error('wpcb_waitlist_accept_race', 'The waiting-list offer changed while it was being accepted.');
        }
        return (int)$bookingId;
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
