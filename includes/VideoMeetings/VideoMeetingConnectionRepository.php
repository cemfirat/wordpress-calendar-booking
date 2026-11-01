<?php
namespace Wpcb\VideoMeetings;

use Wpcb\Security\SecretBox;
use Wpcb\Support\Time;

final class VideoMeetingConnectionRepository {
    public const CREDENTIAL_FIELDS = ['access_token', 'refresh_token', 'client_id', 'client_secret', 'account_id', 'tenant_id'];

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
        return $wpdb->get_results($sql); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static SQL fragments and prefixed table name only; no user input.
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

        $existing = $id > 0 ? $this->find($id, true) : null;
        $config = (array)($data['config'] ?? []);

        $row = [
            'provider' => $provider,
            'name' => $name,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ];

        $submitted = [];
        foreach (self::CREDENTIAL_FIELDS as $field) {
            $value = trim((string)($data[$field] ?? ($data['credentials'][$field] ?? '')));
            if ($value !== '') $submitted[$field] = $value;
        }
        if ($submitted) {
            // Blank fields keep stored secrets; any new secret starts a clean grant.
            $merged = array_merge($existing ? $existing->credentials : [], $submitted);
            unset($merged['expires_at']);
            if (!isset($submitted['access_token'])) unset($merged['access_token']);
            $encrypted = (new SecretBox())->encrypt(wp_json_encode($merged));
            if (is_wp_error($encrypted)) return $encrypted;
            $row['credentials_enc'] = $encrypted;
        } elseif ($existing) {
            foreach (['auth_status', 'auth_error', 'auth_checked_at'] as $key) {
                if (isset($existing->config[$key])) $config[$key] = $existing->config[$key];
            }
        }
        if ($submitted) {
            $config['auth_status'] = 'ok';
            $config['auth_error'] = '';
            $config['auth_checked_at'] = Time::formatUtc(Time::nowUtc());
        }
        $row['config_json'] = wp_json_encode($config);

        if ($id > 0) {
            if (!$this->find($id)) return new \WP_Error('wpcb_video_connection_missing', 'Meeting connection not found.');
            $updated = $wpdb->update($this->table, $row, ['id' => $id]);
            return $updated === false ? new \WP_Error('wpcb_video_connection_storage', 'Meeting connection could not be saved.') : $id;
        }

        $row['created_at'] = Time::formatUtc(Time::nowUtc());
        $inserted = $wpdb->insert($this->table, $row);
        return $inserted === false ? new \WP_Error('wpcb_video_connection_storage', 'Meeting connection could not be saved.') : (int)$wpdb->insert_id;
    }

    /** Stores renewed credentials without touching updated_at, which versions queued jobs. */
    public function updateCredentials(int $id, array $credentials) {
        global $wpdb;
        $encrypted = (new SecretBox())->encrypt(wp_json_encode($credentials));
        if (is_wp_error($encrypted)) return $encrypted;
        $ok = $wpdb->update($this->table, ['credentials_enc' => $encrypted], ['id' => $id]);
        return $ok === false ? new \WP_Error('wpcb_video_connection_storage', 'Meeting credentials could not be stored.') : true;
    }

    public function setAuthHealth(int $id, string $status, string $error): void {
        global $wpdb;
        $row = $this->find($id);
        if (!$row) return;
        $config = $row->config;
        $config['auth_status'] = sanitize_key($status);
        $config['auth_error'] = mb_substr(wp_strip_all_tags($error), 0, 500);
        $config['auth_checked_at'] = Time::formatUtc(Time::nowUtc());
        $wpdb->update($this->table, ['config_json' => wp_json_encode($config)], ['id' => $id]);
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
