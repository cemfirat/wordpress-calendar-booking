<?php
namespace Wpcb\Sync;

use Wpcb\Booking\BookingRepository;
use Wpcb\Admin\Settings;
use Wpcb\Calendar\CalendarConnectionRepository;
use Wpcb\Calendar\ProviderSyncService;
use Wpcb\Webhooks\WebhookDispatcher;
use Wpcb\VideoMeetings\VideoMeetingJobRunner;
use Wpcb\Mail\EmailRetryJobRunner;

class QueueService {
    private JobRepository $jobs;
    private IcloudSyncService $sync;
    private BookingRepository $bookings;
    private CalendarConnectionRepository $connections;
    private ProviderSyncService $providerSync;
    private WebhookDispatcher $webhooks;
    private VideoMeetingJobRunner $videoMeetings;
    private EmailRetryJobRunner $emailRetries;

    public function __construct() {
        $this->jobs = new JobRepository();
        $this->sync = new IcloudSyncService();
        $this->bookings = new BookingRepository();
        $this->connections = new CalendarConnectionRepository();
        $this->providerSync = new ProviderSyncService();
        $this->webhooks = new WebhookDispatcher();
        $this->videoMeetings = new VideoMeetingJobRunner();
        $this->emailRetries = new EmailRetryJobRunner();
    }

    public function boot(): void {
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action('wpcb_sync_queue', [$this, 'processPending']);
        if (!wp_next_scheduled('wpcb_sync_queue')) {
            wp_schedule_event(time() + 300, 'five_minutes', 'wpcb_sync_queue');
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
        update_option('wpcb_sync_queue_last_run', \Wpcb\Support\Time::formatUtc(\Wpcb\Support\Time::nowUtc()), false);
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

        $jobIds = [];
        $resourceId = !empty($booking->resource_id) ? (int)$booking->resource_id : 0;
        $destinations = $resourceId > 0
            ? $this->connections->writeDestinationsForResource($resourceId, (int)$booking->booking_type_id)
            : $this->connections->writeDestinationsForBookingType((int)$booking->booking_type_id);
        foreach ($destinations as $connection) {
            $desiredVersion = hash('sha256', wp_json_encode([
                'booking_id' => $bookingId,
                'status' => (string)$booking->status,
                'slot_start' => (string)$booking->slot_start,
                'slot_end' => (string)$booking->slot_end,
                'updated_at' => (string)$booking->updated_at,
                'connection_id' => $connection->id,
                'provider' => $connection->provider,
                'remote_calendar_id' => $connection->remoteCalendarId,
                'resource_id' => $resourceId,
            ]));

            $operation = $jobType === 'cancel' ? 'cancel' : 'upsert';
            $idempotencyKey = 'calendar:provider:' . $operation . ':' . $bookingId . ':' . $connection->id . ':' . substr($desiredVersion, 0, 40);
            $jobIds[] = $this->jobs->enqueue(
                'provider_' . $jobType,
                $bookingId,
                [
                    'connection_id' => $connection->id,
                    'desired_version' => $desiredVersion,
                ],
                $idempotencyKey
            );
        }

        // Keep the imported 1.x iCloud path alive until #16 migrates it to
        // provider-neutral CalDAV connections.
        $settings = Settings::get();
        $meta = $this->bookings->getMeta($bookingId);
        $legacyEnabled = !empty($settings['icloud_sync_enabled']);
        if ($jobType === 'update') {
            $legacyEnabled = $legacyEnabled && !empty($settings['icloud_sync_updates']);
        } elseif ($jobType === 'cancel') {
            $legacyEnabled = $legacyEnabled && !empty($settings['icloud_sync_cancellations']);
        }
        if ($legacyEnabled) {
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
                'legacy' => 'icloud',
            ]));
            $operation = $jobType === 'cancel' ? 'cancel' : 'upsert';
            $jobIds[] = $this->jobs->enqueue(
                $jobType,
                $bookingId,
                [
                    'destination' => $destination,
                    'desired_version' => $desiredVersion,
                ],
                'calendar:legacy:' . $operation . ':' . $bookingId . ':' . substr($desiredVersion, 0, 48)
            );
        }

        $jobIds = array_values(array_filter(array_map('intval', $jobIds)));
        if ($jobIds) {
            $this->bookings->updateMeta($bookingId, 'sync_status', 'queued_' . $jobType);
            return $jobIds[0];
        }
        return 0;
    }

    private function workerId(): string {
        return substr(
            'wp:' . md5(home_url('/') . '|' . getmypid() . '|' . wp_generate_uuid4()),
            0,
            64
        );
    }

    private function runJob(object $job): array {
        $payload = json_decode((string)($job->payload_json ?? ''), true);
        $payload = is_array($payload) ? $payload : [];

        switch ((string)$job->job_type) {
            case 'provider_create':
                return $this->providerSync->run('create', (int)$job->booking_id, (int)($payload['connection_id'] ?? 0));
            case 'provider_update':
                return $this->providerSync->run('update', (int)$job->booking_id, (int)($payload['connection_id'] ?? 0));
            case 'provider_cancel':
                return $this->providerSync->run('cancel', (int)$job->booking_id, (int)($payload['connection_id'] ?? 0));
            case 'webhook_delivery':
                return $this->webhooks->dispatch($payload, (int)$job->booking_id);
            case EmailRetryJobRunner::JOB_TYPE:
                return $this->emailRetries->run($payload, (int)$job->booking_id);
            case 'video_create':
                return $this->videoMeetings->run('create', (int)$job->booking_id, (int)($payload['connection_id'] ?? 0));
            case 'video_update':
                return $this->videoMeetings->run('update', (int)$job->booking_id, (int)($payload['connection_id'] ?? 0));
            case 'video_delete':
                return $this->videoMeetings->run('delete', (int)$job->booking_id, (int)($payload['connection_id'] ?? 0));
            case 'create':
            case 'update':
                return $this->sync->syncBooking((int)$job->booking_id);
            case 'cancel':
                return $this->sync->cancelBooking((int)$job->booking_id);
            default:
                return ['ok' => false, 'message' => 'Unknown calendar job type.'];
        }
    }
}
