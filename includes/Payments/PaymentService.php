<?php
namespace Wpcb\Payments;

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingSeriesRepository;
use Wpcb\Booking\RecurringBookingService;
use Wpcb\Booking\BookingStateMachine;
use Wpcb\Booking\BookingStatus;
use Wpcb\Booking\BookingTransitionService;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Support\Time;

final class PaymentService {
    private PaymentRepository $payments;
    private BookingRepository $bookings;
    private BookingTypeRepository $types;

    public function __construct(
        ?PaymentRepository $payments = null,
        ?BookingRepository $bookings = null,
        ?BookingTypeRepository $types = null
    ) {
        $this->payments = $payments ?: new PaymentRepository();
        $this->bookings = $bookings ?: new BookingRepository();
        $this->types = $types ?: new BookingTypeRepository();
    }

    public function boot(): void {
        add_action('wpcb_booking_transitioned', [$this, 'onBookingTransition'], 20, 2);
        add_action('wpcb_hourly_reminders', [$this, 'expirePending'], 1);
    }

    /**
     * Create the payment obligation for a newly reserved paid booking.
     *
     * @return object|null|\WP_Error Null means the booking is free.
     */
    public function ensureForBooking(int $bookingId) {
        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return new \WP_Error('wpcb_payment_booking_missing', 'Booking not found.');
        }

        $scope = $this->paymentScope($booking);
        if (is_wp_error($scope)) {
            return $scope;
        }
        $owner = $scope['owner'];
        $type = $this->types->find((int)$owner->booking_type_id);
        if (!$type || (string)($type->payment_mode ?? 'free') !== 'required') {
            return null;
        }

        $existing = $this->payments->forBooking((int)$owner->id);
        if ($existing && in_array((string)$existing->status, [
            PaymentStatus::PENDING,
            PaymentStatus::PAID,
            PaymentStatus::REFUND_PENDING,
            PaymentStatus::REFUNDED,
        ], true)) {
            return $existing;
        }

        $unitAmount = max(0, (int)($type->price_minor ?? 0));
        $occurrenceCount = max(1, (int)$scope['occurrence_count']);
        $currency = strtoupper((string)($type->currency ?? 'EUR'));
        if ($unitAmount < 1 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return new \WP_Error('wpcb_payment_config_invalid', 'Paid booking type has invalid price settings.');
        }
        if ($unitAmount > intdiv(PHP_INT_MAX, $occurrenceCount)) {
            return new \WP_Error('wpcb_payment_amount_invalid', 'Series payment amount is too large.');
        }

        // The stored payment amount/currency are the immutable price snapshot.
        $amount = $unitAmount * $occurrenceCount;
        $id = $this->payments->createPending(
            (int)$owner->id,
            $amount,
            $currency,
            (string)$owner->reserved_until
        );
        return $id > 0 ? $this->payments->find($id) : new \WP_Error('wpcb_payment_storage', 'Payment could not be stored.');
    }

    public function paymentForBooking(int $bookingId): ?object {
        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return null;
        }
        $scope = $this->paymentScope($booking);
        if (is_wp_error($scope)) {
            return null;
        }
        return $this->payments->forBooking((int)$scope['owner']->id);
    }

    /**
     * Paid series are one payment obligation. A paid/refundable series can only
     * be cancelled as the complete series from its first occurrence.
     *
     * @return true|\WP_Error
     */
    public function validateSeriesCancellation(int $bookingId, bool $wholeSeries): mixed {
        $booking = $this->bookings->find($bookingId);
        if (!$booking || empty($booking->series_id)) {
            return true;
        }
        $type = $this->types->find((int)$booking->booking_type_id);
        if (!$type || (string)($type->payment_mode ?? 'free') !== 'required') {
            return true;
        }
        if (!$wholeSeries) {
            return new \WP_Error(
                'wpcb_paid_series_partial_refund_unsupported',
                'Paid recurring bookings can currently be cancelled only as the complete series from the first occurrence.'
            );
        }
        return true;
    }

    /**
     * Start a provider checkout without exposing booking/customer data to the adapter.
     */
    public function begin(int $bookingId, PaymentAdapterInterface $adapter) {
        $payment = $this->ensureForBooking($bookingId);
        if (is_wp_error($payment) || $payment === null) {
            return $payment instanceof \WP_Error ? $payment : new \WP_Error('wpcb_payment_not_required', 'This booking does not require payment.');
        }
        if ((string)$payment->status !== PaymentStatus::PENDING) {
            return $payment;
        }

        $lockName = 'wpcb_pay_checkout_' . (int)$payment->id;
        if (!$this->acquireLock($lockName, 5)) {
            return new \WP_Error('wpcb_payment_checkout_busy', 'Payment checkout is already being prepared.');
        }

        try {
            $payment = $this->payments->find((int)$payment->id);
            if (!$payment || (string)$payment->status !== PaymentStatus::PENDING) {
                return $payment ?: new \WP_Error('wpcb_payment_missing', 'Payment no longer exists.');
            }
            $expiresAt = Time::parseUtc((string)($payment->expires_at ?? ''));
            $ownerBooking = $this->bookings->find((int)$payment->booking_id);
            $bookingExpiresAt = $ownerBooking ? Time::parseUtc((string)($ownerBooking->reserved_until ?? '')) : null;
            if (!$expiresAt || $expiresAt <= Time::nowUtc()
                || !$ownerBooking || (string)$ownerBooking->status !== BookingStatus::RESERVED_UNCONFIRMED
                || !$bookingExpiresAt || $bookingExpiresAt <= Time::nowUtc()
            ) {
                return new \WP_Error('wpcb_payment_expired', 'Payment reservation has expired.');
            }

            $result = $adapter->createPayment([
                'payment_id' => (int)$payment->id,
                'payment_uuid' => (string)$payment->payment_uuid,
                'amount_minor' => (int)$payment->amount_minor,
                'currency' => (string)$payment->currency,
                'expires_at' => (string)$payment->expires_at,
                'provider_reference' => (string)($payment->provider_reference ?? ''),
            ]);
            if (is_wp_error($result)) {
                return $result;
            }
            $reference = sanitize_text_field((string)($result['provider_reference'] ?? ''));
            $oldReference = sanitize_text_field((string)($payment->provider_reference ?? ''));
            $provider = sanitize_key($adapter->code());
            if ($reference === '') {
                return new \WP_Error('wpcb_payment_provider_reference', 'Payment provider reference could not be stored.');
            }
            if ($oldReference === '') {
                $storedReference = $this->payments->attachProvider((int)$payment->id, $provider, $reference);
            } elseif (hash_equals($oldReference, $reference)) {
                $storedReference = true;
            } else {
                $storedReference = $this->payments->replaceProviderReference(
                    (int)$payment->id,
                    $provider,
                    $oldReference,
                    $reference
                );
            }
            if (!$storedReference) {
                return new \WP_Error('wpcb_payment_provider_reference', 'Payment provider reference could not be stored.');
            }
            $stored = $this->payments->find((int)$payment->id);
            if ($stored && !empty($result['checkout_url'])) {
                $stored->checkout_url = esc_url_raw((string)$result['checkout_url']);
            }
            return $stored;
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Apply a verified provider callback. No raw provider payload is persisted.
     */
    public function applyProviderEvent(
        string $provider,
        string $eventId,
        string $providerReference,
        string $eventType,
        int $amountMinor,
        string $currency
    ) {
        $provider = sanitize_key($provider);
        $eventId = sanitize_text_field($eventId);
        $providerReference = sanitize_text_field($providerReference);
        $eventType = sanitize_key($eventType);
        $currency = strtoupper(sanitize_text_field($currency));

        if ($provider === '' || $eventId === '' || $providerReference === '') {
            return new \WP_Error('wpcb_payment_event_invalid', 'Payment event identity is incomplete.');
        }
        $payment = $this->payments->findByProviderReference($provider, $providerReference);
        if (!$payment) {
            return new \WP_Error('wpcb_payment_reference_unknown', 'Payment reference is unknown.');
        }
        if ((int)$payment->amount_minor !== $amountMinor || !hash_equals((string)$payment->currency, $currency)) {
            return new \WP_Error('wpcb_payment_amount_mismatch', 'Payment amount or currency does not match the booking.');
        }
        if ($this->payments->eventExists($provider, $eventId)) {
            return $payment; // provider retries are idempotent.
        }

        $target = match ($eventType) {
            'paid' => PaymentStatus::PAID,
            'failed' => PaymentStatus::FAILED,
            'refunded' => PaymentStatus::REFUNDED,
            default => null,
        };
        if ($target === null) {
            return new \WP_Error('wpcb_payment_event_unsupported', 'Unsupported payment event.');
        }

        $current = (string)$payment->status;
        $allowed = [
            PaymentStatus::PENDING => [PaymentStatus::PAID, PaymentStatus::FAILED],
            PaymentStatus::REFUND_PENDING => [PaymentStatus::REFUNDED],
            PaymentStatus::PAID => [],
            PaymentStatus::FAILED => [],
            PaymentStatus::EXPIRED => [],
            PaymentStatus::REFUNDED => [],
        ];
        if ($target !== $current && !in_array($target, $allowed[$current] ?? [], true)) {
            return new \WP_Error('wpcb_payment_transition_invalid', 'Payment transition is not allowed.');
        }

        if ($target !== $current && !$this->payments->setStatus((int)$payment->id, $current, $target)) {
            return new \WP_Error('wpcb_payment_transition_race', 'Payment changed while the event was processed.');
        }
        if (!$this->payments->recordEvent((int)$payment->id, $provider, $eventId, $eventType)) {
            return $this->payments->find((int)$payment->id);
        }

        $booking = $this->bookings->find((int)$payment->booking_id);
        if ($booking) {
            $this->logScopeEvent($booking, 'payment_' . $target, 'Payment lifecycle updated', 'payment_provider');
        }
        return $this->payments->find((int)$payment->id);
    }

    public function canConfirm(int $bookingId): bool {
        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return false;
        }
        $type = $this->types->find((int)$booking->booking_type_id);
        if (!$type || (string)($type->payment_mode ?? 'free') !== 'required') {
            return true;
        }
        $payment = $this->paymentForBooking($bookingId);
        return $payment && (string)$payment->status === PaymentStatus::PAID;
    }

    public function expirePending(int $limit = 100): int {
        $count = 0;
        foreach ($this->payments->expiredPending($limit) as $payment) {
            if (!$this->payments->setStatus((int)$payment->id, PaymentStatus::PENDING, PaymentStatus::EXPIRED)) {
                continue;
            }
            $booking = $this->bookings->find((int)$payment->booking_id);
            if ($booking && (string)$booking->status === BookingStatus::RESERVED_UNCONFIRMED) {
                if (!empty($booking->series_id)) {
                    (new RecurringBookingService())->applyRemaining(
                        (int)$booking->id,
                        BookingStateMachine::RESERVATION_EXPIRED,
                        'payment',
                        'Series payment reservation expired'
                    );
                } else {
                    (new BookingTransitionService())->apply(
                        (int)$booking->id,
                        BookingStateMachine::RESERVATION_EXPIRED,
                        'payment',
                        'Payment reservation expired'
                    );
                }
            }
            ++$count;
        }
        return $count;
    }

    public function onBookingTransition(array $event, $booking): void {
        if (!$booking || empty($event['changed']) || !in_array(
            (string)($event['to'] ?? ''),
            [BookingStatus::CANCELLED, BookingStatus::REJECTED],
            true
        )) {
            return;
        }
        $payment = $this->paymentForBooking((int)$booking->id);
        if ($payment && (string)$payment->status === PaymentStatus::PAID) {
            $this->payments->setStatus((int)$payment->id, PaymentStatus::PAID, PaymentStatus::REFUND_PENDING);
            $this->logScopeEvent($booking, 'payment_refund_pending', 'Booking lifecycle requires payment refund');
        }
    }

    private function acquireLock(string $name, int $timeoutSeconds): bool {
        global $wpdb;
        return 1 === (int)$wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, max(0, $timeoutSeconds))
        );
    }

    private function releaseLock(string $name): void {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    public function refund(int $paymentId, PaymentAdapterInterface $adapter) {
        $payment = $this->payments->find($paymentId);
        if (!$payment || (string)$payment->status !== PaymentStatus::REFUND_PENDING) {
            return new \WP_Error('wpcb_payment_refund_invalid', 'Payment is not awaiting a refund.');
        }
        if ((string)$payment->provider !== $adapter->code()) {
            return new \WP_Error('wpcb_payment_provider_mismatch', 'Refund provider does not match the payment.');
        }
        $result = $adapter->refund([
            'payment_id' => (int)$payment->id,
            'payment_uuid' => (string)$payment->payment_uuid,
            'provider_reference' => (string)$payment->provider_reference,
            'amount_minor' => (int)$payment->amount_minor,
            'currency' => (string)$payment->currency,
        ]);
        if (is_wp_error($result)) {
            return $result;
        }
        return $this->applyProviderEvent(
            $adapter->code(),
            sanitize_text_field((string)($result['provider_event_id'] ?? '')),
            (string)$payment->provider_reference,
            'refunded',
            (int)$payment->amount_minor,
            (string)$payment->currency
        );
    }
    /**
     * @return array{owner:object,occurrence_count:int}|\WP_Error
     */
    private function paymentScope(object $booking): array|\WP_Error {
        if (empty($booking->series_id)) {
            return ['owner' => $booking, 'occurrence_count' => 1];
        }

        $seriesRepo = new BookingSeriesRepository();
        $series = $seriesRepo->find((int)$booking->series_id);
        $members = $series ? $seriesRepo->members((int)$series->id, 0) : [];
        if (!$series || !$members) {
            return new \WP_Error('wpcb_payment_series_missing', 'Recurring series payment scope is incomplete.');
        }

        return [
            'owner' => $members[0],
            'occurrence_count' => max(1, (int)$series->occurrence_count),
        ];
    }

    private function logScopeEvent(
        object $booking,
        string $context,
        string $note,
        string $actor = 'payment'
    ): void {
        $members = [$booking];
        if (!empty($booking->series_id)) {
            $seriesMembers = (new BookingSeriesRepository())->members((int)$booking->series_id, 0);
            if ($seriesMembers) {
                $members = $seriesMembers;
            }
        }
        foreach ($members as $member) {
            $this->bookings->logEvent(
                (int)$member->id,
                (string)$member->status,
                $context,
                $actor,
                $note
            );
        }
    }

}
