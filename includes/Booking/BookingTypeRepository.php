<?php
namespace Wpcb\Booking;

class BookingTypeRepository {
    private string $table;
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_booking_types';
    }
    public function all(bool $publicOnly = false): array {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table} WHERE is_active = 1";
        if ($publicOnly) {
            $sql .= ' AND is_public = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';
        return $wpdb->get_results($sql);
    }
    public function find(int $id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));
    }
}
