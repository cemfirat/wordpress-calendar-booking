<?php
namespace Cemb\Calendar;

use Cemb\Booking\BookingRepository;

final class ProviderSyncService {
    private CalendarConnectionRepository $connections;
    private ProviderRegistry $providers;
    private BookingRepository $bookings;

    public function __construct(
        ?CalendarConnectionRepository $connections = null,
        ?ProviderRegistry $providers = null,
        ?BookingRepository $bookings = null
    ) {
        $this->connections = $connections ?: new CalendarConnectionRepository();
        $this->providers = $providers ?: new ProviderRegistry();
        $this->bookings = $bookings ?: new BookingRepository();
    }

    public function run(string $operation, int $bookingId, int $connectionId): array {
        $booking = $this->bookings->find($bookingId);
        $connection = $this->connections->find($connectionId);
        if (!$booking || !$connection || !$connection->active) {
            return ['ok' => false, 'message' => 'Calendar connection or booking is unavailable.'];
        }

        $provider = $this->providers->get($connection->provider);
        if (!$provider instanceof CalendarSyncProviderInterface) {
            return ['ok' => false, 'message' => 'Calendar provider does not support write-back.'];
        }

        $bookingArray = (array)$booking;
        $meta = $this->bookings->getMeta($bookingId);
        $metaKey = $this->eventMetaKey($connection);
        $eventId = (string)($meta[$metaKey] ?? '');

        if ($operation === 'create') {
            // A retried create with an already persisted remote ID becomes an update.
            $result = $eventId !== ''
                ? $provider->updateEvent($bookingArray, $meta, $connection, $eventId)
                : $provider->createEvent($bookingArray, $meta, $connection);
        } elseif ($operation === 'update') {
            $result = $eventId !== ''
                ? $provider->updateEvent($bookingArray, $meta, $connection, $eventId)
                : $provider->createEvent($bookingArray, $meta, $connection);
        } elseif ($operation === 'cancel') {
            $result = $provider->cancelEvent($connection, $eventId);
        } else {
            return ['ok' => false, 'message' => 'Unknown calendar provider operation.'];
        }

        if (is_wp_error($result)) {
            return ['ok' => false, 'message' => $result->get_error_message()];
        }

        if (($operation === 'create' || $operation === 'update') && !empty($result['event_id'])) {
            $this->bookings->updateMeta($bookingId, $metaKey, (string)$result['event_id']);
        }
        if ($operation === 'cancel' && !empty($result['ok'])) {
            $this->bookings->updateMeta($bookingId, $metaKey, '');
        }

        return [
            'ok' => !empty($result['ok']),
            'message' => !empty($result['ok']) ? 'Calendar provider sync completed.' : 'Calendar provider sync failed.',
        ];
    }

    private function eventMetaKey(CalendarConnection $connection): string {
        return 'provider_event_' . sanitize_key($connection->provider) . '_' . $connection->id;
    }
}
