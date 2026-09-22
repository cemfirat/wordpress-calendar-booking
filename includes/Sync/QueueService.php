<?php
namespace Cemb\Sync;

use Cemb\Booking\BookingRepository;

class QueueService {
    private JobRepository $jobs;
    private IcloudSyncService $sync;
    private BookingRepository $bookings;

    public function __construct() {
        $this->jobs = new JobRepository();
        $this->sync = new IcloudSyncService();
        $this->bookings = new BookingRepository();
    }

    public function boot(): void {
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action('cemb_sync_queue', [$this, 'processPending']);
        if (!wp_next_scheduled('cemb_sync_queue')) {
            wp_schedule_event(time() + 300, 'five_minutes', 'cemb_sync_queue');
        }
    }

    public function schedules(array $schedules): array {
        if (empty($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 300,
                'display' => 'Alle 5 Minuten',
            ];
        }
        return $schedules;
    }

    public function enqueueCreate(int $bookingId): int {
        $this->bookings->updateMeta($bookingId, 'sync_status', 'queued_create');
        return $this->jobs->enqueue('create', $bookingId);
    }

    public function enqueueUpdate(int $bookingId): int {
        $this->bookings->updateMeta($bookingId, 'sync_status', 'queued_update');
        return $this->jobs->enqueue('update', $bookingId);
    }

    public function enqueueCancel(int $bookingId): int {
        $this->bookings->updateMeta($bookingId, 'sync_status', 'queued_cancel');
        return $this->jobs->enqueue('cancel', $bookingId);
    }

    public function processPending(): void {
        $items = $this->jobs->nextPending(10);
        foreach ($items as $job) {
            $this->jobs->markRunning((int)$job->id);
            try {
                $result = $this->runJob($job);
                if (!empty($result['ok'])) {
                    $this->jobs->markDone((int)$job->id, (int)$job->booking_id, (string)($result['message'] ?? 'Erfolgreich synchronisiert'));
                } else {
                    $this->jobs->markFailed((int)$job->id, (int)$job->booking_id, (string)($result['message'] ?? 'Synchronisierung fehlgeschlagen'));
                }
            } catch (\Throwable $e) {
                $this->jobs->markFailed((int)$job->id, (int)$job->booking_id, $e->getMessage());
            }
        }
    }

    public function runNow(int $limit = 10): void {
        $this->processPending();
    }

    private function runJob(object $job): array {
        switch ((string)$job->job_type) {
            case 'create':
            case 'update':
                return $this->sync->syncBooking((int)$job->booking_id);
            case 'cancel':
                return $this->sync->cancelBooking((int)$job->booking_id);
            default:
                return ['ok' => false, 'message' => 'Unbekannter Jobtyp'];
        }
    }
}
