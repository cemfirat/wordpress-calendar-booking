<?php
namespace Wpcb\Webhooks;

use Wpcb\Support\Time;

final class WebhookJobRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_webhook_jobs';
    }

    public function enqueue(
        string $eventId,
        int $endpointId,
        int $bookingId,
        string $eventType,
        array $payload
    ): int {
        global $wpdb;
        if (!wp_is_uuid($eventId) || $endpointId < 1 || $bookingId < 1) {
            return 0;
        }
        $now = Time::formatUtc(Time::nowUtc());
        $inserted = $wpdb->insert($this->table, [
            'event_id' => $eventId,
            'endpoint_id' => $endpointId,
            'booking_id' => $bookingId,
            'event_type' => sanitize_text_field($eventType),
            'payload_json' => wp_json_encode($payload),
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== false) {
            return (int)$wpdb->insert_id;
        }
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE endpoint_id = %d AND event_id = %s LIMIT 1",
            $endpointId,
            $eventId
        ));
    }

    public function claim(string $worker, int $limit = 10, int $leaseSeconds = 300): array {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $now = Time::formatUtc(Time::nowUtc());
        $leaseUntil = Time::formatUtc(Time::nowUtc()->modify('+' . max(60, $leaseSeconds) . ' seconds'));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE status IN ('pending','failed')
               AND attempts < 6
               AND next_attempt_at <= %s
               AND (lease_expires_at IS NULL OR lease_expires_at < %s)
             ORDER BY id ASC
             LIMIT %d",
            $now,
            $now,
            $limit
        ));
        $claimed = [];
        foreach (array_map('intval', $ids) as $id) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table}
                 SET status='sending',
                     attempts=attempts+1,
                     last_attempt_at=%s,
                     lease_owner=%s,
                     lease_expires_at=%s,
                     updated_at=%s
                 WHERE id=%d
                   AND status IN ('pending','failed')
                   AND (lease_expires_at IS NULL OR lease_expires_at < %s)",
                $now,
                $worker,
                $leaseUntil,
                $now,
                $id,
                $now
            ));
            if ($updated === 1) {
                $row = $this->find($id);
                if ($row) {
                    $claimed[] = $row;
                }
            }
        }
        return $claimed;
    }

    public function markSent(int $id, string $worker, int $httpCode): void {
        global $wpdb;
        $wpdb->update($this->table, [
            'status' => 'sent',
            'last_http_code' => $httpCode,
            'last_error' => '',
            'lease_owner' => null,
            'lease_expires_at' => null,
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ], ['id' => $id, 'lease_owner' => $worker]);
    }

    public function markFailed(int $id, string $worker, int $httpCode, string $error): void {
        global $wpdb;
        $row = $this->find($id);
        if (!$row || (string)$row->lease_owner !== $worker) {
            return;
        }
        $attempts = max(1, (int)$row->attempts);
        $terminal = $attempts >= 6;
        $backoff = min(3600, 60 * (2 ** max(0, $attempts - 1)));
        $wpdb->update($this->table, [
            'status' => $terminal ? 'dead' : 'failed',
            'last_http_code' => $httpCode > 0 ? $httpCode : null,
            'last_error' => $this->sanitizeError($error),
            'next_attempt_at' => Time::formatUtc(Time::nowUtc()->modify('+' . $backoff . ' seconds')),
            'lease_owner' => null,
            'lease_expires_at' => null,
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ], ['id' => $id, 'lease_owner' => $worker]);
    }

    public function find(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id)) ?: null;
    }

    public function search(array $filters = [], int $limit = 100): array {
        global $wpdb;
        $sql = "SELECT id,event_id,endpoint_id,booking_id,event_type,status,attempts,next_attempt_at,last_attempt_at,last_http_code,last_error,created_at,updated_at
                FROM {$this->table} WHERE 1=1";
        $params = [];
        if (!empty($filters['endpoint_id'])) {
            $sql .= ' AND endpoint_id = %d';
            $params[] = (int)$filters['endpoint_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND status = %s';
            $params[] = sanitize_key((string)$filters['status']);
        }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max(1, min(500, $limit));
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    private function sanitizeError(string $error): string {
        $error = wp_strip_all_tags($error);
        $error = preg_replace('/Authorization:\s*[^\s]+/i', 'Authorization: [redacted]', $error);
        $error = preg_replace('/Bearer\s+[A-Za-z0-9._~-]+/i', 'Bearer [redacted]', $error);
        $error = preg_replace('/\b(token|password|secret)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $error);
        return mb_substr((string)$error, 0, 1000);
    }
}
