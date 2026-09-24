<?php
namespace Wpcb\Admin;

use Wpcb\Support\Time;

final class ConfigurationBackupService {
    public const FORMAT = 'wordpress-calendar-booking-configuration';
    public const FORMAT_VERSION = 1;

    private const SETTING_KEYS = [
        'mode','sender_name','sender_email','timezone','date_format','time_format',
        'notifications_enabled','notification_emails','reminders_enabled','reminder_hours',
        'delivery_log_retention_days','calendar_url','calendar_urls','calendar_cache_minutes',
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
        ];
    }

    public function decode(string $json) {
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
        ];
        if (array_diff(array_keys($data), $allowed)) {
            return new \WP_Error('wpcb_backup_unknown', __('Die Sicherung enthält unbekannte Felder.', 'wordpress-calendar-booking'));
        }
        if (($data['format'] ?? '') !== self::FORMAT || (int)($data['format_version'] ?? 0) !== self::FORMAT_VERSION) {
            return new \WP_Error('wpcb_backup_version', __('Das Sicherungsformat wird nicht unterstützt.', 'wordpress-calendar-booking'));
        }
        foreach (['settings','email_templates','booking_types','resources','booking_type_resources','form_fields','availability_rules','exceptions'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                return new \WP_Error('wpcb_backup_shape', __('Die Sicherung ist unvollständig oder ungültig.', 'wordpress-calendar-booking'));
            }
        }
        if (array_diff(array_keys($data['settings']), self::SETTING_KEYS)) {
            return new \WP_Error('wpcb_backup_unsafe_settings', __('Die Sicherung enthält nicht erlaubte Einstellungsfelder.', 'wordpress-calendar-booking'));
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
        foreach ($data['booking_type_resources'] as $row) {
            if (!is_array($row)
                || !isset($typeSlugs[(string)($row['booking_type_slug'] ?? '')])
                || !isset($resourceSlugs[(string)($row['resource_slug'] ?? '')])) {
                return new \WP_Error('wpcb_backup_mapping', __('Eine Zuordnung verweist auf ein unbekanntes Objekt.', 'wordpress-calendar-booking'));
            }
        }
        return $data;
    }
}
