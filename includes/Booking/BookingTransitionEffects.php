<?php
namespace Wpcb\Booking;

use Wpcb\Admin\Settings;
use Wpcb\Mail\Mailer;
use Wpcb\Sync\QueueService;
use Wpcb\Tokens\TokenService;

/**
 * Central side effects for lifecycle transitions and domain events.
 */
final class BookingTransitionEffects {
    public function boot(): void {
        add_action('wpcb_booking_transitioned', [$this, 'onTransition'], 10, 2);
        add_action('wpcb_booking_event_recorded', [$this, 'onEvent'], 10, 2);
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
        $seriesSecondary = !empty($booking->series_id) && (int)($booking->series_occurrence ?? 0) > 0;

        if ($target === BookingStatus::PENDING_APPROVAL) {
            if (!$seriesSecondary) $mailer->sendTemplateOnce($this->mailKey($bookingId, $event, $bookingArray), 'pending', $bookingArray, $meta, $this->actionLinks($bookingId), false);
        } elseif ($target === BookingStatus::CONFIRMED) {
            $queue = new QueueService();
            if ($queue->enqueueCreate($bookingId) > 0) {
                $queue->runNow();
            }
            $template = $event === BookingStateMachine::ADMIN_APPROVED ? 'approved' : 'confirmed';
            if (!$seriesSecondary) $mailer->sendTemplateOnce($this->mailKey($bookingId, $event, $bookingArray), $template, $bookingArray, $meta, $this->actionLinks($bookingId), true);
        } elseif ($target === BookingStatus::REJECTED) {
            if (!$seriesSecondary) $mailer->sendTemplateOnce($this->mailKey($bookingId, $event, $bookingArray), 'rejected', $bookingArray, $meta, [], false);
        } elseif ($target === BookingStatus::CANCELLED) {
            $queue = new QueueService();
            if ($queue->enqueueCancel($bookingId) > 0) {
                $queue->runNow();
            }
            if (!$seriesSecondary) $mailer->sendTemplateOnce($this->mailKey($bookingId, $event, $bookingArray), 'cancelled', $bookingArray, $meta, [], false);
        }

        if ($target !== BookingStatus::EXPIRED && !$seriesSecondary) {
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
        $seriesSecondary = !empty($booking->series_id) && (int)($booking->series_occurrence ?? 0) > 0;

        $queue = new QueueService();
        if ($queue->enqueueUpdate($bookingId) > 0) {
            $queue->runNow();
        }

        $bookingArray = (array)$repo->find($bookingId);
        $mailer = new Mailer();
        $version = hash('sha256', (string)($bookingArray['slot_start'] ?? '') . '|' . (string)($bookingArray['slot_end'] ?? ''));
        if (!$seriesSecondary) {
            $mailer->sendTemplateOnce('mail:user:' . $bookingId . ':rescheduled:' . $version, 'updated', $bookingArray, $meta, $this->actionLinks($bookingId), true);
            $mailer->sendInternalOnce('mail:internal:' . $bookingId . ':rescheduled:' . $version, $bookingArray, $meta);
        }
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
                ['wpcb_action' => 'cancel', 'wpcb_token' => rawurlencode($cancelToken)],
                home_url('/')
            ),
            'update' => add_query_arg(
                ['wpcb_action' => 'update', 'wpcb_token' => rawurlencode($updateToken)],
                home_url('/')
            ),
        ];
    }
}
