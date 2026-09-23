<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_payment_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

final class WpcbPaymentFakeAdapter implements Wpcb\Payments\PaymentAdapterInterface {
    public function code(): string { return 'fake'; }
    public function createPayment(array $context) {
        return ['provider_reference' => 'fake-' . $context['payment_uuid']];
    }
    public function refund(array $context) {
        return ['provider_event_id' => 'refund-' . $context['payment_uuid']];
    }
}

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeTable = $wpdb->prefix . 'wpcb_booking_types';
$wpdb->insert($typeTable, [
    'name' => 'Paid Fixture',
    'slug' => 'paid-fixture-' . wp_generate_password(6, false),
    'description' => '',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'capacity' => 1,
    'show_remaining_capacity' => 0,
    'payment_mode' => 'required',
    'price_minor' => 12900,
    'currency' => 'EUR',
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 99,
    'created_at' => $now,
    'updated_at' => $now,
]);
$typeId = (int)$wpdb->insert_id;
$resourceId = (int)get_option('wpcb_default_resource_id', 0);
wpcb_payment_assert($typeId > 0 && $resourceId > 0, 'Paid fixture has booking type and resource.');

$bookings = new Wpcb\Booking\BookingRepository();
$bookingId = $bookings->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2034-03-10 10:00:00',
    'slot_end' => '2034-03-10 10:30:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'full_name' => 'Payment Person',
    'email' => 'payment@example.com',
    'phone' => '',
    'notes' => '',
    'source' => 'payment-smoke',
    'lang' => 'de',
    'reserved_until' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+30 minutes')),
    'created_at' => $now,
    'updated_at' => $now,
]);
wpcb_payment_assert($bookingId > 0, 'Paid fixture booking is created.');

$service = new Wpcb\Payments\PaymentService();
$pending = $service->ensureForBooking($bookingId);
wpcb_payment_assert(is_object($pending) && $pending->status === 'pending', 'Paid booking creates one pending payment obligation.');
wpcb_payment_assert((int)$pending->amount_minor === 12900 && $pending->currency === 'EUR', 'Payment copies amount and currency from booking type.');
wpcb_payment_assert(!$service->canConfirm($bookingId), 'Required payment blocks customer confirmation while pending.');

$adapter = new WpcbPaymentFakeAdapter();
$started = $service->begin($bookingId, $adapter);
wpcb_payment_assert(is_object($started) && $started->provider === 'fake', 'Provider-neutral adapter attaches provider reference.');

$mismatch = $service->applyProviderEvent('fake', 'evt-mismatch', (string)$started->provider_reference, 'paid', 1, 'EUR');
wpcb_payment_assert(is_wp_error($mismatch), 'Provider callback with wrong amount is rejected.');

$paid = $service->applyProviderEvent('fake', 'evt-paid-1', (string)$started->provider_reference, 'paid', 12900, 'EUR');
wpcb_payment_assert(is_object($paid) && $paid->status === 'paid', 'Verified provider callback marks payment paid.');
$retry = $service->applyProviderEvent('fake', 'evt-paid-1', (string)$started->provider_reference, 'paid', 12900, 'EUR');
wpcb_payment_assert(is_object($retry) && $retry->status === 'paid', 'Duplicate provider callback is idempotent.');
wpcb_payment_assert($service->canConfirm($bookingId), 'Paid booking is eligible for customer confirmation.');

$cancelled = (new Wpcb\Booking\BookingTransitionService())->apply(
    $bookingId,
    Wpcb\Booking\BookingStateMachine::USER_CANCELLED,
    'test',
    'Payment cancellation fixture'
);
wpcb_payment_assert(is_array($cancelled) && !empty($cancelled['changed']), 'Confirmed paid booking can be cancelled.');
$refundPending = (new Wpcb\Payments\PaymentRepository())->forBooking($bookingId);
wpcb_payment_assert($refundPending && $refundPending->status === 'refund_pending', 'Cancellation deterministically moves paid payment to refund pending.');
$refunded = $service->refund((int)$refundPending->id, $adapter);
wpcb_payment_assert(is_object($refunded) && $refunded->status === 'refunded', 'Adapter refund deterministically reaches refunded state.');

$expiringId = $bookings->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2034-03-11 10:00:00',
    'slot_end' => '2034-03-11 10:30:00',
    'status' => Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED,
    'party_size' => 1,
    'full_name' => 'Expiring Payment',
    'email' => 'expiring@example.com',
    'source' => 'payment-smoke',
    'lang' => 'de',
    'reserved_until' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-1 minute')),
    'created_at' => $now,
    'updated_at' => $now,
]);
$expiring = $service->ensureForBooking($expiringId);
wpcb_payment_assert(is_object($expiring), 'Expiring paid booking has a pending payment.');
$expiredCount = $service->expirePending();
wpcb_payment_assert($expiredCount >= 1, 'Expired pending payments are processed.');
wpcb_payment_assert((string)(new Wpcb\Payments\PaymentRepository())->forBooking($expiringId)->status === 'expired', 'Expired payment reaches expired state.');
wpcb_payment_assert((string)$bookings->find($expiringId)->status === Wpcb\Booking\BookingStatus::EXPIRED, 'Expired payment releases the reserved booking.');

$adminSource = file_get_contents(WPCB_DIR . 'includes/Admin/PaymentAdminPage.php');
foreach (['card_number', 'cardholder', 'cvc', 'cvv', 'pan'] as $forbidden) {
    wpcb_payment_assert(stripos($adminSource, $forbidden) === false, 'Payment admin never stores or renders raw card field ' . $forbidden . '.');
}

foreach ([$bookingId, $expiringId] as $id) {
    $paymentIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wpcb_payments WHERE booking_id = %d", $id));
    foreach ($paymentIds as $paymentId) {
        $wpdb->delete($wpdb->prefix . 'wpcb_payment_events', ['payment_id' => (int)$paymentId]);
    }
    $wpdb->delete($wpdb->prefix . 'wpcb_payments', ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $id]);
}
$wpdb->delete($typeTable, ['id' => $typeId]);

WP_CLI::success('Payment lifecycle smoke test passed.');
