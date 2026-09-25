<?php
namespace Wpcb\VideoMeetings;

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingStatus;
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

    public function run(
        string $operation,
        int $bookingId,
        int $connectionId,
        string $expectedVersion = ''
    ): array {
        if ($bookingId < 1 || $connectionId < 1) {
            return ['ok'=>false,'message'=>'Meeting booking or connection is unavailable.'];
        }

        $lockName = 'wpcb_vidjob_' . substr(hash('sha256', $bookingId . '|' . $connectionId), 0, 48);
        global $wpdb;
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 5)) !== 1) {
            return ['ok'=>false,'message'=>'Meeting destination is busy; retrying later.'];
        }

        try {
            $booking = $this->bookings->find($bookingId);
            $connection = $this->connections->find($connectionId, true);
            if (!$booking || !$connection) {
                return ['ok'=>false,'message'=>'Meeting booking or connection is unavailable.'];
            }

            $desired = $this->shouldHaveMeeting($booking, $connectionId) ? 'upsert' : 'delete';
            $requested = $operation === 'delete' ? 'delete' : 'upsert';
            if ($desired !== $requested) {
                return ['ok'=>true,'message'=>'Obsolete meeting work skipped after desired-state reconciliation.'];
            }

            $currentVersion = self::desiredVersion($booking, $connection);
            if ($expectedVersion !== '' && !hash_equals($expectedVersion, $currentVersion)) {
                return ['ok'=>true,'message'=>'Obsolete meeting revision skipped after desired-state reconciliation.'];
            }

            if ($requested === 'upsert' && empty($connection->is_active)) {
                return ['ok'=>true,'message'=>'Obsolete meeting work skipped because the destination is inactive.'];
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
                    return ['ok'=>true,'message'=>'Meeting destination already has no persisted remote meeting.'];
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
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    public static function desiredVersion(object $booking, object $connection): string {
        return hash('sha256', wp_json_encode([
            'booking_id' => (int)$booking->id,
            'connection_id' => (int)$connection->id,
            'status' => (string)$booking->status,
            'slot_start' => (string)$booking->slot_start,
            'slot_end' => (string)$booking->slot_end,
            'updated_at' => (string)$booking->updated_at,
            'booking_type_id' => (int)$booking->booking_type_id,
            'provider' => (string)$connection->provider,
            'connection_updated_at' => (string)($connection->updated_at ?? ''),
        ]));
    }

    private function shouldHaveMeeting(object $booking, int $connectionId): bool {
        if ((string)$booking->status !== BookingStatus::CONFIRMED) {
            return false;
        }
        foreach ($this->connections->forBookingType((int)$booking->booking_type_id) as $connection) {
            if ((int)$connection->id === $connectionId && !empty($connection->is_active)) {
                return true;
            }
        }
        return false;
    }
}
