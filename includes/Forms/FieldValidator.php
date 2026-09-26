<?php
namespace Wpcb\Forms;

final class FieldValidator {
    public const TYPES = ['text', 'email', 'textarea', 'checkbox', 'select', 'radio'];
    public const RULE_KEYS = ['min_length', 'max_length', 'must_be_checked'];

    /** @return array{type:string,options:array,rules:array}|\WP_Error */
    public function validateStoredDefinition(string $type, $optionsJson, $rulesJson) {
        $options = $this->decodeJsonArray($optionsJson, 'options');
        if (is_wp_error($options)) {
            return $options;
        }
        $rules = $this->decodeJsonArray($rulesJson, 'rules');
        if (is_wp_error($rules)) {
            return $rules;
        }
        return $this->validateDefinition($type, $options, $rules);
    }

    /** @return array{type:string,options:array,rules:array}|\WP_Error */
    public function validateDefinition(string $type, array $options, array $rules) {
        $type = sanitize_key($type);
        if (!in_array($type, self::TYPES, true)) {
            return new \WP_Error(
                'wpcb_field_type_unsupported',
                __('Der Formularfeld-Typ wird nicht unterstützt.', 'wordpress-calendar-booking')
            );
        }

        $normalizedOptions = [];
        if (in_array($type, ['select', 'radio'], true)) {
            if (!$this->isList($options) || !$options || count($options) > 100) {
                return new \WP_Error(
                    'wpcb_field_options_invalid',
                    __('Auswahlfelder benötigen zwischen 1 und 100 gültige Optionen.', 'wordpress-calendar-booking')
                );
            }
            foreach ($options as $option) {
                if (!is_scalar($option)) {
                    return new \WP_Error(
                        'wpcb_field_options_invalid',
                        __('Auswahloptionen müssen Textwerte sein.', 'wordpress-calendar-booking')
                    );
                }
                $value = sanitize_text_field((string)$option);
                if ($value === '' || $this->length($value) > 190) {
                    return new \WP_Error(
                        'wpcb_field_options_invalid',
                        __('Auswahloptionen müssen nicht leer und höchstens 190 Zeichen lang sein.', 'wordpress-calendar-booking')
                    );
                }
                $normalizedOptions[] = $value;
            }
            if (count(array_unique($normalizedOptions, SORT_STRING)) !== count($normalizedOptions)) {
                return new \WP_Error(
                    'wpcb_field_options_duplicate',
                    __('Auswahloptionen müssen eindeutig sein.', 'wordpress-calendar-booking')
                );
            }
        } elseif ($options !== []) {
            return new \WP_Error(
                'wpcb_field_options_not_allowed',
                __('Optionen sind nur für Auswahl- und Radiofelder erlaubt.', 'wordpress-calendar-booking')
            );
        }

        if (!$this->isAssociativeOrEmpty($rules) || array_diff(array_keys($rules), self::RULE_KEYS)) {
            return new \WP_Error(
                'wpcb_field_rules_unsupported',
                __('Die Formularfeld-Validierung enthält nicht unterstützte Regeln.', 'wordpress-calendar-booking')
            );
        }

        $normalizedRules = [];
        $textual = in_array($type, ['text', 'email', 'textarea'], true);
        foreach (['min_length', 'max_length'] as $key) {
            if (!array_key_exists($key, $rules)) {
                continue;
            }
            if (!$textual || !$this->integerLike($rules[$key])) {
                return new \WP_Error(
                    'wpcb_field_length_rule_invalid',
                    __('Längenregeln sind nur für Text-, E-Mail- und Textbereichsfelder erlaubt.', 'wordpress-calendar-booking')
                );
            }
            $value = (int)$rules[$key];
            $minimum = $key === 'min_length' ? 0 : 1;
            if ($value < $minimum || $value > $this->hardMax($type)) {
                return new \WP_Error(
                    'wpcb_field_length_rule_invalid',
                    sprintf(
                        __('Die Längenregel liegt außerhalb des erlaubten Bereichs (maximal %d Zeichen).', 'wordpress-calendar-booking'),
                        $this->hardMax($type)
                    )
                );
            }
            $normalizedRules[$key] = $value;
        }

        if (isset($normalizedRules['min_length'], $normalizedRules['max_length'])
            && $normalizedRules['min_length'] > $normalizedRules['max_length']) {
            return new \WP_Error(
                'wpcb_field_length_rule_order',
                __('Die minimale Feldlänge darf nicht größer als die maximale Feldlänge sein.', 'wordpress-calendar-booking')
            );
        }

        if (array_key_exists('must_be_checked', $rules)) {
            if ($type !== 'checkbox' || !$this->booleanLike($rules['must_be_checked'])) {
                return new \WP_Error(
                    'wpcb_field_checkbox_rule_invalid',
                    __('„Muss bestätigt werden“ ist nur für Checkbox-Felder erlaubt.', 'wordpress-calendar-booking')
                );
            }
            if ($this->booleanValue($rules['must_be_checked'])) {
                $normalizedRules['must_be_checked'] = true;
            }
        }

        return [
            'type' => $type,
            'options' => $normalizedOptions,
            'rules' => $normalizedRules,
        ];
    }

    /**
     * Validate all active configured fields against one raw submission.
     *
     * @return array<string,string>|\WP_Error
     */
    public function validateSubmission(array $fields, array $input) {
        $values = [];
        $firstError = null;

        foreach ($fields as $field) {
            if (!is_object($field) || empty($field->field_key)) {
                if ($firstError === null) {
                    $firstError = [
                        'code' => 'wpcb_field_config_invalid',
                        'message' => __('Die Formularfeld-Konfiguration ist ungültig.', 'wordpress-calendar-booking'),
                        'field_key' => '',
                    ];
                }
                continue;
            }

            $key = sanitize_key((string)$field->field_key);
            $label = trim((string)($field->label ?? $key));
            $definition = $this->validateStoredDefinition(
                (string)($field->field_type ?? ''),
                $field->options_json ?? null,
                $field->validation_rules_json ?? null
            );
            if (is_wp_error($definition)) {
                if ($firstError === null) {
                    $firstError = [
                        'code' => 'wpcb_field_config_invalid',
                        'message' => sprintf(
                            __('Das Formularfeld „%s“ ist ungültig konfiguriert. Bitte kontaktiere die Website-Administration.', 'wordpress-calendar-booking'),
                            $label
                        ),
                        'field_key' => $key,
                    ];
                }
                continue;
            }

            $raw = array_key_exists($key, $input) ? $input[$key] : '';
            if (is_array($raw) || is_object($raw)) {
                if ($firstError === null) {
                    $firstError = $this->fieldError(
                        'wpcb_field_shape_invalid',
                        $key,
                        $label,
                        __('Bitte gib für „%s“ einen einzelnen gültigen Wert ein.', 'wordpress-calendar-booking')
                    );
                }
                continue;
            }

            $raw = is_scalar($raw) ? (string)wp_unslash((string)$raw) : '';
            $value = '';
            switch ($definition['type']) {
                case 'textarea':
                    $value = sanitize_textarea_field($raw);
                    break;
                case 'email':
                    $value = sanitize_email($raw);
                    break;
                case 'checkbox':
                    if ($raw === '' || $raw === '0') {
                        $value = '';
                    } elseif ($raw === '1') {
                        $value = '1';
                    } else {
                        if ($firstError === null) {
                            $firstError = $this->fieldError(
                                'wpcb_field_checkbox_invalid',
                                $key,
                                $label,
                                __('Bitte bestätige „%s“ über die vorgesehene Checkbox.', 'wordpress-calendar-booking')
                            );
                        }
                        continue 2;
                    }
                    break;
                default:
                    $value = sanitize_text_field($raw);
                    break;
            }

            $required = !empty($field->is_required);
            if ($definition['type'] === 'checkbox') {
                $mustCheck = $required || !empty($definition['rules']['must_be_checked']);
                if ($mustCheck && $value !== '1') {
                    if ($firstError === null) {
                        $firstError = $this->fieldError(
                            'wpcb_field_required',
                            $key,
                            $label,
                            __('Bitte bestätige das Pflichtfeld „%s“.', 'wordpress-calendar-booking')
                        );
                    }
                    continue;
                }
            } elseif ($required && $value === '') {
                if ($firstError === null) {
                    $firstError = $this->fieldError(
                        'wpcb_field_required',
                        $key,
                        $label,
                        __('Bitte fülle das Pflichtfeld „%s“ aus.', 'wordpress-calendar-booking')
                    );
                }
                continue;
            }

            if ($value !== '' && in_array($definition['type'], ['select', 'radio'], true)
                && !in_array($value, $definition['options'], true)) {
                if ($firstError === null) {
                    $firstError = $this->fieldError(
                        'wpcb_field_option_invalid',
                        $key,
                        $label,
                        __('Bitte wähle für „%s“ eine aktuell angebotene Option.', 'wordpress-calendar-booking')
                    );
                }
                continue;
            }

            if ($value !== '' && $definition['type'] === 'email' && !is_email($value)) {
                if ($firstError === null) {
                    $firstError = $this->fieldError(
                        'wpcb_field_email_invalid',
                        $key,
                        $label,
                        __('Bitte gib für „%s“ eine gültige E-Mail-Adresse ein.', 'wordpress-calendar-booking')
                    );
                }
                continue;
            }

            if ($value !== '' && in_array($definition['type'], ['text', 'email', 'textarea'], true)) {
                $length = $this->length($value);
                $min = (int)($definition['rules']['min_length'] ?? 0);
                $max = (int)($definition['rules']['max_length'] ?? $this->hardMax($definition['type']));
                if ($length < $min || $length > $max) {
                    if ($firstError === null) {
                        $firstError = $this->fieldError(
                            'wpcb_field_length_invalid',
                            $key,
                            $label,
                            sprintf(
                                __('Das Feld „%%s“ muss zwischen %d und %d Zeichen lang sein.', 'wordpress-calendar-booking'),
                                $min,
                                $max
                            )
                        );
                    }
                    continue;
                }
            }

            $values[$key] = $value;
        }

        if ($firstError !== null) {
            return new \WP_Error(
                $firstError['code'],
                $firstError['message'],
                [
                    'field_key' => $firstError['field_key'],
                    'values' => $values,
                ]
            );
        }

        return $values;
    }

    /** @return array{min_length:int,max_length:int,must_be_checked:bool,options:array}|\WP_Error */
    public function constraints(object $field) {
        $definition = $this->validateStoredDefinition(
            (string)($field->field_type ?? ''),
            $field->options_json ?? null,
            $field->validation_rules_json ?? null
        );
        if (is_wp_error($definition)) {
            return $definition;
        }
        return [
            'min_length' => (int)($definition['rules']['min_length'] ?? 0),
            'max_length' => in_array($definition['type'], ['text', 'email', 'textarea'], true)
                ? (int)($definition['rules']['max_length'] ?? $this->hardMax($definition['type']))
                : 0,
            'must_be_checked' => !empty($definition['rules']['must_be_checked']),
            'options' => $definition['options'],
        ];
    }

    public function hardMax(string $type): int {
        return match ($type) {
            'textarea' => 5000,
            'checkbox' => 1,
            default => 190,
        };
    }

    private function decodeJsonArray($value, string $kind) {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value) || strlen($value) > 20000) {
            return new \WP_Error(
                'wpcb_field_' . $kind . '_json_invalid',
                __('Die Formularfeld-Konfiguration enthält ungültige JSON-Daten.', 'wordpress-calendar-booking')
            );
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error(
                'wpcb_field_' . $kind . '_json_invalid',
                __('Die Formularfeld-Konfiguration enthält ungültige JSON-Daten.', 'wordpress-calendar-booking')
            );
        }
        return $decoded;
    }

    private function fieldError(string $code, string $key, string $label, string $template): array {
        return [
            'code' => $code,
            'message' => sprintf($template, $label),
            'field_key' => $key,
        ];
    }

    private function isList(array $value): bool {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private function isAssociativeOrEmpty(array $value): bool {
        return $value === [] || array_keys($value) !== range(0, count($value) - 1);
    }

    private function integerLike($value): bool {
        return is_int($value)
            || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
    }

    private function booleanLike($value): bool {
        return is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
    }

    private function booleanValue($value): bool {
        return $value === true || $value === 1 || $value === '1';
    }

    private function length(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
