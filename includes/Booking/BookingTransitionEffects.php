<?php
namespace Cemb\Booking;

use Cemb\Admin\Settings;
use Cemb\Mail\Mailer;
use Cemb\Sync\QueueService;
use Cemb\Tokens\TokenService;

/**
 * Central side effects for lifecycle transitions and domain events.
 */
final class BookingTransitionEffects {
    public function boot(): void {
        add_action('cemb_booking_transitioned', [$this, 'onTransition'], 10, 2);
        add_action('cemb_booking_event_recorded', [$this, 'onEvent'], 10, 2);
    }

    public function onTransition(array $transition, $booking): void {
        if (empty($transition['changed']) || !$booking) {
            return;
        }

        $bookingId = (int)$transition['booking_id'];
        $event = (string)$transition['event'];
        $target = (string)$transition['to'];
        $repo = new BookingRepository();
        $meta = $repo->getMeta($bookingId);
        $mailer = new Mailer();
        $settings = Settings::get();
        $bookingArray = (array)$booking;

        if ($target === BookingStatus::PENDING_APPROVAL) {
            $mailer->sendTemplateOnce($this->mailKey($bookingId, $event, $bookingArray), 'pending', $bookingArray, $meta, $this->actionLinks($bookingId), false);
        } elseif ($target === BookingStatus::CONFIRMED) {
            if (!empty($settings['icloud_sync_enabled'])) {
                $queue = new QueueService();
                $queue->enqueueCreate($bookingId);
                $queue->runNow();
            }
            $template = $event === BookingStateMachine::ADMIN_APPROVED ? 'approved' : 'confirmed';
            $mailer->sendTemplateOnce($this->mailKey($bookingId, $event, $bookingArray), $template, $bookingArray, $meta, $this->actionLinks($bookingId), true);
        } elseif ($target === BookingStatus::REJECTED) {
            $mailer->sendTemplateOnce($this->mailKey($bookingId, $event, $bookingArray), 'rejected', $bookingArray, $meta, [], false);
        } elseif ($target === BookingStatus::CANCELLED) {
            if (!empty($settings['icloud_sync_cancellations'])) {
                $queue = new QueueService();
                $queue->enqueueCancel($bookingId);
                $queue->runNow();
            }
            $mailer->sendTemplateOnce($this->mailKey($bookingId, $event, $bookingArray), 'cancelled', $bookingArray, $meta, [], false);
        }

        if ($target !== BookingStatus::EXPIRED) {
            $mailer->sendInternalOnce('mail:internal:' . $bookingId . ':transition:' . $event, $bookingArray, $meta);
        }
    }

    public function onEvent(array $event, $booking): void {
        if (empty($event['changed']) || !$booking || ($event['event'] ?? '') !== BookingTransitionService::RESCHEDULED) {
            return;
        }

        $bookingId = (int)$event['booking_id'];
        $repo = new BookingRepository();
        $meta = $repo->getMeta($bookingId);
        $settings = Settings::get();

        if (!empty($settings['icloud_sync_updates'])) {
            $queue = new QueueService();
            $queue->enqueueUpdate($bookingId);
            $queue->runNow();
        }

        $bookingArray = (array)$repo->find($bookingId);
        $mailer = new Mailer();
        $version = hash('sha256', (string)($bookingArray['slot_start'] ?? '') . '|' . (string)($bookingArray['slot_end'] ?? ''));
        $mailer->sendTemplateOnce('mail:user:' . $bookingId . ':rescheduled:' . $version, 'updated', $bookingArray, $meta, $this->actionLinks($bookingId), true);
        $mailer->sendInternalOnce('mail:internal:' . $bookingId . ':rescheduled:' . $version, $bookingArray, $meta);
    }

    private function mailKey(int $bookingId, string $event, array $booking): string {
        return 'mail:user:' . $bookingId . ':transition:' . $event . ':' . hash(
            'sha256',
            (string)($booking['status'] ?? '') . '|' . (string)($booking['slot_start'] ?? '') . '|' . (string)($booking['slot_end'] ?? '')
        );
    }

    private function actionLinks(int $bookingId): array {
        $tokens = new TokenService();
        $cancelToken = $tokens->create($bookingId, 'cancel', 60 * 24 * 30);
        $updateToken = $tokens->create($bookingId, 'update', 60 * 24 * 30);

        return [
            'cancel' => add_query_arg(
                ['cemb_action' => 'cancel', 'cemb_token' => rawurlencode($cancelToken)],
                home_url('/')
            ),
            'update' => add_query_arg(
                ['cemb_action' => 'update', 'cemb_token' => rawurlencode($updateToken)],
                home_url('/')
            ),
        ];
    }
}
