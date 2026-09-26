<?php
if (!defined('ABSPATH')) { exit(1); }

use Wpcb\Availability\SlotService;
use Wpcb\Booking\BookingSeriesRepository;
use Wpcb\Booking\BookingStateMachine;
use Wpcb\Booking\BookingStatus;
use Wpcb\Booking\BookingTransitionService;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Booking\RecurringBookingService;
use Wpcb\Payments\PaymentAdapterInterface;
use Wpcb\Payments\PaymentRepository;
use Wpcb\Payments\PaymentService;
use Wpcb\Payments\PaymentStatus;
use Wpcb\Support\Time;
use Wpcb\Tokens\SlotTokenService;

function wpcb_paid_series_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

/**
 * Pick an anchor only after every weekly occurrence is independently
 * canonical. A generic free slot is not automatically a valid series anchor.
 */
function wpcb_paid_series_anchor(SlotService $slots, int $typeId, int $count): ?array {
    foreach ($slots->getSlots($typeId, 21) as $candidate) {
        $resourceId = (int)($candidate['resource_id'] ?? 0);
        $startUtc = Time::parseUtc((string)($candidate['start'] ?? ''));
        $endUtc = Time::parseUtc((string)($candidate['end'] ?? ''));
        if ($resourceId < 1 || !$startUtc || !$endUtc) {
            continue;
        }

        $startLocal = $startUtc->setTimezone(Time::bookingTimezone());
        $endLocal = $endUtc->setTimezone(Time::bookingTimezone());
        $valid = true;
        for ($index = 1; $index < $count; ++$index) {
            $occurrenceStart = Time::formatUtc($startLocal->modify('+' . $index . ' weeks'));
            $occurrenceEnd = Time::formatUtc($endLocal->modify('+' . $index . ' weeks'));
            if (!$slots->isCanonicalSlot(
                $typeId,
                $occurrenceStart,
                $occurrenceEnd,
                null,
                $resourceId,
                1
            )) {
                $valid = false;
                break;
            }
        }
        if ($valid) {
            return $candidate;
        }
    }
    return null;
}

final class WpcbPaidSeriesAdapter implements PaymentAdapterInterface {
    public array $contexts = [];
    public array $refundContexts = [];

    public function code(): string {
        return 'series_fake';
    }

    public function createPayment(array $context) {
        $this->contexts[] = $context;
        return [
            'provider_reference' => 'series-payment-' . (int)$context['payment_id'],
            'checkout_url' => 'https://payments.example.test/series/' . rawurlencode((string)$context['payment_uuid']),
        ];
    }

    public function refund(array $context) {
        $this->refundContexts[] = $context;
        return [
            'provider_refund_id' => 'series-refund-' . hash('sha256', (string)$context['idempotency_key']),
            'provider_status' => 'succeeded',
            'amount_minor' => (int)$context['amount_minor'],
            'currency' => (string)$context['currency'],
            'provider_created_at' => time(),
        ];
    }
}

global $wpdb;
$type = null;
foreach ((new BookingTypeRepository())->all(true) as $candidate) {
    if ((string)($candidate->payment_mode ?? 'free') === 'free') {
        $type = $candidate;
        break;
    }
}
wpcb_paid_series_assert($type !== null, 'A public booking type is available for the paid-series fixture.');

$typeId = (int)$type->id;
$original = [
    'payment_mode' => (string)$type->payment_mode,
    'price_minor' => (int)$type->price_minor,
    'currency' => (string)$type->currency,
];
$wpdb->update($wpdb->prefix . 'wpcb_booking_types', [
    'payment_mode' => 'required',
    'price_minor' => 2500,
    'currency' => 'EUR',
    'updated_at' => Time::formatUtc(Time::nowUtc()),
], ['id' => $typeId]);

$slotService = new SlotService();
$anchor = wpcb_paid_series_anchor($slotService, $typeId, 3);
wpcb_paid_series_assert(
    is_array($anchor),
    'A canonical anchor with all three weekly occurrences is available for the paid-series fixture.'
);
$token = (new SlotTokenService())->issue(
    $typeId,
    (string)$anchor['start'],
    (string)$anchor['end'],
    (int)$anchor['resource_id'],
    3600
);

$seriesService = new RecurringBookingService();
$reserved = $seriesService->reserveWeekly(
    $token,
    $typeId,
    [
        'full_name' => 'Paid Series Fixture',
        'email' => 'paid-series@example.com',
        'phone' => '',
        'notes' => '',
        'source' => 'ci',
        'lang' => 'de',
        'party_size' => 1,
    ],
    ['fixture' => 'paid-series'],
    3,
    1
);
wpcb_paid_series_assert(!is_wp_error($reserved), 'Paid recurring series reserves atomically.');
$seriesId = (int)$reserved['series_id'];
$members = (new BookingSeriesRepository())->members($seriesId, 0);
wpcb_paid_series_assert(count($members) === 3, 'Paid series contains three occurrences.');

$payments = new PaymentService();
$adapter = new WpcbPaidSeriesAdapter();
$checkout = $payments->begin((int)$members[0]->id, $adapter);
wpcb_paid_series_assert(!is_wp_error($checkout), 'One checkout starts for the paid series.');
wpcb_paid_series_assert((int)$checkout->amount_minor === 7500, 'Series checkout amount is unit price multiplied by occurrence count.');
wpcb_paid_series_assert((string)$checkout->currency === 'EUR', 'Series checkout snapshots the server-side currency.');
wpcb_paid_series_assert(count($adapter->contexts) === 1 && (int)$adapter->contexts[0]['amount_minor'] === 7500, 'Payment adapter receives only the total technical series amount.');

$secondaryPayment = $payments->paymentForBooking((int)$members[2]->id);
wpcb_paid_series_assert($secondaryPayment && (int)$secondaryPayment->id === (int)$checkout->id, 'All occurrences resolve to the same series payment obligation.');
wpcb_paid_series_assert(!$payments->canConfirm((int)$members[0]->id) && !$payments->canConfirm((int)$members[2]->id), 'No paid series occurrence can confirm before verified payment.');

$paid = $payments->applyProviderEvent(
    'series_fake',
    'series-paid-event',
    (string)$checkout->provider_reference,
    'paid',
    7500,
    'EUR'
);
wpcb_paid_series_assert(!is_wp_error($paid) && (string)$paid->status === PaymentStatus::PAID, 'Verified provider event marks the complete series payment paid.');

$confirmed = $seriesService->applyRemaining(
    (int)$members[0]->id,
    BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
    'ci',
    'Paid recurring series confirmation'
);
wpcb_paid_series_assert(!is_wp_error($confirmed), 'Paid series confirms after verified payment.');
$members = (new BookingSeriesRepository())->members($seriesId, 0);
wpcb_paid_series_assert(count(array_filter($members, fn($b) => (string)$b->status === BookingStatus::CONFIRMED)) === 3, 'Paid series cannot be partially confirmed.');

wpcb_paid_series_assert(
    $payments->refundAmountForBooking((int)$members[0]->id, false) === 2500,
    'Single-occurrence refund preview uses the immutable per-occurrence allocation.'
);
wpcb_paid_series_assert(
    $payments->refundAmountForBooking((int)$members[0]->id, true) === 7500,
    'Remaining-series refund preview sums only active occurrence allocations.'
);

$partial = (new BookingTransitionService())->apply(
    (int)$members[0]->id,
    BookingStateMachine::USER_CANCELLED,
    'ci',
    'Cancel one paid series occurrence'
);
wpcb_paid_series_assert(!is_wp_error($partial) && !empty($partial['changed']), 'Single-occurrence cancellation of a paid series succeeds.');

$partialPending = $payments->paymentForBooking((int)$members[1]->id);
wpcb_paid_series_assert(
    $partialPending
    && (string)$partialPending->status === PaymentStatus::REFUND_PENDING
    && (int)$partialPending->refund_pending_minor === 2500
    && (int)$partialPending->refunded_minor === 0,
    'Single cancellation queues exactly one occurrence allocation.'
);

$partialRefunded = $payments->refund((int)$partialPending->id, $adapter);
wpcb_paid_series_assert(
    !is_wp_error($partialRefunded)
    && (string)$partialRefunded->status === PaymentStatus::PAID
    && (int)$partialRefunded->refunded_minor === 2500
    && (int)$partialRefunded->refund_pending_minor === 0,
    'Partial refund returns the payment to paid with cumulative refunded amount preserved.'
);
wpcb_paid_series_assert(
    (int)$adapter->refundContexts[0]['amount_minor'] === 2500,
    'Payment adapter receives only the queued partial refund amount.'
);

$members = (new BookingSeriesRepository())->members($seriesId, 0);
wpcb_paid_series_assert((string)$members[0]->status === BookingStatus::CANCELLED, 'Only the selected occurrence is cancelled by the single-occurrence action.');
wpcb_paid_series_assert(
    $payments->refundAmountForBooking((int)$members[1]->id, true) === 5000,
    'Remaining-series preview excludes the already cancelled/refunded occurrence.'
);

$remainingPartial = $seriesService->applyRemaining(
    (int)$members[1]->id,
    BookingStateMachine::USER_CANCELLED,
    'ci',
    'Cancel remaining paid series'
);
wpcb_paid_series_assert(!is_wp_error($remainingPartial), 'Remaining paid-series cancellation succeeds after a previous partial refund.');

$members = (new BookingSeriesRepository())->members($seriesId, 0);
wpcb_paid_series_assert(
    count(array_filter($members, fn($b) => (string)$b->status === BookingStatus::CANCELLED)) === 3,
    'Single plus remaining-series cancellation covers every occurrence exactly once.'
);
$seriesState = (new BookingSeriesRepository())->find($seriesId);
wpcb_paid_series_assert($seriesState && (string)$seriesState->status === 'cancelled', 'Series status becomes cancelled when all occurrences are cancelled.');

$remainingPending = $payments->paymentForBooking((int)$members[2]->id);
wpcb_paid_series_assert(
    $remainingPending
    && (string)$remainingPending->status === PaymentStatus::REFUND_PENDING
    && (int)$remainingPending->refund_pending_minor === 5000
    && (int)$remainingPending->refunded_minor === 2500,
    'Remaining cancellation queues only the two still-unrefunded allocations.'
);
$fullyRefunded = $payments->refund((int)$remainingPending->id, $adapter);
wpcb_paid_series_assert(
    !is_wp_error($fullyRefunded)
    && (string)$fullyRefunded->status === PaymentStatus::REFUNDED
    && (int)$fullyRefunded->refunded_minor === 7500
    && (int)$fullyRefunded->refund_pending_minor === 0,
    'Final partial refund reaches the exact original series total without over-refunding.'
);
wpcb_paid_series_assert(
    count($adapter->refundContexts) === 2
    && (int)$adapter->refundContexts[1]['amount_minor'] === 5000,
    'Remaining-series refund is sent as one bounded provider refund.'
);

$overRefund = $payments->applyProviderEvent(
    'series_fake',
    'series-refund-too-large',
    (string)$fullyRefunded->provider_reference,
    'refunded',
    1,
    'EUR'
);
wpcb_paid_series_assert(is_wp_error($overRefund), 'Refund processing rejects any amount beyond the immutable original payment total.');

foreach ($members as $member) {
    $contexts = $wpdb->get_col($wpdb->prepare(
        "SELECT context FROM {$wpdb->prefix}wpcb_booking_status_log WHERE booking_id = %d ORDER BY id ASC",
        (int)$member->id
    ));
    wpcb_paid_series_assert(
        in_array('payment_paid', $contexts, true) && in_array('payment_refund_pending', $contexts, true),
        'Every cancelled occurrence receives privacy-safe payment audit context.'
    );
}

function wpcb_paid_series_cleanup(int $seriesId): void {
    global $wpdb;
    $members = (new BookingSeriesRepository())->members($seriesId, 0);
    $paymentIds = [];
    foreach ($members as $member) {
        $payment = (new PaymentService())->paymentForBooking((int)$member->id);
        if ($payment) {
            $paymentIds[(int)$payment->id] = (int)$payment->id;
        }
    }
    foreach ($paymentIds as $paymentId) {
        $wpdb->delete($wpdb->prefix . 'wpcb_payment_events', ['payment_id' => $paymentId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_payment_refunds', ['payment_id' => $paymentId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_payments', ['id' => $paymentId]);
    }
    foreach ($members as $member) {
        foreach (['wpcb_tokens','wpcb_booking_meta','wpcb_booking_status_log','wpcb_sync_jobs','wpcb_sync_log','wpcb_deliveries'] as $suffix) {
            $wpdb->delete($wpdb->prefix . $suffix, ['booking_id' => (int)$member->id]);
        }
        $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => (int)$member->id]);
    }
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_series', ['id' => $seriesId]);
}

wpcb_paid_series_cleanup($seriesId);

// Expiry must release every reserved occurrence, not only the payment-owner booking.
$slotService = new SlotService();
$anchor = wpcb_paid_series_anchor($slotService, $typeId, 2);
wpcb_paid_series_assert(
    is_array($anchor),
    'A canonical anchor with both weekly occurrences is available for expiry coverage.'
);
$token = (new SlotTokenService())->issue(
    $typeId,
    (string)$anchor['start'],
    (string)$anchor['end'],
    (int)$anchor['resource_id'],
    3600
);
$expiring = $seriesService->reserveWeekly(
    $token,
    $typeId,
    [
        'full_name' => 'Paid Series Expiry',
        'email' => 'paid-series-expiry@example.com',
        'source' => 'ci',
        'lang' => 'de',
        'party_size' => 1,
    ],
    [],
    2,
    1
);
wpcb_paid_series_assert(!is_wp_error($expiring), 'Second paid series reserves for expiry coverage.');
$expiringMembers = (new BookingSeriesRepository())->members((int)$expiring['series_id'], 0);
$pending = $payments->ensureForBooking((int)$expiringMembers[0]->id);
wpcb_paid_series_assert($pending && !is_wp_error($pending), 'Expiring paid series has one pending payment.');
$wpdb->update($wpdb->prefix . 'wpcb_payments', [
    'expires_at' => Time::formatUtc(Time::nowUtc()->modify('-1 minute')),
], ['id' => (int)$pending->id]);
wpcb_paid_series_assert($payments->expirePending(20) >= 1, 'Expired series payment is processed.');
$expiredMembers = (new BookingSeriesRepository())->members((int)$expiring['series_id'], 0);
wpcb_paid_series_assert(
    count(array_filter($expiredMembers, fn($b) => (string)$b->status === BookingStatus::EXPIRED)) === 2,
    'Payment expiry releases every reserved occurrence in the series.'
);
wpcb_paid_series_cleanup((int)$expiring['series_id']);

$wpdb->update($wpdb->prefix . 'wpcb_booking_types', [
    'payment_mode' => $original['payment_mode'],
    'price_minor' => $original['price_minor'],
    'currency' => $original['currency'],
    'updated_at' => Time::formatUtc(Time::nowUtc()),
], ['id' => $typeId]);

WP_CLI::success('Paid recurring booking series smoke test passed.');
