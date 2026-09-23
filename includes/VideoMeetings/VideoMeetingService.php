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
        $connectionIds = [];
        if ($operation !== 'delete') {
            foreach ((new VideoMeetingConnectionRepository())->forBookingType((int)$booking->booking_type_id) as $connection) {
                $connectionIds[$connectionId] = $connectionId;
            }
        }
        if ($operation !== 'create') {
            foreach ((new VideoMeetingRepository())->forBooking((int)$booking->id) as $meeting) {
                $connectionIds[(int)$meeting->connection_id] = (int)$meeting->connection_id;
            }
        }
        if (!$connectionIds) return;

        $jobs = new JobRepository();
        $queued = false;
        foreach (array_values($connectionIds) as $connectionId) {
            $version = hash('sha256', wp_json_encode([
                'operation' => $operation,
                'booking_id' => (int)$booking->id,
                'connection_id' => $connectionId,
                'status' => (string)$booking->status,
                'slot_start' => (string)$booking->slot_start,
                'slot_end' => (string)$booking->slot_end,
                'updated_at' => (string)$booking->updated_at,
            ]));
            $id = $jobs->enqueue(
                'video_' . $operation,
                (int)$booking->id,
                ['connection_id' => $connectionId],
                'video:' . $operation . ':' . (int)$booking->id . ':' . $connectionId . ':' . substr($version, 0, 40)
            );
            $queued = $queued || $id > 0;
        }
        if ($queued) (new QueueService())->runNow();
    }
}
