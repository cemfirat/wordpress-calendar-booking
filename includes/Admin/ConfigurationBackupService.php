<?php
namespace Wpcb\Admin;

use Wpcb\Support\Time;

final class ConfigurationBackupService {
    public const FORMAT = 'wordpress-calendar-booking/config';
    public const SCHEMA_VERSION = 1;

    private const SETTINGS_KEYS = [
        'mode','sender_name','timezone','date_format','time_format','notifications_enabled',
        'reminders_enabled','reminder_hours','delivery_log_retention_days',
        'calendar_cache_minutes','token_ttl_minutes','reservation_ttl_minutes','cancel_min_hours',
        'change_min_hours','honeypot_enabled','timing_enabled','min_form_seconds',
        'rate_limit_enabled','rate_limit_requests','rate_limit_window_minutes',
        'show_calendar_limit','retention_enabled','retention_days',
        'icloud_sync_enabled','icloud_sync_target_calendar_name','icloud_sync_updates',
        'icloud_sync_cancellations'
    ];

    private const TYPE_FIELDS = [
        'name','slug','description','duration_minutes','buffer_before_minutes','buffer_after_minutes',
        'capacity','show_remaining_capacity','payment_mode','price_minor','currency',
        'is_active','is_public','sort_order'
    ];

    private const RESOURCE_FIELDS = [
        'name','slug','public_label','description','capacity','is_active','is_public','sort_order'
    ];

    private const FIELD_FIELDS = [
        'field_key','label','field_type','is_required','is_active','options_json',
        'validation_rules_json','sort_order'
    ];

    private const RULE_FIELDS = [
        'scope_type','weekday','start_time','end_time','slot_duration_minutes',
        'buffer_before_minutes','buffer_after_minutes','min_notice_minutes',
        'max_days_in_advance','is_active'
    ];

    private const EXCEPTION_FIELDS = [
        'type','title','date_start','date_end','all_day','is_active'
    ];

    private const CONNECTION_FIELDS = [
        'provider','name','remote_calendar_id','blocks_availability','receives_bookings'
    ];

    public function exportSnapshot(): array {
        global $wpdb;
        $p = $wpdb->prefix . 'wpcb_';

        $types = $this->rows($p . 'booking_types', self::TYPE_FIELDS);
        $resources = $this->rows($p . 'resources', self::RESOURCE_FIELDS);
        $fields = $this->rows($p . 'form_fields', self::FIELD_FIELDS);
        $rules = $wpdb->get_results("SELECT * FROM {$p}availability_rules ORDER BY id ASC", ARRAY_A) ?: [];
        $exceptions = $wpdb->get_results("SELECT * FROM {$p}exceptions ORDER BY id ASC", ARRAY_A) ?: [];
        $connections = $this->rows($p . 'calendar_connections', self::CONNECTION_FIELDS);

        $typeIds = $this->idMap($types);
        $resourceIds = $this->idMap($resources);
        $connectionIds = $this->idMap($connections);

        $outRules = [];
        foreach ($rules as $row) {
            $item = $this->pick($row, self::RULE_FIELDS);
            $scopeType = (string)($row['scope_type'] ?? 'global');
            $scopeId = (int)($row['scope_id'] ?? 0);
            $item['scope_ref'] = $scopeType === 'booking_type'
                ? ($typeIds[$scopeId] ?? null)
                : ($scopeType === 'resource' ? ($resourceIds[$scopeId] ?? null) : null);
            $outRules[] = $item;
        }

        $outExceptions = [];
        foreach ($exceptions as $row) {
            $item = $this->pick($row, self::EXCEPTION_FIELDS);
            $item['booking_type_ref'] = $typeIds[(int)($row['booking_type_id'] ?? 0)] ?? null;
            $item['resource_ref'] = $resourceIds[(int)($row['resource_id'] ?? 0)] ?? null;
            $outExceptions[] = $item;
        }

        $settings = Settings::get();
        $safeSettings = [];
        foreach (self::SETTINGS_KEYS as $key) {
            if (array_key_exists($key, $settings)) {
                $safeSettings[$key] = $settings[$key];
            }
        }

        return [
            'format' => self::FORMAT,
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => WPCB_VERSION,
            'exported_at' => Time::formatUtc(Time::nowUtc()),
            'settings' => $safeSettings,
            'booking_types' => array_map(fn(array $r): array => $this->withRef($r, 'type'), $types),
            'resources' => array_map(fn(array $r): array => $this->withRef($r, 'resource'), $resources),
            'booking_type_resources' => $this->mappingRows(
                $p . 'booking_type_resources',
                'booking_type_id',
                'resource_id',
                $typeIds,
                $resourceIds
            ),
            'form_fields' => array_map(fn(array $r): array => $this->withRef($r, 'field'), $fields),
            'availability_rules' => $outRules,
            'exceptions' => $outExceptions,
            'calendar_connections' => array_map(function(array $r): array {
                $item = $this->withRef($r, 'calendar');
                $item['restore_state'] = 'disabled_until_credentials_reentered';
                return $item;
            }, $connections),
            'booking_type_calendar_connections' => $this->connectionMappings(
                $p . 'booking_type_calendar_connections',
                'booking_type_id',
                $typeIds,
                $connectionIds
            ),
            'resource_calendar_connections' => $this->connectionMappings(
                $p . 'resource_calendar_connections',
                'resource_id',
                $resourceIds,
                $connectionIds
            ),
        ];
    }

    public function exportJson(): string {
        return (string)wp_json_encode($this->exportSnapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function importJson(string $json, bool $dryRun = true) {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return new \WP_Error('wpcb_backup_json', __('Ungültiges JSON.', 'wordpress-calendar-booking'));
        }
        return $this->importSnapshot($decoded, $dryRun);
    }

    public function importSnapshot(array $snapshot, bool $dryRun = true) {
        $valid = $this->validate($snapshot);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $plan = $this->buildPlan($snapshot);
        if (is_wp_error($plan) || $dryRun) {
            return $plan;
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $result = $this->apply($snapshot, $plan);
            if (is_wp_error($result)) {
                $wpdb->query('ROLLBACK');
                $this->invalidateSettingsCache();
                return $result;
            }
            $wpdb->query('COMMIT');
            do_action('wpcb_capacity_changed');
            return $result;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            $this->invalidateSettingsCache();
            return new \WP_Error('wpcb_backup_restore_failed', $error->getMessage());
        }
    }

    private function validate(array $s) {
        $top = [
            'format','schema_version','plugin_version','exported_at','settings','booking_types','resources',
            'booking_type_resources','form_fields','availability_rules','exceptions','calendar_connections',
            'booking_type_calendar_connections','resource_calendar_connections'
        ];
        $unknown = array_diff(array_keys($s), $top);
        if ($unknown) {
            return new \WP_Error('wpcb_backup_unknown', sprintf(
                __('Unbekannte Snapshot-Felder: %s', 'wordpress-calendar-booking'),
                implode(', ', $unknown)
            ));
        }
        if (($s['format'] ?? '') !== self::FORMAT || (int)($s['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return new \WP_Error('wpcb_backup_schema', __('Nicht unterstütztes Backup-Format oder Schema.', 'wordpress-calendar-booking'));
        }

        $sections = [
            'settings' => 'assoc',
            'booking_types' => 'list',
            'resources' => 'list',
            'booking_type_resources' => 'list',
            'form_fields' => 'list',
            'availability_rules' => 'list',
            'exceptions' => 'list',
            'calendar_connections' => 'list',
            'booking_type_calendar_connections' => 'list',
            'resource_calendar_connections' => 'list',
        ];
        foreach ($sections as $key => $kind) {
            if (!array_key_exists($key, $s) || !is_array($s[$key])) {
                return new \WP_Error('wpcb_backup_section', sprintf(
                    __('Snapshot-Abschnitt fehlt oder ist ungültig: %s', 'wordpress-calendar-booking'),
                    $key
                ));
            }
            $listKeys = $s[$key] ? range(0, count($s[$key]) - 1) : [];
            if ($kind === 'list' && array_keys($s[$key]) !== $listKeys) {
                return new \WP_Error('wpcb_backup_section', sprintf(
                    __('Snapshot-Abschnitt muss eine Liste sein: %s', 'wordpress-calendar-booking'),
                    $key
                ));
            }
        }

        $unknownSettings = array_diff(array_keys($s['settings']), self::SETTINGS_KEYS);
        if ($unknownSettings) {
            return new \WP_Error('wpcb_backup_settings', sprintf(
                __('Unsichere oder unbekannte Einstellungen im Snapshot: %s', 'wordpress-calendar-booking'),
                implode(', ', $unknownSettings)
            ));
        }

        $checks = [
            ['booking_types', array_merge(['ref'], self::TYPE_FIELDS)],
            ['resources', array_merge(['ref'], self::RESOURCE_FIELDS)],
            ['form_fields', array_merge(['ref'], self::FIELD_FIELDS)],
            ['availability_rules', array_merge(self::RULE_FIELDS, ['scope_ref'])],
            ['exceptions', array_merge(self::EXCEPTION_FIELDS, ['booking_type_ref','resource_ref'])],
            ['calendar_connections', array_merge(['ref','restore_state'], self::CONNECTION_FIELDS)],
            ['booking_type_resources', ['booking_type_ref','resource_ref']],
            ['booking_type_calendar_connections', ['owner_ref','connection_ref','blocks_availability','receives_bookings']],
            ['resource_calendar_connections', ['owner_ref','connection_ref','blocks_availability','receives_bookings']],
        ];
        foreach ($checks as [$section, $allowed]) {
            foreach ($s[$section] as $index => $row) {
                if (!is_array($row) || array_diff(array_keys($row), $allowed)) {
                    return new \WP_Error('wpcb_backup_fields', sprintf(
                        __('Unbekannte Felder in %1$s[%2$d].', 'wordpress-calendar-booking'),
                        $section,
                        $index
                    ));
                }
            }
        }

        $refs = [];
        foreach (['booking_types','resources','form_fields','calendar_connections'] as $section) {
            $seen = [];
            foreach ($s[$section] as $row) {
                $ref = sanitize_key((string)($row['ref'] ?? ''));
                if ($ref === '' || isset($seen[$ref])) {
                    return new \WP_Error('wpcb_backup_ref', sprintf(
                        __('Ungültige oder doppelte Referenz in %s.', 'wordpress-calendar-booking'),
                        $section
                    ));
                }
                $seen[$ref] = true;
            }
            $refs[$section] = $seen;
        }

        foreach ($s['booking_type_resources'] as $row) {
            if (empty($refs['booking_types'][(string)($row['booking_type_ref'] ?? '')])
                || empty($refs['resources'][(string)($row['resource_ref'] ?? '')])) {
                return new \WP_Error('wpcb_backup_mapping', __('Terminart-/Ressourcen-Zuordnung verweist auf unbekannte Einträge.', 'wordpress-calendar-booking'));
            }
        }
        foreach ($s['availability_rules'] as $row) {
            $scope = (string)($row['scope_type'] ?? 'global');
            $ref = (string)($row['scope_ref'] ?? '');
            if (($scope === 'booking_type' && empty($refs['booking_types'][$ref]))
                || ($scope === 'resource' && empty($refs['resources'][$ref]))
                || !in_array($scope, ['global','booking_type','resource'], true)) {
                return new \WP_Error('wpcb_backup_scope', __('Verfügbarkeitsregel verweist auf einen unbekannten Scope.', 'wordpress-calendar-booking'));
            }
        }
        foreach ($s['exceptions'] as $row) {
            $typeRef = (string)($row['booking_type_ref'] ?? '');
            $resourceRef = (string)($row['resource_ref'] ?? '');
            if (($typeRef !== '' && empty($refs['booking_types'][$typeRef]))
                || ($resourceRef !== '' && empty($refs['resources'][$resourceRef]))) {
                return new \WP_Error('wpcb_backup_exception_ref', __('Ausnahme verweist auf unbekannte Terminart oder Ressource.', 'wordpress-calendar-booking'));
            }
        }
        foreach ([
            ['booking_type_calendar_connections', 'booking_types'],
            ['resource_calendar_connections', 'resources'],
        ] as [$section, $ownerSection]) {
            foreach ($s[$section] as $row) {
                if (empty($refs[$ownerSection][(string)($row['owner_ref'] ?? '')])
                    || empty($refs['calendar_connections'][(string)($row['connection_ref'] ?? '')])) {
                    return new \WP_Error('wpcb_backup_connection_mapping', __('Kalender-Zuordnung verweist auf unbekannte Einträge.', 'wordpress-calendar-booking'));
                }
            }
        }

        return true;
    }

    private function buildPlan(array $s) {
        global $wpdb;
        $p = $wpdb->prefix . 'wpcb_';

        $summary = [
            'dry_run' => true,
            'creates' => 0,
            'updates' => 0,
            'conflicts' => 0,
            'relationships' => count($s['booking_type_resources'])
                + count($s['booking_type_calendar_connections'])
                + count($s['resource_calendar_connections']),
            'connections_disabled' => count($s['calendar_connections']),
            'sections' => [],
        ];

        $definitions = [
            'booking_types' => [$p . 'booking_types', 'slug'],
            'resources' => [$p . 'resources', 'slug'],
            'form_fields' => [$p . 'form_fields', 'field_key'],
        ];
        foreach ($definitions as $section => [$table, $key]) {
            $creates = 0;
            $updates = 0;
            foreach ($s[$section] as $row) {
                $value = (string)($row[$key] ?? '');
                if ($value === '') {
                    return new \WP_Error('wpcb_backup_required', sprintf(
                        __('Pflichtfeld %1$s fehlt in %2$s.', 'wordpress-calendar-booking'),
                        $key,
                        $section
                    ));
                }
                $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE {$key} = %s LIMIT 1", $value));
                $exists ? $updates++ : $creates++;
            }
            $summary['creates'] += $creates;
            $summary['updates'] += $updates;
            $summary['sections'][$section] = ['creates' => $creates, 'updates' => $updates];
        }

        $connCreates = 0;
        $connUpdates = 0;
        foreach ($s['calendar_connections'] as $row) {
            $provider = sanitize_key((string)($row['provider'] ?? ''));
            $name = sanitize_text_field((string)($row['name'] ?? ''));
            if ($provider === '' || $name === '') {
                return new \WP_Error('wpcb_backup_connection', __('Kalender-Verbindung ohne Provider oder Name.', 'wordpress-calendar-booking'));
            }
            $exists = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}calendar_connections WHERE provider = %s AND name = %s LIMIT 1",
                $provider,
                $name
            ));
            $exists ? $connUpdates++ : $connCreates++;
        }
        $summary['creates'] += $connCreates;
        $summary['updates'] += $connUpdates;
        $summary['sections']['calendar_connections'] = ['creates' => $connCreates, 'updates' => $connUpdates];

        foreach (['availability_rules','exceptions'] as $section) {
            $summary['sections'][$section] = ['creates' => count($s[$section]), 'updates' => 0];
            $summary['creates'] += count($s[$section]);
        }

        return $summary;
    }

    private function apply(array $s, array $plan) {
        global $wpdb;
        $p = $wpdb->prefix . 'wpcb_';
        $now = Time::formatUtc(Time::nowUtc());

        $settings = $s['settings'];
        $settings['icloud_sync_enabled'] = 0;
        $settingsResult = Settings::update($settings);
        if (is_wp_error($settingsResult)) {
            return $settingsResult;
        }

        $typeMap = [];
        foreach ($s['booking_types'] as $row) {
            $id = $this->upsert($p . 'booking_types', 'slug', (string)$row['slug'], $this->pick($row, self::TYPE_FIELDS), $now);
            if ($id < 1) return $this->dbError('booking_types');
            $typeMap[(string)$row['ref']] = $id;
        }

        $resourceMap = [];
        foreach ($s['resources'] as $row) {
            $id = $this->upsert($p . 'resources', 'slug', (string)$row['slug'], $this->pick($row, self::RESOURCE_FIELDS), $now);
            if ($id < 1) return $this->dbError('resources');
            $resourceMap[(string)$row['ref']] = $id;
        }

        $fieldMap = [];
        foreach ($s['form_fields'] as $row) {
            $id = $this->upsert($p . 'form_fields', 'field_key', (string)$row['field_key'], $this->pick($row, self::FIELD_FIELDS), $now);
            if ($id < 1) return $this->dbError('form_fields');
            $fieldMap[(string)$row['ref']] = $id;
        }

        $connectionMap = [];
        foreach ($s['calendar_connections'] as $row) {
            $provider = sanitize_key((string)$row['provider']);
            $name = sanitize_text_field((string)$row['name']);
            $existing = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}calendar_connections WHERE provider = %s AND name = %s LIMIT 1",
                $provider,
                $name
            ));
            $data = $this->pick($row, self::CONNECTION_FIELDS);
            $data['provider'] = $provider;
            $data['name'] = $name;
            $data['is_active'] = 0;
            $data['credentials_enc'] = '';
            $data['config_json'] = wp_json_encode([]);
            $data['health_status'] = 'unknown';
            $data['last_success_at'] = null;
            $data['last_read_at'] = null;
            $data['last_write_at'] = null;
            $data['last_error_at'] = null;
            $data['last_error_message'] = '';
            $data['updated_at'] = $now;

            if ($existing > 0) {
                unset($data['credentials_enc'], $data['config_json']);
                $ok = $wpdb->update($p . 'calendar_connections', $data, ['id' => $existing]);
                if ($ok === false) return $this->dbError('calendar_connections');
                $id = $existing;
            } else {
                $data['created_at'] = $now;
                $ok = $wpdb->insert($p . 'calendar_connections', $data);
                if ($ok === false) return $this->dbError('calendar_connections');
                $id = (int)$wpdb->insert_id;
            }
            $connectionMap[(string)$row['ref']] = $id;
        }

        foreach ($s['booking_type_resources'] as $row) {
            $typeId = $typeMap[(string)($row['booking_type_ref'] ?? '')] ?? 0;
            $resourceId = $resourceMap[(string)($row['resource_ref'] ?? '')] ?? 0;
            if ($typeId < 1 || $resourceId < 1) {
                return new \WP_Error('wpcb_backup_mapping', __('Ungültige Terminart-/Ressourcen-Zuordnung.', 'wordpress-calendar-booking'));
            }
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$p}booking_type_resources (booking_type_id, resource_id, created_at, updated_at)
                 VALUES (%d, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                $typeId, $resourceId, $now, $now
            ));
            if ($wpdb->last_error) return $this->dbError('booking_type_resources');
        }

        foreach ($s['availability_rules'] as $row) {
            $data = $this->pick($row, self::RULE_FIELDS);
            $scopeType = sanitize_key((string)($data['scope_type'] ?? 'global'));
            $scopeRef = (string)($row['scope_ref'] ?? '');
            $data['scope_type'] = in_array($scopeType, ['global','booking_type','resource'], true) ? $scopeType : 'global';
            $data['scope_id'] = $data['scope_type'] === 'booking_type'
                ? ($typeMap[$scopeRef] ?? null)
                : ($data['scope_type'] === 'resource' ? ($resourceMap[$scopeRef] ?? null) : null);
            if ($data['scope_type'] !== 'global' && empty($data['scope_id'])) {
                return new \WP_Error('wpcb_backup_scope', __('Ungültige Verfügbarkeits-Referenz.', 'wordpress-calendar-booking'));
            }
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            if ($wpdb->insert($p . 'availability_rules', $data) === false) return $this->dbError('availability_rules');
        }

        foreach ($s['exceptions'] as $row) {
            $data = $this->pick($row, self::EXCEPTION_FIELDS);
            $typeRef = (string)($row['booking_type_ref'] ?? '');
            $resourceRef = (string)($row['resource_ref'] ?? '');
            $data['booking_type_id'] = $typeRef !== '' ? ($typeMap[$typeRef] ?? null) : null;
            $data['resource_id'] = $resourceRef !== '' ? ($resourceMap[$resourceRef] ?? null) : null;
            if (($typeRef !== '' && empty($data['booking_type_id'])) || ($resourceRef !== '' && empty($data['resource_id']))) {
                return new \WP_Error('wpcb_backup_exception_ref', __('Ungültige Ausnahme-Referenz.', 'wordpress-calendar-booking'));
            }
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            if ($wpdb->insert($p . 'exceptions', $data) === false) return $this->dbError('exceptions');
        }

        foreach ([
            ['booking_type_calendar_connections', 'owner_ref', $typeMap, 'booking_type_id'],
            ['resource_calendar_connections', 'owner_ref', $resourceMap, 'resource_id'],
        ] as [$section, $ownerKey, $ownerMap, $ownerColumn]) {
            foreach ($s[$section] as $row) {
                $ownerId = $ownerMap[(string)($row[$ownerKey] ?? '')] ?? 0;
                $connectionId = $connectionMap[(string)($row['connection_ref'] ?? '')] ?? 0;
                if ($ownerId < 1 || $connectionId < 1) {
                    return new \WP_Error('wpcb_backup_connection_mapping', __('Ungültige Kalender-Zuordnung.', 'wordpress-calendar-booking'));
                }
                $table = $p . $section;
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table} ({$ownerColumn}, connection_id, blocks_availability, receives_bookings, created_at, updated_at)
                     VALUES (%d, %d, %d, %d, %s, %s)
                     ON DUPLICATE KEY UPDATE blocks_availability = VALUES(blocks_availability),
                                             receives_bookings = VALUES(receives_bookings),
                                             updated_at = VALUES(updated_at)",
                    $ownerId,
                    $connectionId,
                    !empty($row['blocks_availability']) ? 1 : 0,
                    !empty($row['receives_bookings']) ? 1 : 0,
                    $now,
                    $now
                ));
                if ($wpdb->last_error) return $this->dbError($section);
            }
        }

        $plan['dry_run'] = false;
        $plan['restored_at'] = $now;
        return $plan;
    }

    private function rows(string $table, array $fields): array {
        global $wpdb;
        $cols = implode(', ', array_merge(['id'], $fields));
        return $wpdb->get_results("SELECT {$cols} FROM {$table} ORDER BY id ASC", ARRAY_A) ?: [];
    }

    private function idMap(array $rows): array {
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = $this->ref((int)$row['id']);
        }
        return $map;
    }

    private function withRef(array $row, string $kind): array {
        $id = (int)($row['id'] ?? 0);
        unset($row['id']);
        return ['ref' => $this->ref($id)] + $row;
    }

    private function ref(int $id): string {
        return 'id-' . $id;
    }

    private function mappingRows(string $table, string $left, string $right, array $leftMap, array $rightMap): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT {$left}, {$right} FROM {$table} ORDER BY {$left}, {$right}", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $l = $leftMap[(int)$row[$left]] ?? null;
            $r = $rightMap[(int)$row[$right]] ?? null;
            if ($l && $r) $out[] = ['booking_type_ref' => $l, 'resource_ref' => $r];
        }
        return $out;
    }

    private function connectionMappings(string $table, string $ownerColumn, array $ownerMap, array $connectionMap): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT {$ownerColumn}, connection_id, blocks_availability, receives_bookings
             FROM {$table} ORDER BY {$ownerColumn}, connection_id",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $owner = $ownerMap[(int)$row[$ownerColumn]] ?? null;
            $connection = $connectionMap[(int)$row['connection_id']] ?? null;
            if ($owner && $connection) {
                $out[] = [
                    'owner_ref' => $owner,
                    'connection_ref' => $connection,
                    'blocks_availability' => (int)$row['blocks_availability'],
                    'receives_bookings' => (int)$row['receives_bookings'],
                ];
            }
        }
        return $out;
    }

    private function pick(array $row, array $fields): array {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) $out[$field] = $row[$field];
        }
        return $out;
    }

    private function upsert(string $table, string $key, string $value, array $data, string $now): int {
        global $wpdb;
        $id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE {$key} = %s LIMIT 1", $value));
        $data['updated_at'] = $now;
        if ($id > 0) {
            $ok = $wpdb->update($table, $data, ['id' => $id]);
            return $ok === false ? 0 : $id;
        }
        $data['created_at'] = $now;
        $ok = $wpdb->insert($table, $data);
        return $ok === false ? 0 : (int)$wpdb->insert_id;
    }

    private function invalidateSettingsCache(): void {
        wp_cache_delete('wpcb_settings', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    private function dbError(string $section): \WP_Error {
        global $wpdb;
        return new \WP_Error(
            'wpcb_backup_db',
            sprintf(
                __('Restore fehlgeschlagen in %1$s: %2$s', 'wordpress-calendar-booking'),
                $section,
                sanitize_text_field((string)$wpdb->last_error)
            )
        );
    }
}
