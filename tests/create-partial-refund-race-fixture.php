<?php
if (!defined('ABSPATH')) { exit(1); }

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingSeriesRepository;
use Wpcb\Booking\BookingStatus;
use Wpcb\Payments\PaymentRepository;
use Wpcb\Payments\PaymentStatus;
use Wpcb\Support\Time;

global $wpdb;
$now = Time::formatUtc(Time::nowUtc());
$resourceId = (int)get_option('wpcb_default_resource_id', 0);
if ($resourceId < 1) { throw new RuntimeException('Default resource missing.'); }

$wpdb->insert($wpdb->prefix . 'wpcb_booking_types', [
    'name' => 'Partial Refund Race',
    'slug' => 'partial-refund-race-' . wp_generate_password(8, false),
    'description' => '',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'capacity' => 1,
    'show_remaining_capacity' => 0,
    'payment_mode' => 'required',
    'price_minor' => 3334,
    'currency' => 'EUR',
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 999,
    'created_at' => $now,
    'updated_at' => $now,
]);
$typeId = (int)$wpdb->insert_id;

$series = new BookingSeriesRepository();
$seriesId = $series->create([
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'frequency' => 'weekly',
    'interval_count' => 1,
    'occurrence_count' => 3,
    'timezone' => 'Europe/Vienna',
]);
if ($seriesId < 1) { throw new RuntimeException('Series fixture could not be created.'); }

$bookings = new BookingRepository();
$ids = [];
for ($i = 0; $i < 3; $i++) {
    $ids[] = $bookings->create([
        'booking_uuid' => wp_generate_uuid4(),
        'booking_type_id' => $typeId,
        'resource_id' => $resourceId,
        'series_id' => $seriesId,
        'series_occurrence' => $i,
        'slot_start' => sprintf('2037-03-%02d 10:00:00', 10 + ($i * 7)),
        'slot_end' => sprintf('2037-03-%02d 10:30:00', 10 + ($i * 7)),
        'status' => BookingStatus::CONFIRMED,
        'party_size' => 1,
        'full_name' => 'Refund Race Fixture',
        'email' => 'refund-race@example.com',
        'phone' => '',
        'notes' => '',
        'source' => 'ci',
        'lang' => 'en',
        'confirmed_at' => $now,
        'approved_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ], [], false);
}
if (count(array_filter($ids)) !== 3) { throw new RuntimeException('Booking fixtures could not be created.'); }

$payments = new PaymentRepository();
$paymentId = $payments->createPending($ids[0], 10001, 'EUR', null);
if ($paymentId < 1
    || !$payments->attachProvider($paymentId, 'race_fake', 'race-payment-' . $paymentId)
    || !$payments->setStatus($paymentId, PaymentStatus::PENDING, PaymentStatus::PAID)
) {
    throw new RuntimeException('Paid fixture could not be created.');
}

echo implode('|', [$ids[0], $ids[1], $paymentId, 6668, $seriesId, $typeId]);
