<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_resource_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
Wpcb\Resources\ResourceMigration::maybeRun();

$resources = new Wpcb\Resources\ResourceRepository();
$types = new Wpcb\Booking\BookingTypeRepository();
$type = $types->all(true)[0] ?? null;
wpcb_resource_assert($type !== null, 'Public booking type fixture exists.');

$defaultId = $resources->ensureDefault();
wpcb_resource_assert($defaultId > 0, 'Default resource exists.');
wpcb_resource_assert(
    $resources->isAssignedToBookingType($defaultId, (int)$type->id),
    'Default resource is assigned to existing booking types.'
);

$secondId = $resources->save([
    'name' => 'Private Specialist Internal',
    'slug' => 'resource-smoke-private',
    'public_label' => '',
    'description' => 'Internal-only resource fixture',
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 20,
]);
wpcb_resource_assert(is_int($secondId) && $secondId > 0, 'Second resource is created.');

$resources->setForBookingType((int)$type->id, [$defaultId, $secondId]);
$assigned = $resources->forBookingType((int)$type->id);
wpcb_resource_assert(count($assigned) === 2, 'Booking type can use multiple resources.');

$slots = new Wpcb\Availability\SlotService();
$defaultSlots = $slots->getSlotsForResource((int)$type->id, $defaultId, 21);
$secondSlots = $slots->getSlotsForResource((int)$type->id, $secondId, 21);
wpcb_resource_assert($defaultSlots !== [] && $secondSlots !== [], 'Both resources generate canonical slots.');

$secondByStart = [];
foreach ($secondSlots as $slot) {
    $secondByStart[(string)$slot['start']] = $slot;
}
$overlap = null;
foreach ($defaultSlots as $slot) {
    if (isset($secondByStart[(string)$slot['start']])
        && (string)$secondByStart[(string)$slot['start']]['end'] === (string)$slot['end']
    ) {
        $overlap = $slot;
        break;
    }
}
wpcb_resource_assert(is_array($overlap), 'Independent resources expose at least one simultaneous slot.');

$publicSlots = $slots->getSlots((int)$type->id, 21);
$publicLabels = implode(' ', array_map(static fn(array $slot): string => (string)$slot['label'], $publicSlots));
wpcb_resource_assert(
    strpos($publicLabels, 'Private Specialist Internal') === false,
    'Private internal resource name is not exposed in public slot labels.'
);

$repo = new Wpcb\Booking\BookingRepository();
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$bookingId = $repo->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => (int)$type->id,
    'resource_id' => $defaultId,
    'slot_start' => (string)$overlap['start'],
    'slot_end' => (string)$overlap['end'],
    'status' => Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED,
    'full_name' => 'Resource Smoke',
    'email' => 'resource-smoke@example.com',
    'phone' => '',
    'notes' => '',
    'source' => 'test',
    'lang' => 'en',
    'reserved_until' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+30 minutes')),
    'created_at' => $now,
    'updated_at' => $now,
]);
wpcb_resource_assert($bookingId > 0, 'Resource-scoped booking fixture is created.');
wpcb_resource_assert(
    $repo->hasConflict((string)$overlap['start'], (string)$overlap['end'], null, $defaultId),
    'Same resource is blocked by an overlapping reservation.'
);
wpcb_resource_assert(
    !$repo->hasConflict((string)$overlap['start'], (string)$overlap['end'], null, $secondId),
    'Independent resource remains available at the same time.'
);

$tokenService = new Wpcb\Tokens\SlotTokenService();
$token = $tokenService->issue(
    (int)$type->id,
    (string)$secondByStart[(string)$overlap['start']]['start'],
    (string)$secondByStart[(string)$overlap['start']]['end'],
    $secondId
);
$payload = $tokenService->verify($token);
wpcb_resource_assert(
    is_array($payload) && (int)$payload['resource_id'] === $secondId,
    'Signed slot token binds the selected resource.'
);

$availability = new Wpcb\Availability\AvailabilityRepository();
$ruleId = 0;
$wpdb->insert($wpdb->prefix . 'wpcb_availability_rules', [
    'scope_type' => 'resource',
    'scope_id' => $secondId,
    'weekday' => 7,
    'start_time' => '06:00:00',
    'end_time' => '07:00:00',
    'slot_duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'min_notice_minutes' => 0,
    'max_days_in_advance' => 30,
    'is_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);
$ruleId = (int)$wpdb->insert_id;
$resourceRules = $availability->rulesForTypeAndResource((int)$type->id, $secondId);
wpcb_resource_assert(
    $resourceRules !== [] && (int)$resourceRules[0]->scope_id === $secondId,
    'Resource-specific availability overrides broader rules.'
);

$exceptionId = 0;
$wpdb->insert($wpdb->prefix . 'wpcb_exceptions', [
    'type' => 'blocked_range',
    'title' => 'Resource-only smoke exception',
    'date_start' => (string)$overlap['start'],
    'date_end' => (string)$overlap['end'],
    'all_day' => 0,
    'booking_type_id' => (int)$type->id,
    'resource_id' => $secondId,
    'is_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);
$exceptionId = (int)$wpdb->insert_id;
$defaultExceptions = $availability->exceptions(
    (string)$overlap['start'],
    (string)$overlap['end'],
    (int)$type->id,
    $defaultId
);
$secondExceptions = $availability->exceptions(
    (string)$overlap['start'],
    (string)$overlap['end'],
    (int)$type->id,
    $secondId
);
wpcb_resource_assert(
    !in_array($exceptionId, array_map(static fn(object $row): int => (int)$row->id, $defaultExceptions), true),
    'Resource-specific exception does not block another resource.'
);
wpcb_resource_assert(
    in_array($exceptionId, array_map(static fn(object $row): int => (int)$row->id, $secondExceptions), true),
    'Resource-specific exception applies to its resource.'
);

$connections = new Wpcb\Calendar\CalendarConnectionRepository();
$connectionId = $connections->create([
    'provider' => 'ics',
    'name' => 'Resource Smoke Calendar',
    'remote_calendar_id' => 'resource-smoke',
    'blocks_availability' => 1,
    'receives_bookings' => 0,
    'is_active' => 1,
], []);
wpcb_resource_assert(is_int($connectionId) && $connectionId > 0, 'Calendar connection fixture is created.');
$connections->setForResource($secondId, [[
    'connection_id' => $connectionId,
    'blocks_availability' => 1,
    'receives_bookings' => 0,
]]);
$resourceConnections = $connections->blockingForResource($secondId);
wpcb_resource_assert(
    count($resourceConnections) === 1 && (int)$resourceConnections[0]->id === $connectionId,
    'Calendar connections can be routed directly to a resource.'
);

$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_availability_rules', ['id' => $ruleId]);
$wpdb->delete($wpdb->prefix . 'wpcb_exceptions', ['id' => $exceptionId]);
$connections->delete($connectionId);
$resources->setForBookingType((int)$type->id, [$defaultId]);
$deleted = $resources->delete($secondId);
wpcb_resource_assert($deleted === true, 'Unused test resource can be deleted.');

WP_CLI::success('Resource scheduling smoke test passed.');
