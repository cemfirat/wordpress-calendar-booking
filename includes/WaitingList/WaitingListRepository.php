<?php
namespace Wpcb\WaitingList;

use Wpcb\Support\Time;

final class WaitingListRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_waiting_list';
    }

    public function create(array $data): int {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $ok = $wpdb->insert($this->table, array_merge([
            'entry_uuid' => wp_generate_uuid4(),
            'status' => 'waiting',
            'party_size' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $data));
        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public function find(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id)) ?: null;
    }

    public function findDuplicate(int $typeId, int $resourceId, string $start, string $end, string $email): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE booking_type_id = %d AND resource_id = %d
               AND slot_start = %s AND slot_end = %s AND email = %s
               AND status IN ('waiting','offered')
             ORDER BY id ASC LIMIT 1",
            $typeId, $resourceId, $start, $end, $email
        )) ?: null;
    }

    public function offeredSeats(int $typeId, int $resourceId, string $start, string $end): int {
        global $wpdb;
        return max(0, (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(party_size), 0) FROM {$this->table}
             WHERE booking_type_id = %d AND resource_id = %d
               AND slot_start = %s AND slot_end = %s
               AND status IN ('offered','claiming')
               AND offer_expires_at >= %s",
            $typeId, $resourceId, $start, $end, Time::formatUtc(Time::nowUtc())
        )));
    }

    public function nextWaiting(int $typeId, int $resourceId, string $start, string $end, int $maxPartySize): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE booking_type_id = %d AND resource_id = %d
               AND slot_start = %s AND slot_end = %s
               AND status = 'waiting' AND party_size <= %d
             ORDER BY created_at ASC, id ASC LIMIT 1",
            $typeId, $resourceId, $start, $end, $maxPartySize
        )) ?: null;
    }

    public function markOffered(int $id, string $selector, string $hash, string $secretEnc, string $expiresAt): bool {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        return 1 === (int)$wpdb->update($this->table, [
            'status' => 'offered',
            'offer_selector' => $selector,
            'offer_hash' => $hash,
            'offer_secret_enc' => $secretEnc,
            'offer_expires_at' => $expiresAt,
            'offered_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id, 'status' => 'waiting']);
    }

    public function rotateOfferedToken(
        int $id,
        string $expectedOfferedAt,
        string $selector,
        string $hash,
        string $secretEnc,
        string $expiresAt
    ): bool {
        global $wpdb;
        return 1 === (int)$wpdb->update(
            $this->table,
            [
                'offer_selector' => $selector,
                'offer_hash' => $hash,
                'offer_secret_enc' => $secretEnc,
                'offer_expires_at' => $expiresAt,
                'updated_at' => Time::formatUtc(Time::nowUtc()),
            ],
            [
                'id' => $id,
                'status' => 'offered',
                'offered_at' => $expectedOfferedAt,
            ]
        );
    }

    public function acceptIfTokenMatches(int $id, string $selector, string $verifier): ?object {
        global $wpdb;
        $row = $this->find($id);
        if (!$row || (string)$row->status !== 'offered'
            || !hash_equals((string)$row->offer_selector, $selector)
            || !hash_equals((string)$row->offer_hash, hash('sha256', $verifier))
            || empty($row->offer_expires_at)
            || (string)$row->offer_expires_at < Time::formatUtc(Time::nowUtc())) {
            return null;
        }
        return $row;
    }

    public function claimOffer(int $id, string $selector, string $verifier): ?object {
        global $wpdb;
        $row = $this->acceptIfTokenMatches($id, $selector, $verifier);
        if (!$row) return null;
        $changed = $wpdb->update($this->table, [
            'status' => 'claiming',
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ], ['id' => $id, 'status' => 'offered']);
        return 1 === (int)$changed ? $this->find($id) : null;
    }

    public function resetClaim(int $id): void {
        global $wpdb;
        $wpdb->update($this->table, [
            'status' => 'offered',
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ], ['id' => $id, 'status' => 'claiming']);
    }

    public function markAccepted(int $id, int $bookingId): bool {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        return 1 === (int)$wpdb->update($this->table, [
            'status' => 'accepted',
            'booking_id' => $bookingId,
            'offer_selector' => null,
            'offer_hash' => null,
            'offer_secret_enc' => null,
            'offer_expires_at' => null,
            'accepted_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id, 'status' => 'claiming']);
    }

    public function waitingSlots(int $limit = 100): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT booking_type_id, resource_id, slot_start, slot_end
             FROM {$this->table}
             WHERE status = 'waiting'
             GROUP BY booking_type_id, resource_id, slot_start, slot_end
             ORDER BY MIN(created_at) ASC LIMIT %d",
            max(1, min(500, $limit))
        ));
    }

    public function expireOffers(int $limit = 100): array {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status IN ('offered','claiming') AND offer_expires_at < %s
             ORDER BY offer_expires_at ASC LIMIT %d",
            $now, max(1, min(500, $limit))
        ));
        foreach ($rows as $row) {
            $wpdb->update($this->table, [
                'status' => 'waiting',
                'offer_selector' => null,
                'offer_hash' => null,
                'offer_secret_enc' => null,
                'offer_expires_at' => null,
                'offered_at' => null,
                'updated_at' => $now,
            ], ['id' => (int)$row->id]);
        }
        return $rows;
    }

    public function recent(int $limit = 200): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d",
            max(1, min(500, $limit))
        ));
    }

    public function forEmail(string $email, int $limit = 50): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE email = %s ORDER BY id ASC LIMIT %d",
            $email, max(1, min(100, $limit))
        ));
    }

    public function eraseForEmail(string $email): int {
        global $wpdb;
        return (int)$wpdb->delete($this->table, ['email' => $email]);
    }
}
