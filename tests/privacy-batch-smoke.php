<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_privacy_batch_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$p = $wpdb->prefix . 'wpcb_';
$bookings = $p . 'bookings';
$meta = $p . 'booking_meta';
$waiting = $p . 'waiting_list';
$settingsBefore = get_option('wpcb_settings', []);
$settings = Wpcb\Admin\Settings::get();
$settings['retention_enabled'] = 1;
$settings['retention_days'] = 1;
update_option('wpcb_settings', $settings, false);

$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$oldStart = '2020-01-01 10:00:00';
$oldEnd = '2020-01-01 10:30:00';
$typeId = (int)$wpdb->get_var("SELECT id FROM {$p}booking_types ORDER BY id ASC LIMIT 1");
$resourceId = (int)get_option('wpcb_default_resource_id', 0);
wpcb_privacy_batch_assert($typeId > 0 && $resourceId > 0, 'Privacy fixture has booking type and resource.');

$bookingIds = [];
$insertBooking = static function (string $email) use ($wpdb, $bookings, $typeId, $resourceId, $oldStart, $oldEnd, $now, &$bookingIds): int {
    $ok = $wpdb->insert($bookings, [
        'booking_uuid' => wp_generate_uuid4(),
        'booking_type_id' => $typeId,
        'resource_id' => $resourceId,
        'slot_start' => $oldStart,
        'slot_end' => $oldEnd,
        'status' => Wpcb\Booking\BookingStatus::CANCELLED,
        'party_size' => 1,
        'full_name' => 'Synthetic Privacy Fixture',
        'email' => $email,
        'phone' => 'synthetic',
        'notes' => 'synthetic',
        'admin_notes' => 'synthetic',
        'source' => 'privacy-ci',
        'lang' => 'en',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if ($ok !== 1) throw new RuntimeException('Could not create privacy fixture booking');
    $id = (int)$wpdb->insert_id;
    $bookingIds[] = $id;
    return $id;
};

$privacy = new Wpcb\Privacy\PrivacyService();

// 201 eligible rows must progress 200 -> 1 -> 0 instead of selecting the same anonymized first batch forever.
$retentionIds = [];
for ($i = 0; $i < 201; ++$i) {
    $retentionIds[] = $insertBooking('retention-' . $i . '-' . wp_generate_password(6, false) . '@example.test');
}
$retainedId = $insertBooking('retained-' . wp_generate_password(8, false) . '@example.test');
$privacy->setRetention($retainedId, true);

wpcb_privacy_batch_assert($privacy->runRetention() === 200, 'Retention processes the first bounded batch of 200.');
wpcb_privacy_batch_assert($privacy->runRetention() === 1, 'Retention advances to the 201st eligible booking.');
wpcb_privacy_batch_assert($privacy->runRetention() === 0, 'Retention reaches eventual completion without reselecting anonymized rows.');
$retentionPlaceholders = implode(',', array_fill(0, count($retentionIds), '%d'));
$anonymized = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$bookings} WHERE id IN ({$retentionPlaceholders}) AND email LIKE %s",
    ...array_merge($retentionIds, ['anonymized-%@example.invalid'])
));
wpcb_privacy_batch_assert($anonymized === 201, 'All 201 eligible retention rows are anonymized exactly once.');
wpcb_privacy_batch_assert((string)$wpdb->get_var($wpdb->prepare("SELECT email FROM {$bookings} WHERE id = %d", $retainedId)) !== ''
    && $privacy->isRetained($retainedId), 'Retention hold preserves the retained booking.');

// Fifty retained rows may not hide an erasable 51st row.
$mixedEmail = 'mixed-retention-' . wp_generate_password(8, false) . '@example.test';
$mixedIds = [];
for ($i = 0; $i < 51; ++$i) {
    $id = $insertBooking($mixedEmail);
    $mixedIds[] = $id;
    if ($i < 50) $privacy->setRetention($id, true);
}
$erased = $privacy->eraser($mixedEmail, 1);
wpcb_privacy_batch_assert(!empty($erased['items_removed']) && !empty($erased['items_retained']) && !empty($erased['done']),
    'Eraser progresses past 50 retained rows and completes the erasable tail.');
wpcb_privacy_batch_assert((string)$wpdb->get_var($wpdb->prepare("SELECT email FROM {$bookings} WHERE id = %d", $mixedIds[50]))
    === 'anonymized-' . $mixedIds[50] . '@example.invalid', 'The erasable 51st booking is anonymized.');
wpcb_privacy_batch_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings} WHERE email = %s", $mixedEmail)) === 50,
    'All 50 retained rows remain identifiable and untouched.');

// Mutation-safe erasure must converge across more than two pages.
$multiEmail = 'multi-erase-' . wp_generate_password(8, false) . '@example.test';
for ($i = 0; $i < 101; ++$i) $insertBooking($multiEmail);
$e1 = $privacy->eraser($multiEmail, 1);
$e2 = $privacy->eraser($multiEmail, 2);
$e3 = $privacy->eraser($multiEmail, 3);
wpcb_privacy_batch_assert(!$e1['done'] && !$e2['done'] && $e3['done'],
    'Booking erasure converges across 50/50/1 mutation-safe batches.');
wpcb_privacy_batch_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings} WHERE email = %s", $multiEmail)) === 0,
    'No erasable booking remains after the final eraser batch.');

// Waiting-list exporter/eraser must honor the WordPress page contract.
$waitEmail = 'waiting-export-' . wp_generate_password(8, false) . '@example.test';
$waitIds = [];
for ($i = 0; $i < 51; ++$i) {
    $ok = $wpdb->insert($waiting, [
        'entry_uuid' => wp_generate_uuid4(),
        'booking_type_id' => $typeId,
        'resource_id' => $resourceId,
        'slot_start' => $oldStart,
        'slot_end' => $oldEnd,
        'party_size' => 1,
        'full_name' => 'Synthetic Waiting Fixture',
        'email' => $waitEmail,
        'phone' => '',
        'status' => 'waiting',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if ($ok !== 1) throw new RuntimeException('Could not create waiting-list fixture');
    $waitIds[] = (int)$wpdb->insert_id;
}
$waitPrivacy = new Wpcb\WaitingList\WaitingListPrivacy();
$x1 = $waitPrivacy->exporter($waitEmail, 1);
$x2 = $waitPrivacy->exporter($waitEmail, 2);
$exportedIds = array_merge(
    array_column($x1['data'], 'item_id'),
    array_column($x2['data'], 'item_id')
);
wpcb_privacy_batch_assert(count($x1['data']) === 50 && !$x1['done'], 'Waiting-list export page 1 returns 50 and requests another page.');
wpcb_privacy_batch_assert(count($x2['data']) === 1 && $x2['done'], 'Waiting-list export page 2 returns the 51st row and completes.');
wpcb_privacy_batch_assert(count(array_unique($exportedIds)) === 51, 'Waiting-list export contains 51 unique entries without duplicates.');

$d1 = $waitPrivacy->eraser($waitEmail, 1);
$d2 = $waitPrivacy->eraser($waitEmail, 2);
wpcb_privacy_batch_assert(!empty($d1['items_removed']) && !$d1['done'] && !empty($d2['items_removed']) && $d2['done'],
    'Waiting-list erasure progresses in bounded 50/1 batches.');
wpcb_privacy_batch_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$waiting} WHERE email = %s", $waitEmail)) === 0,
    'Waiting-list erasure reaches eventual completion.');

if ($bookingIds) {
    $ph = implode(',', array_fill(0, count($bookingIds), '%d'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$meta} WHERE booking_id IN ({$ph})", ...$bookingIds));
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}tokens WHERE booking_id IN ({$ph})", ...$bookingIds));
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}video_meetings WHERE booking_id IN ({$ph})", ...$bookingIds));
    $wpdb->query($wpdb->prepare("DELETE FROM {$bookings} WHERE id IN ({$ph})", ...$bookingIds));
}
update_option('wpcb_settings', $settingsBefore, false);

WP_CLI::success('Privacy batch progression smoke test passed.');
