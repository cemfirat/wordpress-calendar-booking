<?php
namespace Wpcb\VideoMeetings;

use Wpcb\Booking\BookingRepository;
use Wpcb\Reliability\DeliveryRepository;
use Wpcb\Admin\Settings;

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
            $this->sendReadyMail($bookingArray, $connectionId, $joinUrl);
        }
        return ['ok'=>true,'message'=>'Meeting synchronized.'];
    }

    private function sendReadyMail(array $booking, int $connectionId, string $joinUrl): void {
        $bookingId = (int)($booking['id'] ?? 0);
        $email = sanitize_email((string)($booking['email'] ?? ''));
        if ($bookingId < 1 || !is_email($email)) return;

        $deliveries = new DeliveryRepository();
        $key = 'mail:user:' . $bookingId . ':video-ready:' . $connectionId . ':' . hash('sha256', $joinUrl);
        $delivery = $deliveries->begin($bookingId, $key, 'email', 'video_meeting_ready', 'customer', 'wp_mail');
        if (empty($delivery['should_run']) || !$deliveries->markSending((int)$delivery['id'])) return;

        $sent = wp_mail(
            $email,
            __('Your video meeting is ready', 'wordpress-calendar-booking'),
            sprintf(
                '<p>%s</p><p><a href="%s">%s</a></p>',
                esc_html__('Your video meeting link is ready:', 'wordpress-calendar-booking'),
                esc_url($joinUrl),
                esc_html__('Join video meeting', 'wordpress-calendar-booking')
            ),
            ['Content-Type: text/html; charset=UTF-8']
        );
        if ($sent) $deliveries->markSent((int)$delivery['id']);
        else $deliveries->markFailed((int)$delivery['id'], 'wp_mail returned false before accepting the meeting message.', 'wp_mail_false');

        $settings = Settings::get();
        $recipients = array_values(array_filter(array_map('sanitize_email', array_map('trim', explode(',', (string)($settings['notification_emails'] ?? ''))))));
        if (empty($settings['notifications_enabled']) || !$recipients) return;

        $internalKey = 'mail:internal:' . $bookingId . ':video-ready:' . $connectionId . ':' . hash('sha256', $joinUrl);
        $internal = $deliveries->begin($bookingId, $internalKey, 'email', 'video_meeting_ready', 'internal', 'wp_mail');
        if (empty($internal['should_run']) || !$deliveries->markSending((int)$internal['id'])) return;
        $internalSent = wp_mail(
            $recipients,
            __('Video meeting ready', 'wordpress-calendar-booking'),
            sprintf(
                '<p>%s</p><p><a href="%s">%s</a></p>',
                esc_html(sprintf(__('Video meeting for booking #%d is ready.', 'wordpress-calendar-booking'), $bookingId)),
                esc_url($joinUrl),
                esc_html__('Open video meeting', 'wordpress-calendar-booking')
            ),
            ['Content-Type: text/html; charset=UTF-8']
        );
        if ($internalSent) $deliveries->markSent((int)$internal['id']);
        else $deliveries->markFailed((int)$internal['id'], 'wp_mail returned false before accepting the internal meeting message.', 'wp_mail_false');
    }
}
