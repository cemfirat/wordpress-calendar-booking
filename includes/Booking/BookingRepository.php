<?php
namespace Wpcb\Booking;

use Wpcb\Support\Time;

class BookingRepository {
    private string $table;
    private string $metaTable;
    private string $logTable;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_bookings';
        $this->metaTable = $wpdb->prefix . 'wpcb_booking_meta';
        $this->logTable = $wpdb->prefix . 'wpcb_booking_status_log';
    }

    public function create(array $data, array $meta = [], bool $emitCreated = true): int {
        global $wpdb;
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, BookingStatus::all(), true)) {
            return 0;
        }
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
        $created = $this->find($id);
        if ($emitCreated && $created) {
            do_action('wpcb_booking_created', $created);
        }
        return $id;
    }

    /**
     * Atomically transition a booking only when its current state still matches.
     */
    public function transitionStatus(
        int $bookingId,
        string $expectedStatus,
        string $newStatus,
        array $fields,
        string $event,
        string $actor,
        string $note = ''
    ): bool {
        global $wpdb;
        if (!in_array($expectedStatus, BookingStatus::all(), true)
            || !in_array($newStatus, BookingStatus::all(), true)
        ) {
            return false;
        }
        $fields['status'] = $newStatus;
        $fields['updated_at'] = Time::formatUtc(Time::nowUtc());

        $updated = $wpdb->update(
            $this->table,
            $fields,
            ['id' => $bookingId, 'status' => $expectedStatus]
        );

        if ($updated !== 1) {
            return false;
        }

        $this->log($bookingId, $expectedStatus, $newStatus, $event, $actor, $note);
        return true;
    }

    /**
     * Record a lifecycle event that intentionally leaves the state unchanged.
     */
    public function logEvent(int $bookingId, string $status, string $event, string $actor, string $note = ''): void {
        $this->log($bookingId, $status, $status, $event, $actor, $note);
    }

    public function update(int $bookingId, array $data): void {
        global $wpdb;
        if (array_key_exists('status', $data)) {
            throw new \InvalidArgumentException('Booking status changes must use BookingTransitionService.');
        }
        $data['updated_at'] = Time::formatUtc(Time::nowUtc());
        $wpdb->update($this->table, $data, ['id' => $bookingId]);
    }

    public function updateWhenStatus(int $bookingId, string $expectedStatus, array $data): bool {
        global $wpdb;
        $data['updated_at'] = Time::formatUtc(Time::nowUtc());
        return 1 === $wpdb->update(
            $this->table,
            $data,
            ['id' => $bookingId, 'status' => $expectedStatus]
        );
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

    public function deleteMeta(int $bookingId, string $key): void {
        global $wpdb;
        $wpdb->delete($this->metaTable, ['booking_id' => $bookingId, 'meta_key' => $key]);
    }

    public function latestForEmail(string $email): ?object {
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        if ($email === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE email = %s ORDER BY id DESC LIMIT 1",
            $email
        ));
        return $row ?: null;
    }

    public function forEmail(string $email, int $limit = 100): array {
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        if ($email === '') {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE email = %s
             ORDER BY slot_start DESC, id DESC
             LIMIT %d",
            $email,
            max(1, min(200, $limit))
        ));
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
        [$where, $params] = $this->filterWhere($args);
        $sql = "SELECT * FROM {$this->table}{$where} ORDER BY slot_start ASC, id ASC";

        if (array_key_exists('limit', $args)) {
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = max(1, min(1000, (int)$args['limit']));
            $params[] = max(0, (int)($args['offset'] ?? 0));
        }

        return $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params))
            : $wpdb->get_results($sql);
    }

    public function count(array $args = []): int {
        global $wpdb;
        [$where, $params] = $this->filterWhere($args);
        $sql = "SELECT COUNT(*) FROM {$this->table}{$where}";

        return (int)($params
            ? $wpdb->get_var($wpdb->prepare($sql, ...$params))
            : $wpdb->get_var($sql));
    }

    /**
     * Batch-load booking meta for a bounded set of bookings.
     *
     * @return array<int,array<string,mixed>>
     */
    public function metaForBookings(array $bookingIds, array $keys = []): array {
        global $wpdb;

        $bookingIds = array_values(array_unique(array_filter(
            array_map('intval', $bookingIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$bookingIds) {
            return [];
        }
        $bookingIds = array_slice($bookingIds, 0, 1000);

        $keys = array_values(array_unique(array_filter(array_map(
            static fn($key): string => sanitize_key((string)$key),
            $keys
        ))));
        $keys = array_slice($keys, 0, 50);

        $idPlaceholders = implode(',', array_fill(0, count($bookingIds), '%d'));
        $sql = "SELECT booking_id, meta_key, meta_value
                FROM {$this->metaTable}
                WHERE booking_id IN ({$idPlaceholders})";
        $params = $bookingIds;

        if ($keys) {
            $keyPlaceholders = implode(',', array_fill(0, count($keys), '%s'));
            $sql .= " AND meta_key IN ({$keyPlaceholders})";
            $params = array_merge($params, $keys);
        }

        $sql .= ' ORDER BY booking_id ASC, id ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        $meta = [];

        foreach ($rows as $row) {
            $bookingId = (int)$row->booking_id;
            if (!isset($meta[$bookingId])) {
                $meta[$bookingId] = [];
            }
            $decoded = json_decode((string)$row->meta_value, true);
            $meta[$bookingId][(string)$row->meta_key] =
                (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                    ? $decoded
                    : maybe_unserialize($row->meta_value);
        }

        return $meta;
    }

    /**
     * @return array{0:string,1:array}
     */
    private function filterWhere(array $args): array {
        $where = ' WHERE 1=1';
        $params = [];

        if (!empty($args['status'])) {
            $where .= ' AND status = %s';
            $params[] = (string)$args['status'];
        }
        if (!empty($args['booking_type_id'])) {
            $where .= ' AND booking_type_id = %d';
            $params[] = (int)$args['booking_type_id'];
        }
        if (!empty($args['resource_id'])) {
            $where .= ' AND resource_id = %d';
            $params[] = (int)$args['resource_id'];
        }
        if (!empty($args['from'])) {
            $where .= ' AND slot_start >= %s';
            $params[] = (string)$args['from'];
        }
        if (!empty($args['to'])) {
            $where .= ' AND slot_start <= %s';
            $params[] = (string)$args['to'];
        }

        return [$where, $params];
    }

    public function expiredReservationIds(int $limit = 100): array {
        global $wpdb;
        $limit = max(1, min(1000, $limit));
        $now = Time::formatUtc(Time::nowUtc());
        $sql = $wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE status = %s
             AND reserved_until IS NOT NULL
             AND reserved_until < %s
             ORDER BY reserved_until ASC
             LIMIT %d",
            BookingStatus::RESERVED_UNCONFIRMED,
            $now,
            $limit
        );
        return array_map('intval', $wpdb->get_col($sql));
    }

    public function displayableBetween(string $from, string $to): array {
        global $wpdb;
        $statuses = BookingStatus::displayableCalendarStatuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT * FROM {$this->table} WHERE status IN ($placeholders) AND slot_start < %s AND slot_end > %s ORDER BY slot_start ASC";
        $params = array_merge($statuses, [$to, $from]);
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Return active blocking bookings whose raw intervals intersect a caller-
     * supplied search window. Callers that apply scheduling buffers should
     * widen the window first and then perform the exact buffered comparison.
     *
     * @return object[]
     */
    public function blockingBookings(
        string $from,
        string $to,
        int $resourceId,
        ?int $ignoreId = null
    ): array {
        global $wpdb;
        if ($resourceId < 1 || !Time::parseUtc($from) || !Time::parseUtc($to)) {
            return [];
        }
        $statuses = BookingStatus::activeBlockingStatuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT * FROM {$this->table}
            WHERE status IN ($placeholders)
            AND (status != %s OR reserved_until IS NULL OR reserved_until >= %s)
            AND slot_start < %s
            AND slot_end > %s
            AND (resource_id = %d OR resource_id IS NULL OR resource_id = 0)";
        $params = array_merge(
            $statuses,
            [BookingStatus::RESERVED_UNCONFIRMED, Time::formatUtc(Time::nowUtc()), $to, $from, $resourceId]
        );
        if ($ignoreId) {
            $sql .= ' AND id != %d';
            $params[] = $ignoreId;
        }
        $sql .= ' ORDER BY slot_start ASC, id ASC';
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    public function occupiedSeats(
        string $start,
        string $end,
        int $resourceId,
        ?int $ignoreId = null
    ): int {
        global $wpdb;
        if ($resourceId < 1) {
            return 0;
        }
        $statuses = BookingStatus::activeBlockingStatuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT COALESCE(SUM(GREATEST(1, party_size)), 0) FROM {$this->table}
            WHERE status IN ($placeholders)
            AND (status != %s OR reserved_until IS NULL OR reserved_until >= %s)
            AND slot_start < %s
            AND slot_end > %s
            AND (resource_id = %d OR resource_id IS NULL OR resource_id = 0)";
        $params = array_merge(
            $statuses,
            [BookingStatus::RESERVED_UNCONFIRMED, Time::formatUtc(Time::nowUtc()), $end, $start, $resourceId]
        );
        if ($ignoreId) {
            $sql .= ' AND id != %d';
            $params[] = $ignoreId;
        }
        return max(0, (int)$wpdb->get_var($wpdb->prepare($sql, ...$params)));
    }

    public function hasConflict(
        string $start,
        string $end,
        ?int $ignoreId = null,
        ?int $resourceId = null
    ): bool {
        global $wpdb;
        $statuses = BookingStatus::activeBlockingStatuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT COUNT(*) FROM {$this->table}
            WHERE status IN ($placeholders)
            AND (status != %s OR reserved_until IS NULL OR reserved_until >= %s)
            AND slot_start < %s
            AND slot_end > %s";
        $params = array_merge(
            $statuses,
            [BookingStatus::RESERVED_UNCONFIRMED, Time::formatUtc(Time::nowUtc()), $end, $start]
        );
        if ($resourceId !== null && $resourceId > 0) {
            // Unscoped/legacy rows block every resource until the resource
            // migration assigns them. This is deliberately conservative.
            $sql .= ' AND (resource_id = %d OR resource_id IS NULL OR resource_id = 0)';
            $params[] = $resourceId;
        }
        if ($ignoreId) {
            $sql .= ' AND id != %d';
            $params[] = $ignoreId;
        }
        return (int)$wpdb->get_var($wpdb->prepare($sql, ...$params)) > 0;
    }

    public function moveWhenPositionMatches(
        int $bookingId,
        string $expectedStatus,
        ?int $expectedResourceId,
        string $expectedStart,
        string $expectedEnd,
        int $newResourceId,
        string $newStart,
        string $newEnd
    ): bool {
        global $wpdb;
        if ($bookingId < 1 || $newResourceId < 1) {
            return false;
        }

        $sql = "UPDATE {$this->table}
                SET resource_id = %d,
                    slot_start = %s,
                    slot_end = %s,
                    updated_at_user = %s,
                    updated_at = %s
                WHERE id = %d
                  AND status = %s
                  AND slot_start = %s
                  AND slot_end = %s";
        $params = [
            $newResourceId,
            $newStart,
            $newEnd,
            Time::formatUtc(Time::nowUtc()),
            Time::formatUtc(Time::nowUtc()),
            $bookingId,
            $expectedStatus,
            $expectedStart,
            $expectedEnd,
        ];
        if ($expectedResourceId === null || $expectedResourceId < 1) {
            $sql .= ' AND (resource_id IS NULL OR resource_id = 0)';
        } else {
            $sql .= ' AND resource_id = %d';
            $params[] = $expectedResourceId;
        }

        return 1 === (int)$wpdb->query($wpdb->prepare($sql, ...$params));
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
            'created_at' => Time::formatUtc(Time::nowUtc()),
        ]);
    }
}
