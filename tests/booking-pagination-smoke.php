<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_pagination_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());

$wpdb->insert($wpdb->prefix . 'wpcb_booking_types', [
    'name' => 'Pagination Fixture',
    'slug' => 'pagination-fixture-' . wp_generate_password(6, false),
    'description' => '',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 999,
    'created_at' => $now,
    'updated_at' => $now,
]);
$typeId = (int)$wpdb->insert_id;
wpcb_pagination_assert($typeId > 0, 'Pagination fixture booking type is created.');

$repo = new Wpcb\Booking\BookingRepository();
$bookingIds = [];
$base = new DateTimeImmutable('2040-01-01 08:00:00', new DateTimeZone('UTC'));

for ($i = 1; $i <= 55; ++$i) {
    $start = $base->modify('+' . ($i - 1) . ' hours');
    $end = $start->modify('+30 minutes');
    $meta = ['sync_status' => 'pagination-sync-' . $i];
    if ($i === 1) {
        $meta['privacy_retain'] = '1';
    }

    $bookingId = $repo->create([
        'booking_uuid' => wp_generate_uuid4(),
        'booking_type_id' => $typeId,
        'slot_start' => $start->format('Y-m-d H:i:s'),
        'slot_end' => $end->format('Y-m-d H:i:s'),
        'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
        'full_name' => sprintf('Pagination Booking %02d', $i),
        'email' => sprintf('pagination-%02d@example.com', $i),
        'phone' => '',
        'notes' => '',
        'source' => 'test',
        'lang' => 'de',
        'created_at' => $now,
        'updated_at' => $now,
    ], $meta, false);

    wpcb_pagination_assert($bookingId > 0, 'Pagination fixture booking ' . $i . ' is created.');
    $bookingIds[] = $bookingId;
}

$filters = ['booking_type_id' => $typeId];
wpcb_pagination_assert($repo->count($filters) === 55, 'Filtered total count reports all 55 fixture bookings.');

$pageOne = $repo->all($filters + ['limit' => 50, 'offset' => 0]);
$pageTwo = $repo->all($filters + ['limit' => 50, 'offset' => 50]);
wpcb_pagination_assert(count($pageOne) === 50, 'First repository page is bounded to 50 bookings.');
wpcb_pagination_assert(count($pageTwo) === 5, 'Second repository page contains the remaining five bookings.');

$pageOneIds = array_map(static fn($booking): int => (int)$booking->id, $pageOne);
$pageTwoIds = array_map(static fn($booking): int => (int)$booking->id, $pageTwo);
wpcb_pagination_assert(count(array_intersect($pageOneIds, $pageTwoIds)) === 0, 'Repository pages contain no duplicate bookings.');
wpcb_pagination_assert(count(array_unique(array_merge($pageOneIds, $pageTwoIds))) === 55, 'Repository pages contain every filtered booking exactly once.');

$negativeOffset = $repo->all($filters + ['limit' => 2, 'offset' => -500]);
wpcb_pagination_assert(
    count($negativeOffset) === 2 && (int)$negativeOffset[0]->id === (int)$pageOne[0]->id,
    'Negative repository offsets are clamped safely to zero.'
);

$meta = $repo->metaForBookings($pageOneIds, ['sync_status', 'privacy_retain']);
wpcb_pagination_assert(
    isset($meta[$pageOneIds[0]]['sync_status']) && $meta[$pageOneIds[0]]['sync_status'] === 'pagination-sync-1',
    'Batch meta loader returns requested technical metadata.'
);
wpcb_pagination_assert(($meta[$pageOneIds[0]]['privacy_retain'] ?? '') === '1', 'Batch meta loader returns the retention flag without a per-row query.');

$unbounded = $repo->all($filters);
wpcb_pagination_assert(count($unbounded) === 55, 'Unbounded repository reads remain available for complete CSV export.');

$admin = get_user_by('login', 'admin');
wpcb_pagination_assert($admin !== false, 'WordPress admin fixture user exists.');
wp_set_current_user((int)$admin->ID);

$oldGet = $_GET;
$_GET = [
    'page' => 'wpcb_bookings',
    'booking_type_id' => (string)$typeId,
    'paged' => '2',
];
ob_start();
(new Wpcb\Admin\Admin())->bookings();
$html = (string)ob_get_clean();
$_GET = $oldGet;

wpcb_pagination_assert(strpos($html, 'Pagination Booking 51') !== false, 'Admin page two renders its first expected booking.');
wpcb_pagination_assert(strpos($html, 'Pagination Booking 55') !== false, 'Admin page two renders its last expected booking.');
wpcb_pagination_assert(strpos($html, 'Pagination Booking 01') === false, 'Admin page two does not render page-one bookings.');
wpcb_pagination_assert(strpos($html, '55 Buchungen') !== false, 'Admin list renders the filtered total count.');
wpcb_pagination_assert(
    strpos($html, 'booking_type_id=' . $typeId) !== false || strpos($html, 'booking_type_id%3D' . $typeId) !== false,
    'Pagination preserves the booking-type filter.'
);

$adminSource = file_get_contents(WPCB_DIR . 'includes/Admin/Admin.php');
$methodStart = strpos($adminSource, 'public function bookings(): void');
$methodEnd = strpos($adminSource, 'public function exportBookings(): void', $methodStart);
$listSource = ($methodStart !== false && $methodEnd !== false)
    ? substr($adminSource, $methodStart, $methodEnd - $methodStart)
    : '';
wpcb_pagination_assert($listSource !== '', 'Admin booking list source can be inspected.');
wpcb_pagination_assert(strpos($listSource, '->getMeta(') === false, 'Admin booking list has no per-row meta query.');
wpcb_pagination_assert(strpos($listSource, '->isRetained(') === false, 'Admin booking list has no per-row retention query.');
wpcb_pagination_assert(strpos($listSource, "\$pageFilters['limit'] = \$perPage") !== false, 'Admin booking list always applies a bounded page limit.');

foreach ($bookingIds as $bookingId) {
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
    $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
}
$wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $typeId]);

WP_CLI::success('Admin booking pagination smoke test passed.');
