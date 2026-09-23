<?php
/**
 * Emit one signed slot token on a fresh capacity-3 resource for a last-seat race.
 */
if (!defined('ABSPATH')) { exit(1); }

global $wpdb;
$type = (new Wpcb\Booking\BookingTypeRepository())->all(true)[0] ?? null;
if (!$type) { exit(2); }

$resources = new Wpcb\Resources\ResourceRepository();
$resourceId = $resources->save([
    'name' => 'CI Capacity Race Resource',
    'slug' => 'ci-capacity-race-' . wp_generate_password(6, false),
    'public_label' => '',
    'description' => '',
    'capacity' => 3,
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 1001,
]);
if (is_wp_error($resourceId) || (int)$resourceId < 1) { exit(3); }
$resourceId = (int)$resourceId;

$wpdb->update($wpdb->prefix . 'wpcb_booking_types', ['capacity'=>3], ['id'=>(int)$type->id]);
$resources->setForBookingType((int)$type->id, [$resourceId]);

$slots = (new Wpcb\Availability\SlotService())->getSlotsForResource((int)$type->id, $resourceId, 21, null, 2);
$slot = $slots[0] ?? null;
if (!$slot) { exit(4); }

$token = (new Wpcb\Tokens\SlotTokenService())->issue(
    (int)$type->id,
    (string)$slot['start'],
    (string)$slot['end'],
    $resourceId
);

echo (int)$type->id . '|' . $token;
