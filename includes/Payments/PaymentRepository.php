<?php
namespace Wpcb\Payments;

use Wpcb\Support\Time;

final class PaymentRepository {
    private string $table;
    private string $eventsTable;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_payments';
        $this->eventsTable = $wpdb->prefix . 'wpcb_payment_events';
    }

    public function createPending(int $bookingId, int $amountMinor, string $currency, ?string $expiresAt): int {
        global $wpdb;
        if ($bookingId < 1 || $amountMinor < 1 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return 0;
        }
        $existing = $this->forBooking($bookingId);
        if ($existing && in_array((string)$existing->status, [
            PaymentStatus::PENDING,
            PaymentStatus::PAID,
            PaymentStatus::REFUND_PENDING,
            PaymentStatus::REFUNDED,
        ], true)) {
            return (int)$existing->id;
        }
        $now = Time::formatUtc(Time::nowUtc());
        $ok = $wpdb->insert($this->table, [
            'payment_uuid' => wp_generate_uuid4(),
            'booking_id' => $bookingId,
            'provider' => '',
            'provider_reference' => null,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $ok === 1 ? (int)$wpdb->insert_id : 0;
    }

    public function find(int $id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id));
        return $row ?: null;
    }

    public function forBooking(int $bookingId): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE booking_id = %d ORDER BY id DESC LIMIT 1", $bookingId));
        return $row ?: null;
    }

    public function findByUuid(string $uuid): ?object {
        global $wpdb;
        $uuid = sanitize_text_field($uuid);
        if ($uuid === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE payment_uuid = %s LIMIT 1",
            $uuid
        ));
        return $row ?: null;
    }

    public function findByProviderReference(string $provider, string $reference): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE provider = %s AND provider_reference = %s LIMIT 1",
            sanitize_key($provider),
            sanitize_text_field($reference)
        ));
        return $row ?: null;
    }

    public function attachProvider(int $paymentId, string $provider, string $reference): bool {
        global $wpdb;
        $provider = sanitize_key($provider);
        $reference = sanitize_text_field($reference);
        if ($provider === '' || $reference === '') {
            return false;
        }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET provider = %s, provider_reference = %s, updated_at = %s
             WHERE id = %d AND status = 'pending' AND (provider = '' OR provider = %s)
             AND (provider_reference IS NULL OR provider_reference = %s)",
            $provider,
            $reference,
            Time::formatUtc(Time::nowUtc()),
            $paymentId,
            $provider,
            $reference
        ));
        return $updated === 1 || ($updated === 0 && ($p = $this->find($paymentId)) && (string)$p->provider === $provider && (string)$p->provider_reference === $reference);
    }

    public function replaceProviderReference(int $paymentId, string $provider, string $expectedReference, string $newReference): bool {
        global $wpdb;
        $provider = sanitize_key($provider);
        $expectedReference = sanitize_text_field($expectedReference);
        $newReference = sanitize_text_field($newReference);
        if ($paymentId < 1 || $provider === '' || $expectedReference === '' || $newReference === '') {
            return false;
        }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET provider_reference = %s, updated_at = %s
             WHERE id = %d AND status = %s AND provider = %s AND provider_reference = %s",
            $newReference,
            Time::formatUtc(Time::nowUtc()),
            $paymentId,
            PaymentStatus::PENDING,
            $provider,
            $expectedReference
        ));
        return $updated === 1 || (
            $updated === 0
            && ($payment = $this->find($paymentId))
            && (string)$payment->status === PaymentStatus::PENDING
            && (string)$payment->provider === $provider
            && (string)$payment->provider_reference === $newReference
        );
    }

    public function queueRefund(int $paymentId, int $amountMinor): bool {
        global $wpdb;
        if ($paymentId < 1 || $amountMinor < 1) {
            return false;
        }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET refund_pending_minor = refund_pending_minor + %d,
                 status = %s,
                 updated_at = %s
             WHERE id = %d
               AND status IN (%s, %s)
               AND refunded_minor + refund_pending_minor + %d <= amount_minor",
            $amountMinor,
            PaymentStatus::REFUND_PENDING,
            Time::formatUtc(Time::nowUtc()),
            $paymentId,
            PaymentStatus::PAID,
            PaymentStatus::REFUND_PENDING,
            $amountMinor
        ));
        return $updated === 1;
    }

    public function completeRefund(int $paymentId, int $amountMinor): bool {
        global $wpdb;
        if ($paymentId < 1 || $amountMinor < 1) {
            return false;
        }
        $payment = $this->find($paymentId);
        if (!$payment || (string)$payment->status !== PaymentStatus::REFUND_PENDING) {
            return false;
        }
        $refunded = (int)($payment->refunded_minor ?? 0);
        $pending = (int)($payment->refund_pending_minor ?? 0);
        $total = (int)$payment->amount_minor;
        if ($pending < $amountMinor || $refunded + $amountMinor > $total) {
            return false;
        }

        $nextRefunded = $refunded + $amountMinor;
        $nextPending = $pending - $amountMinor;
        $nextStatus = $nextRefunded >= $total
            ? PaymentStatus::REFUNDED
            : ($nextPending > 0 ? PaymentStatus::REFUND_PENDING : PaymentStatus::PAID);
        $now = Time::formatUtc(Time::nowUtc());

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET refunded_minor = %d,
                 refund_pending_minor = %d,
                 status = %s,
                 refunded_at = %s,
                 updated_at = %s
             WHERE id = %d
               AND status = %s
               AND refunded_minor = %d
               AND refund_pending_minor = %d",
            $nextRefunded,
            $nextPending,
            $nextStatus,
            $nextStatus === PaymentStatus::REFUNDED ? $now : (string)($payment->refunded_at ?? ''),
            $now,
            $paymentId,
            PaymentStatus::REFUND_PENDING,
            $refunded,
            $pending
        ));
        return $updated === 1;
    }

    public function syncRefundTotal(int $paymentId, int $totalRefundedMinor): bool {
        global $wpdb;
        $payment = $this->find($paymentId);
        if (!$payment || $totalRefundedMinor < 0 || $totalRefundedMinor > (int)$payment->amount_minor) {
            return false;
        }
        $currentRefunded = (int)($payment->refunded_minor ?? 0);
        if ($totalRefundedMinor < $currentRefunded) {
            return false;
        }
        $delta = $totalRefundedMinor - $currentRefunded;
        $currentPending = (int)($payment->refund_pending_minor ?? 0);
        if ($delta > $currentPending) {
            return false;
        }
        if ($delta === 0) {
            return true;
        }
        return $this->completeRefund($paymentId, $delta);
    }

    public function setStatus(int $paymentId, string $expected, string $status): bool {
        global $wpdb;
        if (!in_array($expected, PaymentStatus::all(), true) || !in_array($status, PaymentStatus::all(), true)) {
            return false;
        }
        $fields = [
            'status' => $status,
            'updated_at' => Time::formatUtc(Time::nowUtc()),
        ];
        if ($status === PaymentStatus::PAID) {
            $fields['paid_at'] = Time::formatUtc(Time::nowUtc());
        }
        if ($status === PaymentStatus::REFUNDED) {
            $fields['refunded_at'] = Time::formatUtc(Time::nowUtc());
        }
        return 1 === $wpdb->update($this->table, $fields, ['id' => $paymentId, 'status' => $expected]);
    }

    public function recordEvent(int $paymentId, string $provider, string $eventId, string $eventType): bool {
        global $wpdb;
        $eventId = sanitize_text_field($eventId);
        if ($paymentId < 1 || $eventId === '') {
            return false;
        }
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->eventsTable}
                (payment_id, provider, provider_event_id, event_type, created_at)
             VALUES (%d, %s, %s, %s, %s)",
            $paymentId,
            sanitize_key($provider),
            $eventId,
            sanitize_key($eventType),
            Time::formatUtc(Time::nowUtc())
        ));
        return $inserted === 1;
    }

    public function eventExists(string $provider, string $eventId): bool {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->eventsTable} WHERE provider = %s AND provider_event_id = %s LIMIT 1",
            sanitize_key($provider),
            sanitize_text_field($eventId)
        ));
    }

    public function expiredPending(int $limit = 100): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s
             ORDER BY expires_at ASC LIMIT %d",
            PaymentStatus::PENDING,
            Time::formatUtc(Time::nowUtc()),
            max(1, min(500, $limit))
        ));
    }

    public function recent(int $limit = 200): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, payment_uuid, booking_id, provider, amount_minor, refunded_minor,
                    refund_pending_minor, currency, status, expires_at, paid_at, refunded_at,
                    created_at, updated_at
             FROM {$this->table} ORDER BY id DESC LIMIT %d",
            max(1, min(500, $limit))
        ));
    }
}
