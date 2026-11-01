<?php
namespace Wpcb\Booking;

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
        $bookingArray = (array)$booking;
        $seriesBookingIds = $this->seriesBookingIds($transition, $booking);
        $batch = ($transition['series_scope'] ?? '') === 'batch' && $seriesBookingIds !== [];
        $notify = !$batch || (int)($transition['series_owner_booking_id'] ?? 0) === $bookingId;
        $deliverySuffix = $batch
            ? 'series:' . sanitize_key((string)($transition['series_operation_id'] ?? ''))
            : (string)$bookingId;

        if ($target === BookingStatus::PENDING_APPROVAL) {
            if ($notify) $mailer->sendTemplateOnce(
                $batch ? 'mail:user:' . $deliverySuffix . ':pending' : $this->mailKey($bookingId, $event, $bookingArray),
                'pending',
                $bookingArray,
                $meta,
                $this->actionLinks($bookingId),
                false,
                $seriesBookingIds
            );
        } elseif ($target === BookingStatus::CONFIRMED) {
            $queue = new QueueService();
            if ($queue->enqueueCreate($bookingId) > 0) {
                $queue->runNow();
            }
            $template = $event === BookingStateMachine::ADMIN_APPROVED ? 'approved' : 'confirmed';
            if ($notify) $mailer->sendTemplateOnce(
                $batch ? 'mail:user:' . $deliverySuffix . ':' . $template : $this->mailKey($bookingId, $event, $bookingArray),
                $template,
                $bookingArray,
                $meta,
                $this->actionLinks($bookingId),
                true,
                $seriesBookingIds
            );
        } elseif ($target === BookingStatus::REJECTED) {
            if ($notify) $mailer->sendTemplateOnce(
                $batch ? 'mail:user:' . $deliverySuffix . ':rejected' : $this->mailKey($bookingId, $event, $bookingArray),
                'rejected',
                $bookingArray,
                $meta,
                [],
                false,
                $seriesBookingIds
            );
        } elseif ($target === BookingStatus::CANCELLED) {
            $queue = new QueueService();
            if ($queue->enqueueCancel($bookingId) > 0) {
                $queue->runNow();
            }
            if ($notify) $mailer->sendTemplateOnce(
                $batch ? 'mail:user:' . $deliverySuffix . ':cancelled' : $this->mailKey($bookingId, $event, $bookingArray),
                'cancelled',
                $bookingArray,
                $meta,
                [],
                !empty($booking->series_id),
                $seriesBookingIds
            );
        }

        if ($notify && $target !== BookingStatus::EXPIRED) {
            $mailer->sendInternalOnce(
                $batch ? 'mail:internal:' . $deliverySuffix . ':' . $event : 'mail:internal:' . $bookingId . ':transition:' . $event,
                $bookingArray,
                $meta,
                $seriesBookingIds
            );
        }
    }

    public function onEvent(array $event, $booking): void {
        if (empty($event['changed']) || !$booking || ($event['event'] ?? '') !== BookingTransitionService::RESCHEDULED) {
            return;
        }

        $bookingId = (int)$event['booking_id'];
        $repo = new BookingRepository();
        $meta = $repo->getMeta($bookingId);
        $seriesBookingIds = $this->seriesBookingIds($event, $booking);
        $batch = ($event['series_scope'] ?? '') === 'batch' && $seriesBookingIds !== [];
        $notify = !$batch || (int)($event['series_owner_booking_id'] ?? 0) === $bookingId;

        $queue = new QueueService();
        if ($queue->enqueueUpdate($bookingId) > 0) {
            $queue->runNow();
        }

        $bookingArray = (array)$repo->find($bookingId);
        $mailer = new Mailer();
        $version = hash('sha256', (string)($bookingArray['slot_start'] ?? '') . '|' . (string)($bookingArray['slot_end'] ?? ''));
        $operationId = sanitize_key((string)($event['series_operation_id'] ?? ''));
        if ($notify) $mailer->sendTemplateOnce(
            $batch ? 'mail:user:series:' . $operationId . ':updated' : 'mail:user:' . $bookingId . ':rescheduled:' . $version,
            'updated',
            $bookingArray,
            $meta,
            $this->actionLinks($bookingId),
            true,
            $seriesBookingIds
        );
        if ($notify) $mailer->sendInternalOnce(
            $batch ? 'mail:internal:series:' . $operationId . ':rescheduled' : 'mail:internal:' . $bookingId . ':rescheduled:' . $version,
            $bookingArray,
            $meta,
            $seriesBookingIds
        );
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

    private function seriesBookingIds(array $event, object $booking): array {
        if (empty($booking->series_id)) {
            return [];
        }
        if (($event['series_scope'] ?? '') !== 'batch') {
            return [(int)$booking->id];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($event['series_booking_ids'] ?? [])))));
        return array_slice($ids, 0, 24);
    }
}
