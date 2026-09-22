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
            $mailer->sendTemplate('pending', $bookingArray, $meta, $this->actionLinks($bookingId), false);
        } elseif ($target === BookingStatus::CONFIRMED) {
            $queue = new QueueService();
            $queue->enqueueCreate($bookingId);
            $queue->runNow();
            $template = $event === BookingStateMachine::ADMIN_APPROVED ? 'approved' : 'confirmed';
            $mailer->sendTemplate($template, $bookingArray, $meta, $this->actionLinks($bookingId), true);
        } elseif ($target === BookingStatus::REJECTED) {
            $mailer->sendTemplate('rejected', $bookingArray, $meta, [], false);
        } elseif ($target === BookingStatus::CANCELLED) {
            if (!empty($settings['icloud_sync_cancellations'])) {
                $queue = new QueueService();
                $queue->enqueueCancel($bookingId);
                $queue->runNow();
            }
            $mailer->sendTemplate('cancelled', $bookingArray, $meta, [], false);
        }

        if ($target !== BookingStatus::EXPIRED) {
            $mailer->sendInternal($bookingArray, $meta);
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
        $mailer->sendTemplate('updated', $bookingArray, $meta, $this->actionLinks($bookingId), true);
        $mailer->sendInternal($bookingArray, $meta);
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
