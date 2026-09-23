<?php
namespace Wpcb\Webhooks;

use Wpcb\Support\Time;

final class WebhookDeliveryRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_webhook_deliveries';
    }

    public function ensure(int $endpointId, int $bookingId, string $eventId, string $eventType): object {
        global $wpdb;
        $deliveryId = substr(hash('sha256', $endpointId . '|' . $eventId), 0, 48);
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE delivery_id = %s LIMIT 1",
            $deliveryId
        ));
        if ($existing) {
            return $existing;
        }

        $now = Time::formatUtc(Time::nowUtc());
        $wpdb->insert($this->table, [
            'delivery_id' => $deliveryId,
            'endpoint_id' => $endpointId,
            'booking_id' => $bookingId,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE delivery_id = %s LIMIT 1",
            $deliveryId
        ));
    }

    public function markAttempt(int $id): void {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'sending', attempts = attempts + 1, last_attempt_at = %s, updated_at = %s
             WHERE id = %d",
            $now,
            $now,
            $id
        ));
    }

    public function markSent(int $id, int $responseCode): void {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $wpdb->update($this->table, [
            'status' => 'sent',
            'response_code' => $responseCode,
            'last_error' => '',
            'completed_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    public function markFailed(int $id, int $responseCode, string $message): void {
        global $wpdb;
        $message = wp_strip_all_tags($message);
        $message = preg_replace('/\b(token|password|secret|authorization)\b\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message);
        $wpdb->update($this->table, [
            'status' => 'failed',
            'response_code' => $responseCode ?: null,
            'last_error' => mb_substr((string)$message, 0, 1000),
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ], ['id' => $id]);
    }

    public function recent(int $limit = 100): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT delivery_id, endpoint_id, booking_id, event_id, event_type, status, attempts,
                    response_code, last_error, last_attempt_at, completed_at, created_at, updated_at
             FROM {$this->table}
             ORDER BY id DESC
             LIMIT %d",
            max(1, min(500, $limit))
        ));
    }
}
