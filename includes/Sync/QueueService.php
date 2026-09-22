<?php
namespace Cemb\Sync;

use Cemb\Booking\BookingRepository;
use Cemb\Admin\Settings;

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
        return $this->enqueueCalendarJob('create', $bookingId);
    }

    public function enqueueUpdate(int $bookingId): int {
        return $this->enqueueCalendarJob('update', $bookingId);
    }

    public function enqueueCancel(int $bookingId): int {
        return $this->enqueueCalendarJob('cancel', $bookingId);
    }

    public function processPending(int $limit = 10): void {
        $worker = $this->workerId();
        $items = $this->jobs->claim($worker, $limit, 600);
        foreach ($items as $job) {
            try {
                $result = $this->runJob($job);
                if (!empty($result['ok'])) {
                    $this->jobs->markDone(
                        (int)$job->id,
                        (int)$job->booking_id,
                        $worker,
                        (string)($result['message'] ?? 'Calendar sync completed')
                    );
                } else {
                    $this->jobs->markFailed(
                        (int)$job->id,
                        (int)$job->booking_id,
                        $worker,
                        (string)($result['message'] ?? 'Calendar sync failed')
                    );
                }
            } catch (\Throwable $e) {
                $this->jobs->markFailed((int)$job->id, (int)$job->booking_id, $worker, $e->getMessage());
            }
        }
    }

    public function runNow(int $limit = 10): void {
        $this->processPending($limit);
    }

    private function enqueueCalendarJob(string $jobType, int $bookingId): int {
        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return 0;
        }

        $settings = Settings::get();
        $meta = $this->bookings->getMeta($bookingId);
        $destination = (string)($meta['icloud_calendar_url'] ?? $settings['icloud_sync_target_calendar_url'] ?? '');
        if ($jobType === 'cancel' && !empty($meta['icloud_event_url'])) {
            $destination = (string)$meta['icloud_event_url'];
        }

        $desiredVersion = hash('sha256', wp_json_encode([
            'booking_id' => $bookingId,
            'status' => (string)$booking->status,
            'slot_start' => (string)$booking->slot_start,
            'slot_end' => (string)$booking->slot_end,
            'updated_at' => (string)$booking->updated_at,
            'destination' => $destination,
        ]));

        $operation = $jobType === 'cancel' ? 'cancel' : 'upsert';
        $idempotencyKey = 'calendar:' . $operation . ':' . $bookingId . ':' . substr($desiredVersion, 0, 48);
        $this->bookings->updateMeta($bookingId, 'sync_status', 'queued_' . $jobType);

        return $this->jobs->enqueue(
            $jobType,
            $bookingId,
            [
                'destination' => $destination,
                'desired_version' => $desiredVersion,
            ],
            $idempotencyKey
        );
    }

    private function workerId(): string {
        return substr(
            'wp:' . md5(home_url('/') . '|' . getmypid() . '|' . wp_generate_uuid4()),
            0,
            64
        );
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
