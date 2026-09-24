<?php
namespace Wpcb\Admin;

use Wpcb\Support\Time;
use Wpcb\Resources\ResourceRepository;
use Wpcb\Calendar\CalendarConnectionRepository;
use Wpcb\Calendar\ProviderRegistry;

final class ConfigurationBackupService {
    public const FORMAT = 'wordpress-calendar-booking-configuration';
    public const FORMAT_VERSION = 1;
    public const MAX_JSON_BYTES = 1048576;

    private const SECTION_LIMITS = [
        'booking_types' => 500,
        'resources' => 500,
        'booking_type_resources' => 5000,
        'form_fields' => 500,
        'availability_rules' => 5000,
        'exceptions' => 5000,
        'calendar_connections' => 200,
        'booking_type_calendar_connections' => 5000,
        'resource_calendar_connections' => 5000,
    ];
    private const MAX_EMAIL_TEMPLATES = 100;
    private const MAX_TEMPLATE_LENGTH = 20000;

    private const SETTING_KEYS = [
        'mode','sender_name','sender_email','timezone','date_format','time_format',
        'notifications_enabled','notification_emails','reminders_enabled','reminder_hours',
        'delivery_log_retention_days','calendar_cache_minutes',
        'token_ttl_minutes','reservation_ttl_minutes','cancel_min_hours','change_min_hours',
        'honeypot_enabled','timing_enabled','min_form_seconds','rate_limit_enabled',
        'rate_limit_requests','rate_limit_window_minutes','show_calendar_limit',
        'retention_enabled','retention_days','delete_data_on_uninstall','visit_address','own_phone',
    ];

    public function exportSnapshot(): array {
        global $wpdb;
        $settings = Settings::get();
        $safeSettings = [];
        foreach (self::SETTING_KEYS as $key) {
            if (array_key_exists($key, $settings)) {
                $safeSettings[$key] = $settings[$key];
            }
        }

        $types = $wpdb->get_results(
            "SELECT name, slug, description, duration_minutes, buffer_before_minutes, buffer_after_minutes,
                    capacity, show_remaining_capacity, payment_mode, price_minor, currency, is_active, is_public, sort_order
             FROM {$wpdb->prefix}wpcb_booking_types ORDER BY id ASC",
            ARRAY_A
        );
        $resources = $wpdb->get_results(
            "SELECT name, slug, public_label, description, capacity, is_active, is_public, sort_order
             FROM {$wpdb->prefix}wpcb_resources ORDER BY id ASC",
            ARRAY_A
        );
        $fields = $wpdb->get_results(
            "SELECT field_key, label, field_type, is_required, is_active, options_json, validation_rules_json, sort_order
             FROM {$wpdb->prefix}wpcb_form_fields ORDER BY id ASC",
            ARRAY_A
        );
        $typeResources = $wpdb->get_results(
            "SELECT t.slug AS booking_type_slug, r.slug AS resource_slug
             FROM {$wpdb->prefix}wpcb_booking_type_resources m
             INNER JOIN {$wpdb->prefix}wpcb_booking_types t ON t.id=m.booking_type_id
             INNER JOIN {$wpdb->prefix}wpcb_resources r ON r.id=m.resource_id
             ORDER BY t.slug ASC, r.slug ASC",
            ARRAY_A
        );
        $rules = $wpdb->get_results(
            "SELECT ar.scope_type, ar.weekday, ar.start_time, ar.end_time, ar.slot_duration_minutes,
                    ar.buffer_before_minutes, ar.buffer_after_minutes, ar.min_notice_minutes,
                    ar.max_days_in_advance, ar.is_active, t.slug AS booking_type_slug, r.slug AS resource_slug
             FROM {$wpdb->prefix}wpcb_availability_rules ar
             LEFT JOIN {$wpdb->prefix}wpcb_booking_types t ON ar.scope_type='booking_type' AND t.id=ar.scope_id
             LEFT JOIN {$wpdb->prefix}wpcb_resources r ON ar.scope_type='resource' AND r.id=ar.scope_id
             ORDER BY ar.id ASC",
            ARRAY_A
        );
        $exceptions = $wpdb->get_results(
            "SELECT e.type, e.title, e.date_start, e.date_end, e.all_day, e.is_active,
                    t.slug AS booking_type_slug, r.slug AS resource_slug
             FROM {$wpdb->prefix}wpcb_exceptions e
             LEFT JOIN {$wpdb->prefix}wpcb_booking_types t ON t.id=e.booking_type_id
             LEFT JOIN {$wpdb->prefix}wpcb_resources r ON r.id=e.resource_id
             ORDER BY e.id ASC",
            ARRAY_A
        );
        $connections = $wpdb->get_results(
            "SELECT provider, name, remote_calendar_id, blocks_availability, receives_bookings
             FROM {$wpdb->prefix}wpcb_calendar_connections ORDER BY id ASC",
            ARRAY_A
        );
        foreach ($connections as &$connection) {
            $connection['requires_reconnect'] = true;
        }
        unset($connection);
        $typeConnections = $wpdb->get_results(
            "SELECT c.provider, c.name AS connection_name, c.remote_calendar_id,
                    t.slug AS booking_type_slug, m.blocks_availability, m.receives_bookings
             FROM {$wpdb->prefix}wpcb_booking_type_calendar_connections m
             INNER JOIN {$wpdb->prefix}wpcb_calendar_connections c ON c.id=m.connection_id
             INNER JOIN {$wpdb->prefix}wpcb_booking_types t ON t.id=m.booking_type_id
             ORDER BY t.slug ASC, c.id ASC",
            ARRAY_A
        );
        $resourceConnections = $wpdb->get_results(
            "SELECT c.provider, c.name AS connection_name, c.remote_calendar_id,
                    r.slug AS resource_slug, m.blocks_availability, m.receives_bookings
             FROM {$wpdb->prefix}wpcb_resource_calendar_connections m
             INNER JOIN {$wpdb->prefix}wpcb_calendar_connections c ON c.id=m.connection_id
             INNER JOIN {$wpdb->prefix}wpcb_resources r ON r.id=m.resource_id
             ORDER BY r.slug ASC, c.id ASC",
            ARRAY_A
        );

        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'plugin_version' => defined('WPCB_VERSION') ? WPCB_VERSION : '',
            'exported_at_utc' => Time::formatUtc(Time::nowUtc()),
            'settings' => $safeSettings,
            'email_templates' => (array)get_option('wpcb_email_templates', []),
            'booking_types' => $types,
            'resources' => $resources,
            'booking_type_resources' => $typeResources,
            'form_fields' => $fields,
            'availability_rules' => $rules,
            'exceptions' => $exceptions,
            'calendar_connections' => $connections,
            'booking_type_calendar_connections' => $typeConnections,
            'resource_calendar_connections' => $resourceConnections,
        ];
    }

    public function decode(string $json) {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            return new \WP_Error(
                'wpcb_backup_too_large',
                __('Die Sicherungsdatei überschreitet die zulässige Größe.', 'wordpress-calendar-booking')
            );
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new \WP_Error('wpcb_backup_json', __('Die Sicherungsdatei enthält kein gültiges JSON.', 'wordpress-calendar-booking'));
        }
        return $this->validate($data);
    }

    public function validate(array $data) {
        $allowed = [
            'format','format_version','plugin_version','exported_at_utc','settings','email_templates',
            'booking_types','resources','booking_type_resources','form_fields','availability_rules','exceptions',
            'calendar_connections','booking_type_calendar_connections','resource_calendar_connections',
        ];
        if (array_diff(array_keys($data), $allowed)) {
            return new \WP_Error('wpcb_backup_unknown', __('Die Sicherung enthält unbekannte Felder.', 'wordpress-calendar-booking'));
        }
        if (($data['format'] ?? '') !== self::FORMAT || (int)($data['format_version'] ?? 0) !== self::FORMAT_VERSION) {
            return new \WP_Error('wpcb_backup_version', __('Das Sicherungsformat wird nicht unterstützt.', 'wordpress-calendar-booking'));
        }
        foreach (['settings','email_templates','booking_types','resources','booking_type_resources','form_fields','availability_rules','exceptions','calendar_connections','booking_type_calendar_connections','resource_calendar_connections'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                return new \WP_Error('wpcb_backup_shape', __('Die Sicherung ist unvollständig oder ungültig.', 'wordpress-calendar-booking'));
            }
        }
        if (array_diff(array_keys($data['settings']), self::SETTING_KEYS)) {
            return new \WP_Error('wpcb_backup_unsafe_settings', __('Die Sicherung enthält nicht erlaubte Einstellungsfelder.', 'wordpress-calendar-booking'));
        }

        $rowKeys = [
            'booking_types' => ['name','slug','description','duration_minutes','buffer_before_minutes','buffer_after_minutes','capacity','show_remaining_capacity','payment_mode','price_minor','currency','is_active','is_public','sort_order'],
            'resources' => ['name','slug','public_label','description','capacity','is_active','is_public','sort_order'],
            'booking_type_resources' => ['booking_type_slug','resource_slug'],
            'form_fields' => ['field_key','label','field_type','is_required','is_active','options_json','validation_rules_json','sort_order'],
            'availability_rules' => ['scope_type','weekday','start_time','end_time','slot_duration_minutes','buffer_before_minutes','buffer_after_minutes','min_notice_minutes','max_days_in_advance','is_active','booking_type_slug','resource_slug'],
            'exceptions' => ['type','title','date_start','date_end','all_day','is_active','booking_type_slug','resource_slug'],
            'calendar_connections' => ['provider','name','remote_calendar_id','blocks_availability','receives_bookings','requires_reconnect'],
            'booking_type_calendar_connections' => ['provider','connection_name','remote_calendar_id','booking_type_slug','blocks_availability','receives_bookings'],
            'resource_calendar_connections' => ['provider','connection_name','remote_calendar_id','resource_slug','blocks_availability','receives_bookings'],
        ];
        foreach ($rowKeys as $section => $allowedKeys) {
            if (count($data[$section]) > (self::SECTION_LIMITS[$section] ?? 0)) {
                return new \WP_Error('wpcb_backup_section_limit', __('Ein Konfigurationsabschnitt enthält zu viele Datensätze.', 'wordpress-calendar-booking'));
            }
            foreach ($data[$section] as $row) {
                if (!is_array($row)
                    || array_diff(array_keys($row), $allowedKeys)
                    || array_diff($allowedKeys, array_keys($row))) {
                    return new \WP_Error('wpcb_backup_row_fields', __('Die Sicherung enthält fehlende oder unbekannte Konfigurationsfelder.', 'wordpress-calendar-booking'));
                }
            }
        }

        $typed = $this->validateTypedSchema($data);
        if (is_wp_error($typed)) {
            return $typed;
        }

        $typeSlugs = [];
        foreach ($data['booking_types'] as $row) {
            if (!is_array($row)) return new \WP_Error('wpcb_backup_row', __('Ungültige Terminart in der Sicherung.', 'wordpress-calendar-booking'));
            $slug = sanitize_title((string)($row['slug'] ?? ''));
            if ($slug === '' || isset($typeSlugs[$slug])) {
                return new \WP_Error('wpcb_backup_type_slug', __('Terminart-Slugs müssen eindeutig sein.', 'wordpress-calendar-booking'));
            }
            $typeSlugs[$slug] = true;
        }
        $resourceSlugs = [];
        foreach ($data['resources'] as $row) {
            if (!is_array($row)) return new \WP_Error('wpcb_backup_row', __('Ungültige Ressource in der Sicherung.', 'wordpress-calendar-booking'));
            $slug = sanitize_title((string)($row['slug'] ?? ''));
            if ($slug === '' || isset($resourceSlugs[$slug])) {
                return new \WP_Error('wpcb_backup_resource_slug', __('Ressourcen-Slugs müssen eindeutig sein.', 'wordpress-calendar-booking'));
            }
            $resourceSlugs[$slug] = true;
        }
        $fieldKeys = [];
        foreach ($data['form_fields'] as $row) {
            $key = sanitize_key((string)($row['field_key'] ?? ''));
            if ($key === '' || isset($fieldKeys[$key])) {
                return new \WP_Error('wpcb_backup_field_key', __('Formularfeld-Schlüssel müssen eindeutig sein.', 'wordpress-calendar-booking'));
            }
            $fieldKeys[$key] = true;
        }
        foreach ($data['booking_type_resources'] as $row) {
            if (!is_array($row)
                || !isset($typeSlugs[(string)($row['booking_type_slug'] ?? '')])
                || !isset($resourceSlugs[(string)($row['resource_slug'] ?? '')])) {
                return new \WP_Error('wpcb_backup_mapping', __('Eine Zuordnung verweist auf ein unbekanntes Objekt.', 'wordpress-calendar-booking'));
            }
        }
        foreach ($data['availability_rules'] as $row) {
            $scope = (string)($row['scope_type'] ?? '');
            if (!in_array($scope, ['global','booking_type','resource'], true)) {
                return new \WP_Error('wpcb_backup_rule_scope', __('Eine Verfügbarkeitsregel enthält einen ungültigen Geltungsbereich.', 'wordpress-calendar-booking'));
            }
            if ($scope === 'booking_type' && !isset($typeSlugs[(string)($row['booking_type_slug'] ?? '')])) {
                return new \WP_Error('wpcb_backup_rule_type', __('Eine Verfügbarkeitsregel verweist auf eine unbekannte Terminart.', 'wordpress-calendar-booking'));
            }
            if ($scope === 'resource' && !isset($resourceSlugs[(string)($row['resource_slug'] ?? '')])) {
                return new \WP_Error('wpcb_backup_rule_resource', __('Eine Verfügbarkeitsregel verweist auf eine unbekannte Ressource.', 'wordpress-calendar-booking'));
            }
        }
        foreach ($data['exceptions'] as $row) {
            $typeSlug = (string)($row['booking_type_slug'] ?? '');
            $resourceSlug = (string)($row['resource_slug'] ?? '');
            if (($typeSlug !== '' && !isset($typeSlugs[$typeSlug]))
                || ($resourceSlug !== '' && !isset($resourceSlugs[$resourceSlug]))
                || strtotime((string)($row['date_start'] ?? '') . ' UTC') === false
                || strtotime((string)($row['date_end'] ?? '') . ' UTC') === false) {
                return new \WP_Error('wpcb_backup_exception', __('Eine Ausnahme enthält ungültige Referenzen oder Datumswerte.', 'wordpress-calendar-booking'));
            }
        }

        $connectionKeys = [];
        foreach ($data['calendar_connections'] as $row) {
            if (empty($row['provider']) || empty($row['name']) || empty($row['requires_reconnect'])) {
                return new \WP_Error('wpcb_backup_connection', __('Kalender-Metadaten müssen als neu zu verbindende Verbindung gekennzeichnet sein.', 'wordpress-calendar-booking'));
            }
            $key = $this->calendarConnectionKey($row);
            if (isset($connectionKeys[$key])) {
                return new \WP_Error('wpcb_backup_connection_duplicate', __('Kalenderverbindungen müssen innerhalb der Sicherung eindeutig sein.', 'wordpress-calendar-booking'));
            }
            $connectionKeys[$key] = true;
        }
        foreach ($data['booking_type_calendar_connections'] as $row) {
            $key = $this->calendarConnectionKey([
                'provider'=>$row['provider'] ?? '',
                'name'=>$row['connection_name'] ?? '',
                'remote_calendar_id'=>$row['remote_calendar_id'] ?? '',
            ]);
            if (!isset($typeSlugs[(string)($row['booking_type_slug'] ?? '')], $connectionKeys[$key])) {
                return new \WP_Error('wpcb_backup_calendar_type_mapping', __('Eine Kalender-/Terminart-Zuordnung ist ungültig.', 'wordpress-calendar-booking'));
            }
        }
        foreach ($data['resource_calendar_connections'] as $row) {
            $key = $this->calendarConnectionKey([
                'provider'=>$row['provider'] ?? '',
                'name'=>$row['connection_name'] ?? '',
                'remote_calendar_id'=>$row['remote_calendar_id'] ?? '',
            ]);
            if (!isset($resourceSlugs[(string)($row['resource_slug'] ?? '')], $connectionKeys[$key])) {
                return new \WP_Error('wpcb_backup_calendar_resource_mapping', __('Eine Kalender-/Ressourcen-Zuordnung ist ungültig.', 'wordpress-calendar-booking'));
            }
        }
        return $data;
    }

    public function plan(array $snapshot) {
        $valid = $this->validate($snapshot);
        if (is_wp_error($valid)) {
            return $valid;
        }
        global $wpdb;
        $plan = [
            'create' => ['booking_types'=>0,'resources'=>0,'form_fields'=>0,'availability_rules'=>0,'exceptions'=>0,'calendar_connections'=>0],
            'update' => ['booking_types'=>0,'resources'=>0,'form_fields'=>0,'availability_rules'=>0,'exceptions'=>0],
            'conflict_count' => 0,
            'conflicts' => [],
            'relationships' => count($snapshot['booking_type_resources']) + count($snapshot['booking_type_calendar_connections']) + count($snapshot['resource_calendar_connections']),
            'warnings' => [],
        ];
        if ($snapshot['calendar_connections']) {
            $plan['warnings'][] = __('Kalenderverbindungen werden ohne Zugangsdaten importiert und bleiben bis zur erneuten Verbindung deaktiviert.', 'wordpress-calendar-booking');
        }

        foreach ($snapshot['booking_types'] as $row) {
            $slug = sanitize_title((string)$row['slug']);
            $matches = $wpdb->get_results($wpdb->prepare(
                "SELECT name, slug, description, duration_minutes, buffer_before_minutes, buffer_after_minutes,
                        capacity, show_remaining_capacity, payment_mode, price_minor, currency, is_active, is_public, sort_order
                 FROM {$wpdb->prefix}wpcb_booking_types WHERE slug=%s",
                $slug
            ), ARRAY_A) ?: [];
            if (count($matches) > 1) {
                return $this->ambiguousNaturalKey('booking_types', $slug);
            }
            if ($matches) {
                $plan['update']['booking_types']++;
                $this->recordConflict($plan, 'booking_types', $slug, $matches[0], $row, [
                    'name','description','duration_minutes','buffer_before_minutes','buffer_after_minutes',
                    'capacity','show_remaining_capacity','payment_mode','price_minor','currency','is_active','is_public','sort_order'
                ]);
            } else {
                $plan['create']['booking_types']++;
            }
        }
        foreach ($snapshot['resources'] as $row) {
            $slug = sanitize_title((string)$row['slug']);
            $matches = $wpdb->get_results($wpdb->prepare(
                "SELECT name, slug, public_label, description, capacity, is_active, is_public, sort_order
                 FROM {$wpdb->prefix}wpcb_resources WHERE slug=%s",
                $slug
            ), ARRAY_A) ?: [];
            if (count($matches) > 1) {
                return $this->ambiguousNaturalKey('resources', $slug);
            }
            if ($matches) {
                $plan['update']['resources']++;
                $this->recordConflict($plan, 'resources', $slug, $matches[0], $row, [
                    'name','public_label','description','capacity','is_active','is_public','sort_order'
                ]);
            } else {
                $plan['create']['resources']++;
            }
        }
        foreach ($snapshot['form_fields'] as $row) {
            $fieldKey = sanitize_key((string)$row['field_key']);
            $matches = $wpdb->get_results($wpdb->prepare(
                "SELECT field_key, label, field_type, is_required, is_active, options_json, validation_rules_json, sort_order
                 FROM {$wpdb->prefix}wpcb_form_fields WHERE field_key=%s",
                $fieldKey
            ), ARRAY_A) ?: [];
            if (count($matches) > 1) {
                return $this->ambiguousNaturalKey('form_fields', $fieldKey);
            }
            if ($matches) {
                $plan['update']['form_fields']++;
                $this->recordConflict($plan, 'form_fields', $fieldKey, $matches[0], $row, [
                    'label','field_type','is_required','is_active','options_json','validation_rules_json','sort_order'
                ]);
            } else {
                $plan['create']['form_fields']++;
            }
        }

        $typeIds = $this->typeIdsBySlug();
        $resourceIds = $this->resourceIdsBySlug();
        foreach ($snapshot['availability_rules'] as $row) {
            $scopeType = (string)($row['scope_type'] ?? 'global');
            $scopeId = $this->snapshotScopeId($row, $typeIds, $resourceIds);
            $matches = $this->ruleRows($scopeType, $scopeId, $row);
            $identity = $scopeType . ':' . ($scopeId ?: 'global') . ':' . (int)($row['weekday'] ?? 0)
                . ':' . (string)($row['start_time'] ?? '') . '-' . (string)($row['end_time'] ?? '');
            if (count($matches) > 1) {
                return $this->ambiguousNaturalKey('availability_rules', $identity);
            }
            if ($matches) {
                $plan['update']['availability_rules']++;
                $this->recordConflict($plan, 'availability_rules', $identity, $matches[0], $row, [
                    'slot_duration_minutes','buffer_before_minutes','buffer_after_minutes',
                    'min_notice_minutes','max_days_in_advance','is_active'
                ]);
            } else {
                $plan['create']['availability_rules']++;
            }
        }
        foreach ($snapshot['exceptions'] as $row) {
            $typeId = $typeIds[(string)($row['booking_type_slug'] ?? '')] ?? 0;
            $resourceId = $resourceIds[(string)($row['resource_slug'] ?? '')] ?? 0;
            $matches = $this->exceptionRows($row, $typeId, $resourceId);
            $identity = sanitize_key((string)($row['type'] ?? '')) . ':' .
                sanitize_text_field((string)($row['title'] ?? '')) . ':' .
                (string)($row['date_start'] ?? '') . ':' . (string)($row['date_end'] ?? '');
            if (count($matches) > 1) {
                return $this->ambiguousNaturalKey('exceptions', $identity);
            }
            if ($matches) {
                $plan['update']['exceptions']++;
                $this->recordConflict($plan, 'exceptions', $identity, $matches[0], $row, ['all_day','is_active']);
            } else {
                $plan['create']['exceptions']++;
            }
        }
        foreach ($snapshot['calendar_connections'] as $row) {
            $matches = $this->calendarConnectionRows($row);
            $identity = sanitize_key((string)($row['provider'] ?? '')) . ':' .
                sanitize_text_field((string)($row['name'] ?? '')) . ':' .
                sanitize_text_field((string)($row['remote_calendar_id'] ?? ''));
            if (count($matches) > 1) {
                return $this->ambiguousNaturalKey('calendar_connections', $identity);
            }
            if (!$matches) {
                $plan['create']['calendar_connections']++;
            }
        }
        return $plan;
    }

    public function import(array $snapshot, bool $dryRun = false) {
        $plan = $this->plan($snapshot);
        if (is_wp_error($plan) || $dryRun) {
            return $plan;
        }

        global $wpdb;
        $configuration = new ConfigurationService();
        $resourceRepo = new ResourceRepository();
        $calendarRepo = new CalendarConnectionRepository();
        $previousSettings = get_option('wpcb_settings', []);
        $previousTemplates = get_option('wpcb_email_templates', []);

        $wpdb->query('START TRANSACTION');
        try {
            $settingsResult = Settings::update($snapshot['settings']);
            if (is_wp_error($settingsResult)) {
                throw new \RuntimeException($settingsResult->get_error_message());
            }
            update_option('wpcb_email_templates', $this->sanitizeTemplates($snapshot['email_templates']), false);

            $typeIds = [];
            foreach ($snapshot['booking_types'] as $row) {
                $slug = sanitize_title((string)$row['slug']);
                $existing = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}wpcb_booking_types WHERE slug=%s LIMIT 1",
                    $slug
                ));
                $id = $configuration->saveBookingType($row, $existing);
                if ($id < 1) {
                    throw new \RuntimeException('booking type');
                }
                $typeIds[$slug] = $id;
            }

            $resourceIds = [];
            foreach ($snapshot['resources'] as $row) {
                $slug = sanitize_title((string)$row['slug']);
                $existing = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}wpcb_resources WHERE slug=%s LIMIT 1",
                    $slug
                ));
                $saved = $resourceRepo->save($row, $existing);
                if (is_wp_error($saved) || (int)$saved < 1) {
                    throw new \RuntimeException('resource');
                }
                $resourceIds[$slug] = (int)$saved;
            }

            foreach ($snapshot['form_fields'] as $row) {
                $fieldKey = sanitize_key((string)$row['field_key']);
                $existing = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}wpcb_form_fields WHERE field_key=%s LIMIT 1",
                    $fieldKey
                ));
                $options = json_decode((string)($row['options_json'] ?? ''), true);
                $input = $row;
                $input['options_raw'] = is_array($options) ? implode("\n", array_map('strval', $options)) : '';
                $id = $configuration->saveField($input, $existing);
                if ($id < 1) {
                    throw new \RuntimeException('form field');
                }
                $validation = $this->safeJson($row['validation_rules_json'] ?? null);
                if ($wpdb->update(
                    $wpdb->prefix . 'wpcb_form_fields',
                    ['validation_rules_json' => $validation],
                    ['id' => $id]
                ) === false) {
                    throw new \RuntimeException('form validation');
                }
            }

            $resourceSelections = [];
            foreach ($snapshot['booking_type_resources'] as $row) {
                $typeSlug = (string)$row['booking_type_slug'];
                $resourceSlug = (string)$row['resource_slug'];
                $resourceSelections[$typeSlug][] = $resourceIds[$resourceSlug];
            }
            foreach ($resourceSelections as $typeSlug => $ids) {
                $resourceRepo->setForBookingType($typeIds[$typeSlug], $ids);
            }

            foreach ($snapshot['availability_rules'] as $row) {
                $scopeType = sanitize_key((string)($row['scope_type'] ?? 'global'));
                $scopeId = $this->snapshotScopeId($row, $typeIds, $resourceIds);
                if ($scopeType !== 'global' && $scopeId < 1) {
                    throw new \RuntimeException('availability mapping');
                }
                $existing = $this->ruleId($scopeType, $scopeId ?: null, $row);
                $input = $row;
                $input['scope_type'] = $scopeType;
                $input['scope_id'] = $scopeId;
                $input['resource_scope_id'] = $scopeId;
                if ($configuration->saveRule($input, $existing) < 1) {
                    throw new \RuntimeException('availability');
                }
            }

            foreach ($snapshot['exceptions'] as $row) {
                $typeId = $typeIds[(string)($row['booking_type_slug'] ?? '')] ?? 0;
                $resourceId = $resourceIds[(string)($row['resource_slug'] ?? '')] ?? 0;
                $existing = $this->exceptionId($row, $typeId, $resourceId);
                $data = [
                    'type' => sanitize_key((string)($row['type'] ?? 'blocked_range')),
                    'title' => sanitize_text_field((string)($row['title'] ?? '')),
                    'date_start' => $this->utcDate((string)($row['date_start'] ?? '')),
                    'date_end' => $this->utcDate((string)($row['date_end'] ?? '')),
                    'all_day' => empty($row['all_day']) ? 0 : 1,
                    'booking_type_id' => $typeId ?: null,
                    'resource_id' => $resourceId ?: null,
                    'is_active' => empty($row['is_active']) ? 0 : 1,
                    'updated_at' => Time::formatUtc(Time::nowUtc()),
                ];
                if ($existing > 0) {
                    if ($wpdb->update($wpdb->prefix . 'wpcb_exceptions', $data, ['id'=>$existing]) === false) {
                        throw new \RuntimeException('exception update');
                    }
                } else {
                    $data['created_at'] = Time::formatUtc(Time::nowUtc());
                    if ($wpdb->insert($wpdb->prefix . 'wpcb_exceptions', $data) === false) {
                        throw new \RuntimeException('exception insert');
                    }
                }
            }

            $connectionIds = [];
            foreach ($snapshot['calendar_connections'] as $row) {
                $key = $this->calendarConnectionKey($row);
                $id = $this->calendarConnectionId($row);
                if ($id < 1) {
                    $created = $calendarRepo->create([
                        'provider' => sanitize_key((string)($row['provider'] ?? '')),
                        'name' => sanitize_text_field((string)($row['name'] ?? '')),
                        'remote_calendar_id' => sanitize_text_field((string)($row['remote_calendar_id'] ?? '')),
                        'blocks_availability' => !empty($row['blocks_availability']),
                        'receives_bookings' => !empty($row['receives_bookings']),
                        'is_active' => false,
                        'config' => [],
                    ], []);
                    if (is_wp_error($created)) {
                        throw new \RuntimeException('calendar connection');
                    }
                    $id = (int)$created;
                }
                $connectionIds[$key] = $id;
            }

            $typeSelections = [];
            foreach ($snapshot['booking_type_calendar_connections'] as $row) {
                $typeSlug = (string)($row['booking_type_slug'] ?? '');
                $key = $this->calendarConnectionKey([
                    'provider'=>$row['provider'] ?? '',
                    'name'=>$row['connection_name'] ?? '',
                    'remote_calendar_id'=>$row['remote_calendar_id'] ?? '',
                ]);
                if (!isset($typeIds[$typeSlug], $connectionIds[$key])) {
                    throw new \RuntimeException('calendar type mapping');
                }
                $typeSelections[$typeSlug][] = [
                    'connection_id'=>$connectionIds[$key],
                    'blocks_availability'=>!empty($row['blocks_availability']),
                    'receives_bookings'=>!empty($row['receives_bookings']),
                ];
            }
            foreach ($typeSelections as $slug => $selections) {
                $calendarRepo->setForBookingType($typeIds[$slug], $selections);
            }

            $resourceSelectionsCalendar = [];
            foreach ($snapshot['resource_calendar_connections'] as $row) {
                $resourceSlug = (string)($row['resource_slug'] ?? '');
                $key = $this->calendarConnectionKey([
                    'provider'=>$row['provider'] ?? '',
                    'name'=>$row['connection_name'] ?? '',
                    'remote_calendar_id'=>$row['remote_calendar_id'] ?? '',
                ]);
                if (!isset($resourceIds[$resourceSlug], $connectionIds[$key])) {
                    throw new \RuntimeException('calendar resource mapping');
                }
                $resourceSelectionsCalendar[$resourceSlug][] = [
                    'connection_id'=>$connectionIds[$key],
                    'blocks_availability'=>!empty($row['blocks_availability']),
                    'receives_bookings'=>!empty($row['receives_bookings']),
                ];
            }
            foreach ($resourceSelectionsCalendar as $slug => $selections) {
                $calendarRepo->setForResource($resourceIds[$slug], $selections);
            }

            $wpdb->query('COMMIT');
            do_action('wpcb_capacity_changed');
            return $plan + ['applied'=>true];
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');

            // WordPress may still hold option values written inside the rolled-back
            // transaction in its in-request option caches. Invalidate those caches
            // before restoring the snapshots so get_option() cannot observe state
            // that no longer exists in the database.
            wp_cache_delete('wpcb_settings', 'options');
            wp_cache_delete('wpcb_email_templates', 'options');
            wp_cache_delete('alloptions', 'options');
            update_option('wpcb_settings', $previousSettings, false);
            update_option('wpcb_email_templates', $previousTemplates, false);
            wp_cache_delete('wpcb_settings', 'options');
            wp_cache_delete('wpcb_email_templates', 'options');
            wp_cache_delete('alloptions', 'options');

            return new \WP_Error(
                'wpcb_backup_import',
                __('Die Konfiguration konnte nicht vollständig importiert werden. Datenbankänderungen wurden zurückgerollt.', 'wordpress-calendar-booking')
            );
        }
    }

    private function validateTypedSchema(array $data) {
        if (!is_string($data['format'] ?? null)
            || !is_string($data['plugin_version'] ?? null)
            || strlen((string)$data['plugin_version']) > 32
            || !is_string($data['exported_at_utc'] ?? null)
            || !$this->validDateTime((string)$data['exported_at_utc'])) {
            return $this->schemaError('wpcb_backup_metadata', __('Die Sicherungsmetadaten sind ungültig.', 'wordpress-calendar-booking'));
        }

        if (count($data['email_templates']) > self::MAX_EMAIL_TEMPLATES) {
            return $this->schemaError('wpcb_backup_template_limit', __('Die Sicherung enthält zu viele E-Mail-Vorlagen.', 'wordpress-calendar-booking'));
        }
        foreach ($data['email_templates'] as $key => $value) {
            if (!is_string($key)
                || $key === ''
                || strlen($key) > 100
                || sanitize_key($key) !== $key
                || !is_string($value)
                || strlen($value) > self::MAX_TEMPLATE_LENGTH) {
                return $this->schemaError('wpcb_backup_template_type', __('Eine E-Mail-Vorlage ist ungültig oder zu groß.', 'wordpress-calendar-booking'));
            }
        }

        $settingsError = $this->validateSettingsSchema($data['settings']);
        if (is_wp_error($settingsError)) {
            return $settingsError;
        }

        foreach ($data['booking_types'] as $row) {
            if (!$this->validString($row['name'], 190, false)
                || !$this->validSlug($row['slug'])
                || !$this->validString($row['description'], 10000)
                || !$this->validInteger($row['duration_minutes'], 1, 1440)
                || !$this->validInteger($row['buffer_before_minutes'], 0, 1440)
                || !$this->validInteger($row['buffer_after_minutes'], 0, 1440)
                || !$this->validInteger($row['capacity'], 1, 10000)
                || !$this->validBoolean($row['show_remaining_capacity'])
                || !is_string($row['payment_mode'])
                || !in_array($row['payment_mode'], ['free', 'required'], true)
                || !$this->validInteger($row['price_minor'], 0, 1000000000000)
                || !is_string($row['currency'])
                || !preg_match('/^[A-Z]{3}$/', $row['currency'])
                || !$this->validBoolean($row['is_active'])
                || !$this->validBoolean($row['is_public'])
                || !$this->validInteger($row['sort_order'], -1000000, 1000000)) {
                return $this->schemaError('wpcb_backup_type_schema', __('Eine Terminart enthält ungültige Typen oder Werte.', 'wordpress-calendar-booking'));
            }
        }

        foreach ($data['resources'] as $row) {
            if (!$this->validString($row['name'], 190, false)
                || !$this->validSlug($row['slug'])
                || !$this->validString($row['public_label'], 190)
                || !$this->validString($row['description'], 10000)
                || !$this->validInteger($row['capacity'], 1, 10000)
                || !$this->validBoolean($row['is_active'])
                || !$this->validBoolean($row['is_public'])
                || !$this->validInteger($row['sort_order'], -1000000, 1000000)) {
                return $this->schemaError('wpcb_backup_resource_schema', __('Eine Ressource enthält ungültige Typen oder Werte.', 'wordpress-calendar-booking'));
            }
        }

        foreach ($data['booking_type_resources'] as $row) {
            if (!$this->validSlug($row['booking_type_slug']) || !$this->validSlug($row['resource_slug'])) {
                return $this->schemaError('wpcb_backup_mapping_schema', __('Eine Ressourcen-Zuordnung ist ungültig.', 'wordpress-calendar-booking'));
            }
        }

        $fieldTypes = ['text','email','textarea','checkbox','select','radio'];
        foreach ($data['form_fields'] as $row) {
            if (!$this->validKey($row['field_key'], 190)
                || !$this->validString($row['label'], 190, false)
                || !is_string($row['field_type'])
                || !in_array($row['field_type'], $fieldTypes, true)
                || !$this->validBoolean($row['is_required'])
                || !$this->validBoolean($row['is_active'])
                || !$this->validJsonArray($row['options_json'], true)
                || !$this->validJsonArray($row['validation_rules_json'], false)
                || !$this->validInteger($row['sort_order'], -1000000, 1000000)) {
                return $this->schemaError('wpcb_backup_field_schema', __('Ein Formularfeld enthält ungültige Typen oder Werte.', 'wordpress-calendar-booking'));
            }
        }

        foreach ($data['availability_rules'] as $row) {
            $scope = $row['scope_type'];
            if (!is_string($scope)
                || !in_array($scope, ['global','booking_type','resource'], true)
                || !$this->validInteger($row['weekday'], 1, 7)
                || !$this->validTime($row['start_time'])
                || !$this->validTime($row['end_time'])
                || $this->timeSeconds((string)$row['start_time']) >= $this->timeSeconds((string)$row['end_time'])
                || !$this->validInteger($row['slot_duration_minutes'], 1, 1440)
                || !$this->validInteger($row['buffer_before_minutes'], 0, 1440)
                || !$this->validInteger($row['buffer_after_minutes'], 0, 1440)
                || !$this->validInteger($row['min_notice_minutes'], 0, 525600)
                || !$this->validInteger($row['max_days_in_advance'], 1, 3650)
                || !$this->validBoolean($row['is_active'])
                || !$this->validNullableSlug($row['booking_type_slug'])
                || !$this->validNullableSlug($row['resource_slug'])) {
                return $this->schemaError('wpcb_backup_rule_schema', __('Eine Verfügbarkeitsregel enthält ungültige Typen oder Werte.', 'wordpress-calendar-booking'));
            }
            $typeSlug = (string)($row['booking_type_slug'] ?? '');
            $resourceSlug = (string)($row['resource_slug'] ?? '');
            if (($scope === 'global' && ($typeSlug !== '' || $resourceSlug !== ''))
                || ($scope === 'booking_type' && ($typeSlug === '' || $resourceSlug !== ''))
                || ($scope === 'resource' && ($resourceSlug === '' || $typeSlug !== ''))) {
                return $this->schemaError('wpcb_backup_rule_scope_shape', __('Eine Verfügbarkeitsregel hat widersprüchliche Scope-Daten.', 'wordpress-calendar-booking'));
            }
        }

        $exceptionTypes = ['holiday','blocked_day','blocked_range','vacation'];
        foreach ($data['exceptions'] as $row) {
            if (!is_string($row['type'])
                || !in_array($row['type'], $exceptionTypes, true)
                || !$this->validString($row['title'], 190, false)
                || !$this->validDateTime($row['date_start'])
                || !$this->validDateTime($row['date_end'])
                || strtotime((string)$row['date_start'] . ' UTC') >= strtotime((string)$row['date_end'] . ' UTC')
                || !$this->validBoolean($row['all_day'])
                || !$this->validBoolean($row['is_active'])
                || !$this->validNullableSlug($row['booking_type_slug'])
                || !$this->validNullableSlug($row['resource_slug'])) {
                return $this->schemaError('wpcb_backup_exception_schema', __('Eine Ausnahme enthält ungültige Typen oder Werte.', 'wordpress-calendar-booking'));
            }
        }

        $providers = array_keys((new ProviderRegistry())->all());
        foreach ($data['calendar_connections'] as $row) {
            if (!$this->validProvider($row['provider'], $providers)
                || !$this->validString($row['name'], 190, false)
                || !$this->validNullableString($row['remote_calendar_id'], 255)
                || !$this->validBoolean($row['blocks_availability'])
                || !$this->validBoolean($row['receives_bookings'])
                || !$this->validBoolean($row['requires_reconnect'])
                || !$this->booleanValue($row['requires_reconnect'])) {
                return $this->schemaError('wpcb_backup_connection_schema', __('Eine Kalenderverbindung enthält ungültige Typen oder Werte.', 'wordpress-calendar-booking'));
            }
        }

        foreach (['booking_type_calendar_connections','resource_calendar_connections'] as $section) {
            foreach ($data[$section] as $row) {
                $targetKey = $section === 'booking_type_calendar_connections' ? 'booking_type_slug' : 'resource_slug';
                if (!$this->validProvider($row['provider'], $providers)
                    || !$this->validString($row['connection_name'], 190, false)
                    || !$this->validNullableString($row['remote_calendar_id'], 255)
                    || !$this->validSlug($row[$targetKey])
                    || !$this->validBoolean($row['blocks_availability'])
                    || !$this->validBoolean($row['receives_bookings'])) {
                    return $this->schemaError('wpcb_backup_calendar_mapping_schema', __('Eine Kalender-Zuordnung enthält ungültige Typen oder Werte.', 'wordpress-calendar-booking'));
                }
            }
        }

        return true;
    }

    private function validateSettingsSchema(array $settings) {
        $boolKeys = [
            'notifications_enabled','reminders_enabled','honeypot_enabled','timing_enabled',
            'rate_limit_enabled','retention_enabled','delete_data_on_uninstall',
        ];
        $intRanges = [
            'reminder_hours' => [1, 8760],
            'delivery_log_retention_days' => [1, 3650],
            'calendar_cache_minutes' => [0, 1440],
            'token_ttl_minutes' => [1, 10080],
            'reservation_ttl_minutes' => [1, 1440],
            'cancel_min_hours' => [0, 8760],
            'change_min_hours' => [0, 8760],
            'min_form_seconds' => [0, 600],
            'rate_limit_requests' => [1, 10000],
            'rate_limit_window_minutes' => [1, 1440],
            'show_calendar_limit' => [1, 1000],
            'retention_days' => [1, 36500],
        ];
        $stringLimits = [
            'sender_name' => 190,
            'sender_email' => 254,
            'timezone' => 100,
            'date_format' => 100,
            'time_format' => 100,
            'notification_emails' => 2000,
            'visit_address' => 1000,
            'own_phone' => 100,
        ];

        foreach ($settings as $key => $value) {
            if ($key === 'mode') {
                if (!is_string($value) || !in_array($value, ['automatic','approval'], true)) {
                    return $this->schemaError('wpcb_backup_settings_schema', __('Die Sicherung enthält ungültige Einstellungen.', 'wordpress-calendar-booking'));
                }
                continue;
            }
            if (in_array($key, $boolKeys, true)) {
                if (!$this->validBoolean($value)) {
                    return $this->schemaError('wpcb_backup_settings_schema', __('Die Sicherung enthält ungültige Einstellungen.', 'wordpress-calendar-booking'));
                }
                continue;
            }
            if (isset($intRanges[$key])) {
                if (!$this->validInteger($value, $intRanges[$key][0], $intRanges[$key][1])) {
                    return $this->schemaError('wpcb_backup_settings_schema', __('Die Sicherung enthält ungültige Einstellungen.', 'wordpress-calendar-booking'));
                }
                continue;
            }
            if (isset($stringLimits[$key])) {
                if (!$this->validString($value, $stringLimits[$key])) {
                    return $this->schemaError('wpcb_backup_settings_schema', __('Die Sicherung enthält ungültige Einstellungen.', 'wordpress-calendar-booking'));
                }
                if ($key === 'sender_email' && $value !== '' && !is_email($value)) {
                    return $this->schemaError('wpcb_backup_settings_schema', __('Die Sicherung enthält ungültige Einstellungen.', 'wordpress-calendar-booking'));
                }
                if ($key === 'timezone' && $value !== 'UTC' && !in_array($value, \DateTimeZone::listIdentifiers(), true)) {
                    return $this->schemaError('wpcb_backup_settings_schema', __('Die Sicherung enthält ungültige Einstellungen.', 'wordpress-calendar-booking'));
                }
                continue;
            }
            return $this->schemaError('wpcb_backup_settings_schema', __('Die Sicherung enthält ungültige Einstellungen.', 'wordpress-calendar-booking'));
        }
        return true;
    }

    private function schemaError(string $code, string $message): \WP_Error {
        return new \WP_Error($code, $message);
    }

    private function validString($value, int $maxLength, bool $allowEmpty = true): bool {
        return is_string($value)
            && ($allowEmpty || $value !== '')
            && strlen($value) <= $maxLength;
    }

    private function validNullableString($value, int $maxLength): bool {
        return $value === null || $this->validString($value, $maxLength);
    }

    private function validSlug($value): bool {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 190
            && sanitize_title($value) === $value;
    }

    private function validNullableSlug($value): bool {
        return $value === null || $value === '' || $this->validSlug($value);
    }

    private function validKey($value, int $maxLength): bool {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= $maxLength
            && sanitize_key($value) === $value;
    }

    private function validInteger($value, int $min, int $max): bool {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $integer = (int)$value;
        } else {
            return false;
        }
        return $integer >= $min && $integer <= $max;
    }

    private function validBoolean($value): bool {
        return is_bool($value)
            || $value === 0
            || $value === 1
            || $value === '0'
            || $value === '1';
    }

    private function booleanValue($value): bool {
        return $value === true || $value === 1 || $value === '1';
    }

    private function validTime($value): bool {
        if (!is_string($value) || !preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return false;
        }
        return (int)$m[1] <= 23 && (int)$m[2] <= 59 && (!isset($m[3]) || (int)$m[3] <= 59);
    }

    private function timeSeconds(string $value): int {
        $parts = array_map('intval', explode(':', $value));
        return ($parts[0] ?? 0) * 3600 + ($parts[1] ?? 0) * 60 + ($parts[2] ?? 0);
    }

    private function validDateTime($value): bool {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d H:i:s') === $value;
    }

    private function validJsonArray($value, bool $listOnly): bool {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_string($value) || strlen($value) > 20000) {
            return false;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        if ($listOnly) {
            if (array_keys($decoded) !== range(0, count($decoded) - 1) && $decoded !== []) {
                return false;
            }
            if (count($decoded) > 100) {
                return false;
            }
            foreach ($decoded as $item) {
                if (!is_string($item) || strlen($item) > 190) {
                    return false;
                }
            }
        }
        return true;
    }

    private function validProvider($value, array $providers): bool {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 64
            && sanitize_key($value) === $value
            && in_array($value, $providers, true);
    }

    private function sanitizeTemplates(array $templates): array {
        $out = [];
        foreach ($templates as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $safeKey = sanitize_key((string)$key);
            if ($safeKey !== '') {
                $out[$safeKey] = sanitize_textarea_field((string)$value);
            }
        }
        return $out;
    }

    private function safeJson($value): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        return is_array($decoded) ? wp_json_encode($decoded) : null;
    }

    private function utcDate(string $value): string {
        $timestamp = strtotime($value . ' UTC');
        if ($timestamp === false) {
            throw new \RuntimeException('invalid date');
        }
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function typeIdsBySlug(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT slug,id FROM {$wpdb->prefix}wpcb_booking_types");
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row->slug] = (int)$row->id;
        }
        return $out;
    }

    private function resourceIdsBySlug(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT slug,id FROM {$wpdb->prefix}wpcb_resources");
        $out = [];
        foreach ($rows as $row) $out[(string)$row->slug] = (int)$row->id;
        return $out;
    }

    private function snapshotScopeId(array $row, array $typeIds, array $resourceIds): int {
        $scope = (string)($row['scope_type'] ?? 'global');
        if ($scope === 'booking_type') return (int)($typeIds[(string)($row['booking_type_slug'] ?? '')] ?? 0);
        if ($scope === 'resource') return (int)($resourceIds[(string)($row['resource_slug'] ?? '')] ?? 0);
        return 0;
    }

    private function recordConflict(array &$plan, string $section, string $identity, array $existing, array $incoming, array $fields): void {
        $changed = [];
        foreach ($fields as $field) {
            $left = $this->comparableValue($existing[$field] ?? null);
            $right = $this->comparableValue($incoming[$field] ?? null);
            if ($left !== $right) {
                $changed[] = $field;
            }
        }
        if (!$changed) {
            return;
        }
        $plan['conflict_count']++;
        if (count($plan['conflicts']) < 50) {
            $plan['conflicts'][] = [
                'section' => $section,
                'identity' => $identity,
                'fields' => $changed,
            ];
        }
    }

    private function comparableValue($value): string {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return (string)wp_json_encode($value);
        }
        return trim((string)$value);
    }

    private function ambiguousNaturalKey(string $section, string $identity): \WP_Error {
        return new \WP_Error(
            'wpcb_backup_ambiguous',
            sprintf(
                /* translators: 1: configuration section, 2: natural key */
                __('Lokale Konfiguration ist für %1$s (%2$s) nicht eindeutig. Restore wurde vor Schreibzugriff abgebrochen.', 'wordpress-calendar-booking'),
                $section,
                $identity
            )
        );
    }

    private function ruleRows(string $scopeType, ?int $scopeId, array $row): array {
        global $wpdb;
        $sql = "SELECT id, slot_duration_minutes, buffer_before_minutes, buffer_after_minutes,
                       min_notice_minutes, max_days_in_advance, is_active
                FROM {$wpdb->prefix}wpcb_availability_rules
                WHERE scope_type=%s AND weekday=%d AND start_time=%s AND end_time=%s";
        $params = [$scopeType, (int)($row['weekday'] ?? 0), (string)($row['start_time'] ?? ''), (string)($row['end_time'] ?? '')];
        if ($scopeId) {
            $sql .= ' AND scope_id=%d';
            $params[] = $scopeId;
        } else {
            $sql .= ' AND scope_id IS NULL';
        }
        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
    }

    private function exceptionRows(array $row, int $typeId, int $resourceId): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, all_day, is_active FROM {$wpdb->prefix}wpcb_exceptions
             WHERE type=%s AND title=%s AND date_start=%s AND date_end=%s
               AND COALESCE(booking_type_id,0)=%d AND COALESCE(resource_id,0)=%d",
            sanitize_key((string)($row['type'] ?? '')),
            sanitize_text_field((string)($row['title'] ?? '')),
            $this->utcDate((string)($row['date_start'] ?? '')),
            $this->utcDate((string)($row['date_end'] ?? '')),
            $typeId,
            $resourceId
        ), ARRAY_A) ?: [];
    }

    private function calendarConnectionRows(array $row): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wpcb_calendar_connections
             WHERE provider=%s AND name=%s AND COALESCE(remote_calendar_id,'')=%s",
            sanitize_key((string)($row['provider'] ?? '')),
            sanitize_text_field((string)($row['name'] ?? '')),
            sanitize_text_field((string)($row['remote_calendar_id'] ?? ''))
        ), ARRAY_A) ?: [];
    }

    private function ruleId(string $scopeType, ?int $scopeId, array $row): int {
        $rows = $this->ruleRows($scopeType, $scopeId, $row);
        return $rows ? (int)$rows[0]['id'] : 0;
    }

    private function calendarConnectionKey(array $row): string {
        return hash('sha256',
            sanitize_key((string)($row['provider'] ?? '')) . "\n" .
            sanitize_text_field((string)($row['name'] ?? '')) . "\n" .
            sanitize_text_field((string)($row['remote_calendar_id'] ?? ''))
        );
    }

    private function calendarConnectionId(array $row): int {
        global $wpdb;
        $rows = $this->calendarConnectionRows($row);
        return $rows ? (int)$rows[0]['id'] : 0;
    }

    private function exceptionId(array $row, int $typeId, int $resourceId): int {
        global $wpdb;
        $rows = $this->exceptionRows($row, $typeId, $resourceId);
        return $rows ? (int)$rows[0]['id'] : 0;
    }
}
