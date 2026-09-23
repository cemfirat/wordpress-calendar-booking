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
$service->deleteBookingType($typeId);
unset($_GET['edit_type'], $_GET['edit_field'], $_GET['edit_rule'], $_GET['edit_exception']);

WP_CLI::success('Configuration CRUD smoke test passed.');
