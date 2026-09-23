<?php
namespace Wpcb\Webhooks;

final class WebhookDispatcher {
    private WebhookEndpointRepository $endpoints;
    private WebhookDeliveryRepository $deliveries;

    public function __construct(
        ?WebhookEndpointRepository $endpoints = null,
        ?WebhookDeliveryRepository $deliveries = null
    ) {
        $this->endpoints = $endpoints ?: new WebhookEndpointRepository();
        $this->deliveries = $deliveries ?: new WebhookDeliveryRepository();
    }

    public function dispatch(array $jobPayload, int $bookingId): array {
        $endpointId = (int)($jobPayload['endpoint_id'] ?? 0);
        $eventId = sanitize_text_field((string)($jobPayload['event_id'] ?? ''));
        $eventType = sanitize_text_field((string)($jobPayload['event_type'] ?? ''));
        $payload = $jobPayload['payload'] ?? null;
        if ($endpointId < 1 || $eventId === '' || !is_array($payload)) {
            return ['ok' => false, 'message' => 'Webhook job payload is invalid.'];
        }

        $endpoint = $this->endpoints->find($endpointId);
        if (!$endpoint || empty($endpoint->is_active)) {
            return ['ok' => true, 'message' => 'Webhook endpoint is disabled or removed.'];
        }
        $secret = $this->endpoints->secret($endpoint);
        if ($secret === null) {
            return ['ok' => false, 'message' => 'Webhook secret could not be decrypted.'];
        }

        $delivery = $this->deliveries->ensure($endpointId, $bookingId, $eventId, $eventType);
        $this->deliveries->markAttempt((int)$delivery->id);

        $body = wp_json_encode($payload);
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $response = wp_remote_post((string)$endpoint->url, [
            'timeout' => 8,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress-Calendar-Booking/' . WPCB_VERSION,
                'X-WPCB-Event' => $eventType,
                'X-WPCB-Event-ID' => $eventId,
                'X-WPCB-Delivery' => (string)$delivery->delivery_id,
                'X-WPCB-Timestamp' => $timestamp,
                'X-WPCB-Signature' => 'sha256=' . $signature,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            $this->deliveries->markFailed((int)$delivery->id, 0, $response->get_error_message());
            return ['ok' => false, 'message' => 'Webhook transport failed.'];
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $this->deliveries->markSent((int)$delivery->id, $code);
            return ['ok' => true, 'message' => 'Webhook delivered.'];
        }

        $this->deliveries->markFailed((int)$delivery->id, $code, 'Webhook returned HTTP ' . $code . '.');
        return ['ok' => false, 'message' => 'Webhook returned HTTP ' . $code . '.'];
    }
}
