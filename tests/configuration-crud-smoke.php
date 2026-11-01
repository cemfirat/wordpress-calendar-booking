<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_crud_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$service = new Wpcb\Admin\ConfigurationService();
$now = gmdate('Y-m-d H:i:s');
$resourceId = (int)get_option('wpcb_default_resource_id', 0);
wpcb_crud_assert($resourceId > 0, 'Default resource exists for CRUD fixtures.');

$suffix = strtolower(wp_generate_password(8, false, false));
$typeId = $service->saveBookingType([
    'name' => 'CRUD Type',
    'slug' => 'crud-type-' . $suffix,
    'description' => 'Initial description',
    'channel' => 'online',
    'duration_minutes' => 30,
    'capacity' => 1,
    'payment_mode' => 'free',
    'currency' => 'EUR',
    'is_active' => 1,
    'is_public' => 1,
]);
wpcb_crud_assert($typeId > 0, 'Booking type can be created through configuration service.');

$service->saveBookingType([
    'name' => 'CRUD Type Updated',
    'slug' => 'crud-type-' . $suffix,
    'description' => 'Updated description',
    'channel' => 'phone',
    'duration_minutes' => 45,
    'capacity' => 3,
    'show_remaining_capacity' => 1,
    'payment_mode' => 'required',
    'price_minor' => 2500,
    'currency' => 'EUR',
    'is_active' => 1,
    'is_public' => 1,
], $typeId);
$type = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}wpcb_booking_types WHERE id = %d",
    $typeId
));
wpcb_crud_assert($type && $type->name === 'CRUD Type Updated' && (int)$type->duration_minutes === 45, 'Booking type can be edited.');
wpcb_crud_assert((int)$type->capacity === 3 && $type->payment_mode === 'required', 'Booking type edit persists capacity and payment fields.');
wpcb_crud_assert((string)$type->channel === 'phone', 'Booking type edit persists its explicit technical channel.');

$formatter = new Wpcb\Support\BookingFormatter();
wpcb_crud_assert(
    $formatter->location(
        ['booking_type_id' => $typeId],
        ['who_calls' => 'business_calls', 'location' => 'untrusted submitted location'],
        ['own_phone' => '+43123456789', 'visit_address' => 'Trusted street 1']
    ) === '+43123456789',
    'Phone behavior uses the stable caller value and trusted configured phone, not editable type or option labels.'
);
$mailSettingsBefore = get_option('wpcb_settings', []);
$mailTemplatesBefore = get_option('wpcb_email_templates', []);
$mailSettings = (array)$mailSettingsBefore;
$mailSettings['own_phone'] = '+43123456789';
update_option('wpcb_settings', $mailSettings, false);
update_option('wpcb_email_templates', [
    'confirmed_subject' => 'Termin bestätigt',
    'confirmed_body' => 'Ort: {ort}',
], false);
$capturedMail = [];
$captureMail = static function ($pre, array $attributes) use (&$capturedMail) {
    foreach ((array)($attributes['attachments'] ?? []) as $path) {
        $capturedMail['ics'] = is_string($path) && is_file($path) ? (string)file_get_contents($path) : '';
    }
    $capturedMail['message'] = (string)($attributes['message'] ?? '');
    return true;
};
add_filter('pre_wp_mail', $captureMail, 10, 2);
try {
    $mailerSent = (new Wpcb\Mail\Mailer())->sendTemplate(
        'confirmed',
        [
            'booking_type_id' => $typeId,
            'booking_uuid' => 'channel-crud-fixture',
            'slot_start' => '2033-01-10 09:00:00',
            'slot_end' => '2033-01-10 09:30:00',
            'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
            'email' => 'channel-fixture@example.com',
            'party_size' => 1,
        ],
        ['who_calls'=>'business_calls'],
        [],
        true
    );
    wpcb_crud_assert(
        $mailerSent
        && strpos($capturedMail['message'] ?? '', '+43123456789') !== false
        && strpos($capturedMail['ics'] ?? '', 'LOCATION:+43123456789') !== false,
        'Renamed-channel behavior keeps the trusted location consistent in notification text and ICS.'
    );
} finally {
    remove_filter('pre_wp_mail', $captureMail, 10);
    update_option('wpcb_settings', $mailSettingsBefore, false);
    update_option('wpcb_email_templates', $mailTemplatesBefore, false);
}
$bookingFormHtml = (new Wpcb\Frontend\ComponentRenderer())->bookingForm();
wpcb_crud_assert(
    strpos($bookingFormHtml, 'data-channel="phone"') !== false,
    'Frontend type choices expose the same explicit channel used by the server.'
);

$customTypeId = $service->saveBookingType([
    'name' => 'Telefonischer Sonderfall',
    'slug' => 'crud-custom-channel-' . $suffix,
    'channel' => 'custom',
    'duration_minutes' => 30,
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 1,
]);
$visitTypeId = $service->saveBookingType([
    'name' => 'Rendez-vous',
    'slug' => 'crud-visit-channel-' . $suffix,
    'channel' => 'in_person',
    'duration_minutes' => 30,
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 1,
]);
wpcb_crud_assert(
    $formatter->location(
        ['booking_type_id' => $customTypeId],
        ['location' => 'Customer-provided location'],
        ['own_phone' => '+43123456789', 'visit_address' => 'Trusted street 1']
    ) === 'Customer-provided location',
    'Custom types whose names contain channel words retain generic location behavior.'
);
wpcb_crud_assert(
    $formatter->location(
        ['booking_type_id' => $visitTypeId],
        ['location' => 'Untrusted submitted location'],
        ['own_phone' => '+43123456789', 'visit_address' => 'Trusted street 1']
    ) === 'Trusted street 1',
    'In-person behavior uses the explicit channel and trusted configured address, independent of translated names.'
);
$formSettingsBefore = get_option('wpcb_settings', []);
$formSettings = (array)$formSettingsBefore;
$formSettings['own_phone'] = '+43123456789';
$formSettings['visit_address'] = 'Trusted street 1';
update_option('wpcb_settings', $formSettings, false);
try {
    $preparedPhone = (new Wpcb\Forms\BookingFormData($formatter))->prepare($typeId, [
        'email' => 'channel-fixture@example.com',
        'who_calls' => 'business_calls',
        'location' => 'Untrusted submitted location',
    ]);
    $preparedVisit = (new Wpcb\Forms\BookingFormData($formatter))->prepare($visitTypeId, [
        'email' => 'channel-fixture@example.com',
        'location' => 'Untrusted submitted location',
        'computed_location' => 'Untrusted hidden value',
    ]);
    wpcb_crud_assert(
        !is_wp_error($preparedPhone)
        && $preparedPhone['customer']['phone'] === '+43123456789'
        && $preparedPhone['meta']['computed_location'] === '+43123456789',
        'Booking preparation derives the phone and notification location from trusted settings and canonical caller values.'
    );
    wpcb_crud_assert(
        !is_wp_error($preparedVisit)
        && $preparedVisit['meta']['computed_location'] === 'Trusted street 1',
        'Booking preparation ignores a client-supplied computed_location and stores the configured visit address.'
    );
} finally {
    update_option('wpcb_settings', $formSettingsBefore, false);
}

$legacyTypeId = (int)$wpdb->get_var(
    "SELECT id FROM {$wpdb->prefix}wpcb_booking_types WHERE slug='telefon' AND name='Telefon' ORDER BY id ASC LIMIT 1"
);
$wpdb->update($wpdb->prefix . 'wpcb_booking_types', ['channel'=>'review'], ['id'=>$legacyTypeId]);
$ambiguousLegacyTypeId = $service->saveBookingType([
    'name' => 'Telefonische Sonderberatung',
    'slug' => 'crud-ambiguous-channel-' . $suffix,
    'channel' => 'review',
    'duration_minutes' => 30,
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 1,
]);
wpcb_crud_assert(
    Wpcb\Database\BookingChannelMigration::migrate()
    && $legacyTypeId > 0
    && (string)$wpdb->get_var($wpdb->prepare("SELECT channel FROM {$wpdb->prefix}wpcb_booking_types WHERE id=%d", $legacyTypeId)) === 'phone'
    && (string)$wpdb->get_var($wpdb->prepare("SELECT channel FROM {$wpdb->prefix}wpcb_booking_types WHERE id=%d", $ambiguousLegacyTypeId)) === 'review',
    'Channel migration recognizes only the exact legacy seed and leaves ambiguous custom types for review.'
);
$wpdb->update($wpdb->prefix . 'wpcb_booking_types', ['channel'=>'review'], ['id'=>$legacyTypeId]);
$duplicateLegacyTypeId = $service->saveBookingType([
    'name' => 'Telefon',
    'slug' => 'telefon',
    'channel' => 'review',
    'duration_minutes' => 30,
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 1,
]);
Wpcb\Database\BookingChannelMigration::migrate();
wpcb_crud_assert(
    (string)$wpdb->get_var($wpdb->prepare("SELECT channel FROM {$wpdb->prefix}wpcb_booking_types WHERE id=%d", $legacyTypeId)) === 'review'
    && (string)$wpdb->get_var($wpdb->prepare("SELECT channel FROM {$wpdb->prefix}wpcb_booking_types WHERE id=%d", $duplicateLegacyTypeId)) === 'review',
    'Duplicate legacy seed identities are left for administrator review.'
);
$wpdb->update($wpdb->prefix . 'wpcb_booking_types', ['channel'=>'phone'], ['id'=>$legacyTypeId]);

$fieldId = $service->saveField([
    'field_key' => 'crud_field_' . $suffix,
    'label' => 'CRUD Field',
    'field_type' => 'select',
    'options_raw' => "Alpha\nBeta",
    'is_required' => 1,
    'is_active' => 1,
]);
$service->saveField([
    'field_key' => 'crud_field_' . $suffix,
    'label' => 'CRUD Field Updated',
    'field_type' => 'radio',
    'options_raw' => "Gamma\nDelta",
    'is_required' => 0,
    'is_active' => 1,
], $fieldId);
$field = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}wpcb_form_fields WHERE id = %d",
    $fieldId
));
wpcb_crud_assert($field && $field->label === 'CRUD Field Updated' && $field->field_type === 'radio', 'Form field can be edited.');
wpcb_crud_assert(json_decode((string)$field->options_json, true) === ['Gamma', 'Delta'], 'Form field options are normalized on update.');

$ruleId = $service->saveRule([
    'scope_type' => 'booking_type',
    'scope_id' => $typeId,
    'weekday' => 2,
    'start_time' => '10:00',
    'end_time' => '12:00',
    'slot_duration_minutes' => 30,
    'buffer_before_minutes' => 5,
    'buffer_after_minutes' => 10,
    'min_notice_minutes' => 60,
    'max_days_in_advance' => 20,
    'is_active' => 1,
]);
$service->saveRule([
    'scope_type' => 'booking_type',
    'scope_id' => $typeId,
    'weekday' => 3,
    'start_time' => '11:00',
    'end_time' => '13:00',
    'slot_duration_minutes' => 45,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 15,
    'min_notice_minutes' => 120,
    'max_days_in_advance' => 30,
    'is_active' => 1,
], $ruleId);
$rule = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}wpcb_availability_rules WHERE id = %d",
    $ruleId
));
wpcb_crud_assert($rule && (int)$rule->weekday === 3 && $rule->start_time === '11:00:00', 'Availability rule can be edited.');

$exceptionId = $service->saveException([
    'type' => 'vacation',
    'title' => 'CRUD Exception',
    'date_start' => '2031-05-10T09:00',
    'date_end' => '2031-05-10T11:00',
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'all_day' => 0,
    'is_active' => 1,
]);
$service->saveException([
    'type' => 'blocked_range',
    'title' => 'CRUD Exception Updated',
    'date_start' => '2031-05-11T09:00',
    'date_end' => '2031-05-11T12:00',
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'all_day' => 0,
    'is_active' => 1,
], $exceptionId);
$exception = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}wpcb_exceptions WHERE id = %d",
    $exceptionId
));
wpcb_crud_assert($exception && $exception->title === 'CRUD Exception Updated' && $exception->type === 'blocked_range', 'Availability exception can be edited.');

$admin = get_user_by('login', 'admin');
wpcb_crud_assert($admin !== false, 'WordPress admin fixture user exists.');
wp_set_current_user((int)$admin->ID);

$_GET = ['page' => 'wpcb_types', 'edit_type' => (string)$typeId];
ob_start();
(new Wpcb\Admin\Admin())->types();
$typeHtml = (string)ob_get_clean();
wpcb_crud_assert(strpos($typeHtml, 'Terminart bearbeiten') !== false, 'Booking type edit form is rendered from GET selection.');
wpcb_crud_assert(strpos($typeHtml, 'CRUD Type Updated') !== false, 'Booking type edit form is prefilled.');
wpcb_crud_assert(strpos($typeHtml, 'name="channel"') !== false, 'Booking type editor exposes the explicit channel selector.');
wpcb_crud_assert(strpos($typeHtml, 'delete_type') !== false, 'Booking type table exposes POST delete action.');

$_GET = ['page' => 'wpcb_fields', 'edit_field' => (string)$fieldId];
ob_start();
(new Wpcb\Admin\Admin())->fields();
$fieldHtml = (string)ob_get_clean();
wpcb_crud_assert(strpos($fieldHtml, 'Formularfeld bearbeiten') !== false, 'Form field edit form is rendered.');
wpcb_crud_assert(strpos($fieldHtml, 'CRUD Field Updated') !== false, 'Form field edit form is prefilled.');
wpcb_crud_assert(strpos($fieldHtml, 'delete_field') !== false, 'Form field table exposes POST delete action.');

$_GET = ['page' => 'wpcb_availability', 'edit_rule' => (string)$ruleId, 'edit_exception' => (string)$exceptionId];
ob_start();
(new Wpcb\Admin\Admin())->availability();
$availabilityHtml = (string)ob_get_clean();
wpcb_crud_assert(strpos($availabilityHtml, 'Regel bearbeiten') !== false, 'Availability rule edit form is rendered.');
wpcb_crud_assert(strpos($availabilityHtml, 'Ausnahme bearbeiten') !== false, 'Exception edit form is rendered.');
wpcb_crud_assert(strpos($availabilityHtml, 'delete_rule') !== false && strpos($availabilityHtml, 'delete_exception') !== false, 'Availability tables expose POST delete actions.');

wpcb_crud_assert($service->deleteField($fieldId), 'Form field can be deleted.');
wpcb_crud_assert($service->deleteRule($ruleId), 'Availability rule can be deleted.');
wpcb_crud_assert($service->deleteException($exceptionId), 'Availability exception can be deleted.');

$cleanupTypeId = $service->saveBookingType([
    'name' => 'CRUD Cleanup Type',
    'slug' => 'crud-cleanup-' . $suffix,
    'duration_minutes' => 30,
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 1,
]);
$wpdb->insert($wpdb->prefix . 'wpcb_booking_type_resources', [
    'booking_type_id' => $cleanupTypeId,
    'resource_id' => $resourceId,
    'created_at' => $now,
    'updated_at' => $now,
]);
$cleanupRuleId = $service->saveRule([
    'scope_type' => 'booking_type',
    'scope_id' => $cleanupTypeId,
    'weekday' => 1,
    'start_time' => '09:00',
    'end_time' => '10:00',
    'slot_duration_minutes' => 30,
    'max_days_in_advance' => 30,
    'is_active' => 1,
]);
$cleanupExceptionId = $service->saveException([
    'type' => 'blocked_range',
    'title' => 'Cleanup exception',
    'date_start' => '2031-06-01T09:00',
    'date_end' => '2031-06-01T10:00',
    'booking_type_id' => $cleanupTypeId,
    'resource_id' => $resourceId,
    'is_active' => 1,
]);
wpcb_crud_assert($service->deleteBookingType($cleanupTypeId) === true, 'Unused booking type can be deleted.');
wpcb_crud_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_type_resources WHERE booking_type_id = %d", $cleanupTypeId)) === 0, 'Booking-type resource mappings are removed with unused type.');
wpcb_crud_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_availability_rules WHERE id = %d", $cleanupRuleId)) === 0, 'Booking-type availability rules are removed with unused type.');
wpcb_crud_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_exceptions WHERE id = %d", $cleanupExceptionId)) === 0, 'Booking-type exceptions are removed with unused type.');

$historyTypeId = $service->saveBookingType([
    'name' => 'CRUD History Type',
    'slug' => 'crud-history-' . $suffix,
    'duration_minutes' => 30,
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 1,
]);
$wpdb->insert($wpdb->prefix . 'wpcb_bookings', [
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $historyTypeId,
    'resource_id' => $resourceId,
    'slot_start' => '2031-07-01 09:00:00',
    'slot_end' => '2031-07-01 09:30:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'email' => 'history@example.com',
    'source' => 'test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
$historyBookingId = (int)$wpdb->insert_id;
$guard = $service->deleteBookingType($historyTypeId);
wpcb_crud_assert(is_wp_error($guard) && $guard->get_error_code() === 'booking_type_in_use', 'Historical booking prevents hard deletion of booking type.');
wpcb_crud_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_types WHERE id = %d", $historyTypeId)) === 1, 'Guarded booking type remains stored.');

$seriesTypeId = $service->saveBookingType([
    'name' => 'CRUD Series Type',
    'slug' => 'crud-series-' . $suffix,
    'duration_minutes' => 30,
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 1,
]);
$wpdb->insert($wpdb->prefix . 'wpcb_booking_series', [
    'series_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $seriesTypeId,
    'resource_id' => $resourceId,
    'frequency' => 'weekly',
    'interval_count' => 1,
    'occurrence_count' => 2,
    'timezone' => 'Europe/Vienna',
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);
$seriesId = (int)$wpdb->insert_id;
wpcb_crud_assert(is_wp_error($service->deleteBookingType($seriesTypeId)), 'Booking series prevents hard deletion of booking type.');

$waitingTypeId = $service->saveBookingType([
    'name' => 'CRUD Waiting Type',
    'slug' => 'crud-waiting-' . $suffix,
    'duration_minutes' => 30,
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 1,
]);
$wpdb->insert($wpdb->prefix . 'wpcb_waiting_list', [
    'entry_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $waitingTypeId,
    'resource_id' => $resourceId,
    'slot_start' => '2031-08-01 09:00:00',
    'slot_end' => '2031-08-01 09:30:00',
    'party_size' => 1,
    'full_name' => 'Waiting Fixture',
    'email' => 'waiting@example.com',
    'status' => 'waiting',
    'created_at' => $now,
    'updated_at' => $now,
]);
$waitingId = (int)$wpdb->insert_id;
wpcb_crud_assert(is_wp_error($service->deleteBookingType($waitingTypeId)), 'Waiting-list entry prevents hard deletion of booking type.');

$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $historyBookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_series', ['id' => $seriesId]);
$wpdb->delete($wpdb->prefix . 'wpcb_waiting_list', ['id' => $waitingId]);
$service->deleteBookingType($historyTypeId);
$service->deleteBookingType($seriesTypeId);
$service->deleteBookingType($waitingTypeId);
$service->deleteBookingType($customTypeId);
$service->deleteBookingType($visitTypeId);
$service->deleteBookingType($duplicateLegacyTypeId);
$service->deleteBookingType($ambiguousLegacyTypeId);
$service->deleteBookingType($typeId);
unset($_GET['edit_type'], $_GET['edit_field'], $_GET['edit_rule'], $_GET['edit_exception']);

WP_CLI::success('Configuration CRUD smoke test passed.');
