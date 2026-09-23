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
        return $this->rulesForTypeAndResource($typeId, null);
    }

    /**
     * Resource rules override booking-type rules, which override global rules.
     */
    public function rulesForTypeAndResource(?int $typeId, ?int $resourceId): array {
        global $wpdb;

        if ($resourceId) {
            $resource = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->rulesTable}
                 WHERE is_active = 1
                 AND scope_type = 'resource'
                 AND scope_id = %d
                 ORDER BY weekday ASC, start_time ASC, id ASC",
                $resourceId
            ));
            if ($resource) {
                return $resource;
            }
        }

        if ($typeId) {
            $specific = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->rulesTable}
                 WHERE is_active = 1
                 AND scope_type = 'booking_type'
                 AND scope_id = %d
                 ORDER BY weekday ASC, start_time ASC, id ASC",
                $typeId
            ));
            if ($specific) {
                return $specific;
            }
        }

        return $wpdb->get_results(
            "SELECT * FROM {$this->rulesTable}
             WHERE is_active = 1
             AND scope_type = 'global'
             ORDER BY weekday ASC, start_time ASC, id ASC"
        );
    }

    public function exceptions(
        string $from,
        string $to,
        ?int $typeId = null,
        ?int $resourceId = null
    ): array {
        global $wpdb;
        $sql = "SELECT * FROM {$this->exceptionsTable}
                WHERE is_active = 1
                AND date_start < %s
                AND date_end > %s";
        $params = [$to, $from];

        if ($typeId) {
            $sql .= ' AND (booking_type_id IS NULL OR booking_type_id = %d)';
            $params[] = $typeId;
        }
        if ($resourceId) {
            $sql .= ' AND (resource_id IS NULL OR resource_id = %d)';
            $params[] = $resourceId;
        }

        $sql .= ' ORDER BY date_start ASC, id ASC';
        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }
}
