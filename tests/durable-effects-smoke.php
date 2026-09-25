<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_effect_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
add_filter('pre_wp_mail', static fn() => true);

$bookings = new Wpcb\Booking\BookingRepository();
$typesTable = $wpdb->prefix . 'wpcb_booking_types';
$jobsTable = $wpdb->prefix . 'wpcb_sync_jobs';
$eventsTable = $wpdb->prefix . 'wpcb_payment_events';
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$resourceId = (int)get_option('wpcb_default_resource_id');
wpcb_effect_assert($resourceId > 0, 'Durable-effect fixture has a default resource.');

$freeType = null;
foreach ((new Wpcb\Booking\BookingTypeRepository())->all(true) as $candidate) {
    if ((string)($candidate->payment_mode ?? 'free') === 'free') {
        $freeType = $candidate;
        break;
    }
}
wpcb_effect_assert($freeType !== null, 'Durable-effect fixture has a free booking type.');

$makeBooking = static function (int $typeId, int $resourceId, string $email, string $status = Wpcb\Booking\BookingStatus::CONFIRMED) use ($bookings, $now): int {
    return $bookings->create([
        'booking_uuid' => wp_generate_uuid4(),
        'booking_type_id' => $typeId,
        'resource_id' => $resourceId,
        'slot_start' => '2034-04-03 10:00:00',
        'slot_end' => '2034-04-03 10:30:00',
        'status' => $status,
        'party_size' => 1,
        'full_name' => 'Synthetic Durable Effect',
        'email' => $email,
        'phone' => '',
        'notes' => '',
        'source' => 'ci',
        'lang' => 'de',
        'created_at' => $now,
        'updated_at' => $now,
    ], [], false);
};

// Fault injection: the authoritative transition and its outbox insert must roll
// back together. A trigger fails only booking_effect inserts in disposable CI.
$atomicId = $makeBooking((int)$freeType->id, $resourceId, 'effect-atomic@example.invalid');
wpcb_effect_assert($atomicId > 0, 'Atomicity fixture booking is stored.');
$logBefore = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_status_log WHERE booking_id = %d",
    $atomicId
));
$trigger = 'wpcb_effect_fail_' . strtolower(wp_generate_password(8, false, false));
$trigger = preg_replace('/[^a-z0-9_]/', '', $trigger);
$createdTrigger = $wpdb->query(
    "CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$jobsTable}`
     FOR EACH ROW BEGIN
       IF NEW.job_type = 'booking_effect' THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic booking effect failure';
       END IF;
     END"
);
wpcb_effect_assert($createdTrigger !== false, 'Fault injector for the durable outbox is installed.');
try {
    $failed = (new Wpcb\Booking\BookingTransitionService())->apply(
        $atomicId,
        Wpcb\Booking\BookingStateMachine::USER_CANCELLED,
        'ci',
        'Synthetic durable effect rollback'
    );
    wpcb_effect_assert(is_wp_error($failed) && $failed->get_error_code() === 'wpcb_effect_storage', 'Outbox storage failure rejects the transition.');
} finally {
    $wpdb->query("DROP TRIGGER IF EXISTS `{$trigger}`");
}
$atomic = $bookings->find($atomicId);
wpcb_effect_assert($atomic && (string)$atomic->status === Wpcb\Booking\BookingStatus::CONFIRMED, 'Failed outbox insert rolls the booking state back.');
$logAfter = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_status_log WHERE booking_id = %d",
    $atomicId
));
wpcb_effect_assert($logAfter === $logBefore, 'Failed outbox insert rolls the lifecycle audit row back.');

// Successful transition: intent is durable and privacy-minimal.
$cancelled = (new Wpcb\Booking\BookingTransitionService())->apply(
    $atomicId,
    Wpcb\Booking\BookingStateMachine::USER_CANCELLED,
    'ci',
    'Synthetic durable effect success'
);
wpcb_effect_assert(is_array($cancelled) && !empty($cancelled['changed']), 'Transition succeeds after durable intent storage recovers.');
$effect = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$jobsTable} WHERE booking_id = %d AND job_type = 'booking_effect' ORDER BY id DESC LIMIT 1",
    $atomicId
));
wpcb_effect_assert($effect !== null, 'Committed transition retains a durable booking-effect queue row.');
$payloadText = (string)$effect->payload_json;
wpcb_effect_assert(
    strpos($payloadText, 'effect-atomic@example.invalid') === false
    && strpos($payloadText, 'Synthetic Durable Effect') === false,
    'Durable booking-effect payload contains no copied customer name or email.'
);

// Paid cancellation: replay the same durable effect and prove refund allocation
// remains exactly once. This covers the dangerous non-idempotent downstream path.
$wpdb->insert($typesTable, [
    'name' => 'Durable paid fixture',
    'slug' => 'durable-paid-' . strtolower(wp_generate_password(8, false, false)),
    'description' => '',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'capacity' => 1,
    'show_remaining_capacity' => 0,
    'payment_mode' => 'required',
    'price_minor' => 1200,
    'currency' => 'EUR',
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$paidTypeId = (int)$wpdb->insert_id;
wpcb_effect_assert($paidTypeId > 0, 'Paid durable-effect fixture type is stored.');
$paidId = $makeBooking($paidTypeId, $resourceId, 'effect-paid@example.invalid');
$payments = new Wpcb\Payments\PaymentRepository();
$paymentId = $payments->createPending($paidId, 1200, 'EUR', '2034-04-03 09:30:00');
wpcb_effect_assert($paymentId > 0 && $payments->setStatus($paymentId, Wpcb\Payments\PaymentStatus::PENDING, Wpcb\Payments\PaymentStatus::PAID), 'Paid fixture has a settled payment.');

$paidCancel = (new Wpcb\Booking\BookingTransitionService())->apply(
    $paidId,
    Wpcb\Booking\BookingStateMachine::USER_CANCELLED,
    'ci',
    'Synthetic paid durable effect'
);
wpcb_effect_assert(is_array($paidCancel) && !empty($paidCancel['changed']), 'Paid booking cancellation commits with durable effect intent.');
$payment = $payments->find($paymentId);
wpcb_effect_assert(
    $payment && (string)$payment->status === Wpcb\Payments\PaymentStatus::REFUND_PENDING
    && (int)$payment->refund_pending_minor === 1200,
    'First durable delivery queues the refund allocation exactly once.'
);
$paidEffect = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$jobsTable} WHERE booking_id = %d AND job_type = 'booking_effect' ORDER BY id DESC LIMIT 1",
    $paidId
));
wpcb_effect_assert($paidEffect !== null, 'Paid cancellation retains its durable effect row.');
$wpdb->query($wpdb->prepare(
    "UPDATE {$jobsTable}
     SET status='pending', attempts=0, available_at=%s, lease_owner=NULL, lease_expires_at=NULL
     WHERE id=%d",
    $now,
    (int)$paidEffect->id
));
(new Wpcb\Sync\QueueService())->runNow(10);
$paymentReplay = $payments->find($paymentId);
wpcb_effect_assert(
    $paymentReplay && (int)$paymentReplay->refund_pending_minor === 1200,
    'Replaying the same committed lifecycle effect does not duplicate a refund allocation.'
);
$internalEvents = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$eventsTable}
     WHERE payment_id=%d AND provider='internal' AND event_type='refund_queued'",
    $paymentId
));
wpcb_effect_assert($internalEvents === 1, 'Refund queue intent has one durable idempotency marker.');

// Clean only this synthetic fixture.
foreach ([$atomicId, $paidId] as $id) {
    $wpdb->delete($wpdb->prefix . 'wpcb_deliveries', ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_sync_log', ['booking_id' => $id]);
    $wpdb->delete($jobsTable, ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $id]);
}
$wpdb->delete($eventsTable, ['payment_id' => $paymentId]);
$wpdb->delete($wpdb->prefix . 'wpcb_payments', ['id' => $paymentId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $atomicId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $paidId]);
$wpdb->delete($typesTable, ['id' => $paidTypeId]);

WP_CLI::success('Durable lifecycle-effect smoke test passed.');
