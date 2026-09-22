<?php
namespace Cemb\Sync;

class JobRepository {
    private string $jobsTable;
    private string $logTable;

    public function __construct() {
        global $wpdb;
        $this->jobsTable = $wpdb->prefix . 'cemb_sync_jobs';
        $this->logTable = $wpdb->prefix . 'cemb_sync_log';
    }

    public function enqueue(string $jobType, int $bookingId, array $payload = []): int {
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->jobsTable} WHERE booking_id = %d AND job_type = %s AND status IN ('pending','running') ORDER BY id DESC LIMIT 1",
            $bookingId,
            $jobType
        ));
        if ($existing) {
            return (int)$existing;
        }
        $wpdb->insert($this->jobsTable, [
            'booking_id' => $bookingId,
            'job_type' => $jobType,
            'payload_json' => wp_json_encode($payload),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => current_time('mysql'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $id = (int)$wpdb->insert_id;
        $this->log($id, $bookingId, 'info', 'Job angelegt: ' . $jobType);
        return $id;
    }

    public function nextPending(int $limit = 10): array {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->jobsTable} WHERE status = 'pending' AND available_at <= %s ORDER BY id ASC LIMIT %d",
            current_time('mysql'),
            $limit
        );
        return $wpdb->get_results($sql);
    }

    public function markRunning(int $jobId): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->jobsTable} SET status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %d",
            current_time('mysql'),
            $jobId
        ));
    }

    public function markDone(int $jobId, int $bookingId, string $message = ''): void {
        global $wpdb;
        $wpdb->update($this->jobsTable, [
            'status' => 'done',
            'last_error' => '',
            'updated_at' => current_time('mysql'),
        ], ['id' => $jobId]);
        $this->log($jobId, $bookingId, 'success', $message ?: 'Job abgeschlossen');
    }

    public function markFailed(int $jobId, int $bookingId, string $message, int $delayMinutes = 10): void {
        global $wpdb;
        $attempts = (int)$wpdb->get_var($wpdb->prepare("SELECT attempts FROM {$this->jobsTable} WHERE id = %d", $jobId));
        $status = $attempts >= 5 ? 'failed' : 'pending';
        $wpdb->update($this->jobsTable, [
            'status' => $status,
            'last_error' => $message,
            'available_at' => date('Y-m-d H:i:s', strtotime('+' . $delayMinutes . ' minutes', current_time('timestamp'))),
            'updated_at' => current_time('mysql'),
        ], ['id' => $jobId]);
        $this->log($jobId, $bookingId, $status === 'failed' ? 'error' : 'warning', $message);
    }

    public function pendingCount(): int {
        global $wpdb;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->jobsTable} WHERE status IN ('pending','running')");
    }

    public function recentLogs(int $limit = 50): array {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM {$this->logTable} ORDER BY id DESC LIMIT %d", $limit);
        return $wpdb->get_results($sql);
    }

    public function log(int $jobId, int $bookingId, string $level, string $message): void {
        global $wpdb;
        $wpdb->insert($this->logTable, [
            'job_id' => $jobId,
            'booking_id' => $bookingId,
            'level' => $level,
            'message' => $message,
            'created_at' => current_time('mysql'),
        ]);
    }
}
