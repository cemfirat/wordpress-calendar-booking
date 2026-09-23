<?php
namespace Wpcb\Availability;

class AvailabilityRepository {
    private string $rulesTable;
    private string $exceptionsTable;
    public function __construct() {
        global $wpdb;
        $this->rulesTable = $wpdb->prefix . 'wpcb_availability_rules';
        $this->exceptionsTable = $wpdb->prefix . 'wpcb_exceptions';
    }
    public function rulesForType(?int $typeId = null): array {
        global $wpdb;
        $global = $wpdb->get_results("SELECT * FROM {$this->rulesTable} WHERE is_active = 1 AND scope_type = 'global'");
        $specific = [];
        if ($typeId) {
            $specific = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->rulesTable} WHERE is_active = 1 AND scope_type = 'booking_type' AND scope_id = %d", $typeId));
        }
        return $specific ?: $global;
    }
    public function exceptions(string $from, string $to, ?int $typeId = null): array {
        global $wpdb;
        $sql = "SELECT * FROM {$this->exceptionsTable} WHERE is_active = 1 AND date_start < %s AND date_end > %s";
        $params = [$to, $from];
        if ($typeId) {
            $sql .= ' AND (booking_type_id IS NULL OR booking_type_id = %d)';
            $params[] = $typeId;
        }
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }
}
