<?php
namespace Cemb\Booking;

class BookingRepository {
    private string $table;
    private string $metaTable;
    private string $logTable;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cemb_bookings';
        $this->metaTable = $wpdb->prefix . 'cemb_booking_meta';
        $this->logTable = $wpdb->prefix . 'cemb_booking_status_log';
    }

    public function create(array $data, array $meta = []): int {
        global $wpdb;
        $wpdb->insert($this->table, $data);
        $id = (int)$wpdb->insert_id;
        foreach ($meta as $key => $value) {
            $wpdb->insert($this->metaTable, [
                'booking_id' => $id,
                'meta_key' => (string)$key,
                'meta_value' => is_scalar($value) ? (string)$value : wp_json_encode($value),
            ]);
        }
        $this->log($id, null, $data['status'], 'create', 'system', 'Buchung erstellt');
        return $id;
    }

    public function updateStatus(int $bookingId, string $newStatus, string $context = 'system', string $changedBy = 'system', string $note = ''): void {
        global $wpdb;
        $booking = $this->find($bookingId);
        if (!$booking) return;
        $wpdb->update($this->table, [
            'status' => $newStatus,
            'updated_at' => current_time('mysql'),
        ], ['id' => $bookingId]);
        $this->log($bookingId, (string)$booking->status, $newStatus, $context, $changedBy, $note);
    }

    public function update(int $bookingId, array $data): void {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($this->table, $data, ['id' => $bookingId]);
    }

    public function replaceMeta(int $bookingId, array $meta): void {
        global $wpdb;
        $wpdb->delete($this->metaTable, ['booking_id' => $bookingId]);
        foreach ($meta as $key => $value) {
            $wpdb->insert($this->metaTable, [
                'booking_id' => $bookingId,
                'meta_key' => (string)$key,
                'meta_value' => is_scalar($value) ? (string)$value : wp_json_encode($value),
            ]);
        }
    }

    public function updateMeta(int $bookingId, string $key, $value): void {
        global $wpdb;
        $existingId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->metaTable} WHERE booking_id = %d AND meta_key = %s LIMIT 1", $bookingId, $key));
        $data = [
            'booking_id' => $bookingId,
            'meta_key' => $key,
            'meta_value' => is_scalar($value) ? (string)$value : wp_json_encode($value),
        ];
        if ($existingId) {
            $wpdb->update($this->metaTable, ['meta_value' => $data['meta_value']], ['id' => (int)$existingId]);
        } else {
            $wpdb->insert($this->metaTable, $data);
        }
    }

    public function find(int $bookingId) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $bookingId));
    }

    public function findByUuid(string $uuid) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE booking_uuid = %s", $uuid));
    }

    public function getMeta(int $bookingId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$this->metaTable} WHERE booking_id = %d", $bookingId));
        $meta = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string)$row->meta_value, true);
            $meta[$row->meta_key] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : maybe_unserialize($row->meta_value);
        }
        return $meta;
    }

    public function all(array $args = []): array {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];
        if (!empty($args['status'])) {
            $sql .= ' AND status = %s';
            $params[] = $args['status'];
        }
        $sql .= ' ORDER BY slot_start ASC';
        if (!empty($args['limit'])) {
            $sql .= ' LIMIT %d';
            $params[] = (int)$args['limit'];
        }
        return $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);
    }

    public function displayableBetween(string $from, string $to): array {
        global $wpdb;
        $statuses = BookingStatus::displayableCalendarStatuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT * FROM {$this->table} WHERE status IN ($placeholders) AND slot_start < %s AND slot_end > %s ORDER BY slot_start ASC";
        $params = array_merge($statuses, [$to, $from]);
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    public function hasConflict(string $start, string $end, ?int $ignoreId = null): bool {
        global $wpdb;
        $statuses = BookingStatus::activeBlockingStatuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE status IN ($placeholders) AND slot_start < %s AND slot_end > %s";
        $params = array_merge($statuses, [$end, $start]);
        if ($ignoreId) {
            $sql .= ' AND id != %d';
            $params[] = $ignoreId;
        }
        return (int)$wpdb->get_var($wpdb->prepare($sql, ...$params)) > 0;
    }

    private function log(int $bookingId, ?string $oldStatus, string $newStatus, string $context, string $changedBy, string $note): void {
        global $wpdb;
        $wpdb->insert($this->logTable, [
            'booking_id' => $bookingId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'context' => $context,
            'changed_by' => $changedBy,
            'note' => $note,
            'created_at' => current_time('mysql'),
        ]);
    }
}
