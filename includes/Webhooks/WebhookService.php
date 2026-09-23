<?php
namespace Wpcb\Webhooks;

use Wpcb\Booking\BookingStatus;
use Wpcb\Sync\JobRepository;
use Wpcb\Support\Time;

final class WebhookService {
    private WebhookEndpointRepository $endpoints;
    private JobRepository $jobs;

    public function __construct(
        ?WebhookEndpointRepository $endpoints = null,
        ?JobRepository $jobs = null
    ) {
        $this->endpoints = $endpoints ?: new WebhookEndpointRepository();
        $this->jobs = $jobs ?: new JobRepository();
    }

    public function boot(): void {
        add_action('wpcb_booking_created', [$this, 'onCreated'], 10, 1);
        add_action('wpcb_booking_transitioned', [$this, 'onTransition'], 20, 2);
        add_action('wpcb_booking_event_recorded', [$this, 'onEvent'], 20, 2);
    }

    public function onCreated($booking): void {
        if ($booking) {
            $this->enqueue('booking.created', $booking, 'create');
        }
    }

    public function onTransition(array $transition, $booking): void {
        if (empty($transition['changed']) || !$booking) {
            return;
        }
        $status = (string)($booking->status ?? '');
        $eventType = match ($status) {
            BookingStatus::CONFIRMED => 'booking.confirmed',
            BookingStatus::REJECTED => 'booking.rejected',
            BookingStatus::CANCELLED => 'booking.cancelled',
            default => '',
        };
        if ($eventType !== '') {
            $this->enqueue($eventType, $booking, (string)($transition['event'] ?? 'transition'));
        }
    }

    public function onEvent(array $event, $booking): void {
        if (!empty($event['changed']) && $booking && ($event['event'] ?? '') === 'rescheduled') {
            $this->enqueue('booking.rescheduled', $booking, 'rescheduled');
        }
    }

    private function enqueue(string $eventType, object $booking, string $cause): void {
        if (!in_array($eventType, WebhookEndpointRepository::EVENTS, true)) {
            return;
        }

        $eventId = hash('sha256', implode('|', [
            'v1',
            $eventType,
            (string)($booking->booking_uuid ?? ''),
            $cause,
            (string)($booking->status ?? ''),
            (string)($booking->slot_start ?? ''),
            (string)($booking->slot_end ?? ''),
            (string)($booking->updated_at ?? ''),
        ]));

        $payload = [
            'schema_version' => 1,
            'event_id' => $eventId,
            'type' => $eventType,
            'occurred_at' => Time::formatUtc(Time::nowUtc()),
            'data' => [
                'booking' => [
                    'id' => (int)$booking->id,
                    'uuid' => (string)$booking->booking_uuid,
                    'booking_type_id' => (int)$booking->booking_type_id,
                    'resource_id' => !empty($booking->resource_id) ? (int)$booking->resource_id : null,
                    'status' => (string)$booking->status,
                    'slot_start' => (string)$booking->slot_start,
                    'slot_end' => (string)$booking->slot_end,
                    'party_size' => max(1, (int)($booking->party_size ?? 1)),
                ],
            ],
        ];

        foreach ($this->endpoints->activeForEvent($eventType) as $endpoint) {
            $this->jobs->enqueue(
                'webhook_delivery',
                (int)$booking->id,
                [
                    'endpoint_id' => (int)$endpoint->id,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'payload' => $payload,
                ],
                'webhook:' . (int)$endpoint->id . ':' . $eventId
            );
        }
    }
}
