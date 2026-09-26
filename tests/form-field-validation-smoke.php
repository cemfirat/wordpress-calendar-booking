<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_field_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

function wpcb_field_row(
    string $key,
    string $type,
    bool $required = false,
    bool $active = true,
    ?array $options = null,
    ?array $rules = null
): object {
    return (object)[
        'field_key' => $key,
        'label' => ucwords(str_replace('_', ' ', $key)),
        'field_type' => $type,
        'is_required' => $required ? 1 : 0,
        'is_active' => $active ? 1 : 0,
        'options_json' => $options === null ? null : wp_json_encode($options),
        'validation_rules_json' => $rules === null ? null : wp_json_encode($rules),
    ];
}

$validator = new Wpcb\Forms\FieldSubmissionValidator();
$fields = [
    wpcb_field_row('required_text', 'text', true, true, null, ['min_length'=>1,'max_length'=>5]),
    wpcb_field_row('optional_zero', 'text', false, true, null, ['max_length'=>3]),
    wpcb_field_row('choice', 'select', true, true, ['0','one']),
    wpcb_field_row('consent', 'checkbox', false, true, null, ['must_be_checked'=>true]),
    wpcb_field_row('email', 'email', true),
    wpcb_field_row('inactive_required', 'text', true, false),
];

$validInput = [
    'required_text' => '0',
    'optional_zero' => '0',
    'choice' => '0',
    'consent' => '1',
    'email' => 'typed-fields@example.com',
];
$valid = $validator->validate($validInput, $fields);
wpcb_field_assert(
    is_array($valid)
    && ($valid['required_text'] ?? null) === '0'
    && ($valid['optional_zero'] ?? null) === '0'
    && ($valid['choice'] ?? null) === '0'
    && ($valid['consent'] ?? null) === '1'
    && !array_key_exists('inactive_required', $valid),
    'Typed validation preserves legitimate string zero values and ignores disabled fields.'
);

$shape = $validInput;
$shape['required_text'] = ['not' => 'scalar'];
$shapeResult = $validator->validate($shape, $fields);
wpcb_field_assert(
    is_wp_error($shapeResult) && $shapeResult->get_error_code() === 'wpcb_field_shape',
    'Array/object submission shapes are rejected before reservation.'
);

$stale = $validInput;
$stale['choice'] = 'removed-option';
$staleResult = $validator->validate($stale, $fields);
wpcb_field_assert(
    is_wp_error($staleResult) && $staleResult->get_error_code() === 'wpcb_field_option',
    'Select/radio values must still exist in the current configured option set.'
);

$long = $validInput;
$long['required_text'] = '123456';
$longResult = $validator->validate($long, $fields);
wpcb_field_assert(
    is_wp_error($longResult) && $longResult->get_error_code() === 'wpcb_field_too_long',
    'Configured and storage-backed maximum lengths are enforced.'
);

$shortFields = [
    wpcb_field_row('short_text', 'text', false, true, null, ['min_length'=>2,'max_length'=>5]),
];
$shortResult = $validator->validate(['short_text'=>'x'], $shortFields);
wpcb_field_assert(
    is_wp_error($shortResult) && $shortResult->get_error_code() === 'wpcb_field_too_short',
    'Supported minimum-length rules are enforced.'
);

$unchecked = $validInput;
$unchecked['consent'] = '';
$uncheckedResult = $validator->validate($unchecked, $fields);
wpcb_field_assert(
    is_wp_error($uncheckedResult) && $uncheckedResult->get_error_code() === 'wpcb_field_required',
    'Configured consent must be checked in every shared validation path.'
);

$badCheckbox = $validInput;
$badCheckbox['consent'] = '0';
$badCheckboxResult = $validator->validate($badCheckbox, $fields);
wpcb_field_assert(
    is_wp_error($badCheckboxResult) && $badCheckboxResult->get_error_code() === 'wpcb_field_checkbox',
    'Checkboxes reject values outside the explicit empty-or-one contract.'
);

$badEmail = $validInput;
$badEmail['email'] = 'not-an-email';
$badEmailResult = $validator->validate($badEmail, $fields);
wpcb_field_assert(
    is_wp_error($badEmailResult) && $badEmailResult->get_error_code() === 'wpcb_field_email',
    'Email fields use server-side email validation.'
);

$unsupportedRule = Wpcb\Forms\FieldContract::definition(
    wpcb_field_row('unsupported_rule', 'text', false, true, null, ['pattern'=>'.*'])
);
wpcb_field_assert(
    is_wp_error($unsupportedRule) && $unsupportedRule->get_error_code() === 'wpcb_field_config_invalid',
    'Unknown validation-rule keys fail closed instead of being stored ineffectively.'
);

$oversizedRule = Wpcb\Forms\FieldContract::definition(
    wpcb_field_row('oversized_rule', 'text', false, true, null, ['max_length'=>191])
);
wpcb_field_assert(
    is_wp_error($oversizedRule) && $oversizedRule->get_error_code() === 'wpcb_field_config_invalid',
    'A configured maximum cannot exceed the actual text/storage contract.'
);

$missingOptions = Wpcb\Forms\FieldContract::definition(
    wpcb_field_row('empty_choice', 'select', false, true, [])
);
wpcb_field_assert(
    is_wp_error($missingOptions) && $missingOptions->get_error_code() === 'wpcb_field_config_invalid',
    'Active choice fields without options fail closed.'
);

$recoveryTimestamp = max(1, (int)current_time('timestamp') - 30);
$recoveryHtml = (new Wpcb\Frontend\ComponentRenderer())->bookingForm([
    'wpcb_form_ts' => $recoveryTimestamp,
    'subject' => 'Preserved recovery value',
]);
wpcb_field_assert(
    str_contains($recoveryHtml, 'name="wpcb_form_ts" value="' . $recoveryTimestamp . '"')
    && str_contains($recoveryHtml, 'value="Preserved recovery value"'),
    'Form recovery preserves the already-qualified form age and valid submitted values.'
);

global $wpdb;
$table = $wpdb->prefix . 'wpcb_form_fields';
$key = 'typed_ci_' . strtolower(wp_generate_password(8, false, false));
$key = preg_replace('/[^a-z0-9_]/', '', $key);
$service = new Wpcb\Admin\ConfigurationService();
$fieldId = 0;
try {
    $saved = $service->saveField([
        'field_key' => $key,
        'label' => 'Typed CI field',
        'field_type' => 'text',
        'options_raw' => '',
        'field_rules_present' => 1,
        'min_length' => '1',
        'max_length' => '5',
        'is_active' => 1,
        'sort_order' => 999,
    ]);
    wpcb_field_assert(is_int($saved) && $saved > 0, 'Supported field rules are persisted through the configuration service.');
    $fieldId = (int)$saved;
    $before = (string)$wpdb->get_var($wpdb->prepare(
        "SELECT validation_rules_json FROM {$table} WHERE id=%d",
        $fieldId
    ));

    $rejected = $service->saveField([
        'field_key' => $key,
        'label' => 'Typed CI field',
        'field_type' => 'text',
        'options_raw' => '',
        'validation_rules_json' => wp_json_encode(['pattern'=>'.*']),
        'is_active' => 1,
        'sort_order' => 999,
    ], $fieldId);
    $after = (string)$wpdb->get_var($wpdb->prepare(
        "SELECT validation_rules_json FROM {$table} WHERE id=%d",
        $fieldId
    ));
    wpcb_field_assert(
        is_wp_error($rejected)
        && $rejected->get_error_code() === 'wpcb_field_config_invalid'
        && $after === $before,
        'Unsupported field rules are rejected without mutating the last valid configuration.'
    );
} finally {
    if ($fieldId > 0) {
        $wpdb->delete($table, ['id'=>$fieldId]);
    }
}

WP_CLI::success('Typed form-field validation smoke test passed.');
