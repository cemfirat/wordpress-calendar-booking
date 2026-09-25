<?php
namespace Wpcb\VideoMeetings;

use Wpcb\Booking\BookingStatus;
use Wpcb\Booking\BookingTransitionService;
use Wpcb\Sync\JobRepository;
use Wpcb\Sync\QueueService;

final class VideoMeetingService {
    public function boot(): void {
        add_action('wpcb_booking_transitioned', [$this, 'onTransition'], 5, 2);
        add_action('wpcb_booking_event_recorded', [$this, 'onEvent'], 5, 2);
    }

    public function onTransition(array $transition, $booking): void {
        if (empty($transition['changed']) || !$booking) return;
        $target = (string)($transition['to'] ?? '');
        if ($target === BookingStatus::CONFIRMED) {
            $this->enqueue('create', $booking);
        } elseif ($target === BookingStatus::CANCELLED) {
            $this->enqueue('delete', $booking);
        }
    }

    public function onEvent(array $event, $booking): void {
        if (empty($event['changed']) || !$booking || ($event['event'] ?? '') !== BookingTransitionService::RESCHEDULED) return;
        $this->enqueue('update', $booking);
    }

    private function enqueue(string $operation, object $booking): void {
        $connections = new VideoMeetingConnectionRepository();
        $meetings = new VideoMeetingRepository();
        $jobs = new JobRepository();

        $currentIds = [];
        foreach ($connections->forBookingType((int)$booking->booking_type_id) as $connection) {
            $currentIds[(int)$connection->id] = (int)$connection->id;
        }

        $knownIds = array_values($currentIds);
        foreach ($meetings->forBooking((int)$booking->id) as $meeting) {
            $knownIds[] = (int)$meeting->connection_id;
        }
        $knownIds = array_merge(
            $knownIds,
            $jobs->connectionIdsForBooking(
                (int)$booking->id,
                ['video_create', 'video_update', 'video_delete']
            )
        );
        $knownIds = array_values(array_unique(array_filter(array_map('intval', $knownIds))));
        if (!$knownIds) {
            return;
        }

        $queued = false;
        foreach ($knownIds as $connectionId) {
            $connection = $connections->find($connectionId, false);
            if (!$connection) {
                continue;
            }

            $shouldExist = (string)$booking->status === BookingStatus::CONFIRMED
                && !empty($connection->is_active)
                && isset($currentIds[$connectionId]);
            $effectiveOperation = $shouldExist
                ? ($operation === 'create' ? 'create' : 'update')
                : 'delete';
            $version = VideoMeetingJobRunner::desiredVersion($booking, $connection);

            $id = $jobs->enqueue(
                'video_' . $effectiveOperation,
                (int)$booking->id,
                [
                    'connection_id' => $connectionId,
                    'desired_version' => $version,
                ],
                'video:' . ($shouldExist ? 'upsert' : 'delete') . ':' . (int)$booking->id . ':' . $connectionId . ':' . substr($version, 0, 40)
            );
            $queued = $queued || $id > 0;
        }
        if ($queued) {
            (new QueueService())->runNow();
        }
    }
}
