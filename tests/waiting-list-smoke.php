<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_waitlist_assert($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeTable = $wpdb->prefix . 'wpcb_booking_types';
$wpdb->insert($typeTable, [
    'name' => 'Waitlist Fixture',
    'slug' => 'waitlist-fixture-' . wp_generate_password(6, false),
    'description' => '',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'capacity' => 1,
    'show_remaining_capacity' => 0,
    'payment_mode' => 'free',
    'price_minor' => 0,
    'currency' => 'EUR',
    'waiting_list_enabled' => 1,
    'waiting_list_offer_minutes' => 30,
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 99,
    'created_at' => $now,
    'updated_at' => $now,
]);
$typeId = (int)$wpdb->insert_id;

$resources = new Wpcb\Resources\ResourceRepository();
$resourceId = $resources->save([
    'name' => 'Waitlist Room',
    'slug' => 'waitlist-room-' . wp_generate_password(6, false),
    'public_label' => 'Room',
    'description' => '',
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 1,
    'sort_order' => 99,
]);
wpcb_waitlist_assert($typeId > 0 && !is_wp_error($resourceId), 'Waiting-list booking type and resource are created.');
$resourceId = (int)$resourceId;
$resources->setForBookingType($typeId, [$resourceId]);

$start = '2035-04-10 10:00:00';
$end = '2035-04-10 10:30:00';
$bookings = new Wpcb\Booking\BookingRepository();
$occupantId = $bookings->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => $start,
    'slot_end' => $end,
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'full_name' => 'Occupant',
    'email' => 'occupant@example.com',
    'source' => 'waitlist-smoke',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
wpcb_waitlist_assert($occupantId > 0, 'Full-capacity booking fixture is created.');

$service = new Wpcb\WaitingList\WaitingListService();
$repo = new Wpcb\WaitingList\WaitingListRepository();
$firstId = $service->join($typeId, $resourceId, $start, $end, 1, 'first@example.com', home_url('/waitlist'));
$secondId = $service->join($typeId, $resourceId, $start, $end, 1, 'second@example.com', home_url('/waitlist'));
$duplicateId = $service->join($typeId, $resourceId, $start, $end, 1, 'first@example.com', home_url('/waitlist'));
wpcb_waitlist_assert(is_int($firstId) && is_int($secondId) && $firstId !== $secondId, 'Two customers can join the full slot waiting list.');
wpcb_waitlist_assert($duplicateId === $firstId, 'Duplicate waiting-list joins are idempotent.');

$cancelled = (new Wpcb\Booking\BookingTransitionService())->apply(
    $occupantId,
    Wpcb\Booking\BookingStateMachine::USER_CANCELLED,
    'test',
    'Release slot for waitlist'
);
wpcb_waitlist_assert(is_array($cancelled) && !empty($cancelled['changed']), 'Cancellation releases capacity and triggers promotion.');

$first = $repo->find($firstId);
$second = $repo->find($secondId);
wpcb_waitlist_assert($first && $first->status === 'offered', 'Oldest waiting entry is promoted first.');
wpcb_waitlist_assert($second && $second->status === 'waiting', 'Only one customer receives the single released seat.');
wpcb_waitlist_assert($repo->activeHeldSeats($typeId, $resourceId, $start, $end) === 1, 'Active promotion hold consumes the released capacity.');

$again = $service->promoteForSlot($typeId, $resourceId, $start, $end);
wpcb_waitlist_assert($again === 0, 'Repeated promotion cannot create a second hold for the same seat.');

$jobs = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}wpcb_sync_jobs WHERE job_type = 'waiting_list_offer' AND payload_json LIKE %s ORDER BY id ASC",
    '%"entry_id":' . $firstId . '%'
));
wpcb_waitlist_assert(count($jobs) === 1, 'Promotion notification is queued exactly once with an idempotency key.');
$payload = json_decode((string)$jobs[0]->payload_json, true);
$offerToken = (string)($payload['token'] ?? '');
wpcb_waitlist_assert($offerToken !== '', 'Queued promotion contains the one-time offer token.');

$acceptedBookingId = $service->accept($offerToken);
wpcb_waitlist_assert(is_int($acceptedBookingId) && $acceptedBookingId > 0, 'Valid offer token atomically reserves the promoted slot.');
wpcb_waitlist_assert((string)$repo->find($firstId)->status === 'accepted', 'Accepted waiting-list entry reaches accepted state.');
wpcb_waitlist_assert(
    (string)$bookings->find($acceptedBookingId)->status === Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED,
    'Accepted offer enters the normal Double Opt-In booking lifecycle.'
);
$replay = $service->accept($offerToken);
wpcb_waitlist_assert(is_wp_error($replay), 'Accepted offer token cannot be replayed.');

$cancelAccepted = (new Wpcb\Booking\BookingTransitionService())->apply(
    $acceptedBookingId,
    Wpcb\Booking\BookingStateMachine::RESERVATION_EXPIRED,
    'test',
    'Release accepted waitlist hold'
);
wpcb_waitlist_assert(is_array($cancelAccepted) && !empty($cancelAccepted['changed']), 'Expired accepted reservation releases the seat again.');

$second = $repo->find($secondId);
wpcb_waitlist_assert($second && $second->status === 'offered', 'Next waiting entry is promoted after capacity is released.');
$oldSelector = (string)$second->offer_selector;
$wpdb->update($wpdb->prefix . 'wpcb_waiting_list', [
    'offer_expires_at' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-1 minute')),
], ['id' => $secondId]);
$service->maintenance();
$secondAfter = $repo->find($secondId);
wpcb_waitlist_assert(
    $secondAfter && $secondAfter->status === 'offered' && (string)$secondAfter->offer_selector !== $oldSelector,
    'Expired offer returns to the queue and receives a fresh promotion token.'
);

$export = (new Wpcb\Privacy\PrivacyService())->exportWaitingList('second@example.com', 1);
wpcb_waitlist_assert(count($export['data']) === 1, 'Waiting-list personal data is included in privacy export.');
$erase = (new Wpcb\Privacy\PrivacyService())->eraseWaitingList('second@example.com', 1);
wpcb_waitlist_assert(!empty($erase['items_removed']) && $repo->forEmail('second@example.com') === [], 'Privacy erasure removes waiting-list entries.');

$wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['job_type' => 'waiting_list_offer']);
$wpdb->delete($wpdb->prefix . 'wpcb_waiting_list', ['id' => $firstId]);
foreach ([$occupantId, $acceptedBookingId] as $bookingId) {
    $wpdb->delete($wpdb->prefix . 'wpcb_tokens', ['booking_id' => $bookingId]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
    $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
}
$wpdb->delete($wpdb->prefix . 'wpcb_booking_type_resources', ['booking_type_id' => $typeId]);
$wpdb->delete($wpdb->prefix . 'wpcb_resources', ['id' => $resourceId]);
$wpdb->delete($typeTable, ['id' => $typeId]);

WP_CLI::success('Waiting-list promotion smoke test passed.');
