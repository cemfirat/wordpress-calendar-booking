<?php
namespace Wpcb\Admin;

use Wpcb\Support\Time;
use Wpcb\Forms\FieldValidator;

final class ConfigurationService {
    public function saveBookingType(array $input, int $id = 0): int {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_booking_types';
        $currency = strtoupper(sanitize_text_field((string)($input['currency'] ?? 'EUR')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'EUR';
        }
        $data = [
            'name' => sanitize_text_field((string)($input['name'] ?? '')),
            'slug' => sanitize_title((string)($input['slug'] ?? '')),
            'description' => sanitize_textarea_field((string)($input['description'] ?? '')),
            'duration_minutes' => max(1, absint($input['duration_minutes'] ?? 30)),
            'buffer_before_minutes' => absint($input['buffer_before_minutes'] ?? 0),
            'buffer_after_minutes' => absint($input['buffer_after_minutes'] ?? 0),
            'capacity' => max(1, min(10000, absint($input['capacity'] ?? 1))),
            'show_remaining_capacity' => empty($input['show_remaining_capacity']) ? 0 : 1,
            'payment_mode' => in_array(($input['payment_mode'] ?? 'free'), ['free', 'required'], true)
                ? sanitize_key((string)($input['payment_mode'] ?? 'free'))
                : 'free',
            'price_minor' => max(0, absint($input['price_minor'] ?? 0)),
            'currency' => $currency,
            'is_active' => empty($input['is_active']) ? 0 : 1,
            'is_public' => empty($input['is_public']) ? 0 : 1,
            'sort_order' => (int)($input['sort_order'] ?? 0),
            'updated_at' => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update($table, $data, ['id' => $id]);
            do_action('wpcb_capacity_changed');
            return $id;
        }

        $data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $data);
        do_action('wpcb_capacity_changed');
        return (int)$wpdb->insert_id;
    }

    public function deleteBookingType(int $id) {
        global $wpdb;
        if ($id < 1) {
            return new \WP_Error('invalid_booking_type', __('Ungültige Terminart.', 'wordpress-calendar-booking'));
        }

        foreach (['bookings', 'booking_series', 'waiting_list'] as $suffix) {
            $count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_{$suffix} WHERE booking_type_id = %d",
                $id
            ));
            if ($count > 0) {
                return new \WP_Error(
                    'booking_type_in_use',
                    __('Diese Terminart besitzt historische Buchungen, Serien oder Wartelisteneinträge und kann nicht gelöscht werden. Deaktiviere sie stattdessen.', 'wordpress-calendar-booking')
                );
            }
        }

        $wpdb->delete($wpdb->prefix . 'wpcb_booking_type_resources', ['booking_type_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_type_calendar_connections', ['booking_type_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_type_video_connections', ['booking_type_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_availability_rules', ['scope_type' => 'booking_type', 'scope_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_exceptions', ['booking_type_id' => $id]);
        $deleted = $wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $id]);
        do_action('wpcb_capacity_changed');

        return $deleted !== false;
    }

    /** @return int|\WP_Error */
    public function saveField(array $input, int $id = 0) {
        global $wpdb;

        $fieldKey = sanitize_key((string)($input['field_key'] ?? ''));
        $label = sanitize_text_field((string)($input['label'] ?? ''));
        $fieldType = sanitize_key((string)($input['field_type'] ?? 'text'));
        if ($fieldKey === '' || strlen($fieldKey) > 190 || $label === '' || strlen($label) > 190) {
            return new \WP_Error(
                'wpcb_field_identity_invalid',
                __('Formularfeld-Schlüssel und Bezeichnung müssen gültig und höchstens 190 Zeichen lang sein.', 'wordpress-calendar-booking')
            );
        }

        $rawOptions = (string)($input['options_raw'] ?? '');
        $options = preg_split('/\r\n|\r|\n/', $rawOptions) ?: [];
        $options = array_values(array_filter(array_map(
            static fn($value): string => sanitize_text_field(trim((string)$value)),
            $options
        ), static fn(string $value): bool => $value !== ''));

        if (array_key_exists('validation_rules_json', $input)
            && $input['validation_rules_json'] !== null
            && $input['validation_rules_json'] !== '') {
            if (!is_string($input['validation_rules_json'])) {
                return new \WP_Error(
                    'wpcb_field_rules_invalid',
                    __('Die Validierungsregeln des Formularfelds sind ungültig.', 'wordpress-calendar-booking')
                );
            }
            $rules = json_decode($input['validation_rules_json'], true);
            if (!is_array($rules) || json_last_error() !== JSON_ERROR_NONE) {
                return new \WP_Error(
                    'wpcb_field_rules_invalid',
                    __('Die Validierungsregeln des Formularfelds sind ungültig.', 'wordpress-calendar-booking')
                );
            }
        } else {
            $rules = [];
            $minLength = trim((string)($input['validation_min_length'] ?? ''));
            $maxLength = trim((string)($input['validation_max_length'] ?? ''));
            if ($minLength !== '') {
                $rules['min_length'] = $minLength;
            }
            if ($maxLength !== '') {
                $rules['max_length'] = $maxLength;
            }
            if (!empty($input['validation_must_be_checked'])) {
                $rules['must_be_checked'] = true;
            }
        }

        $definition = (new FieldValidator())->validateDefinition($fieldType, $options, $rules);
        if (is_wp_error($definition)) {
            return $definition;
        }

        $table = $wpdb->prefix . 'wpcb_form_fields';
        $duplicate = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE field_key = %s AND id <> %d ORDER BY id ASC LIMIT 1",
            $fieldKey,
            max(0, $id)
        ));
        if ($duplicate > 0) {
            return new \WP_Error(
                'wpcb_field_key_duplicate',
                __('Der Formularfeld-Schlüssel wird bereits verwendet.', 'wordpress-calendar-booking')
            );
        }

        $data = [
            'field_key' => $fieldKey,
            'label' => $label,
            'field_type' => $definition['type'],
            'options_json' => $definition['options'] ? wp_json_encode($definition['options']) : null,
            'validation_rules_json' => $definition['rules'] ? wp_json_encode($definition['rules']) : null,
            'is_required' => empty($input['is_required']) ? 0 : 1,
            'is_active' => empty($input['is_active']) ? 0 : 1,
            'sort_order' => (int)($input['sort_order'] ?? 0),
            'updated_at' => current_time('mysql'),
        ];

        if ($id > 0) {
            if ($wpdb->update($table, $data, ['id' => $id]) === false) {
                return new \WP_Error(
                    'wpcb_field_write_failed',
                    __('Das Formularfeld konnte nicht gespeichert werden.', 'wordpress-calendar-booking')
                );
            }
            return $id;
        }

        $data['created_at'] = current_time('mysql');
        if ($wpdb->insert($table, $data) === false) {
            return new \WP_Error(
                'wpcb_field_write_failed',
                __('Das Formularfeld konnte nicht gespeichert werden.', 'wordpress-calendar-booking')
            );
        }
        return (int)$wpdb->insert_id;
    }

    public function deleteField(int $id): bool {
        global $wpdb;
        return $id > 0 && $wpdb->delete($wpdb->prefix . 'wpcb_form_fields', ['id' => $id]) !== false;
    }

    public function saveRule(array $input, int $id = 0): int {
        global $wpdb;
        $scopeType = sanitize_key((string)($input['scope_type'] ?? 'global'));
        if (!in_array($scopeType, ['global', 'booking_type', 'resource'], true)) {
            $scopeType = 'global';
        }
        $scopeId = $scopeType === 'resource'
            ? absint($input['resource_scope_id'] ?? 0)
            : absint($input['scope_id'] ?? 0);
        if ($scopeType === 'global') {
            $scopeId = 0;
        }
        $normalizeTime = static function ($value, string $fallback): string {
            $value = sanitize_text_field((string)$value);
            if (preg_match('/^\d{2}:\d{2}$/', $value)) {
                return $value . ':00';
            }
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
                return $value;
            }
            return $fallback;
        };
        $data = [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId ?: null,
            'weekday' => max(1, min(7, absint($input['weekday'] ?? 1))),
            'start_time' => $normalizeTime($input['start_time'] ?? '', '09:00:00'),
            'end_time' => $normalizeTime($input['end_time'] ?? '', '17:00:00'),
            'slot_duration_minutes' => max(1, absint($input['slot_duration_minutes'] ?? 30)),
            'buffer_before_minutes' => absint($input['buffer_before_minutes'] ?? 0),
            'buffer_after_minutes' => absint($input['buffer_after_minutes'] ?? 0),
            'min_notice_minutes' => absint($input['min_notice_minutes'] ?? 0),
            'max_days_in_advance' => max(1, absint($input['max_days_in_advance'] ?? 30)),
            'is_active' => empty($input['is_active']) ? 0 : 1,
            'updated_at' => current_time('mysql'),
        ];
        $table = $wpdb->prefix . 'wpcb_availability_rules';
        if ($id > 0) {
            $wpdb->update($table, $data, ['id' => $id]);
            return $id;
        }
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $data);
        return (int)$wpdb->insert_id;
    }

    public function deleteRule(int $id): bool {
        global $wpdb;
        return $id > 0 && $wpdb->delete($wpdb->prefix . 'wpcb_availability_rules', ['id' => $id]) !== false;
    }

    public function saveException(array $input, int $id = 0): int {
        global $wpdb;
        $start = str_replace('T', ' ', sanitize_text_field((string)($input['date_start'] ?? '')));
        $end = str_replace('T', ' ', sanitize_text_field((string)($input['date_end'] ?? '')));
        if (strlen($start) === 16) $start .= ':00';
        if (strlen($end) === 16) $end .= ':00';

        $data = [
            'type' => sanitize_key((string)($input['type'] ?? 'blocked_range')),
            'title' => sanitize_text_field((string)($input['title'] ?? '')),
            'date_start' => Time::localToUtc($start),
            'date_end' => Time::localToUtc($end),
            'all_day' => empty($input['all_day']) ? 0 : 1,
            'booking_type_id' => absint($input['booking_type_id'] ?? 0) ?: null,
            'resource_id' => absint($input['resource_id'] ?? 0) ?: null,
            'is_active' => empty($input['is_active']) ? 0 : 1,
            'updated_at' => current_time('mysql'),
        ];

        $table = $wpdb->prefix . 'wpcb_exceptions';
        if ($id > 0) {
            $wpdb->update($table, $data, ['id' => $id]);
            return $id;
        }
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $data);
        return (int)$wpdb->insert_id;
    }

    public function deleteException(int $id): bool {
        global $wpdb;
        return $id > 0 && $wpdb->delete($wpdb->prefix . 'wpcb_exceptions', ['id' => $id]) !== false;
    }
}
