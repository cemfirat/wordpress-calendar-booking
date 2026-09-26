<?php
namespace Wpcb\Forms;

final class FieldContract {
    private const TYPES = ['text', 'email', 'textarea', 'checkbox', 'select', 'radio'];
    private const RULE_KEYS = ['must_be_checked', 'min_length', 'max_length'];

    /** @return array<string,mixed>|\WP_Error */
    public static function definition($field) {
        $row = is_object($field) ? get_object_vars($field) : (is_array($field) ? $field : []);
        $key = (string)($row['field_key'] ?? '');
        $label = (string)($row['label'] ?? '');
        $type = (string)($row['field_type'] ?? '');

        if ($key === '' || strlen($key) > 190 || sanitize_key($key) !== $key) {
            return self::configError();
        }
        if ($label === '' || self::length($label) > 190 || !in_array($type, self::TYPES, true)) {
            return self::configError();
        }

        $required = self::booleanValue($row['is_required'] ?? 0);
        $active = self::booleanValue($row['is_active'] ?? 0);
        $options = self::decodeOptions($row['options_json'] ?? null);
        if (is_wp_error($options)) {
            return $options;
        }
        if ($active && in_array($type, ['select', 'radio'], true) && !$options) {
            return self::configError();
        }

        $rules = self::decodeRules($row['validation_rules_json'] ?? null, $type);
        if (is_wp_error($rules)) {
            return $rules;
        }

        $baseLimit = self::baseMaxLength($type, $key);
        if (isset($rules['max_length']) && (int)$rules['max_length'] > $baseLimit) {
            return self::configError();
        }
        $limit = isset($rules['max_length']) ? (int)$rules['max_length'] : $baseLimit;
        if (isset($rules['min_length']) && (int)$rules['min_length'] > $limit) {
            return self::configError();
        }

        return [
            'field_key' => $key,
            'label' => $label,
            'field_type' => $type,
            'is_required' => $required,
            'is_active' => $active,
            'options' => $options,
            'options_json' => $options ? wp_json_encode($options) : null,
            'rules' => $rules,
            'validation_rules_json' => $rules ? wp_json_encode($rules) : null,
            'min_length' => isset($rules['min_length']) ? (int)$rules['min_length'] : 0,
            'max_length' => $limit,
        ];
    }

    public static function supportedTypes(): array {
        return self::TYPES;
    }

    public static function supportedRuleKeys(): array {
        return self::RULE_KEYS;
    }

    public static function length(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function baseMaxLength(string $type, string $key): int {
        if ($key === 'phone') {
            return 100;
        }
        if ($type === 'textarea') {
            return 10000;
        }
        if ($type === 'checkbox') {
            return 1;
        }
        return 190;
    }

    /** @return string[]|\WP_Error */
    private static function decodeOptions($encoded) {
        if ($encoded === null || $encoded === '') {
            return [];
        }
        if (!is_string($encoded) || strlen($encoded) > 20000) {
            return self::configError();
        }
        $decoded = json_decode($encoded, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE
            || ($decoded !== [] && array_keys($decoded) !== range(0, count($decoded) - 1))
            || count($decoded) > 100) {
            return self::configError();
        }
        $out = [];
        foreach ($decoded as $option) {
            if (!is_string($option) || $option === '' || self::length($option) > 190) {
                return self::configError();
            }
            if (!in_array($option, $out, true)) {
                $out[] = $option;
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|\WP_Error */
    private static function decodeRules($encoded, string $type) {
        if ($encoded === null || $encoded === '') {
            return [];
        }
        if (!is_string($encoded) || strlen($encoded) > 20000) {
            return self::configError();
        }
        $decoded = json_decode($encoded, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE
            || ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1))
            || array_diff(array_keys($decoded), self::RULE_KEYS)) {
            return self::configError();
        }

        $rules = [];
        if (array_key_exists('must_be_checked', $decoded)) {
            if ($type !== 'checkbox' || !self::validBoolean($decoded['must_be_checked'])) {
                return self::configError();
            }
            $rules['must_be_checked'] = self::booleanValue($decoded['must_be_checked']);
        }

        foreach (['min_length', 'max_length'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }
            if (!in_array($type, ['text', 'email', 'textarea'], true)
                || !self::validInteger($decoded[$key], $key === 'min_length' ? 0 : 1, 10000)) {
                return self::configError();
            }
            $rules[$key] = (int)$decoded[$key];
        }
        return $rules;
    }

    private static function validInteger($value, int $min, int $max): bool {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value)) {
            $number = (int)$value;
        } else {
            return false;
        }
        return $number >= $min && $number <= $max;
    }

    private static function validBoolean($value): bool {
        return is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
    }

    private static function booleanValue($value): bool {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function configError(): \WP_Error {
        return new \WP_Error(
            'wpcb_field_config_invalid',
            __('Ein aktives Formularfeld ist ungültig konfiguriert. Bitte prüfe Typ, Optionen und Validierungsregeln.', 'wordpress-calendar-booking')
        );
    }
}
