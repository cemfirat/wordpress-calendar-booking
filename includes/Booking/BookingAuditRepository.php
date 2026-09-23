<?php
namespace Cemb\Booking;

class BookingAuditRepository {
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cemb_booking_status_log';
    }

    public function search(array $filters = [], int $limit = 200): array {
        global $wpdb;

        $sql = "SELECT id, booking_id, old_status, new_status, context, changed_by, note, created_at
                FROM {$this->table}
                WHERE 1=1";
        $params = [];

        if (!empty($filters['booking_id'])) {
            $sql .= ' AND booking_id = %d';
            $params[] = (int)$filters['booking_id'];
        }
        if (!empty($filters['context'])) {
            $sql .= ' AND context = %s';
            $params[] = (string)$filters['context'];
        }
        if (!empty($filters['actor'])) {
            $sql .= ' AND changed_by = %s';
            $params[] = (string)$filters['actor'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND created_at >= %s';
            $params[] = (string)$filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND created_at <= %s';
            $params[] = (string)$filters['to'];
        }

        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max(1, min(500, $limit));

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    public function contexts(): array {
        global $wpdb;
        return array_values(array_filter(array_map(
            'strval',
            $wpdb->get_col("SELECT DISTINCT context FROM {$this->table} WHERE context IS NOT NULL AND context <> '' ORDER BY context ASC")
        )));
    }

    public function actors(): array {
        global $wpdb;
        return array_values(array_filter(array_map(
            'strval',
            $wpdb->get_col("SELECT DISTINCT changed_by FROM {$this->table} WHERE changed_by IS NOT NULL AND changed_by <> '' ORDER BY changed_by ASC")
        )));
    }
}
