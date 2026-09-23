<?php
/**
 * Emit one signed slot token with capacity 3 for a last-seat race.
 */
if (!defined('ABSPATH')) { exit(1); }

global $wpdb;
$type = (new Wpcb\Booking\BookingTypeRepository())->all(true)[0] ?? null;
if (!$type) { exit(2); }

$resources = new Wpcb\Resources\ResourceRepository();
$resource = $resources->forBookingType((int)$type->id, true)[0] ?? null;
if (!$resource) { exit(3); }

$wpdb->update($wpdb->prefix . 'wpcb_booking_types', ['capacity'=>3], ['id'=>(int)$type->id]);
$wpdb->update($wpdb->prefix . 'wpcb_resources', ['capacity'=>3], ['id'=>(int)$resource->id]);

$slots = (new Wpcb\Availability\SlotService())->getSlotsForResource((int)$type->id, (int)$resource->id, 21);
$slot = $slots[0] ?? null;
if (!$slot) { exit(4); }

$token = (new Wpcb\Tokens\SlotTokenService())->issue(
    (int)$type->id,
    (string)$slot['start'],
    (string)$slot['end'],
    (int)$resource->id
);

echo (int)$type->id . '|' . $token;
