<?php
namespace Wpcb\Sync;

use Wpcb\Support\Time;

class JobRepository {
    private string $jobsTable;
    private string $logTable;
    private const MAX_ATTEMPTS = 5;

    public function __construct() {
        global $wpdb;
        $this->jobsTable = $wpdb->prefix . 'wpcb_sync_jobs';
        $this->logTable = $wpdb->prefix . 'wpcb_sync_log';
    }

    public function enqueue(string $jobType, int $bookingId, array $payload = [], string $idempotencyKey = ''): int {
        global $wpdb;

        if ($idempotencyKey !== '') {
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->jobsTable} WHERE idempotency_key = %s LIMIT 1",
                    $idempotencyKey
                )
            );
            if ($existing) {
                return (int)$existing;
            }
        }

        $now = Time::formatUtc(Time::nowUtc());
        $inserted = $wpdb->insert($this->jobsTable, [
            'booking_id' => $bookingId,
            'job_type' => $jobType,
            'payload_json' => wp_json_encode($payload),
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => '',
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === false && $idempotencyKey !== '') {
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->jobsTable} WHERE idempotency_key = %s LIMIT 1",
                    $idempotencyKey
                )
            );
            return $existing ? (int)$existing : 0;
        }

        $id = (int)$wpdb->insert_id;
        if ($id > 0) {
            $this->log($id, $bookingId, 'info', 'Job queued: ' . $jobType);
        }
        return $id;
    }

    /**
     * Atomically claim pending work and stale running work for one worker.
     */
    public function claim(string $workerId, int $limit = 10, int $leaseSeconds = 120): array {
        global $wpdb;
        $workerId = substr(preg_replace('/[^A-Za-z0-9._:-]/', '', $workerId), 0, 64);
        if ($workerId === '') {
            return [];
        }

        $now = Time::formatUtc(Time::nowUtc());

        // A worker that disappears on its final allowed attempt must not leave
        // an immortal running row behind.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->jobsTable}
                 SET status = 'failed',
                     last_error = 'Worker lease expired after the maximum retry count.',
                     lease_owner = NULL,
                     lease_expires_at = NULL,
                     updated_at = %s
                 WHERE status = 'running'
                   AND attempts >= %d
                   AND lease_expires_at IS NOT NULL
                   AND lease_expires_at < %s",
                $now,
                self::MAX_ATTEMPTS,
                $now
            )
        );

        $leaseUntil = Time::formatUtc(Time::nowUtc()->modify('+' . max(30, $leaseSeconds) . ' seconds'));
        $limit = max(1, min(100, $limit));

        $candidateIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$this->jobsTable}
                 WHERE attempts < %d
                   AND (
                     (status = 'pending' AND available_at <= %s)
                     OR (status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at < %s)
                   )
                 ORDER BY available_at ASC, id ASC
                 LIMIT %d",
                self::MAX_ATTEMPTS,
                $now,
                $now,
                $limit * 3
            )
        );

        $claimed = [];
        foreach ($candidateIds as $jobId) {
            if (count($claimed) >= $limit) {
                break;
            }
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->jobsTable}
                     SET status = 'running',
                         attempts = attempts + 1,
                         lease_owner = %s,
                         lease_expires_at = %s,
                         updated_at = %s
                     WHERE id = %d
                       AND attempts < %d
                       AND (
                         (status = 'pending' AND available_at <= %s)
                         OR (status = 'running' AND lease_expires_at IS NOT NULL AND lease_expires_at < %s)
                       )",
                    $workerId,
                    $leaseUntil,
                    $now,
                    (int)$jobId,
                    self::MAX_ATTEMPTS,
                    $now,
                    $now
                )
            );

            if ($updated === 1) {
                $job = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$this->jobsTable} WHERE id = %d AND lease_owner = %s LIMIT 1",
                        (int)$jobId,
                        $workerId
                    )
                );
                if ($job) {
                    $claimed[] = $job;
                }
            }
        }

        return $claimed;
    }

    public function markDone(int $jobId, int $bookingId, string $workerId, string $message = ''): bool {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->jobsTable}
                 SET status = 'done',
                     last_error = '',
                     lease_owner = NULL,
                     lease_expires_at = NULL,
                     updated_at = %s
                 WHERE id = %d AND status = 'running' AND lease_owner = %s",
                $now,
                $jobId,
                $workerId
            )
        );
        if ($updated === 1) {
            $this->log($jobId, $bookingId, 'success', $message ?: 'Job completed');
            return true;
        }
        return false;
    }

    public function markFailed(int $jobId, int $bookingId, string $workerId, string $message): bool {
        global $wpdb;
        $attempts = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT attempts FROM {$this->jobsTable} WHERE id = %d AND lease_owner = %s LIMIT 1",
                $jobId,
                $workerId
            )
        );
        if ($attempts < 1) {
            return false;
        }

        $terminal = $attempts >= self::MAX_ATTEMPTS;
        $status = $terminal ? 'failed' : 'pending';
        $delayMinutes = $terminal ? 0 : min(60, (int)pow(2, max(0, $attempts - 1)) * 2);
        $available = Time::formatUtc(Time::nowUtc()->modify('+' . $delayMinutes . ' minutes'));
        $error = $this->sanitizeError($message);

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->jobsTable}
                 SET status = %s,
                     last_error = %s,
                     available_at = %s,
                     lease_owner = NULL,
                     lease_expires_at = NULL,
                     updated_at = %s
                 WHERE id = %d AND status = 'running' AND lease_owner = %s",
                $status,
                $error,
                $available,
                Time::formatUtc(Time::nowUtc()),
                $jobId,
                $workerId
            )
        );

        if ($updated === 1) {
            $this->log($jobId, $bookingId, $terminal ? 'error' : 'warning', $error);
            return true;
        }
        return false;
    }

    public function deferPending(int $jobId, int $delaySeconds): bool {
        global $wpdb;
        if ($jobId < 1) {
            return false;
        }

        $delaySeconds = max(1, min(DAY_IN_SECONDS, $delaySeconds));
        $available = Time::formatUtc(Time::nowUtc()->modify('+' . $delaySeconds . ' seconds'));
        return 1 === $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->jobsTable}
                 SET available_at = %s,
                     updated_at = %s
                 WHERE id = %d
                   AND status = 'pending'
                   AND attempts = 0",
                $available,
                Time::formatUtc(Time::nowUtc()),
                $jobId
            )
        );
    }

    /**
     * Batch-load jobs by their non-secret idempotency keys.
     *
     * @return array<string,object>
     */
    public function findByIdempotencyKeys(array $keys): array {
        global $wpdb;
        $keys = array_values(array_unique(array_filter(array_map(
            static function ($key): string {
                $value = (string)$key;
                return preg_match('/^[A-Za-z0-9:_-]{1,190}$/', $value) === 1 ? $value : '';
            },
            $keys
        ))));
        $keys = array_slice($keys, 0, 500);
        if (!$keys) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, booking_id, job_type, status, attempts, last_error, idempotency_key,
                        lease_owner, lease_expires_at, available_at, created_at, updated_at
                 FROM {$this->jobsTable}
                 WHERE idempotency_key IN ({$placeholders})",
                ...$keys
            )
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row->idempotency_key] = $row;
        }
        return $out;
    }

    public function pendingCount(): int {
        global $wpdb;
        return (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->jobsTable} WHERE status IN ('pending','running')"
        );
    }

    public function statusCounts(): array {
        global $wpdb;
        $counts = ['pending' => 0, 'running' => 0, 'failed' => 0];
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS total FROM {$this->jobsTable} WHERE status IN ('pending','running','failed') GROUP BY status"
        );
        foreach ($rows as $row) {
            if (array_key_exists((string)$row->status, $counts)) {
                $counts[(string)$row->status] = (int)$row->total;
            }
        }
        return $counts;
    }

    public function staleLeaseCount(): int {
        global $wpdb;
        $now = Time::formatUtc(Time::nowUtc());
        return (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->jobsTable}
                 WHERE status = 'running'
                   AND lease_expires_at IS NOT NULL
                   AND lease_expires_at < %s",
                $now
            )
        );
    }

    public function recentLogs(int $limit = 50): array {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->logTable} ORDER BY id DESC LIMIT %d",
            max(1, $limit)
        );
        return $wpdb->get_results($sql);
    }

    public function log(int $jobId, int $bookingId, string $level, string $message): void {
        global $wpdb;
        $wpdb->insert($this->logTable, [
            'job_id' => $jobId,
            'booking_id' => $bookingId,
            'level' => $level,
            'message' => $this->sanitizeError($message),
            'created_at' => Time::formatUtc(Time::nowUtc()),
        ]);
    }

    private function sanitizeError(string $message): string {
        $message = wp_strip_all_tags($message);
        $message = preg_replace('/Authorization:\s*[^\s]+/i', 'Authorization: [redacted]', $message);
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._~-]+/i', 'Bearer [redacted]', $message);
        return mb_substr((string)$message, 0, 1500);
    }
}
