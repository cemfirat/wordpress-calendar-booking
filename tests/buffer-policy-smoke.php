<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_buffer_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

function wpcb_buffer_error_is($value, string $code): bool {
    return is_wp_error($value) && $value->get_error_code() === $code;
}

global $wpdb;
$prefix = $wpdb->prefix . 'wpcb_';
$savedSettings = Wpcb\Admin\Settings::get();
$resourceIds = [];
$typeIds = [];
$bookingIds = [];
$ruleIds = [];
$exceptionIds = [];

try {
    $settings = $savedSettings;
    $settings['timezone'] = 'UTC';
    $settings['calendar_urls'] = '';
    $settings['calendar_url'] = '';
    update_option('wpcb_settings', $settings);

    $now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
    $resources = new Wpcb\Resources\ResourceRepository();
    $repo = new Wpcb\Booking\BookingRepository();

    $resourceId = $resources->save([
        'name' => 'Buffer policy resource',
        'slug' => 'buffer-policy-' . wp_generate_password(8, false),
        'capacity' => 1,
        'is_active' => 1,
        'is_public' => 0,
    ]);
    wpcb_buffer_assert(is_int($resourceId) && $resourceId > 0, 'Dedicated buffer-policy resource is created.');
    $resourceIds[] = $resourceId;

    $createType = static function (string $name, int $before, int $after, int $capacity = 1) use ($wpdb, $prefix, $now, &$typeIds): int {
        $wpdb->insert($prefix . 'booking_types', [
            'name' => $name,
            'slug' => sanitize_title($name . '-' . wp_generate_password(8, false)),
            'description' => '',
            'duration_minutes' => 30,
            'buffer_before_minutes' => $before,
            'buffer_after_minutes' => $after,
            'capacity' => $capacity,
            'show_remaining_capacity' => 1,
            'payment_mode' => 'free',
            'price_minor' => 0,
            'currency' => 'EUR',
            'is_active' => 1,
            'is_public' => 1,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int)$wpdb->insert_id;
        if ($id < 1) {
            throw new RuntimeException('Cannot create buffer-policy booking type.');
        }
        $typeIds[] = $id;
        return $id;
    };

    $insertRule = static function (int $typeId, int $weekday, int $before, int $after) use ($wpdb, $prefix, $now, &$ruleIds): int {
        $wpdb->insert($prefix . 'availability_rules', [
            'scope_type' => 'booking_type',
            'scope_id' => $typeId,
            'weekday' => $weekday,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'slot_duration_minutes' => 30,
            'buffer_before_minutes' => $before,
            'buffer_after_minutes' => $after,
            'min_notice_minutes' => 0,
            'max_days_in_advance' => 120,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int)$wpdb->insert_id;
        if ($id < 1) {
            throw new RuntimeException('Cannot create buffer-policy rule.');
        }
        $ruleIds[] = $id;
        return $id;
    };

    $existingTypeId = $createType('Buffer existing', 0, 0);
    $candidateTypeId = $createType('Buffer candidate', 0, 0);
    $resources->setForBookingType($existingTypeId, [$resourceId]);
    $resources->setForBookingType($candidateTypeId, [$resourceId]);

    $localDay = Wpcb\Support\Time::nowLocal()->modify('next monday')->modify('+14 days')->setTime(0, 0, 0);
    $weekday = (int)$localDay->format('N');
    $existingRuleId = $insertRule($existingTypeId, $weekday, 10, 15);
    $candidateRuleId = $insertRule($candidateTypeId, $weekday, 0, 0);

    $utc = static function (\DateTimeImmutable $day, string $time): string {
        $local = Wpcb\Support\Time::parseLocal($day->format('Y-m-d') . ' ' . $time);
        if (!$local) {
            throw new RuntimeException('Fixture local time could not be parsed.');
        }
        return Wpcb\Support\Time::formatUtc($local);
    };

    $start0830 = $utc($localDay, '08:30:00');
    $end0850 = $utc($localDay, '08:50:00');
    $start0840 = $utc($localDay, '08:40:00');
    $end0900 = $utc($localDay, '09:00:00');
    $start0900 = $end0900;
    $end0930 = $utc($localDay, '09:30:00');
    $start0930 = $end0930;
    $end1000 = $utc($localDay, '10:00:00');
    $start0945 = $utc($localDay, '09:45:00');
    $end1015 = $utc($localDay, '10:15:00');

    $bookingId = $repo->create([
        'booking_uuid' => wp_generate_uuid4(),
        'booking_type_id' => $existingTypeId,
        'resource_id' => $resourceId,
        'slot_start' => $start0900,
        'slot_end' => $end0930,
        'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
        'party_size' => 1,
        'full_name' => 'Buffer fixture',
        'email' => 'buffer-fixture@example.test',
        'source' => 'ci-buffer-policy',
        'lang' => 'en',
        'reserved_until' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], [], false);
    wpcb_buffer_assert($bookingId > 0, 'Existing booking fixture is stored.');
    $bookingIds[] = $bookingId;

    $capacity = new Wpcb\Booking\CapacityService();
    $policy = new Wpcb\Availability\BufferPolicy();
    $inherited = $policy->forSlot($existingTypeId, $resourceId, $start0900, $end0930);
    wpcb_buffer_assert(
        $inherited['before'] === 10 && $inherited['after'] === 15,
        'Zero type buffers inherit the matching availability rule.'
    );
    wpcb_buffer_assert(
        !$capacity->canFit($candidateTypeId, $resourceId, $start0840, $end0900, 1),
        'Existing before-buffer blocks a candidate ending at the appointment start.'
    );
    wpcb_buffer_assert(
        $capacity->canFit($candidateTypeId, $resourceId, $start0830, $end0850, 1),
        'Half-open boundary allows a candidate ending exactly when the existing before-buffer starts.'
    );
    wpcb_buffer_assert(
        !$capacity->canFit($candidateTypeId, $resourceId, $start0930, $end1000, 1),
        'Existing after-buffer blocks an adjacent candidate on the same resource.'
    );
    wpcb_buffer_assert(
        $capacity->canFit($candidateTypeId, $resourceId, $start0945, $end1015, 1),
        'Half-open boundary allows a slot beginning exactly when the existing after-buffer ends.'
    );

    $wpdb->update(
        $prefix . 'availability_rules',
        ['buffer_after_minutes' => 0, 'updated_at' => $now],
        ['id' => $existingRuleId]
    );
    wpcb_buffer_assert(
        (new Wpcb\Booking\CapacityService())->canFit($candidateTypeId, $resourceId, $start0930, $end1000, 1),
        'Current rule-buffer edits affect subsequent availability checks.'
    );

    $wpdb->update(
        $prefix . 'booking_types',
        ['buffer_after_minutes' => 20, 'updated_at' => $now],
        ['id' => $existingTypeId]
    );
    $capacityAfterTypeEdit = new Wpcb\Booking\CapacityService();
    wpcb_buffer_assert(
        !$capacityAfterTypeEdit->canFit($candidateTypeId, $resourceId, $start0930, $end1000, 1),
        'Positive booking-type buffer overrides an inherited rule value.'
    );
    wpcb_buffer_assert(
        $capacityAfterTypeEdit->canFit(
            $candidateTypeId,
            $resourceId,
            $utc($localDay, '09:50:00'),
            $utc($localDay, '10:20:00'),
            1
        ),
        'Exact buffer end remains a non-overlapping half-open boundary.'
    );

    foreach (['booking_status_log', 'booking_meta'] as $suffix) {
        $wpdb->delete($prefix . $suffix, ['booking_id' => $bookingId]);
    }
    $wpdb->delete($prefix . 'bookings', ['id' => $bookingId]);
    $bookingIds = array_values(array_diff($bookingIds, [$bookingId]));
    $wpdb->update(
        $prefix . 'booking_types',
        ['buffer_after_minutes' => 0, 'updated_at' => $now],
        ['id' => $existingTypeId]
    );

    $wpdb->insert($prefix . 'exceptions', [
        'type' => 'blocked_range',
        'title' => 'Buffer policy confirmation exception',
        'date_start' => $start0930,
        'date_end' => $end1000,
        'all_day' => 0,
        'booking_type_id' => $candidateTypeId,
        'resource_id' => $resourceId,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $exceptionId = (int)$wpdb->insert_id;
    $exceptionIds[] = $exceptionId;

    wpcb_buffer_assert(
        !(new Wpcb\Availability\SlotService())->slotAvailable(
            $candidateTypeId,
            $start0930,
            $end1000,
            null,
            $resourceId,
            1
        ),
        'Final availability rejects a current exception.'
    );

    $transitionBookingId = $repo->create([
        'booking_uuid' => wp_generate_uuid4(),
        'booking_type_id' => $candidateTypeId,
        'resource_id' => $resourceId,
        'slot_start' => $start0930,
        'slot_end' => $end1000,
        'status' => Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED,
        'party_size' => 1,
        'full_name' => 'Transition buffer fixture',
        'email' => 'transition-buffer@example.test',
        'source' => 'ci-buffer-policy',
        'lang' => 'en',
        'reserved_until' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+30 minutes')),
        'created_at' => $now,
        'updated_at' => $now,
    ], [], false);
    $bookingIds[] = $transitionBookingId;

    $transition = (new Wpcb\Booking\BookingTransitionService())->apply(
        $transitionBookingId,
        Wpcb\Booking\BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
        'ci-buffer-policy'
    );
    wpcb_buffer_assert(
        wpcb_buffer_error_is($transition, 'wpcb_slot_unavailable'),
        'Confirmation revalidates the current exception under the resource lock.'
    );

    $wpdb->delete($prefix . 'exceptions', ['id' => $exceptionId]);
    $exceptionIds = [];
    $transition = (new Wpcb\Booking\BookingTransitionService())->apply(
        $transitionBookingId,
        Wpcb\Booking\BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
        'ci-buffer-policy'
    );
    wpcb_buffer_assert(
        is_array($transition)
            && $repo->find($transitionBookingId)->status === Wpcb\Booking\BookingStatus::CONFIRMED,
        'Confirmation succeeds once current schedule and exception policy permit the slot.'
    );

    $otherWeekday = (int)(($weekday % 7) + 1);
    $wpdb->update(
        $prefix . 'availability_rules',
        ['weekday' => $otherWeekday, 'updated_at' => $now],
        ['id' => $candidateRuleId]
    );
    wpcb_buffer_assert(
        !(new Wpcb\Availability\SlotService())->slotAvailable(
            $candidateTypeId,
            $utc($localDay, '10:30:00'),
            $utc($localDay, '11:00:00'),
            null,
            $resourceId,
            1
        ),
        'Final availability rejects a slot no longer covered by its effective scoped weekly rules.'
    );
    $wpdb->update(
        $prefix . 'availability_rules',
        ['weekday' => $weekday, 'updated_at' => $now],
        ['id' => $candidateRuleId]
    );

    $reschedule = (new Wpcb\Booking\BookingTransitionService())->reschedule(
        $transitionBookingId,
        $utc($localDay, '10:15:00'),
        $utc($localDay, '10:45:00'),
        'ci-buffer-policy',
        'Non-canonical grid regression',
        $resourceId
    );
    wpcb_buffer_assert(
        wpcb_buffer_error_is($reschedule, 'wpcb_slot_unavailable'),
        'Rescheduling requires a current canonical rule-aligned slot.'
    );

    $groupResourceId = $resources->save([
        'name' => 'Buffer group resource',
        'slug' => 'buffer-group-' . wp_generate_password(8, false),
        'capacity' => 3,
        'is_active' => 1,
        'is_public' => 0,
    ]);
    wpcb_buffer_assert(is_int($groupResourceId) && $groupResourceId > 0, 'Group-capacity resource is created.');
    $resourceIds[] = $groupResourceId;

    $groupTypeId = $createType('Buffer group type', 0, 15, 3);
    $resources->setForBookingType($groupTypeId, [$groupResourceId]);
    $insertRule($groupTypeId, $weekday, 0, 0);
    $groupBookingId = $repo->create([
        'booking_uuid' => wp_generate_uuid4(),
        'booking_type_id' => $groupTypeId,
        'resource_id' => $groupResourceId,
        'slot_start' => $start0900,
        'slot_end' => $end0930,
        'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
        'party_size' => 2,
        'full_name' => 'Buffer group fixture',
        'email' => 'buffer-group@example.test',
        'source' => 'ci-buffer-policy',
        'lang' => 'en',
        'created_at' => $now,
        'updated_at' => $now,
    ], [], false);
    $bookingIds[] = $groupBookingId;
    $groupCapacity = new Wpcb\Booking\CapacityService();
    wpcb_buffer_assert(
        !$groupCapacity->canFit($groupTypeId, $groupResourceId, $start0930, $end1000, 2),
        'Buffered group occupancy subtracts every occupied seat.'
    );
    wpcb_buffer_assert(
        $groupCapacity->canFit($groupTypeId, $groupResourceId, $start0930, $end1000, 1),
        'Buffered group occupancy still permits the exact remaining seat.'
    );

    WP_CLI::success('Scheduling buffer and final availability policy smoke test passed.');
} finally {
    foreach ($bookingIds as $id) {
        foreach (['booking_status_log', 'booking_meta', 'tokens'] as $suffix) {
            $wpdb->delete($prefix . $suffix, ['booking_id' => $id]);
        }
        $wpdb->delete($prefix . 'bookings', ['id' => $id]);
    }
    foreach ($exceptionIds as $id) {
        $wpdb->delete($prefix . 'exceptions', ['id' => $id]);
    }
    foreach ($ruleIds as $id) {
        $wpdb->delete($prefix . 'availability_rules', ['id' => $id]);
    }
    foreach ($typeIds as $id) {
        $wpdb->delete($prefix . 'booking_type_resources', ['booking_type_id' => $id]);
        $wpdb->delete($prefix . 'booking_types', ['id' => $id]);
    }
    foreach ($resourceIds as $id) {
        $wpdb->delete($prefix . 'resource_calendar_connections', ['resource_id' => $id]);
        $wpdb->delete($prefix . 'resources', ['id' => $id]);
    }
    update_option('wpcb_settings', $savedSettings);
}
