<?php
namespace Wpcb\Forms;

final class FieldSubmissionValidator {
    private const MAX_TOTAL_JSON_BYTES = 65536;
    private FieldRepository $fields;

    public function __construct(?FieldRepository $fields = null) {
        $this->fields = $fields ?: new FieldRepository();
    }

    /** @return array<string,string>|\WP_Error */
    public function validate(array $input, ?array $fields = null) {
        $normalized = [];
        foreach ($fields ?? $this->fields->active() as $field) {
            $definition = FieldContract::definition($field);
            if (is_wp_error($definition)) {
                return $definition;
            }
            if (empty($definition['is_active'])) {
                continue;
            }

            $key = (string)$definition['field_key'];
            $raw = array_key_exists($key, $input) ? $input[$key] : null;
            if (is_array($raw) || is_object($raw)) {
                return $this->error('wpcb_field_shape', __('Ein Formularfeld hat ein ungültiges Datenformat.', 'wordpress-calendar-booking'), $key);
            }

            if ($definition['field_type'] === 'checkbox') {
                $value = $raw === null ? '' : trim((string)$raw);
                if ($value !== '' && $value !== '1') {
                    return $this->error('wpcb_field_checkbox', __('Eine Checkbox enthält einen ungültigen Wert.', 'wordpress-calendar-booking'), $key);
                }
                $checked = $value === '1';
                if ((!empty($definition['is_required']) || !empty($definition['rules']['must_be_checked'])) && !$checked) {
                    return $this->error('wpcb_field_required', __('Bitte alle Pflichtfelder bestätigen.', 'wordpress-calendar-booking'), $key);
                }
                $normalized[$key] = $checked ? '1' : '';
                continue;
            }

            $value = $raw === null ? '' : (string)$raw;
            if ($definition['field_type'] === 'select' || $definition['field_type'] === 'radio') {
                if ($value !== '' && !in_array($value, $definition['options'], true)) {
                    return $this->error('wpcb_field_option', __('Eine Auswahl ist nicht mehr gültig. Bitte wähle erneut.', 'wordpress-calendar-booking'), $key);
                }
                $clean = $value;
            } elseif ($definition['field_type'] === 'textarea') {
                $clean = sanitize_textarea_field($value);
            } elseif ($definition['field_type'] === 'email') {
                $value = trim($value);
                if ($value !== '' && !is_email($value)) {
                    return $this->error('wpcb_field_email', __('Bitte eine gültige E-Mail-Adresse eingeben.', 'wordpress-calendar-booking'), $key);
                }
                $clean = $value === '' ? '' : sanitize_email($value);
            } else {
                $clean = sanitize_text_field($value);
            }

            if (!empty($definition['is_required']) && $clean === '') {
                return $this->error('wpcb_field_required', __('Bitte alle Pflichtfelder ausfüllen.', 'wordpress-calendar-booking'), $key);
            }

            $length = FieldContract::length($clean);
            if ($length > (int)$definition['max_length']) {
                return $this->error('wpcb_field_too_long', __('Ein Formularfeld ist zu lang.', 'wordpress-calendar-booking'), $key);
            }
            if ($clean !== '' && $length < (int)$definition['min_length']) {
                return $this->error('wpcb_field_too_short', __('Ein Formularfeld ist zu kurz.', 'wordpress-calendar-booking'), $key);
            }
            $normalized[$key] = $clean;
        }

        $json = wp_json_encode($normalized);
        if (!is_string($json) || strlen($json) > self::MAX_TOTAL_JSON_BYTES) {
            return $this->error(
                'wpcb_field_payload_too_large',
                __('Die Formulardaten sind insgesamt zu groß.', 'wordpress-calendar-booking'),
                ''
            );
        }
        return $normalized;
    }

    public static function isFieldError(\WP_Error $error): bool {
        return strncmp($error->get_error_code(), 'wpcb_field_', 11) === 0;
    }

    private function error(string $code, string $message, string $field): \WP_Error {
        return new \WP_Error($code, $message, $field === '' ? [] : ['field' => $field]);
    }
}
