<?php
/**
 * Emit one canonical booking type and signed slot token for the race test.
 */
if (!defined('ABSPATH')) {
    exit(1);
}

$types = (new Wpcb\Booking\BookingTypeRepository())->all(true);
$type = $types[0] ?? null;
if (!$type) {
    exit(2);
}

$slots = (new Wpcb\Availability\SlotService())->getSlots((int)$type->id, 21);
if (!$slots) {
    exit(3);
}

$slot = $slots[0];
$token = (new Wpcb\Tokens\SlotTokenService())->issue(
    (int)$type->id,
    (string)$slot['start'],
    (string)$slot['end']
);

echo (int)$type->id . '|' . $token;
