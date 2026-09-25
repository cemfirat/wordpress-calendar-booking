<?php
namespace Wpcb\Calendar;

use Wpcb\Admin\Settings;
use Wpcb\Support\BookingFormatter;
use Wpcb\Support\Time;

final class MicrosoftGraphProvider implements CalendarSyncProviderInterface {
    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const API_BASE = 'https://graph.microsoft.com/v1.0';
    private const PERSONAL_TENANT_ID = '9188040d-6c67-4c5b-b112-36a304b66dad';

    private CalendarConnectionRepository $connections;
    private MicrosoftOAuthConfig $oauth;

    public function __construct(
        ?CalendarConnectionRepository $connections = null,
        ?MicrosoftOAuthConfig $oauth = null
    ) {
        $this->connections = $connections ?: new CalendarConnectionRepository();
        $this->oauth = $oauth ?: new MicrosoftOAuthConfig();
    }

    public function id(): string {
        return 'microsoft';
    }

    public function label(): string {
        return 'Microsoft 365 / Outlook';
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
            return new \WP_Error('wpcb_microsoft_range', 'Invalid Microsoft calendar busy-time range.');
        }

        $credentials = $this->connections->credentials($connection->id);
        if (!is_array($credentials)) {
            return new \WP_Error('wpcb_microsoft_credentials', 'Microsoft calendar credentials cannot be decrypted. Reconnect the calendar.');
        }

        $isPersonal = (($credentials['tenant_id'] ?? '') === self::PERSONAL_TENANT_ID)
            || (($credentials['account_type'] ?? '') === 'personal');
        $isPrimary = $connection->remoteCalendarId === '' || $connection->remoteCalendarId === 'primary';
        $address = trim((string)($credentials['account_address'] ?? ''));

        // Graph getSchedule is unavailable for delegated personal Microsoft
        // accounts. It also addresses mailboxes, not arbitrary calendar IDs.
        if (!$isPersonal && $isPrimary && $address !== '') {
            $schedule = $this->scheduleBusy($connection, $from, $to, $address);
            if (!is_wp_error($schedule)) {
                $this->connections->setHealthSuccess($connection->id, 'read');
                return $schedule;
            }
            // A tenant/mailbox may still reject getSchedule. CalendarView is the
            // supported availability fallback and also handles recurrence.
        }

        $view = $this->calendarViewBusy($connection, $from, $to);
        if (!is_wp_error($view)) {
            $this->connections->setHealthSuccess($connection->id, 'read');
        }
        return $view;
    }

    public function createEvent(array $booking, array $meta, CalendarConnection $connection) {
        $payload = $this->eventPayload($booking, $meta);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $calendar = $this->calendarPath($connection);
        $response = $this->apiRequest($connection, 'POST', self::API_BASE . $calendar . '/events', $payload);
        if (is_wp_error($response)) {
            return $response;
        }
        $eventId = sanitize_text_field((string)($response['id'] ?? ''));
        if ($eventId === '') {
            return new \WP_Error('wpcb_microsoft_event_id', 'Microsoft Graph did not return an event identifier.');
        }
        $this->connections->setHealthSuccess($connection->id, 'write');
        return ['ok' => true, 'event_id' => $eventId];
    }

    public function updateEvent(array $booking, array $meta, CalendarConnection $connection, string $eventId) {
        $payload = $this->eventPayload($booking, $meta);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $eventId = trim($eventId);
        if ($eventId === '') {
            return new \WP_Error('wpcb_microsoft_event_missing', 'Microsoft event identifier is missing.');
        }

        $response = $this->apiRequest(
            $connection,
            'PATCH',
            self::API_BASE . '/me/events/' . rawurlencode($eventId),
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
            return ['ok' => true, 'message' => 'No Microsoft event exists for this booking.'];
        }

        $response = $this->rawRequest(
            $connection,
            'DELETE',
            self::API_BASE . '/me/events/' . rawurlencode($eventId),
            null
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $this->connections->setHealthSuccess($connection->id, 'write');
        return ['ok' => true, 'event_id' => $eventId];
    }

    private function scheduleBusy(
        CalendarConnection $connection,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $address
    ) {
        $response = $this->apiRequest(
            $connection,
            'POST',
            self::API_BASE . '/me/calendar/getSchedule',
            [
                'schedules' => [$address],
                'startTime' => ['dateTime' => $from->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
                'endTime' => ['dateTime' => $to->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
                'availabilityViewInterval' => 15,
            ]
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $value = $response['value'] ?? null;
        $schedule = is_array($value) && isset($value[0]) && is_array($value[0]) ? $value[0] : null;
        if (!is_array($schedule) || !empty($schedule['error'])
            || !array_key_exists('scheduleItems', $schedule) || !is_array($schedule['scheduleItems'])) {
            $this->connections->setHealthError($connection->id, 'Microsoft schedule availability response was incomplete.');
            return new \WP_Error(
                'wpcb_microsoft_schedule_incomplete',
                'Microsoft calendar availability could not be read completely.'
            );
        }
        $items = $schedule['scheduleItems'];
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['status'] ?? 'free') === 'free') {
                continue;
            }
            $interval = $this->graphInterval($item['start'] ?? null, $item['end'] ?? null);
            if ($interval) {
                $interval['source'] = 'microsoft_schedule';
                $interval['connection_id'] = $connection->id;
                $out[] = $interval;
            }
        }
        return $out;
    }

    private function calendarViewBusy(
        CalendarConnection $connection,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ) {
        $query = http_build_query([
            'startDateTime' => $from->format(DATE_RFC3339),
            'endDateTime' => $to->format(DATE_RFC3339),
            '$select' => 'id,start,end,showAs,isCancelled',
            '$top' => 1000,
        ], '', '&', PHP_QUERY_RFC3986);

        $url = self::API_BASE . $this->calendarPath($connection) . '/calendarView?' . $query;
        $response = $this->apiRequest($connection, 'GET', $url, null);
        if (is_wp_error($response)) {
            return $response;
        }

        $events = $response['value'] ?? [];
        if (!is_array($events)) {
            return [];
        }
        $out = [];
        foreach ($events as $event) {
            if (!is_array($event) || !empty($event['isCancelled']) || ($event['showAs'] ?? '') === 'free') {
                continue;
            }
            $interval = $this->graphInterval($event['start'] ?? null, $event['end'] ?? null);
            if ($interval) {
                $interval['source'] = 'microsoft_calendar_view';
                $interval['connection_id'] = $connection->id;
                $out[] = $interval;
            }
        }
        return $out;
    }

    private function graphInterval($start, $end): ?array {
        if (!is_array($start) || !is_array($end) || empty($start['dateTime']) || empty($end['dateTime'])) {
            return null;
        }

        try {
            $startZone = $this->graphTimezone((string)($start['timeZone'] ?? 'UTC'));
            $endZone = $this->graphTimezone((string)($end['timeZone'] ?? 'UTC'));
            $a = new \DateTimeImmutable((string)$start['dateTime'], $startZone);
            $b = new \DateTimeImmutable((string)$end['dateTime'], $endZone);
        } catch (\Exception $e) {
            return null;
        }
        if ($b <= $a) {
            return null;
        }
        return ['start' => Time::formatUtc($a), 'end' => Time::formatUtc($b)];
    }

    private function graphTimezone(string $timezone): \DateTimeZone {
        $timezone = trim($timezone);
        if ($timezone === '' || strtoupper($timezone) === 'UTC') {
            return Time::utc();
        }
        try {
            return new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            // Graph commonly returns Windows timezone names. Asking Graph for UTC
            // keeps our normal path here; unknown names fail safely to UTC.
            return Time::utc();
        }
    }

    private function eventPayload(array $booking, array $meta) {
        $start = Time::parseUtc((string)($booking['slot_start'] ?? ''));
        $end = Time::parseUtc((string)($booking['slot_end'] ?? ''));
        if (!$start || !$end || $end <= $start) {
            return new \WP_Error('wpcb_microsoft_event_time', 'Booking contains invalid UTC event times.');
        }

        $formatter = new BookingFormatter();
        $settings = Settings::get();
        return [
            'subject' => $formatter->summary($booking, $meta),
            'body' => [
                'contentType' => 'text',
                'content' => (string)($meta['message'] ?? ($booking['notes'] ?? '')),
            ],
            'location' => ['displayName' => $formatter->location($booking, $meta, $settings)],
            'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'transactionId' => (string)($booking['booking_uuid'] ?? ''),
        ];
    }

    private function calendarPath(CalendarConnection $connection): string {
        $calendarId = trim($connection->remoteCalendarId);
        return ($calendarId === '' || $calendarId === 'primary')
            ? '/me/calendar'
            : '/me/calendars/' . rawurlencode($calendarId);
    }

    private function apiRequest(
        CalendarConnection $connection,
        string $method,
        string $url,
        ?array $payload
    ) {
        $response = $this->rawRequest($connection, $method, $url, $payload);
        if (is_wp_error($response)) {
            return $response;
        }
        $body = (string)wp_remote_retrieve_body($response);
        $decoded = $body !== '' ? json_decode($body, true) : [];
        if ($body !== '' && !is_array($decoded)) {
            $this->connections->setHealthError($connection->id, 'Microsoft Graph returned invalid JSON.');
            return new \WP_Error('wpcb_microsoft_json', 'Microsoft Graph returned an unreadable response.');
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function rawRequest(
        CalendarConnection $connection,
        string $method,
        string $url,
        ?array $payload
    ) {
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
                'Prefer' => 'outlook.timezone="UTC"',
            ],
        ];
        if ($payload !== null) {
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $this->connections->setHealthError($connection->id, 'Microsoft Graph request failed.');
            return new \WP_Error('wpcb_microsoft_http', 'Microsoft Graph request failed. Please retry or reconnect.');
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            if ($method === 'DELETE' && $code === 404) {
                return $response;
            }
            $this->connections->setHealthError($connection->id, 'Microsoft Graph returned HTTP ' . $code . '.');
            if ($code === 401 || $code === 403) {
                return new \WP_Error('wpcb_microsoft_reauth', 'Microsoft authorization is no longer valid or lacks permission. Reconnect the calendar.');
            }
            return new \WP_Error('wpcb_microsoft_api', 'Microsoft Graph request failed with HTTP ' . $code . '.');
        }
        return $response;
    }

    private function accessToken(CalendarConnection $connection) {
        $credentials = $this->connections->credentials($connection->id);
        if (!is_array($credentials)) {
            return new \WP_Error('wpcb_microsoft_credentials', 'Microsoft calendar credentials cannot be decrypted. Reconnect the calendar.');
        }

        $access = (string)($credentials['access_token'] ?? '');
        $expiresAt = (int)($credentials['expires_at'] ?? 0);
        if ($access !== '' && $expiresAt > time() + 60) {
            return $access;
        }

        $refresh = (string)($credentials['refresh_token'] ?? '');
        if ($refresh === '' || !$this->oauth->configured()) {
            return new \WP_Error('wpcb_microsoft_reauth', 'Microsoft Calendar needs to be reconnected.');
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'redirection' => 0,
            'body' => [
                'client_id' => $this->oauth->clientId(),
                'client_secret' => $this->oauth->clientSecret(),
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token',
                'scope' => (string)($credentials['scope'] ?? implode(' ', $this->oauth->scopes(true, true))),
            ],
        ]);
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('wpcb_microsoft_reauth', 'Microsoft token refresh failed. Reconnect the calendar.');
        }

        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token'])) {
            return new \WP_Error('wpcb_microsoft_reauth', 'Microsoft token refresh returned an invalid response.');
        }

        $credentials['access_token'] = (string)$body['access_token'];
        $credentials['expires_at'] = time() + max(60, (int)($body['expires_in'] ?? 3600));
        if (!empty($body['refresh_token'])) {
            $credentials['refresh_token'] = (string)$body['refresh_token'];
        }
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
