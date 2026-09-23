<?php
/**
 * Emit two signed tokens for the same time on two independent resources.
 */
if (!defined('ABSPATH')) {
    exit(1);
}

$types = (new Wpcb\Booking\BookingTypeRepository())->all(true);
$type = $types[0] ?? null;
if (!$type) {
    exit(2);
}

$resources = new Wpcb\Resources\ResourceRepository();
$current = $resources->forBookingType((int)$type->id, true);
$first = $current[0] ?? null;
if (!$first) {
    exit(3);
}

$secondId = $resources->save([
    'name' => 'CI Parallel Resource',
    'slug' => 'ci-parallel-resource-' . wp_generate_password(6, false),
    'public_label' => '',
    'description' => '',
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 999,
]);
if (is_wp_error($secondId) || $secondId < 1) {
    exit(4);
}

$resources->setForBookingType((int)$type->id, [(int)$first->id, (int)$secondId]);
$slots = new Wpcb\Availability\SlotService();
$firstSlots = $slots->getSlotsForResource((int)$type->id, (int)$first->id, 21);
$secondSlots = $slots->getSlotsForResource((int)$type->id, (int)$secondId, 21);

$secondByStart = [];
foreach ($secondSlots as $slot) {
    $secondByStart[(string)$slot['start']] = $slot;
}

$pair = null;
foreach ($firstSlots as $slot) {
    $other = $secondByStart[(string)$slot['start']] ?? null;
    if ($other && (string)$other['end'] === (string)$slot['end']) {
        $pair = [$slot, $other];
        break;
    }
}
if (!$pair) {
    exit(5);
}

$tokens = new Wpcb\Tokens\SlotTokenService();
$tokenA = $tokens->issue(
    (int)$type->id,
    (string)$pair[0]['start'],
    (string)$pair[0]['end'],
    (int)$first->id
);
$tokenB = $tokens->issue(
    (int)$type->id,
    (string)$pair[1]['start'],
    (string)$pair[1]['end'],
    (int)$secondId
);

echo (int)$type->id . '|' . $tokenA . '|' . $tokenB;
