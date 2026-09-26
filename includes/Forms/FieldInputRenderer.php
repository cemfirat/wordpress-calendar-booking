<?php
namespace Wpcb\Forms;

final class FieldInputRenderer {
    public function render(object $field, string $id, string $value = ''): string {
        $definition = FieldContract::definition($field);
        if (is_wp_error($definition)) {
            return '<p class="wpcb-field-error" role="alert">'
                . esc_html($definition->get_error_message()) . '</p>';
        }

        $name = (string)$definition['field_key'];
        $type = (string)$definition['field_type'];
        $required = !empty($definition['is_required']) || !empty($definition['rules']['must_be_checked']);
        $requiredAttr = $required ? ' required' : '';
        $lengthAttrs = '';
        if (in_array($type, ['text', 'email', 'textarea'], true)) {
            $lengthAttrs .= ' maxlength="' . (int)$definition['max_length'] . '"';
            if ((int)$definition['min_length'] > 0) {
                $lengthAttrs .= ' minlength="' . (int)$definition['min_length'] . '"';
            }
        }

        if ($type === 'textarea') {
            return '<textarea class="uk-textarea" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"'
                . $requiredAttr . $lengthAttrs . '>' . esc_textarea($value) . '</textarea>';
        }
        if ($type === 'checkbox') {
            return '<label><input class="uk-checkbox" type="checkbox" id="' . esc_attr($id) . '" name="'
                . esc_attr($name) . '" value="1"' . checked($value, '1', false) . $requiredAttr . '> </label>';
        }
        if ($type === 'email') {
            return '<input class="uk-input" type="email" id="' . esc_attr($id) . '" name="' . esc_attr($name)
                . '" value="' . esc_attr($value) . '"' . $requiredAttr . $lengthAttrs . '>';
        }
        if ($type === 'select') {
            $html = '<select class="uk-select" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $requiredAttr . '>'
                . '<option value="">' . esc_html__('Bitte wählen', 'wordpress-calendar-booking') . '</option>';
            foreach ($definition['options'] as $option) {
                $html .= '<option value="' . esc_attr($option) . '"' . selected($value, $option, false) . '>'
                    . esc_html($option) . '</option>';
            }
            return $html . '</select>';
        }
        if ($type === 'radio') {
            $html = '';
            foreach ($definition['options'] as $index => $option) {
                $html .= '<label class="uk-margin-small-right"><input class="uk-radio" type="radio" id="'
                    . esc_attr($id . '_' . $index) . '" name="' . esc_attr($name) . '" value="' . esc_attr($option) . '"'
                    . checked($value, $option, false) . $requiredAttr . '> ' . esc_html($option) . '</label>';
            }
            return $html;
        }
        return '<input class="uk-input" type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name)
            . '" value="' . esc_attr($value) . '"' . $requiredAttr . $lengthAttrs . '>';
    }
}
