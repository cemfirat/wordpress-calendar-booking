<?php
namespace Wpcb\Calendar;

use Wpcb\Admin\Settings;
use Wpcb\ICS\IcsGenerator;
use Wpcb\Support\BookingFormatter;
use Wpcb\Support\Time;

final class CalDavProvider implements CalendarSyncProviderInterface {
    private CalendarConnectionRepository $connections;

    public function __construct(?CalendarConnectionRepository $connections = null) {
        $this->connections = $connections ?: new CalendarConnectionRepository();
    }

    public function id(): string {
        return 'caldav';
    }

    public function label(): string {
        return 'CalDAV';
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
        $client = $this->clientFor($connection);
        if (is_wp_error($client)) {
            return $client;
        }

        $calendarUrl = $this->calendarUrl($connection);
        if ($calendarUrl === '') {
            return new \WP_Error('wpcb_caldav_calendar', 'CalDAV calendar URL is missing.');
        }

        $objects = $client->calendarQuery($calendarUrl, $fromUtc, $toUtc);
        if (is_wp_error($objects)) {
            $this->connections->setHealthError($connection->id, $objects->get_error_message());
            return $objects;
        }

        $parser = new Parser();
        $busy = [];
        foreach ($objects as $object) {
            $parsed = $parser->parseResult((string)($object['ics'] ?? ''), $fromUtc, $toUtc);
            if (is_wp_error($parsed)) {
                $this->connections->setHealthError($connection->id, 'CalDAV calendar data could not be parsed completely.');
                return new \WP_Error(
                    'wpcb_caldav_availability_incomplete',
                    'CalDAV availability could not be read completely.'
                );
            }
            foreach ($parsed as $event) {
                if (empty($event['start']) || empty($event['end'])) {
                    $this->connections->setHealthError($connection->id, 'CalDAV calendar data contained an invalid busy interval.');
                    return new \WP_Error(
                        'wpcb_caldav_availability_incomplete',
                        'CalDAV availability could not be read completely.'
                    );
                }
                $busy[] = [
                    'start' => (string)$event['start'],
                    'end' => (string)$event['end'],
                    'source' => 'caldav',
                    'connection_id' => $connection->id,
                ];
            }
        }

        $this->connections->setHealthSuccess($connection->id, 'read');
        return $busy;
    }

    public function createEvent(array $booking, array $meta, CalendarConnection $connection) {
        $client = $this->clientFor($connection);
        if (is_wp_error($client)) {
            return $client;
        }

        $calendarUrl = $this->calendarUrl($connection);
        if ($calendarUrl === '') {
            return new \WP_Error('wpcb_caldav_calendar', 'CalDAV calendar URL is missing.');
        }

        $bookingUuid = sanitize_key((string)($booking['booking_uuid'] ?? ''));
        if ($bookingUuid === '') {
            return new \WP_Error('wpcb_caldav_booking_identity', 'CalDAV requires a stable booking identity.');
        }
        $uid = 'wpcb-' . $bookingUuid
            . '@' . sanitize_text_field((string)(wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'wordpress'));
        $eventUrl = rtrim($calendarUrl, '/') . '/' . rawurlencode($uid) . '.ics';
        $ics = $this->ics($booking, $meta, $uid);
        if (is_wp_error($ics)) {
            return $ics;
        }

        $result = $client->putEvent($eventUrl, $ics, null, true);
        if (is_wp_error($result)) {
            // If the server committed the deterministic resource but the
            // response was lost, or a retry receives If-None-Match conflict,
            // recover only when the existing object carries our exact UID.
            $recovered = $this->recoverOwnedCreate($client, $eventUrl, $uid);
            if (!is_wp_error($recovered)) {
                $this->connections->setHealthSuccess($connection->id, 'write');
                return $recovered;
            }
            if ($recovered->get_error_code() === 'wpcb_caldav_owner_mismatch') {
                $this->connections->setHealthError($connection->id, $recovered->get_error_message());
                return $recovered;
            }
            $this->connections->setHealthError($connection->id, $result->get_error_message());
            return $result;
        }

        $this->connections->setHealthSuccess($connection->id, 'write');
        return [
            'ok' => true,
            'event_id' => $this->encodeHandle((string)$result['url'], (string)$result['etag']),
        ];
    }

    private function recoverOwnedCreate(CalDavClient $client, string $eventUrl, string $uid) {
        $existing = $client->getEvent($eventUrl);
        if (is_wp_error($existing)) {
            return $existing;
        }

        $ics = str_replace("\r\n", "\n", (string)($existing['ics'] ?? ''));
        if (!preg_match('/(?:^|\n)UID:' . preg_quote($uid, '/') . '(?:\n|$)/', $ics)) {
            return new \WP_Error(
                'wpcb_caldav_owner_mismatch',
                'The deterministic CalDAV event URL is already owned by another event.'
            );
        }

        return [
            'ok' => true,
            'event_id' => $this->encodeHandle(
                (string)$existing['url'],
                (string)($existing['etag'] ?? '')
            ),
            'recovered' => true,
        ];
    }

    public function updateEvent(array $booking, array $meta, CalendarConnection $connection, string $eventId) {
        $client = $this->clientFor($connection);
        if (is_wp_error($client)) {
            return $client;
        }

        $handle = $this->decodeHandle($eventId);
        if (!$handle) {
            return new \WP_Error('wpcb_caldav_event', 'CalDAV event handle is invalid.');
        }

        $uid = 'wpcb-' . sanitize_key((string)($booking['booking_uuid'] ?? ''))
            . '@' . sanitize_text_field((string)(wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'wordpress'));
        $ics = $this->ics($booking, $meta, $uid);
        if (is_wp_error($ics)) {
            return $ics;
        }

        $result = $client->putEvent($handle['url'], $ics, $handle['etag'], false);
        if (is_wp_error($result)) {
            $this->connections->setHealthError($connection->id, $result->get_error_message());
            return $result;
        }

        $this->connections->setHealthSuccess($connection->id, 'write');
        return [
            'ok' => true,
            'event_id' => $this->encodeHandle(
                (string)$result['url'],
                (string)($result['etag'] !== '' ? $result['etag'] : $handle['etag'])
            ),
        ];
    }

    public function cancelEvent(CalendarConnection $connection, string $eventId) {
        if (trim($eventId) === '') {
            return ['ok' => true, 'message' => 'No CalDAV event exists for this booking.'];
        }

        $client = $this->clientFor($connection);
        if (is_wp_error($client)) {
            return $client;
        }

        $handle = $this->decodeHandle($eventId);
        if (!$handle) {
            return new \WP_Error('wpcb_caldav_event', 'CalDAV event handle is invalid.');
        }

        $result = $client->deleteEvent($handle['url'], $handle['etag']);
        if (is_wp_error($result)) {
            $this->connections->setHealthError($connection->id, $result->get_error_message());
            return $result;
        }

        $this->connections->setHealthSuccess($connection->id, 'write');
        return ['ok' => true, 'event_id' => $eventId];
    }

    /** @return CalDavClient|\WP_Error */
    public function clientFor(CalendarConnection $connection) {
        $credentials = $this->connections->credentials($connection->id);
        $config = $this->connections->config($connection->id);
        if (!is_array($credentials)) {
            return new \WP_Error('wpcb_caldav_credentials', 'CalDAV credentials cannot be decrypted. Reconnect the calendar.');
        }

        $endpoint = trim((string)($config['endpoint'] ?? ''));
        $username = trim((string)($credentials['username'] ?? ''));
        $password = (string)($credentials['password'] ?? '');
        if ($endpoint === '' || $username === '' || $password === '') {
            return new \WP_Error('wpcb_caldav_credentials', 'CalDAV endpoint, username and password are incomplete.');
        }

        return new CalDavClient($endpoint, $username, $password);
    }

    private function calendarUrl(CalendarConnection $connection): string {
        $config = $this->connections->config($connection->id);
        $configured = trim((string)($config['calendar_url'] ?? ''));
        if ($configured !== '') {
            return esc_url_raw($configured);
        }
        return esc_url_raw($connection->remoteCalendarId);
    }

    private function ics(array $booking, array $meta, string $uid) {
        $start = Time::parseUtc((string)($booking['slot_start'] ?? ''));
        $end = Time::parseUtc((string)($booking['slot_end'] ?? ''));
        if (!$start || !$end || $end <= $start) {
            return new \WP_Error('wpcb_caldav_event_time', 'Booking contains invalid UTC event times.');
        }

        $formatter = new BookingFormatter();
        $settings = Settings::get();
        return (new IcsGenerator())->generate(
            $booking,
            $meta,
            $formatter->summary($booking, $meta),
            $formatter->location($booking, $meta, $settings),
            $uid
        );
    }

    private function encodeHandle(string $url, string $etag): string {
        $json = wp_json_encode(['u' => $url, 'e' => $etag]);
        return rtrim(strtr(base64_encode((string)$json), '+/', '-_'), '=');
    }

    private function decodeHandle(string $eventId): ?array {
        $value = trim($eventId);
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        $data = $decoded !== false ? json_decode($decoded, true) : null;
        if (!is_array($data) || empty($data['u'])) {
            return null;
        }
        return [
            'url' => esc_url_raw((string)$data['u']),
            'etag' => (string)($data['e'] ?? ''),
        ];
    }
}
