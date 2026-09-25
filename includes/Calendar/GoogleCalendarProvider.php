<?php
namespace Wpcb\Calendar;

use Wpcb\Admin\Settings;
use Wpcb\Booking\BookingRepository;
use Wpcb\Support\BookingFormatter;
use Wpcb\Support\Time;

final class GoogleCalendarProvider implements CalendarSyncProviderInterface {
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    private CalendarConnectionRepository $connections;
    private GoogleOAuthConfig $oauth;

    public function __construct(
        ?CalendarConnectionRepository $connections = null,
        ?GoogleOAuthConfig $oauth = null
    ) {
        $this->connections = $connections ?: new CalendarConnectionRepository();
        $this->oauth = $oauth ?: new GoogleOAuthConfig();
    }

    public function id(): string {
        return 'google';
    }

    public function label(): string {
        return 'Google Calendar';
    }

    public function capabilities(): array {
        return [
            ProviderCapabilities::BUSY_READ,
            ProviderCapabilities::EVENT_CREATE,
            ProviderCapabilities::EVENT_UPDATE,
            ProviderCapabilities::EVENT_CANCEL,
        ];
    }

    public function busyBetween(string $fromUtc, string $toUtc, CalendarConnection $connection) {
        $from = Time::parseUtc($fromUtc);
        $to = Time::parseUtc($toUtc);
        if (!$from || !$to || $to <= $from) {
            return new \WP_Error('wpcb_google_range', 'Invalid Google Calendar busy-time range.');
        }

        $response = $this->apiRequest(
            $connection,
            'POST',
            self::API_BASE . '/freeBusy',
            [
                'timeMin' => $from->format(DATE_RFC3339),
                'timeMax' => $to->format(DATE_RFC3339),
                'items' => [
                    ['id' => $connection->remoteCalendarId !== '' ? $connection->remoteCalendarId : 'primary'],
                ],
            ]
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $calendarId = $connection->remoteCalendarId !== '' ? $connection->remoteCalendarId : 'primary';
        $calendar = $response['calendars'][$calendarId] ?? null;
        if (!is_array($calendar) || !empty($calendar['errors']) || !array_key_exists('busy', $calendar) || !is_array($calendar['busy'])) {
            $this->connections->setHealthError($connection->id, 'Google Calendar availability response was incomplete.');
            return new \WP_Error(
                'wpcb_google_freebusy_incomplete',
                'Google Calendar availability could not be read completely.'
            );
        }
        $busy = $calendar['busy'];

        $out = [];
        foreach ($busy as $interval) {
            if (!is_array($interval) || empty($interval['start']) || empty($interval['end'])) {
                continue;
            }
            try {
                $start = new \DateTimeImmutable((string)$interval['start']);
                $end = new \DateTimeImmutable((string)$interval['end']);
            } catch (\Exception $e) {
                $this->connections->setHealthError($connection->id, 'Google Calendar availability response contained an invalid busy interval.');
                return new \WP_Error('wpcb_google_freebusy_incomplete', 'Google Calendar availability could not be read completely.');
            }
            if ($end <= $start) {
                $this->connections->setHealthError($connection->id, 'Google Calendar availability response contained an invalid busy interval.');
                return new \WP_Error('wpcb_google_freebusy_incomplete', 'Google Calendar availability could not be read completely.');
            }
            $out[] = [
                'start' => Time::formatUtc($start),
                'end' => Time::formatUtc($end),
                'source' => 'google',
                'connection_id' => $connection->id,
            ];
        }

        $this->connections->setHealthSuccess($connection->id, 'read');
        return $out;
    }

    public function createEvent(array $booking, array $meta, CalendarConnection $connection) {
        $payload = $this->eventPayload($booking, $meta);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $bookingUuid = trim((string)($booking['booking_uuid'] ?? ''));
        if ($bookingUuid === '') {
            return new \WP_Error('wpcb_google_booking_identity', 'Google Calendar requires a stable booking identity.');
        }

        $eventId = $this->deterministicEventId($bookingUuid, $connection);
        $payload['id'] = $eventId;
        $calendarId = rawurlencode($connection->remoteCalendarId !== '' ? $connection->remoteCalendarId : 'primary');
        $eventsUrl = self::API_BASE . '/calendars/' . $calendarId . '/events';

        $response = $this->apiRequest($connection, 'POST', $eventsUrl, $payload);
        if (is_wp_error($response)) {
            // A request can fail locally after Google has already committed the
            // event. Reconcile the deterministic ID before allowing a retry to
            // create anything else.
            $recovered = $this->recoverOwnedEvent(
                $connection,
                $eventsUrl,
                $eventId,
                $bookingUuid
            );
            if (!is_wp_error($recovered)) {
                return $recovered;
            }
            if ($recovered->get_error_code() === 'wpcb_google_event_owner_mismatch') {
                return $recovered;
            }
            return $response;
        }

        $returnedId = sanitize_text_field((string)($response['id'] ?? ''));
        if ($returnedId === '' || !hash_equals($eventId, $returnedId)) {
            return new \WP_Error(
                'wpcb_google_event_id',
                'Google Calendar did not confirm the expected event identifier.'
            );
        }
        $this->connections->setHealthSuccess($connection->id, 'write');
        return ['ok' => true, 'event_id' => $eventId];
    }

    private function deterministicEventId(string $bookingUuid, CalendarConnection $connection): string {
        // Google event IDs accept base32hex-compatible characters. A fixed
        // lowercase hex digest is stable across retries and contains only 0-9/a-f.
        return 'b' . substr(hash(
            'sha256',
            home_url('/') . '|' . $bookingUuid . '|' . $connection->id . '|'
                . ($connection->remoteCalendarId !== '' ? $connection->remoteCalendarId : 'primary')
        ), 0, 51);
    }

    private function recoverOwnedEvent(
        CalendarConnection $connection,
        string $eventsUrl,
        string $eventId,
        string $bookingUuid
    ) {
        $existing = $this->apiRequest(
            $connection,
            'GET',
            $eventsUrl . '/' . rawurlencode($eventId),
            null
        );
        if (is_wp_error($existing)) {
            return $existing;
        }

        $owner = (string)($existing['extendedProperties']['private']['wpcb_booking_uuid'] ?? '');
        if ($owner === '' || !hash_equals($bookingUuid, $owner)) {
            return new \WP_Error(
                'wpcb_google_event_owner_mismatch',
                'The deterministic Google Calendar event identifier is already owned by another event.'
            );
        }

        $this->connections->setHealthSuccess($connection->id, 'write');
        return ['ok' => true, 'event_id' => $eventId, 'recovered' => true];
    }

    public function updateEvent(array $booking, array $meta, CalendarConnection $connection, string $eventId) {
        $payload = $this->eventPayload($booking, $meta);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $eventId = trim($eventId);
        if ($eventId === '') {
            return new \WP_Error('wpcb_google_event_missing', 'Google Calendar event identifier is missing.');
        }

        $calendarId = rawurlencode($connection->remoteCalendarId !== '' ? $connection->remoteCalendarId : 'primary');
        $response = $this->apiRequest(
            $connection,
            'PUT',
            self::API_BASE . '/calendars/' . $calendarId . '/events/' . rawurlencode($eventId),
            $payload
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $this->connections->setHealthSuccess($connection->id, 'write');
        return ['ok' => true, 'event_id' => $eventId];
    }

    public function cancelEvent(CalendarConnection $connection, string $eventId) {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return ['ok' => true, 'message' => 'No Google event exists for this booking.'];
        }

        $calendarId = rawurlencode($connection->remoteCalendarId !== '' ? $connection->remoteCalendarId : 'primary');
        $result = $this->rawRequest(
            $connection,
            'DELETE',
            self::API_BASE . '/calendars/' . $calendarId . '/events/' . rawurlencode($eventId),
            null
        );
        if (is_wp_error($result)) {
            return $result;
        }

        $this->connections->setHealthSuccess($connection->id, 'write');
        return ['ok' => true, 'event_id' => $eventId];
    }

    public function revoke(CalendarConnection $connection): void {
        $credentials = $this->connections->credentials($connection->id);
        if (!is_array($credentials)) {
            return;
        }
        $token = (string)($credentials['refresh_token'] ?? $credentials['access_token'] ?? '');
        if ($token === '') {
            return;
        }
        wp_remote_post('https://oauth2.googleapis.com/revoke', [
            'timeout' => 10,
            'redirection' => 0,
            'body' => ['token' => $token],
        ]);
    }

    private function eventPayload(array $booking, array $meta) {
        $start = Time::parseUtc((string)($booking['slot_start'] ?? ''));
        $end = Time::parseUtc((string)($booking['slot_end'] ?? ''));
        if (!$start || !$end || $end <= $start) {
            return new \WP_Error('wpcb_google_event_time', 'Booking contains invalid UTC event times.');
        }

        $formatter = new BookingFormatter();
        $settings = Settings::get();
        return [
            'summary' => $formatter->summary($booking, $meta),
            'description' => (string)($meta['message'] ?? ($booking['notes'] ?? '')),
            'location' => $formatter->location($booking, $meta, $settings),
            'start' => ['dateTime' => $start->format(DATE_RFC3339)],
            'end' => ['dateTime' => $end->format(DATE_RFC3339)],
            'extendedProperties' => [
                'private' => [
                    'wpcb_booking_uuid' => (string)($booking['booking_uuid'] ?? ''),
                ],
            ],
        ];
    }

    private function apiRequest(CalendarConnection $connection, string $method, string $url, ?array $payload) {
        $response = $this->rawRequest($connection, $method, $url, $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        $body = (string)wp_remote_retrieve_body($response);
        $decoded = $body !== '' ? json_decode($body, true) : [];
        if ($body !== '' && !is_array($decoded)) {
            $this->connections->setHealthError($connection->id, 'Google Calendar returned invalid JSON.');
            return new \WP_Error('wpcb_google_json', 'Google Calendar returned an unreadable response.');
        }

        $this->connections->setHealthSuccess($connection->id);
        return is_array($decoded) ? $decoded : [];
    }

    private function rawRequest(CalendarConnection $connection, string $method, string $url, ?array $payload) {
        $token = $this->accessToken($connection);
        if (is_wp_error($token)) {
            $this->connections->setHealthError($connection->id, $token->get_error_message());
            return $token;
        }

        $args = [
            'method' => $method,
            'timeout' => 20,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ];
        if ($payload !== null) {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $this->connections->setHealthError($connection->id, 'Google Calendar request failed.');
            return new \WP_Error('wpcb_google_http', 'Google Calendar request failed. Please retry or reconnect.');
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $this->connections->setHealthError($connection->id, 'Google Calendar returned HTTP ' . $code . '.');
            if ($code === 401 || $code === 403) {
                return new \WP_Error('wpcb_google_reauth', 'Google Calendar authorization is no longer valid. Reconnect the calendar.');
            }
            return new \WP_Error('wpcb_google_api', 'Google Calendar request failed with HTTP ' . $code . '.');
        }

        if ($method === 'DELETE') {
            $this->connections->setHealthSuccess($connection->id);
        }
        return $response;
    }

    private function accessToken(CalendarConnection $connection) {
        $credentials = $this->connections->credentials($connection->id);
        if (!is_array($credentials)) {
            return new \WP_Error('wpcb_google_credentials', 'Google Calendar credentials cannot be decrypted. Reconnect the calendar.');
        }

        $access = (string)($credentials['access_token'] ?? '');
        $expiresAt = (int)($credentials['expires_at'] ?? 0);
        if ($access !== '' && $expiresAt > time() + 60) {
            return $access;
        }

        $refresh = (string)($credentials['refresh_token'] ?? '');
        if ($refresh === '' || !$this->oauth->configured()) {
            return new \WP_Error('wpcb_google_reauth', 'Google Calendar needs to be reconnected.');
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'redirection' => 0,
            'body' => [
                'client_id' => $this->oauth->clientId(),
                'client_secret' => $this->oauth->clientSecret(),
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token',
            ],
        ]);
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('wpcb_google_reauth', 'Google Calendar token refresh failed. Reconnect the calendar.');
        }

        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            return new \WP_Error('wpcb_google_reauth', 'Google Calendar token refresh returned an invalid response.');
        }

        $credentials['access_token'] = (string)$body['access_token'];
        $credentials['expires_at'] = time() + max(60, (int)($body['expires_in'] ?? 3600));
        if (!empty($body['scope'])) {
            $credentials['scope'] = (string)$body['scope'];
        }
        $saved = $this->connections->replaceCredentials($connection->id, $credentials);
        if (is_wp_error($saved)) {
            return $saved;
        }

        return $credentials['access_token'];
    }
}
