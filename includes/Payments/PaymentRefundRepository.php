<?php
namespace Wpcb\Payments;

use Wpcb\Support\Time;

final class PaymentRefundRepository {
    private string $table;
    private string $paymentsTable;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wpcb_payment_refunds';
        $this->paymentsTable = $wpdb->prefix . 'wpcb_payments';
    }

    public function find(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
            $id
        )) ?: null;
    }

    public function findByUuid(string $uuid): ?object {
        global $wpdb;
        $uuid = sanitize_text_field($uuid);
        if ($uuid === '') return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE refund_uuid = %s LIMIT 1",
            $uuid
        )) ?: null;
    }

    public function findByProviderRefund(string $provider, string $providerRefundId): ?object {
        global $wpdb;
        $provider = sanitize_key($provider);
        $providerRefundId = sanitize_text_field($providerRefundId);
        if ($provider === '' || $providerRefundId === '') return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE provider = %s AND provider_refund_id = %s LIMIT 1",
            $provider,
            $providerRefundId
        )) ?: null;
    }

    public function activeForPayment(int $paymentId): ?object {
        global $wpdb;
        return $wpdb->get_row($this->activeQuery($paymentId) . ' LIMIT 1') ?: null;
    }

    public function activeForPaymentList(int $paymentId): array {
        global $wpdb;
        return $wpdb->get_results($this->activeQuery($paymentId)) ?: [];
    }

    private function activeQuery(int $paymentId): string {
        global $wpdb;
        $statuses = PaymentRefundStatus::activeStatuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        return $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE payment_id = %d AND status IN ({$placeholders})
             ORDER BY CASE status
                 WHEN 'requires_action' THEN 0 WHEN 'uncertain' THEN 1
                 WHEN 'submitting' THEN 2 ELSE 3 END, id ASC",
            $paymentId,
            ...$statuses
        );
    }

    public function latestForPayment(int $paymentId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE payment_id = %d ORDER BY id DESC LIMIT 1",
            $paymentId
        )) ?: null;
    }

    /**
     * Atomically reserve queued refund money for one provider attempt.
     *
     * @return object|\WP_Error
     */
    public function beginAttempt(object $payment, string $provider, int $amountMinor) {
        global $wpdb;
        $paymentId = (int)($payment->id ?? 0);
        $provider = sanitize_key($provider);
        $currency = strtoupper(sanitize_text_field((string)($payment->currency ?? '')));
        if ($paymentId < 1 || $provider === '' || $amountMinor < 1 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return new \WP_Error('wpcb_refund_attempt_invalid', 'Refund attempt is invalid.');
        }
        if ($this->activeForPayment($paymentId)) {
            return new \WP_Error('wpcb_refund_attempt_active', 'A provider refund attempt is already active.');
        }

        $refundUuid = wp_generate_uuid4();
        $idempotencyKey = 'wpcb-refund-' . $paymentId . '-' . $refundUuid;
        $now = Time::formatUtc(Time::nowUtc());
        $transaction = false;

        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                return new \WP_Error('wpcb_refund_attempt_transaction', 'Refund attempt could not start a transaction.');
            }
            $transaction = true;

            $moved = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->paymentsTable}
                 SET refund_pending_minor = refund_pending_minor - %d,
                     refund_inflight_minor = refund_inflight_minor + %d,
                     status = %s,
                     updated_at = %s
                 WHERE id = %d
                   AND status = %s
                   AND refund_pending_minor >= %d
                   AND refunded_minor + refund_pending_minor + refund_inflight_minor <= amount_minor",
                $amountMinor,
                $amountMinor,
                PaymentStatus::REFUND_PENDING,
                $now,
                $paymentId,
                PaymentStatus::REFUND_PENDING,
                $amountMinor
            ));
            if ($moved !== 1) {
                $wpdb->query('ROLLBACK');
                $transaction = false;
                return new \WP_Error('wpcb_refund_attempt_race', 'Queued refund amount changed before provider submission.');
            }

            $inserted = $wpdb->insert($this->table, [
                'refund_uuid' => $refundUuid,
                'payment_id' => $paymentId,
                'provider' => $provider,
                'idempotency_key' => $idempotencyKey,
                'provider_refund_id' => null,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => PaymentRefundStatus::SUBMITTING,
                'failure_reason' => null,
                'last_provider_event_created_at' => 0,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted !== 1 || (int)$wpdb->insert_id < 1) {
                $wpdb->query('ROLLBACK');
                $transaction = false;
                return new \WP_Error('wpcb_refund_attempt_storage', 'Refund attempt could not be stored.');
            }
            $id = (int)$wpdb->insert_id;

            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                $transaction = false;
                return new \WP_Error('wpcb_refund_attempt_commit', 'Refund attempt could not be committed.');
            }
            $transaction = false;
            return $this->find($id) ?: new \WP_Error('wpcb_refund_attempt_storage', 'Refund attempt could not be reloaded.');
        } finally {
            if ($transaction) {
                $wpdb->query('ROLLBACK');
            }
        }
    }

    public function markUncertain(int $attemptId, string $reason = '', string $providerRefundId = ''): bool {
        global $wpdb;
        $attempt = $this->find($attemptId);
        if (!$attempt || !in_array((string)$attempt->status, PaymentRefundStatus::activeStatuses(), true)) {
            return false;
        }
        $reason = sanitize_text_field($reason);
        $providerRefundId = sanitize_text_field($providerRefundId);
        $existingId = (string)($attempt->provider_refund_id ?? '');
        if ($existingId !== '' && $providerRefundId !== '' && !hash_equals($existingId, $providerRefundId)) {
            return false;
        }
        return false !== $wpdb->update(
            $this->table,
            [
                'status' => PaymentRefundStatus::UNCERTAIN,
                'provider_refund_id' => $existingId !== '' ? $existingId : ($providerRefundId !== '' ? $providerRefundId : null),
                'failure_reason' => $reason !== '' ? substr($reason, 0, 190) : null,
                'updated_at' => Time::formatUtc(Time::nowUtc()),
            ],
            ['id' => $attemptId]
        );
    }

    /**
     * Apply a verified/current provider status and move money exactly once.
     *
     * @return array{attempt:object,payment:object,changed:bool,stale:bool}|\WP_Error
     */
    public function applyProviderStatus(
        int $attemptId,
        string $providerRefundId,
        string $status,
        string $failureReason = '',
        int $providerEventCreatedAt = 0,
        bool $currentSnapshot = false
    ) {
        global $wpdb;
        $providerRefundId = sanitize_text_field($providerRefundId);
        $status = sanitize_key($status);
        $failureReason = sanitize_text_field($failureReason);
        $providerEventCreatedAt = max(0, $providerEventCreatedAt);
        if (!in_array($status, PaymentRefundStatus::providerStatuses(), true)) {
            return new \WP_Error('wpcb_refund_status_invalid', 'Refund provider status is invalid.');
        }

        $attempt = $this->find($attemptId);
        if (!$attempt) {
            return new \WP_Error('wpcb_refund_attempt_missing', 'Refund attempt does not exist.');
        }
        $existingProviderId = (string)($attempt->provider_refund_id ?? '');
        if ($existingProviderId !== '' && $providerRefundId !== '' && !hash_equals($existingProviderId, $providerRefundId)) {
            return new \WP_Error('wpcb_refund_identity_mismatch', 'Refund provider identity does not match the stored attempt.');
        }
        if ($providerRefundId === '') {
            $providerRefundId = $existingProviderId;
        }

        $lastEvent = (int)($attempt->last_provider_event_created_at ?? 0);
        // A current GET was read under the payment lock. Its triggering event
        // can be old; event creation time is not the refund object's version.
        if (!$currentSnapshot && $providerEventCreatedAt > 0 && $lastEvent > $providerEventCreatedAt) {
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->paymentsTable} WHERE id = %d LIMIT 1",
                (int)$attempt->payment_id
            ));
            return ['attempt'=>$attempt, 'payment'=>$payment, 'changed'=>false, 'stale'=>true];
        }

        $currentStatus = (string)$attempt->status;
        if ($currentStatus === PaymentRefundStatus::SUCCEEDED && $status !== $currentStatus
            && !$currentSnapshot && $providerEventCreatedAt <= $lastEvent
        ) {
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->paymentsTable} WHERE id = %d LIMIT 1",
                (int)$attempt->payment_id
            ));
            return ['attempt'=>$attempt, 'payment'=>$payment, 'changed'=>false, 'stale'=>true];
        }
        if (in_array($currentStatus, PaymentRefundStatus::terminalStatuses(), true)) {
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->paymentsTable} WHERE id = %d LIMIT 1",
                (int)$attempt->payment_id
            ));
            if ($currentStatus === $status && $providerEventCreatedAt > $lastEvent) {
                $wpdb->update($this->table, [
                    'last_provider_event_created_at' => $providerEventCreatedAt,
                    'updated_at' => Time::formatUtc(Time::nowUtc()),
                ], ['id'=>$attemptId]);
                $attempt = $this->find($attemptId) ?: $attempt;
            }
            return ['attempt'=>$attempt, 'payment'=>$payment, 'changed'=>false, 'stale'=>$currentStatus !== $status];
        }

        $transaction = false;
        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                return new \WP_Error('wpcb_refund_status_transaction', 'Refund status could not start a transaction.');
            }
            $transaction = true;

            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->paymentsTable} WHERE id = %d LIMIT 1",
                (int)$attempt->payment_id
            ));
            if (!$payment) {
                $wpdb->query('ROLLBACK');
                $transaction = false;
                return new \WP_Error('wpcb_payment_missing', 'Payment no longer exists.');
            }

            $amount = (int)$attempt->amount_minor;
            $refunded = (int)($payment->refunded_minor ?? 0);
            $queued = (int)($payment->refund_pending_minor ?? 0);
            $inflight = (int)($payment->refund_inflight_minor ?? 0);
            $nextRefunded = $refunded;
            $nextQueued = $queued;
            $nextInflight = $inflight;
            $nextPaymentStatus = (string)$payment->status;

            $oldBucket = $currentStatus === PaymentRefundStatus::SUCCEEDED ? 'completed' : 'inflight';
            $newBucket = $status === PaymentRefundStatus::SUCCEEDED
                ? 'completed'
                : (in_array($status, PaymentRefundStatus::terminalStatuses(), true) ? 'queued' : 'inflight');
            if ($oldBucket !== $newBucket) {
                if ($oldBucket === 'completed') $nextRefunded -= $amount;
                else $nextInflight -= $amount;
                if ($newBucket === 'completed') $nextRefunded += $amount;
                elseif ($newBucket === 'queued') $nextQueued += $amount;
                else $nextInflight += $amount;
            }
            if ($amount < 1 || min($nextRefunded, $nextQueued, $nextInflight) < 0
                || $nextRefunded + $nextQueued + $nextInflight > (int)$payment->amount_minor
            ) {
                $wpdb->query('ROLLBACK');
                $transaction = false;
                return new \WP_Error('wpcb_refund_amount_mismatch', 'Provider refund does not match its reserved allocation.');
            }
            $nextPaymentStatus = $nextRefunded >= (int)$payment->amount_minor
                ? PaymentStatus::REFUNDED
                : (($nextQueued + $nextInflight) > 0 ? PaymentStatus::REFUND_PENDING : PaymentStatus::PAID);

            if ($nextRefunded !== $refunded || $nextQueued !== $queued || $nextInflight !== $inflight
                || $nextPaymentStatus !== (string)$payment->status
            ) {
                $now = Time::formatUtc(Time::nowUtc());
                $refundedAtSql = $nextPaymentStatus === PaymentStatus::REFUNDED
                    ? ', refunded_at = %s' : ', refunded_at = NULL';
                $args = [
                    $nextRefunded,
                    $nextQueued,
                    $nextInflight,
                    $nextPaymentStatus,
                    $now,
                ];
                if ($nextPaymentStatus === PaymentStatus::REFUNDED) {
                    $args[] = $now;
                }
                array_push(
                    $args,
                    (int)$payment->id,
                    $refunded,
                    $queued,
                    $inflight,
                    (string)$payment->status
                );
                $changedPayment = $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->paymentsTable}
                     SET refunded_minor = %d,
                         refund_pending_minor = %d,
                         refund_inflight_minor = %d,
                         status = %s,
                         updated_at = %s{$refundedAtSql}
                     WHERE id = %d
                       AND refunded_minor = %d
                       AND refund_pending_minor = %d
                       AND refund_inflight_minor = %d
                       AND status = %s",
                    ...$args
                ));
                if ($changedPayment !== 1) {
                    $wpdb->query('ROLLBACK');
                    $transaction = false;
                    return new \WP_Error('wpcb_refund_status_race', 'Payment refund accounting changed concurrently.');
                }
            }

            $now = Time::formatUtc(Time::nowUtc());
            $complete = $status === PaymentRefundStatus::SUCCEEDED
                || in_array($status, PaymentRefundStatus::terminalStatuses(), true);
            $attemptFields = [
                'provider_refund_id' => $providerRefundId !== '' ? $providerRefundId : null,
                'status' => $status,
                'failure_reason' => $failureReason !== '' ? substr($failureReason, 0, 190) : null,
                'last_provider_event_created_at' => max($lastEvent, $providerEventCreatedAt),
                'updated_at' => $now,
                'completed_at' => $complete
                    ? ($currentStatus === $status ? ($attempt->completed_at ?? $now) : $now)
                    : null,
            ];
            $updatedAttempt = $wpdb->update(
                $this->table,
                $attemptFields,
                ['id'=>$attemptId, 'status'=>$currentStatus]
            );
            if ($updatedAttempt === false || ($currentStatus !== $status && $updatedAttempt !== 1)) {
                $wpdb->query('ROLLBACK');
                $transaction = false;
                return new \WP_Error('wpcb_refund_status_storage', 'Refund provider status could not be stored.');
            }

            if ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                $transaction = false;
                return new \WP_Error('wpcb_refund_status_commit', 'Refund provider status could not be committed.');
            }
            $transaction = false;

            $freshAttempt = $this->find($attemptId);
            $freshPayment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->paymentsTable} WHERE id = %d LIMIT 1",
                (int)$attempt->payment_id
            ));
            return [
                'attempt'=>$freshAttempt ?: $attempt,
                'payment'=>$freshPayment ?: $payment,
                'changed'=>$currentStatus !== $status,
                'stale'=>false,
            ];
        } finally {
            if ($transaction) {
                $wpdb->query('ROLLBACK');
            }
        }
    }

    /**
     * A definite pre-creation provider rejection returns the reserved amount to
     * the unsubmitted queue so an administrator can retry intentionally.
     *
     * @return array|\WP_Error
     */
    public function rejectSubmission(int $attemptId, string $reason = '') {
        return $this->applyProviderStatus(
            $attemptId,
            '',
            PaymentRefundStatus::FAILED,
            $reason,
            0
        );
    }

    public function recentForPayment(int $paymentId, int $limit = 20): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE payment_id = %d ORDER BY id DESC LIMIT %d",
            $paymentId,
            max(1, min(100, $limit))
        )) ?: [];
    }
}
