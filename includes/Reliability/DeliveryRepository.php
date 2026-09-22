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

    public function begin(int $bookingId, string $key, string $channel, string $effectType, string $recipientClass = 'customer', string $providerCode = 'wp_mail'): array {
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
                'effect_type' => $this->sanitizeLabel($effectType, 'notification'),
                'recipient_class' => $this->sanitizeLabel($recipientClass, 'customer'),
                'provider_code' => $this->sanitizeLabel($providerCode, 'wp_mail'),
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
                 SET status = 'sending', attempts = attempts + 1, last_attempt_at = %s, updated_at = %s
                 WHERE id = %d AND status IN ('pending','failed')",
                Time::formatUtc(Time::nowUtc()),
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
                'last_error_code' => null,
                'last_error' => '',
                'completed_at' => $now,
                'updated_at' => $now,
            ],
            ['id' => $id]
        );
    }

    public function markFailed(int $id, string $message, string $errorCode = 'send_failed'): void {
        global $wpdb;
        $wpdb->update(
            $this->table,
            [
                'status' => 'failed',
                'last_error_code' => $this->sanitizeCode($errorCode),
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

    public function search(array $filters = [], int $limit = 100): array {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];
        if (!empty($filters['booking_id'])) {
            $sql .= ' AND booking_id = %d';
            $params[] = (int)$filters['booking_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND status = %s';
            $params[] = sanitize_key((string)$filters['status']);
        }
        if (!empty($filters['effect_type'])) {
            $sql .= ' AND effect_type = %s';
            $params[] = sanitize_key((string)$filters['effect_type']);
        }
        if (!empty($filters['recipient_class'])) {
            $sql .= ' AND recipient_class = %s';
            $params[] = sanitize_key((string)$filters['recipient_class']);
        }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max(1, min(500, $limit));
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    public function effectTypes(): array {
        global $wpdb;
        return array_values(array_filter(array_map(
            'strval',
            $wpdb->get_col("SELECT DISTINCT effect_type FROM {$this->table} ORDER BY effect_type ASC")
        )));
    }

    public function cleanup(int $retentionDays): int {
        global $wpdb;
        $cutoff = Time::formatUtc(Time::nowUtc()->modify('-' . max(1, $retentionDays) . ' days'));
        return (int)$wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table}
                 WHERE status IN ('sent','failed')
                   AND updated_at < %s",
                $cutoff
            )
        );
    }

    private function sanitizeCode(string $code): string {
        return $this->sanitizeLabel($code, 'send_failed');
    }

    private function sanitizeLabel(string $value, string $fallback): string {
        $value = strtolower((string)preg_replace('/[^A-Za-z0-9:_-]+/', '', $value));
        return mb_substr($value !== '' ? $value : $fallback, 0, 80);
    }

    private function sanitizeError(string $message): string {
        $message = wp_strip_all_tags($message);
        $message = preg_replace('/Authorization:\s*[^\s]+/i', 'Authorization: [redacted]', $message);
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._~-]+/i', 'Bearer [redacted]', $message);
        $message = preg_replace('/\b(token|password|secret)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message);
        return mb_substr((string)$message, 0, 1000);
    }
}
