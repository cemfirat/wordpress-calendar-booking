<?php
namespace Wpcb\Resources;

use Wpcb\Support\Time;

final class ResourceRepository {
    private string $table;
    private string $typeMappingTable;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_resources';
        $this->typeMappingTable = $wpdb->prefix . 'wpcb_booking_type_resources';
    }

    public function all(bool $activeOnly = false): array {
        global $wpdb;
        $sql = "SELECT * FROM {$this->table}";
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC, id ASC';
        return $wpdb->get_results($sql);
    }

    public function find(int $resourceId): ?object {
        global $wpdb;
        if ($resourceId < 1) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $resourceId
        ));
        return $row ?: null;
    }

    /**
     * @return int|\WP_Error
     */
    public function save(array $data, int $resourceId = 0) {
        global $wpdb;

        $name = sanitize_text_field((string)($data['name'] ?? ''));
        $slug = sanitize_title((string)($data['slug'] ?? ''));
        if ($name === '' || $slug === '') {
            return new \WP_Error('wpcb_resource_invalid', 'Resource name and slug are required.');
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE slug = %s AND id != %d LIMIT 1",
            $slug,
            max(0, $resourceId)
        ));
        if ($existing) {
            return new \WP_Error('wpcb_resource_slug', 'Resource slug must be unique.');
        }

        $now = Time::formatUtc(Time::nowUtc());
        $row = [
            'name' => $name,
            'slug' => $slug,
            'public_label' => sanitize_text_field((string)($data['public_label'] ?? '')),
            'description' => sanitize_textarea_field((string)($data['description'] ?? '')),
            'capacity' => max(1, min(10000, (int)($data['capacity'] ?? 1))),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'is_public' => !empty($data['is_public']) ? 1 : 0,
            'sort_order' => (int)($data['sort_order'] ?? 0),
            'updated_at' => $now,
        ];

        if ($resourceId > 0) {
            if (!$this->find($resourceId)) {
                return new \WP_Error('wpcb_resource_missing', 'Resource does not exist.');
            }
            $updated = $wpdb->update($this->table, $row, ['id' => $resourceId]);
            return $updated === false
                ? new \WP_Error('wpcb_resource_storage', 'Resource could not be saved.')
                : $resourceId;
        }

        $row['created_at'] = $now;
        $inserted = $wpdb->insert($this->table, $row);
        return $inserted === false
            ? new \WP_Error('wpcb_resource_storage', 'Resource could not be saved.')
            : (int)$wpdb->insert_id;
    }

    /**
     * @return true|\WP_Error
     */
    public function delete(int $resourceId) {
        global $wpdb;
        if (!$this->find($resourceId)) {
            return true;
        }

        if ((int)get_option('wpcb_default_resource_id', 0) === $resourceId) {
            return new \WP_Error('wpcb_resource_default', 'The default resource cannot be deleted.');
        }

        $bookingCount = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_bookings WHERE resource_id = %d",
            $resourceId
        ));
        if ($bookingCount > 0) {
            return new \WP_Error('wpcb_resource_bookings', 'Resources with bookings cannot be deleted; deactivate them instead.');
        }

        $assignedCount = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->typeMappingTable} WHERE resource_id = %d",
            $resourceId
        ));
        if ($assignedCount > 0) {
            return new \WP_Error('wpcb_resource_assigned', 'Unassign the resource from booking types before deleting it.');
        }

        $wpdb->delete($wpdb->prefix . 'wpcb_resource_calendar_connections', ['resource_id' => $resourceId]);
        return $wpdb->delete($this->table, ['id' => $resourceId]) !== false
            ? true
            : new \WP_Error('wpcb_resource_storage', 'Resource could not be deleted.');
    }

    public function ensureDefault(): int {
        $configured = (int)get_option('wpcb_default_resource_id', 0);
        if ($configured > 0 && $this->find($configured)) {
            return $configured;
        }

        global $wpdb;
        $existing = (int)$wpdb->get_var(
            "SELECT id FROM {$this->table} ORDER BY id ASC LIMIT 1"
        );
        if ($existing > 0) {
            update_option('wpcb_default_resource_id', $existing, false);
            return $existing;
        }

        $created = $this->save([
            'name' => 'Default Resource',
            'slug' => 'default-resource',
            'public_label' => '',
            'description' => '',
            'capacity' => 1,
            'is_active' => 1,
            'is_public' => 0,
            'sort_order' => 0,
        ]);
        if (is_wp_error($created)) {
            return 0;
        }
        update_option('wpcb_default_resource_id', (int)$created, false);
        return (int)$created;
    }

    public function setForBookingType(int $bookingTypeId, array $resourceIds): void {
        global $wpdb;
        if ($bookingTypeId < 1) {
            return;
        }

        $resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
        $resourceIds = array_values(array_filter(
            $resourceIds,
            fn(int $id): bool => $this->find($id) !== null
        ));
        if (!$resourceIds) {
            $defaultId = $this->ensureDefault();
            if ($defaultId > 0) {
                $resourceIds = [$defaultId];
            }
        }

        $wpdb->delete($this->typeMappingTable, ['booking_type_id' => $bookingTypeId]);
        $now = Time::formatUtc(Time::nowUtc());
        foreach ($resourceIds as $resourceId) {
            $wpdb->insert($this->typeMappingTable, [
                'booking_type_id' => $bookingTypeId,
                'resource_id' => $resourceId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function forBookingType(int $bookingTypeId, bool $activeOnly = true): array {
        global $wpdb;
        $sql = "SELECT r.*
                FROM {$this->typeMappingTable} m
                INNER JOIN {$this->table} r ON r.id = m.resource_id
                WHERE m.booking_type_id = %d";
        if ($activeOnly) {
            $sql .= ' AND r.is_active = 1';
        }
        $sql .= ' ORDER BY r.sort_order ASC, r.name ASC, r.id ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $bookingTypeId));

        if (!$rows) {
            $defaultId = $this->ensureDefault();
            $default = $defaultId > 0 ? $this->find($defaultId) : null;
            if ($default && (!$activeOnly || (int)$default->is_active === 1)) {
                return [$default];
            }
        }
        return $rows;
    }

    public function isAssignedToBookingType(int $resourceId, int $bookingTypeId): bool {
        if ($resourceId < 1 || $bookingTypeId < 1) {
            return false;
        }
        foreach ($this->forBookingType($bookingTypeId, true) as $resource) {
            if ((int)$resource->id === $resourceId) {
                return true;
            }
        }
        return false;
    }

    public function publicLabel(object $resource): string {
        if (empty($resource->is_public)) {
            return '';
        }
        return trim((string)($resource->public_label ?? ''));
    }
}
