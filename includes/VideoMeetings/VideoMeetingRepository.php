<?php
namespace Wpcb\VideoMeetings;

use Wpcb\Support\Time;

final class VideoMeetingRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_video_meetings';
    }

    public function find(int $bookingId, int $connectionId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE booking_id = %d AND connection_id = %d LIMIT 1",
            $bookingId, $connectionId
        )) ?: null;
    }

    public function forBooking(int $bookingId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE booking_id = %d ORDER BY id ASC",
            $bookingId
        ));
    }

    public function upsert(int $bookingId, int $connectionId, string $provider, string $remoteId, string $joinUrl, string $status = 'active'): int {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $existing = $this->find($bookingId, $connectionId);
        $row = [
            'provider' => sanitize_key($provider),
            'remote_id' => mb_substr($remoteId, 0, 255),
            'join_url' => esc_url_raw($joinUrl),
            'status' => sanitize_key($status),
            'last_error' => '',
            'updated_at' => $now,
        ];
        if ($existing) {
            $wpdb->update($this->table, $row, ['id' => (int)$existing->id]);
            return (int)$existing->id;
        }
        $row['booking_id'] = $bookingId;
        $row['connection_id'] = $connectionId;
        $row['created_at'] = $now;
        return $wpdb->insert($this->table, $row) ? (int)$wpdb->insert_id : 0;
    }

    public function markDeleted(int $bookingId, int $connectionId): void {
        global $wpdb;
        $wpdb->update($this->table, [
            'status' => 'deleted',
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ], ['booking_id' => $bookingId, 'connection_id' => $connectionId]);
    }

    public function markError(int $bookingId, int $connectionId, string $provider, string $message): void {
        global $wpdb;
        $message = wp_strip_all_tags($message);
        $message = preg_replace('/Bearer\s+[^\s]+/i', 'Bearer [redacted]', $message);
        $message = preg_replace('/\b(token|password|secret)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message);
        $existing = $this->find($bookingId, $connectionId);
        $row = [
            'provider' => sanitize_key($provider),
            'status' => 'error',
            'last_error' => mb_substr((string)$message, 0, 1000),
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ];
        if ($existing) {
            $wpdb->update($this->table, $row, ['id' => (int)$existing->id]);
            return;
        }
        $row += [
            'booking_id' => $bookingId,
            'connection_id' => $connectionId,
            'remote_id' => '',
            'join_url' => '',
            'created_at' => Time::formatUtc(Time::nowUtc()),
        ];
        $wpdb->insert($this->table, $row);
    }

    public function firstJoinUrl(int $bookingId): string {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare(
            "SELECT join_url FROM {$this->table}
             WHERE booking_id = %d AND status = 'active' AND join_url <> ''
             ORDER BY id ASC LIMIT 1",
            $bookingId
        ));
    }
}
