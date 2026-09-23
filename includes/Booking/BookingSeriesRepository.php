<?php
namespace Wpcb\Booking;

use Wpcb\Support\Time;

final class BookingSeriesRepository {
    private string $seriesTable;
    private string $bookingsTable;

    public function __construct() {
        global $wpdb;
        $this->seriesTable = $wpdb->prefix . 'wpcb_booking_series';
        $this->bookingsTable = $wpdb->prefix . 'wpcb_bookings';
    }

    public function create(array $data): int {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $inserted = $wpdb->insert($this->seriesTable, [
            'series_uuid' => (string)($data['series_uuid'] ?? wp_generate_uuid4()),
            'booking_type_id' => (int)$data['booking_type_id'],
            'resource_id' => (int)$data['resource_id'],
            'frequency' => (string)($data['frequency'] ?? 'weekly'),
            'interval_count' => max(1, (int)($data['interval_count'] ?? 1)),
            'occurrence_count' => max(2, (int)$data['occurrence_count']),
            'timezone' => (string)$data['timezone'],
            'status' => (string)($data['status'] ?? 'active'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $inserted ? (int)$wpdb->insert_id : 0;
    }

    public function find(int $seriesId): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->seriesTable} WHERE id = %d",
            $seriesId
        ));
        return $row ?: null;
    }

    public function forBooking(int $bookingId): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*
             FROM {$this->seriesTable} s
             INNER JOIN {$this->bookingsTable} b ON b.series_id = s.id
             WHERE b.id = %d
             LIMIT 1",
            $bookingId
        ));
        return $row ?: null;
    }

    public function members(int $seriesId, ?int $fromOccurrence = null): array {
        global $wpdb;
        $sql = "SELECT * FROM {$this->bookingsTable} WHERE series_id = %d";
        $params = [$seriesId];
        if ($fromOccurrence !== null) {
            $sql .= ' AND series_occurrence >= %d';
            $params[] = max(0, $fromOccurrence);
        }
        $sql .= ' ORDER BY series_occurrence ASC, id ASC';
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    public function markStatus(int $seriesId, string $status): void {
        global $wpdb;
        $wpdb->update($this->seriesTable, [
            'status' => $status,
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ], ['id' => $seriesId]);
    }
}
