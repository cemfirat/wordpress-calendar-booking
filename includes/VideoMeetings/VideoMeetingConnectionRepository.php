<?php
namespace Wpcb\VideoMeetings;

use Wpcb\Security\SecretBox;
use Wpcb\Support\Time;

final class VideoMeetingConnectionRepository {
    private string $table;
    private string $mapTable;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_video_connections';
        $this->mapTable = $wpdb->prefix . 'wpcb_booking_type_video_connections';
    }

    public function all(bool $activeOnly = false): array {
        global $wpdb;
        $sql = "SELECT id, provider, name, config_json, is_active, created_at, updated_at
                FROM {$this->table}";
        if ($activeOnly) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY name ASC, id ASC';
        return $wpdb->get_results($sql);
    }

    public function find(int $id, bool $withCredentials = false): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
            $id
        ));
        if (!$row) return null;

        $config = json_decode((string)$row->config_json, true);
        $row->config = is_array($config) ? $config : [];
        unset($row->config_json);

        $row->credentials = [];
        if ($withCredentials && !empty($row->credentials_enc)) {
            $plain = (new SecretBox())->decrypt((string)$row->credentials_enc);
            $decoded = $plain !== null ? json_decode($plain, true) : null;
            $row->credentials = is_array($decoded) ? $decoded : [];
        }
        unset($row->credentials_enc);
        return $row;
    }

    public function save(array $data, int $id = 0) {
        global $wpdb;
        $provider = sanitize_key((string)($data['provider'] ?? ''));
        $name = sanitize_text_field((string)($data['name'] ?? ''));
        if (!in_array($provider, (new VideoMeetingProviderRegistry())->codes(), true) || $name === '') {
            return new \WP_Error('wpcb_video_connection_invalid', 'Meeting provider and name are required.');
        }

        $row = [
            'provider' => $provider,
            'name' => $name,
            'config_json' => wp_json_encode((array)($data['config'] ?? [])),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ];

        $accessToken = trim((string)($data['access_token'] ?? ''));
        if ($accessToken !== '') {
            $encrypted = (new SecretBox())->encrypt(wp_json_encode(['access_token' => $accessToken]));
            if (is_wp_error($encrypted)) return $encrypted;
            $row['credentials_enc'] = $encrypted;
        }

        if ($id > 0) {
            if (!$this->find($id)) return new \WP_Error('wpcb_video_connection_missing', 'Meeting connection not found.');
            $updated = $wpdb->update($this->table, $row, ['id' => $id]);
            return $updated === false ? new \WP_Error('wpcb_video_connection_storage', 'Meeting connection could not be saved.') : $id;
        }

        $row['created_at'] = Time::formatUtc(Time::nowUtc());
        $inserted = $wpdb->insert($this->table, $row);
        return $inserted === false ? new \WP_Error('wpcb_video_connection_storage', 'Meeting connection could not be saved.') : (int)$wpdb->insert_id;
    }

    public function delete(int $id) {
        global $wpdb;
        $used = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_video_meetings WHERE connection_id = %d",
            $id
        ));
        if ($used > 0) return new \WP_Error('wpcb_video_connection_used', 'Connections with meeting history cannot be deleted; deactivate them instead.');
        $wpdb->delete($this->mapTable, ['connection_id' => $id]);
        return $wpdb->delete($this->table, ['id' => $id]) !== false ? true : new \WP_Error('wpcb_video_connection_storage', 'Meeting connection could not be deleted.');
    }

    public function setForBookingType(int $typeId, array $selections): void {
        global $wpdb;
        if ($typeId < 1) return;
        $wpdb->delete($this->mapTable, ['booking_type_id' => $typeId]);
        $now = Time::formatUtc(Time::nowUtc());
        foreach ($selections as $selection) {
            $connectionId = (int)($selection['connection_id'] ?? 0);
            if ($connectionId < 1 || !$this->find($connectionId)) continue;
            $wpdb->insert($this->mapTable, [
                'booking_type_id' => $typeId,
                'connection_id' => $connectionId,
                'is_required' => !empty($selection['is_required']) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function forBookingType(int $typeId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.provider, c.name, c.is_active, m.is_required
             FROM {$this->mapTable} m
             INNER JOIN {$this->table} c ON c.id = m.connection_id
             WHERE m.booking_type_id = %d AND c.is_active = 1
             ORDER BY c.name ASC, c.id ASC",
            $typeId
        ));
    }

    public function credentialStatus(int $id): string {
        global $wpdb;
        $cipher = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT credentials_enc FROM {$this->table} WHERE id = %d",
            $id
        ));
        if ($cipher === '') return 'empty';
        return (new SecretBox())->decrypt($cipher) === null ? 'invalid' : 'stored';
    }
}
