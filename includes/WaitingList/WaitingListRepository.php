<?php
namespace Wpcb\WaitingList;

use Wpcb\Support\Time;

final class WaitingListRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_waiting_list';
    }

    public function join(array $data): int {
        global $wpdb;
        $email = strtolower(sanitize_email((string)($data['email'] ?? '')));
        $typeId = (int)($data['booking_type_id'] ?? 0);
        $resourceId = (int)($data['resource_id'] ?? 0);
        $partySize = max(1, (int)($data['party_size'] ?? 1));
        $start = (string)($data['slot_start'] ?? '');
        $end = (string)($data['slot_end'] ?? '');
        if ($email === '' || $typeId < 1 || $resourceId < 1 || $start === '' || $end === '') {
            return 0;
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE booking_type_id = %d AND resource_id = %d
               AND slot_start = %s AND slot_end = %s
               AND email = %s AND status IN ('waiting','offered')
             LIMIT 1",
            $typeId, $resourceId, $start, $end, $email
        ));
        if ($existing) {
            return (int)$existing;
        }

        $now = Time::formatUtc(Time::nowUtc());
        $ok = $wpdb->insert($this->table, [
            'entry_uuid' => wp_generate_uuid4(),
            'booking_type_id' => $typeId,
            'resource_id' => $resourceId,
            'slot_start' => $start,
            'slot_end' => $end,
            'party_size' => $partySize,
            'email' => $email,
            'status' => 'waiting',
            'offer_selector' => null,
            'offer_hash' => null,
            'offer_expires_at' => null,
            'offered_at' => null,
            'accepted_at' => null,
            'accepted_booking_id' => null,
            'return_url' => esc_url_raw((string)($data['return_url'] ?? home_url('/'))),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public function find(int $id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));
        return $row ?: null;
    }

    public function findOffer(string $selector): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE offer_selector = %s LIMIT 1",
            $selector
        ));
        return $row ?: null;
    }

    public function waitingForSlot(int $typeId, int $resourceId, string $start, string $end, int $limit = 100): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE booking_type_id = %d AND resource_id = %d
               AND slot_start = %s AND slot_end = %s AND status = 'waiting'
             ORDER BY created_at ASC, id ASC LIMIT %d",
            $typeId, $resourceId, $start, $end, max(1, min(500, $limit))
        ));
    }

    public function waitingSlots(int $limit = 100): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT booking_type_id, resource_id, slot_start, slot_end, MIN(created_at) AS first_waiting
             FROM {$this->table}
             WHERE status = 'waiting'
             GROUP BY booking_type_id, resource_id, slot_start, slot_end
             ORDER BY first_waiting ASC LIMIT %d",
            max(1, min(500, $limit))
        ));
    }

    public function activeHeldSeats(int $typeId, int $resourceId, string $start, string $end): int {
        global $wpdb;
        return max(0, (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(party_size), 0) FROM {$this->table}
             WHERE booking_type_id = %d AND resource_id = %d
               AND slot_start = %s AND slot_end = %s
               AND status = 'offered'
               AND offer_expires_at IS NOT NULL AND offer_expires_at >= %s",
            $typeId, $resourceId, $start, $end, Time::formatUtc(Time::nowUtc())
        )));
    }

    public function offer(int $id, string $selector, string $hash, string $expiresAt): bool {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        return 1 === (int)$wpdb->update($this->table, [
            'status' => 'offered',
            'offer_selector' => $selector,
            'offer_hash' => $hash,
            'offer_expires_at' => $expiresAt,
            'offered_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id, 'status' => 'waiting']);
    }

    public function accept(int $id, int $bookingId): bool {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        return 1 === (int)$wpdb->update($this->table, [
            'status' => 'accepted',
            'accepted_at' => $now,
            'accepted_booking_id' => $bookingId,
            'offer_hash' => null,
            'updated_at' => $now,
        ], ['id' => $id, 'status' => 'offered']);
    }

    public function expireOffers(int $limit = 100): array {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'offered' AND offer_expires_at < %s
             ORDER BY offer_expires_at ASC LIMIT %d",
            $now, max(1, min(500, $limit))
        ));
        foreach ($rows as $row) {
            $wpdb->update($this->table, [
                'status' => 'waiting',
                'offer_selector' => null,
                'offer_hash' => null,
                'offer_expires_at' => null,
                'offered_at' => null,
                'updated_at' => $now,
            ], ['id' => (int)$row->id, 'status' => 'offered']);
        }
        return $rows;
    }

    public function all(int $limit = 200): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, entry_uuid, booking_type_id, resource_id, slot_start, slot_end, party_size,
                    email, status, offer_expires_at, accepted_booking_id, created_at, updated_at
             FROM {$this->table} ORDER BY id DESC LIMIT %d",
            max(1, min(500, $limit))
        ));
    }

    public function forEmail(string $email, int $limit = 100): array {
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        if ($email === '') return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE email = %s ORDER BY id ASC LIMIT %d",
            $email, max(1, min(500, $limit))
        ));
    }

    public function eraseEmail(string $email): int {
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        if ($email === '') return 0;
        return max(0, (int)$wpdb->delete($this->table, ['email' => $email]));
    }

    public function cleanup(int $days = 90): int {
        global $wpdb;
        $cutoff = Time::formatUtc(Time::nowUtc()->modify('-' . max(1, $days) . ' days'));
        return max(0, (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE status IN ('accepted','cancelled')
               AND updated_at < %s",
            $cutoff
        )));
    }
}
