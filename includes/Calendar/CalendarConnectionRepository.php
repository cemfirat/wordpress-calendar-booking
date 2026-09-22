<?php
namespace Cemb\Calendar;

use Cemb\Security\SecretBox;
use Cemb\Support\Time;

final class CalendarConnectionRepository {
    private string $table;
    private string $mappingTable;
    private SecretBox $secrets;

    public function __construct(?SecretBox $secrets = null) {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cemb_calendar_connections';
        $this->mappingTable = $wpdb->prefix . 'cemb_booking_type_calendar_connections';
        $this->secrets = $secrets ?: new SecretBox();
    }

    /**
     * @return int|\WP_Error
     */
    public function create(array $data, array $credentials = []) {
        global $wpdb;

        $provider = sanitize_key((string)($data['provider'] ?? ''));
        $name = sanitize_text_field((string)($data['name'] ?? ''));
        if ($provider === '' || $name === '') {
            return new \WP_Error('cemb_connection_invalid', 'Calendar provider and connection name are required.');
        }

        $encrypted = $this->encryptCredentials($credentials);
        if (is_wp_error($encrypted)) {
            return $encrypted;
        }

        $now = Time::formatUtc(Time::nowUtc());
        $ok = $wpdb->insert($this->table, [
            'provider' => $provider,
            'name' => $name,
            'remote_calendar_id' => sanitize_text_field((string)($data['remote_calendar_id'] ?? '')),
            'credentials_enc' => $encrypted,
            'config_json' => wp_json_encode($this->sanitizeConfig($data['config'] ?? [])),
            'blocks_availability' => !empty($data['blocks_availability']) ? 1 : 0,
            'receives_bookings' => !empty($data['receives_bookings']) ? 1 : 0,
            'is_active' => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
            'health_status' => 'unknown',
            'last_success_at' => null,
            'last_error_at' => null,
            'last_error_message' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok === false) {
            return new \WP_Error('cemb_connection_storage', 'Calendar connection could not be saved.');
        }
        return (int)$wpdb->insert_id;
    }

    public function find(int $connectionId): ?CalendarConnection {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, provider, name, remote_calendar_id, blocks_availability, receives_bookings, is_active,
                    health_status, last_success_at, last_error_at, last_error_message, created_at, updated_at
             FROM {$this->table} WHERE id = %d",
            $connectionId
        ));
        return $row ? CalendarConnection::fromRow($row) : null;
    }

    /** @return CalendarConnection[] */
    public function all(bool $activeOnly = false): array {
        global $wpdb;
        $sql = "SELECT id, provider, name, remote_calendar_id, blocks_availability, receives_bookings, is_active,
                       health_status, last_success_at, last_error_at, last_error_message, created_at, updated_at
                FROM {$this->table}";
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC, id ASC';
        return array_map(
            static fn(object $row): CalendarConnection => CalendarConnection::fromRow($row),
            $wpdb->get_results($sql)
        );
    }

    /**
     * Explicit secret access. Credentials are never loaded by find()/all().
     */
    public function credentials(int $connectionId): ?array {
        global $wpdb;
        $encoded = $wpdb->get_var($wpdb->prepare(
            "SELECT credentials_enc FROM {$this->table} WHERE id = %d",
            $connectionId
        ));
        if ($encoded === null || $encoded === '') {
            return [];
        }

        $plain = $this->secrets->decrypt((string)$encoded);
        if ($plain === null) {
            return null;
        }
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return true|\WP_Error
     */
    public function replaceCredentials(int $connectionId, array $credentials) {
        global $wpdb;
        if (!$this->find($connectionId)) {
            return new \WP_Error('cemb_connection_missing', 'Calendar connection does not exist.');
        }

        $encrypted = $this->encryptCredentials($credentials);
        if (is_wp_error($encrypted)) {
            return $encrypted;
        }

        $updated = $wpdb->update(
            $this->table,
            [
                'credentials_enc' => $encrypted,
                'updated_at' => Time::formatUtc(Time::nowUtc()),
            ],
            ['id' => $connectionId]
        );
        return $updated === false
            ? new \WP_Error('cemb_connection_storage', 'Calendar credentials could not be saved.')
            : true;
    }

    public function config(int $connectionId): array {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT config_json FROM {$this->table} WHERE id = %d",
            $connectionId
        ));
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setHealthSuccess(int $connectionId): void {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $wpdb->update($this->table, [
            'health_status' => 'ok',
            'last_success_at' => $now,
            'last_error_message' => '',
            'updated_at' => $now,
        ], ['id' => $connectionId]);
    }

    public function setHealthError(int $connectionId, string $message): void {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $wpdb->update($this->table, [
            'health_status' => 'error',
            'last_error_at' => $now,
            'last_error_message' => sanitize_text_field($message),
            'updated_at' => $now,
        ], ['id' => $connectionId]);
    }

    /**
     * Replace the selected connections for one booking type.
     *
     * Each row: connection_id, blocks_availability, receives_bookings.
     */
    public function setForBookingType(int $bookingTypeId, array $connections): void {
        global $wpdb;
        $wpdb->delete($this->mappingTable, ['booking_type_id' => $bookingTypeId]);
        $now = Time::formatUtc(Time::nowUtc());

        foreach ($connections as $selection) {
            $connectionId = (int)($selection['connection_id'] ?? 0);
            if ($connectionId < 1 || !$this->find($connectionId)) {
                continue;
            }
            $wpdb->insert($this->mappingTable, [
                'booking_type_id' => $bookingTypeId,
                'connection_id' => $connectionId,
                'blocks_availability' => !empty($selection['blocks_availability']) ? 1 : 0,
                'receives_bookings' => !empty($selection['receives_bookings']) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<int,array{connection:CalendarConnection,blocks_availability:bool,receives_bookings:bool}>
     */
    public function forBookingType(int $bookingTypeId, bool $activeOnly = true): array {
        global $wpdb;
        $sql = "SELECT c.id, c.provider, c.name, c.remote_calendar_id, c.blocks_availability,
                       c.receives_bookings, c.is_active, c.health_status, c.last_success_at,
                       c.last_error_at, c.last_error_message, c.created_at, c.updated_at,
                       m.blocks_availability AS mapping_blocks_availability,
                       m.receives_bookings AS mapping_receives_bookings
                FROM {$this->mappingTable} m
                INNER JOIN {$this->table} c ON c.id = m.connection_id
                WHERE m.booking_type_id = %d";
        if ($activeOnly) {
            $sql .= ' AND c.is_active = 1';
        }
        $sql .= ' ORDER BY c.name ASC, c.id ASC';

        $rows = $wpdb->get_results($wpdb->prepare($sql, $bookingTypeId));
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'connection' => CalendarConnection::fromRow($row),
                'blocks_availability' => (bool)$row->mapping_blocks_availability,
                'receives_bookings' => (bool)$row->mapping_receives_bookings,
            ];
        }
        return $out;
    }

    /** @return CalendarConnection[] */
    public function blockingForBookingType(int $bookingTypeId): array {
        return array_values(array_map(
            static fn(array $row): CalendarConnection => $row['connection'],
            array_filter(
                $this->forBookingType($bookingTypeId),
                static fn(array $row): bool => $row['blocks_availability']
            )
        ));
    }

    /** @return CalendarConnection[] */
    public function writeDestinationsForBookingType(int $bookingTypeId): array {
        return array_values(array_map(
            static fn(array $row): CalendarConnection => $row['connection'],
            array_filter(
                $this->forBookingType($bookingTypeId),
                static fn(array $row): bool => $row['receives_bookings']
            )
        ));
    }

    private function encryptCredentials(array $credentials) {
        if (!$credentials) {
            return '';
        }
        $json = wp_json_encode($credentials);
        if (!is_string($json)) {
            return new \WP_Error('cemb_connection_credentials', 'Calendar credentials could not be encoded.');
        }
        return $this->secrets->encrypt($json);
    }

    private function sanitizeConfig($config): array {
        if (!is_array($config)) {
            return [];
        }
        $clean = [];
        foreach ($config as $key => $value) {
            $key = sanitize_key((string)$key);
            if ($key === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? sanitize_text_field($value) : $value;
            }
        }
        return $clean;
    }
}
