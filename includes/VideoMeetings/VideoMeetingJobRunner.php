<?php
namespace Wpcb\VideoMeetings;

use Wpcb\Booking\BookingRepository;
use Wpcb\Mail\SpecialNotificationMailer;

final class VideoMeetingJobRunner {
    private VideoMeetingConnectionRepository $connections;
    private VideoMeetingRepository $meetings;
    private VideoMeetingProviderRegistry $providers;
    private BookingRepository $bookings;

    public function __construct() {
        $this->connections = new VideoMeetingConnectionRepository();
        $this->meetings = new VideoMeetingRepository();
        $this->providers = new VideoMeetingProviderRegistry();
        $this->bookings = new BookingRepository();
    }

    public function run(string $operation, int $bookingId, int $connectionId): array {
        $booking = $this->bookings->find($bookingId);
        $connection = $this->connections->find($connectionId, true);
        if (!$booking || !$connection || ($operation !== 'delete' && empty($connection->is_active))) {
            return ['ok' => false, 'message' => 'Meeting booking or connection is unavailable.'];
        }
        $provider = $this->providers->get((string)$connection->provider);
        if (!$provider) return ['ok'=>false,'message'=>'Unsupported meeting provider.'];

        $existing = $this->meetings->find($bookingId, $connectionId);
        $bookingArray = (array)$booking;

        if ($operation === 'create') {
            if ($existing && (string)$existing->status === 'active' && (string)$existing->remote_id !== '') {
                return ['ok'=>true,'message'=>'Meeting already exists.'];
            }
            $result = $provider->create($bookingArray, $connection);
        } elseif ($operation === 'update') {
            if (!$existing || (string)$existing->status === 'deleted' || (string)$existing->remote_id === '') {
                $result = $provider->create($bookingArray, $connection);
            } else {
                $result = $provider->update((array)$existing, $bookingArray, $connection);
            }
        } elseif ($operation === 'delete') {
            if (!$existing || (string)$existing->status === 'deleted') {
                return ['ok'=>true,'message'=>'Meeting already deleted.'];
            }
            $result = $provider->delete((array)$existing, $connection);
            if (!empty($result['ok'])) {
                $this->meetings->markDeleted($bookingId, $connectionId);
                return ['ok'=>true,'message'=>'Meeting deleted.'];
            }
            $this->meetings->markError($bookingId, $connectionId, (string)$connection->provider, (string)($result['message'] ?? 'Meeting deletion failed.'));
            return $result;
        } else {
            return ['ok'=>false,'message'=>'Unknown meeting operation.'];
        }

        if (empty($result['ok'])) {
            $this->meetings->markError($bookingId, $connectionId, (string)$connection->provider, (string)($result['message'] ?? 'Meeting provider failed.'));
            return $result;
        }

        $remoteId = (string)($result['remote_id'] ?? ($existing->remote_id ?? ''));
        $joinUrl = (string)($result['join_url'] ?? ($existing->join_url ?? ''));
        if ($remoteId === '' || ($operation === 'create' && $joinUrl === '')) {
            $this->meetings->markError($bookingId, $connectionId, (string)$connection->provider, 'Meeting provider returned incomplete meeting data.');
            return ['ok'=>false,'message'=>'Meeting provider returned incomplete meeting data.'];
        }

        $this->meetings->upsert($bookingId, $connectionId, (string)$connection->provider, $remoteId, $joinUrl, 'active');
        if ($joinUrl !== '') {
            $mailer = new SpecialNotificationMailer();
            $mailer->sendVideoReady($bookingId, $connectionId, 'customer');
            $mailer->sendVideoReady($bookingId, $connectionId, 'admin');
        }
        return ['ok'=>true,'message'=>'Meeting synchronized.'];
    }

}
