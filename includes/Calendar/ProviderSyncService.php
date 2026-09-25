<?php
namespace Wpcb\Calendar;

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingStatus;

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

    public function run(
        string $operation,
        int $bookingId,
        int $connectionId,
        string $expectedVersion = ''
    ): array {
        if ($bookingId < 1 || $connectionId < 1) {
            return ['ok' => false, 'message' => 'Calendar connection or booking is unavailable.'];
        }

        $lockName = 'wpcb_caljob_' . substr(hash('sha256', $bookingId . '|' . $connectionId), 0, 48);
        global $wpdb;
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 5)) !== 1) {
            return ['ok' => false, 'message' => 'Calendar destination is busy; retrying later.'];
        }

        try {
            // Reload after acquiring the destination lock. A lifecycle transition
            // or routing edit may have happened while this job was waiting.
            $booking = $this->bookings->find($bookingId);
            $connection = $this->connections->find($connectionId);
            if (!$booking || !$connection) {
                return ['ok' => false, 'message' => 'Calendar connection or booking is unavailable.'];
            }

            $desired = $this->shouldHaveRemoteEvent($booking, $connectionId) ? 'upsert' : 'cancel';
            $requested = $operation === 'cancel' ? 'cancel' : 'upsert';
            if ($desired !== $requested) {
                return ['ok' => true, 'message' => 'Obsolete calendar work skipped after desired-state reconciliation.'];
            }

            $currentVersion = self::desiredVersion($booking, $connection);
            if ($expectedVersion !== '' && !hash_equals($expectedVersion, $currentVersion)) {
                return ['ok' => true, 'message' => 'Obsolete calendar revision skipped after desired-state reconciliation.'];
            }

            if ($requested === 'upsert' && !$connection->active) {
                return ['ok' => true, 'message' => 'Obsolete calendar work skipped because the destination is inactive.'];
            }

            $provider = $this->providers->get($connection->provider);
            if (!$provider instanceof CalendarSyncProviderInterface) {
                return ['ok' => false, 'message' => 'Calendar provider does not support write-back.'];
            }

            $bookingArray = (array)$booking;
            $meta = $this->bookings->getMeta($bookingId);
            $metaKey = $this->eventMetaKey($connection);
            $eventId = (string)($meta[$metaKey] ?? '');

            if ($operation === 'create' || $operation === 'update') {
                $result = $eventId !== ''
                    ? $provider->updateEvent($bookingArray, $meta, $connection, $eventId)
                    : $provider->createEvent($bookingArray, $meta, $connection);
            } elseif ($operation === 'cancel') {
                if ($eventId === '') {
                    return ['ok' => true, 'message' => 'Calendar destination already has no persisted remote event.'];
                }
                $result = $provider->cancelEvent($connection, $eventId);
            } else {
                return ['ok' => false, 'message' => 'Unknown calendar provider operation.'];
            }

            if (is_wp_error($result)) {
                return ['ok' => false, 'message' => $result->get_error_message()];
            }

            if (($operation === 'create' || $operation === 'update') && !empty($result['event_id'])) {
                if (!$this->bookings->updateMeta($bookingId, $metaKey, (string)$result['event_id'])) {
                    return [
                        'ok' => false,
                        'message' => 'Remote calendar event exists but its local reference could not be stored; retrying safely.',
                    ];
                }
            }
            if ($operation === 'cancel' && !empty($result['ok'])) {
                $this->bookings->updateMeta($bookingId, $metaKey, '');
            }

            return [
                'ok' => !empty($result['ok']),
                'message' => !empty($result['ok']) ? 'Calendar provider sync completed.' : 'Calendar provider sync failed.',
            ];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    public static function desiredVersion(object $booking, CalendarConnection $connection): string {
        return hash('sha256', wp_json_encode([
            'booking_id' => (int)$booking->id,
            'status' => (string)$booking->status,
            'slot_start' => (string)$booking->slot_start,
            'slot_end' => (string)$booking->slot_end,
            'updated_at' => (string)$booking->updated_at,
            'connection_id' => $connection->id,
            'provider' => $connection->provider,
            'remote_calendar_id' => $connection->remoteCalendarId,
            'resource_id' => !empty($booking->resource_id) ? (int)$booking->resource_id : 0,
        ]));
    }

    private function shouldHaveRemoteEvent(object $booking, int $connectionId): bool {
        if ((string)$booking->status !== BookingStatus::CONFIRMED) {
            return false;
        }
        $resourceId = !empty($booking->resource_id) ? (int)$booking->resource_id : 0;
        $destinations = $resourceId > 0
            ? $this->connections->writeDestinationsForResource($resourceId, (int)$booking->booking_type_id)
            : $this->connections->writeDestinationsForBookingType((int)$booking->booking_type_id);
        foreach ($destinations as $destination) {
            if ((int)$destination->id === $connectionId) {
                return true;
            }
        }
        return false;
    }

    private function eventMetaKey(CalendarConnection $connection): string {
        return 'provider_event_' . sanitize_key($connection->provider) . '_' . $connection->id;
    }
}
