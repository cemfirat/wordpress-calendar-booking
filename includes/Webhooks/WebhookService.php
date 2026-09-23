<?php
namespace Wpcb\Webhooks;

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingStatus;
use Wpcb\Booking\BookingTransitionService;
use Wpcb\Reliability\DeliveryRepository;
use Wpcb\Support\Time;

final class WebhookService {
    private WebhookEndpointRepository $endpoints;
    private WebhookJobRepository $jobs;
    private BookingRepository $bookings;
    private DeliveryRepository $deliveries;

    public function __construct(
        ?WebhookEndpointRepository $endpoints = null,
        ?WebhookJobRepository $jobs = null,
        ?BookingRepository $bookings = null,
        ?DeliveryRepository $deliveries = null
    ) {
        $this->endpoints = $endpoints ?: new WebhookEndpointRepository();
        $this->jobs = $jobs ?: new WebhookJobRepository();
        $this->bookings = $bookings ?: new BookingRepository();
        $this->deliveries = $deliveries ?: new DeliveryRepository();
    }

    public function boot(): void {
        add_action('wpcb_booking_created', [$this, 'bookingCreated'], 10, 1);
        add_action('wpcb_booking_transitioned', [$this, 'bookingTransitioned'], 10, 2);
        add_action('wpcb_booking_event_recorded', [$this, 'bookingEventRecorded'], 10, 2);
        add_action('wpcb_webhook_queue', [$this, 'processPending']);
        if (!wp_next_scheduled('wpcb_webhook_queue')) {
            wp_schedule_event(time() + 180, 'five_minutes', 'wpcb_webhook_queue');
        }
    }

    public function bookingCreated($booking): void {
        if (is_object($booking)) {
            $this->dispatch('booking.created', $booking);
        }
    }

    public function bookingTransitioned(array $result, $booking): void {
        if (empty($result['changed']) || !is_object($booking)) {
            return;
        }
        $event = '';
        switch ((string)$booking->status) {
            case BookingStatus::CONFIRMED:
                $event = 'booking.confirmed';
                break;
            case BookingStatus::PENDING_APPROVAL:
                $event = 'booking.pending_approval';
                break;
            case BookingStatus::REJECTED:
                $event = 'booking.rejected';
                break;
            case BookingStatus::CANCELLED:
                $event = 'booking.cancelled';
                break;
        }
        if ($event !== '') {
            $this->dispatch($event, $booking);
        }
    }

    public function bookingEventRecorded(array $result, $booking): void {
        if (!empty($result['changed'])
            && (string)($result['event'] ?? '') === BookingTransitionService::RESCHEDULED
            && is_object($booking)
        ) {
            $this->dispatch('booking.rescheduled', $booking);
        }
    }

    public function dispatch(string $eventType, object $booking): string {
        if (!in_array($eventType, WebhookEndpointRepository::EVENTS, true)) {
            return '';
        }
        $eventId = wp_generate_uuid4();
        $payload = $this->payload($eventId, $eventType, $booking);
        foreach ($this->endpoints->subscribed($eventType) as $endpoint) {
            $this->jobs->enqueue(
                $eventId,
                (int)$endpoint->id,
                (int)$booking->id,
                $eventType,
                $payload
            );
        }
        return $eventId;
    }

    public function processPending(int $limit = 10): void {
        $worker = substr('webhook:' . md5(home_url('/') . '|' . getmypid() . '|' . wp_generate_uuid4()), 0, 64);
        foreach ($this->jobs->claim($worker, $limit, 300) as $job) {
            $endpoint = $this->endpoints->find((int)$job->endpoint_id);
            if (!$endpoint || empty($endpoint->is_active)) {
                $this->jobs->markFailed((int)$job->id, $worker, 0, 'Webhook endpoint is unavailable or disabled.');
                continue;
            }
            $secret = $this->endpoints->secret($endpoint);
            if ($secret === null) {
                $this->jobs->markFailed((int)$job->id, $worker, 0, 'Webhook secret could not be decrypted.');
                continue;
            }

            $deliveryKey = 'webhook:' . (int)$endpoint->id . ':' . (string)$job->event_id;
            $delivery = $this->deliveries->begin(
                (int)$job->booking_id,
                $deliveryKey,
                'webhook',
                (string)$job->event_type,
                'integration',
                'http'
            );
            if (empty($delivery['should_run']) && (string)($delivery['status'] ?? '') === 'sent') {
                $this->jobs->markSent((int)$job->id, $worker, 200);
                continue;
            }
            $deliveryId = (int)($delivery['id'] ?? 0);
            if ($deliveryId > 0) {
                // webhook_jobs owns the lease. The delivery ledger is diagnostic
                // and may contain a stale "sending" state after a hard crash.
                $this->deliveries->markSending($deliveryId);
            }

            $body = (string)$job->payload_json;
            $timestamp = time();
            $response = wp_safe_remote_post((string)$endpoint->url, [
                'timeout' => 10,
                'redirection' => 0,
                'limit_response_size' => 65536,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'WordPress-Calendar-Booking/' . WPCB_VERSION,
                    'X-WPCB-Event' => (string)$job->event_type,
                    'X-WPCB-Delivery' => (string)$job->event_id,
                    'X-WPCB-Timestamp' => (string)$timestamp,
                    'X-WPCB-Signature' => WebhookSigner::sign($secret, $timestamp, $body),
                ],
                'body' => $body,
            ]);

            if (is_wp_error($response)) {
                $message = $response->get_error_message();
                $this->jobs->markFailed((int)$job->id, $worker, 0, $message);
                if ($deliveryId > 0) {
                    $this->deliveries->markFailed($deliveryId, $message, 'webhook_transport');
                }
                continue;
            }

            $code = (int)wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                $this->jobs->markSent((int)$job->id, $worker, $code);
                if ($deliveryId > 0) {
                    $this->deliveries->markSent($deliveryId);
                }
                continue;
            }

            $message = 'Webhook endpoint returned HTTP ' . $code . '.';
            $this->jobs->markFailed((int)$job->id, $worker, $code, $message);
            if ($deliveryId > 0) {
                $this->deliveries->markFailed($deliveryId, $message, 'webhook_http_' . $code);
            }
        }
    }

    private function payload(string $eventId, string $eventType, object $booking): array {
        return [
            'schema_version' => '1',
            'event_id' => $eventId,
            'event' => $eventType,
            'created_at' => Time::formatUtc(Time::nowUtc()),
            'booking' => [
                'id' => (int)$booking->id,
                'uuid' => (string)$booking->booking_uuid,
                'booking_type_id' => (int)$booking->booking_type_id,
                'resource_id' => !empty($booking->resource_id) ? (int)$booking->resource_id : null,
                'status' => (string)$booking->status,
                'slot_start' => (string)$booking->slot_start,
                'slot_end' => (string)$booking->slot_end,
                'party_size' => max(1, (int)($booking->party_size ?? 1)),
                'contact' => [
                    'name' => (string)($booking->full_name ?? ''),
                    'email' => (string)($booking->email ?? ''),
                    'phone' => (string)($booking->phone ?? ''),
                ],
            ],
        ];
    }
}
