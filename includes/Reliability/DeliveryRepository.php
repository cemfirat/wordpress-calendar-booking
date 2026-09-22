<?php
namespace Cemb\Reliability;

use Cemb\Support\Time;

/**
 * Durable idempotency ledger for non-transactional side effects.
 */
final class DeliveryRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cemb_deliveries';
    }

    public function begin(int $bookingId, string $key, string $channel, string $effectType): array {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());

        $existing = $this->findByKey($key);
        if ($existing) {
            return [
                'id' => (int)$existing->id,
                'status' => (string)$existing->status,
                'should_run' => in_array((string)$existing->status, ['pending', 'failed'], true),
            ];
        }

        $inserted = $wpdb->insert(
            $this->table,
            [
                'booking_id' => $bookingId,
                'idempotency_key' => $key,
                'channel' => $channel,
                'effect_type' => $effectType,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        if ($inserted === false) {
            $existing = $this->findByKey($key);
            if ($existing) {
                return [
                    'id' => (int)$existing->id,
                    'status' => (string)$existing->status,
                    'should_run' => in_array((string)$existing->status, ['pending', 'failed'], true),
                ];
            }
            return ['id' => 0, 'status' => 'error', 'should_run' => false];
        }

        return ['id' => (int)$wpdb->insert_id, 'status' => 'pending', 'should_run' => true];
    }

    public function markSending(int $id): bool {
        global $wpdb;
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET status = 'sending', attempts = attempts + 1, updated_at = %s
                 WHERE id = %d AND status IN ('pending','failed')",
                Time::formatUtc(Time::nowUtc()),
                $id
            )
        );
        return $updated === 1;
    }

    public function markSent(int $id): void {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $wpdb->update(
            $this->table,
            [
                'status' => 'sent',
                'last_error' => '',
                'completed_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $id]
        );
    }

    public function markFailed(int $id, string $message): void {
        global $wpdb;
        $wpdb->update(
            $this->table,
            [
                'status' => 'failed',
                'last_error' => $this->sanitizeError($message),
                'updated_at' => Time::formatUtc(Time::nowUtc()),
            ],
            ['id' => $id]
        );
    }

    public function findByKey(string $key): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE idempotency_key = %s LIMIT 1",
                $key
            )
        ) ?: null;
    }

    private function sanitizeError(string $message): string {
        $message = wp_strip_all_tags($message);
        $message = preg_replace('/Authorization:\s*[^\s]+/i', 'Authorization: [redacted]', $message);
        return mb_substr((string)$message, 0, 1000);
    }
}
