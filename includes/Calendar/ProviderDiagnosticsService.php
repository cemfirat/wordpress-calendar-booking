<?php
namespace Cemb\Calendar;

use Cemb\Support\Time;

final class ProviderDiagnosticsService {
    private CalendarConnectionRepository $connections;
    private ProviderRegistry $providers;

    public function __construct(
        ?CalendarConnectionRepository $connections = null,
        ?ProviderRegistry $providers = null
    ) {
        $this->connections = $connections ?: new CalendarConnectionRepository();
        $this->providers = $providers ?: new ProviderRegistry();
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array {
        $rows = [];
        foreach ($this->connections->all(false) as $connection) {
            $rows[] = $this->describe($connection);
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function describe(CalendarConnection $connection): array {
        $provider = $this->providers->get($connection->provider);
        $capabilities = $provider ? ProviderCapabilities::normalize($provider->capabilities()) : [];
        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'provider_id' => $connection->provider,
            'provider_label' => $provider ? $provider->label() : $connection->provider,
            'calendar' => $connection->remoteCalendarId,
            'active' => $connection->active,
            'blocks_availability' => $connection->blocksAvailability,
            'receives_bookings' => $connection->receivesBookings,
            'capabilities' => $capabilities,
            'credential_state' => $this->credentialState($connection),
            'health_status' => $connection->healthStatus,
            'last_success_at' => $connection->lastSuccessAt,
            'last_read_at' => $connection->lastReadAt,
            'last_write_at' => $connection->lastWriteAt,
            'last_error_at' => $connection->lastErrorAt,
            'last_error' => $this->safeMessage($connection->lastErrorMessage),
        ];
    }

    /** @return array{ok:bool,message:string,count?:int} */
    public function testRead(int $connectionId): array {
        $connection = $this->connections->find($connectionId);
        if (!$connection || !$connection->active) {
            return ['ok' => false, 'message' => 'Calendar connection is missing or inactive.'];
        }
        $provider = $this->providers->get($connection->provider);
        if (!$provider || !in_array(ProviderCapabilities::BUSY_READ, ProviderCapabilities::normalize($provider->capabilities()), true)) {
            return ['ok' => false, 'message' => 'This provider does not support availability reads.'];
        }

        $from = Time::formatUtc(Time::nowUtc());
        $to = Time::formatUtc(Time::nowUtc()->modify('+24 hours'));
        $busy = $provider->busyBetween($from, $to, $connection);
        if (is_wp_error($busy)) {
            return ['ok' => false, 'message' => $this->safeMessage($busy->get_error_message())];
        }
        return [
            'ok' => true,
            'message' => 'Availability read succeeded.',
            'count' => is_array($busy) ? count($busy) : 0,
        ];
    }

    /** @return array{ok:bool,message:string} */
    public function testWrite(int $connectionId): array {
        $connection = $this->connections->find($connectionId);
        if (!$connection || !$connection->active) {
            return ['ok' => false, 'message' => 'Calendar connection is missing or inactive.'];
        }
        $provider = $this->providers->get($connection->provider);
        if (!$provider instanceof CalendarSyncProviderInterface) {
            return ['ok' => false, 'message' => 'This provider does not support event write-back.'];
        }
        $caps = ProviderCapabilities::normalize($provider->capabilities());
        if (!in_array(ProviderCapabilities::EVENT_CREATE, $caps, true)
            || !in_array(ProviderCapabilities::EVENT_CANCEL, $caps, true)) {
            return ['ok' => false, 'message' => 'This provider cannot run a reversible write test.'];
        }

        $start = Time::nowUtc()->modify('+180 days')->setTime(12, 0, 0);
        $end = $start->modify('+5 minutes');
        $booking = [
            'booking_uuid' => 'diagnostic-' . wp_generate_uuid4(),
            'booking_type_id' => 0,
            'slot_start' => Time::formatUtc($start),
            'slot_end' => Time::formatUtc($end),
            'notes' => '',
        ];
        $meta = [
            'subject' => 'WordPress Calendar Booking connection test',
            'location' => '',
        ];

        $created = $provider->createEvent($booking, $meta, $connection);
        if (is_wp_error($created)) {
            return ['ok' => false, 'message' => $this->safeMessage($created->get_error_message())];
        }
        $eventId = is_array($created) ? trim((string)($created['event_id'] ?? '')) : '';
        if ($eventId === '') {
            return ['ok' => false, 'message' => 'Provider created a test event without returning an event identifier.'];
        }

        $deleted = $provider->cancelEvent($connection, $eventId);
        if (is_wp_error($deleted)) {
            return ['ok' => false, 'message' => 'Test event was created, but cleanup failed: ' . $this->safeMessage($deleted->get_error_message())];
        }
        return ['ok' => true, 'message' => 'Write test succeeded and the temporary event was removed.'];
    }

    private function credentialState(CalendarConnection $connection): string {
        $credentials = $this->connections->credentials($connection->id);
        if ($credentials === null) {
            return 'invalid';
        }
        if (!$credentials) {
            return 'missing';
        }

        if ($connection->provider === 'google' || $connection->provider === 'microsoft') {
            $access = trim((string)($credentials['access_token'] ?? ''));
            $refresh = trim((string)($credentials['refresh_token'] ?? ''));
            $expires = (int)($credentials['expires_at'] ?? 0);
            if ($refresh !== '') {
                return 'ok';
            }
            if ($access !== '' && $expires > time() + 60) {
                return 'ok';
            }
            return 'reauth_required';
        }

        if ($connection->provider === 'caldav') {
            return !empty($credentials['username']) && !empty($credentials['password'])
                ? 'ok'
                : 'missing';
        }
        return 'present';
    }

    private function safeMessage(string $message): string {
        $message = wp_strip_all_tags($message);
        $message = preg_replace('/Authorization:\s*[^\s]+/i', 'Authorization: [redacted]', $message);
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._~-]+/i', 'Bearer [redacted]', (string)$message);
        $message = preg_replace('/(?:access|refresh|id)[_-]?token\s*[:=]\s*[^\s,;]+/i', 'token=[redacted]', (string)$message);
        return mb_substr((string)$message, 0, 1000);
    }
}
