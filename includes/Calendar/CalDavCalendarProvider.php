<?php
namespace Cemb\Calendar;

use Cemb\Admin\Settings;
use Cemb\ICS\IcsGenerator;
use Cemb\Support\BookingFormatter;

final class CalDavCalendarProvider implements CalendarSyncProviderInterface {
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
            ProviderCapabilities::CALENDAR_DISCOVERY,
        ];
    }

    public function busyBetween(string $fromUtc, string $toUtc, CalendarConnection $connection) {
        $client = $this->client($connection);
        if (is_wp_error($client)) {
            $this->connections->setHealthError($connection->id, $client->get_error_message());
            return $client;
        }

        $calendarUrl = $connection->remoteCalendarId;
        if ($calendarUrl === '') {
            return new \WP_Error('cemb_caldav_calendar', 'No CalDAV calendar is selected.');
        }

        $result = $client->busyBetween($calendarUrl, $fromUtc, $toUtc);
        if (is_wp_error($result)) {
            $this->connections->setHealthError($connection->id, $result->get_error_message());
            return $result;
        }

        $this->connections->setHealthSuccess($connection->id, 'read');
        foreach ($result as &$interval) {
            $interval['source'] = 'caldav';
            $interval['connection_id'] = $connection->id;
        }
        unset($interval);
        return $result;
    }

    public function createEvent(array $booking, array $meta, CalendarConnection $connection) {
        $client = $this->client($connection);
        if (is_wp_error($client)) {
            return $client;
        }

        $uuid = trim((string)($booking['booking_uuid'] ?? ''));
        if ($uuid === '') {
            return new \WP_Error('cemb_caldav_uuid', 'Booking UUID is required for deterministic CalDAV write-back.');
        }

        $uid = 'cemb-' . $uuid . '@' . (string)wp_parse_url(home_url(), PHP_URL_HOST);
        $ics = $this->ics($booking, $meta, $uid);
        if (is_wp_error($ics)) {
            return $ics;
        }

        $eventPath = 'cemb-' . preg_replace('/[^A-Za-z0-9-]/', '', $uuid) . '.ics';
        $result = $client->createEvent($connection->remoteCalendarId, $eventPath, $ics, $uid);
        if (is_wp_error($result)) {
            $this->connections->setHealthError($connection->id, $result->get_error_message());
            return $result;
        }

        $this->connections->setHealthSuccess($connection->id, 'write');
        return [
            'ok' => true,
            'event_id' => (string)$result['url'],
            'provider_state' => ['etag' => (string)($result['etag'] ?? '')],
        ];
    }

    public function updateEvent(
        array $booking,
        array $meta,
        CalendarConnection $connection,
        string $eventId,
        array $providerState = []
    ) {
        $client = $this->client($connection);
        if (is_wp_error($client)) {
            return $client;
        }

        $uuid = trim((string)($booking['booking_uuid'] ?? ''));
        $uid = 'cemb-' . $uuid . '@' . (string)wp_parse_url(home_url(), PHP_URL_HOST);
        $ics = $this->ics($booking, $meta, $uid);
        if (is_wp_error($ics)) {
            return $ics;
        }

        $result = $client->updateEvent(
            $eventId,
            $ics,
            (string)($providerState['etag'] ?? '')
        );
        if (is_wp_error($result)) {
            $this->connections->setHealthError($connection->id, $result->get_error_message());
            return $result;
        }

        $this->connections->setHealthSuccess($connection->id, 'write');
        return [
            'ok' => true,
            'event_id' => (string)$result['url'],
            'provider_state' => ['etag' => (string)($result['etag'] ?? '')],
        ];
    }

    public function cancelEvent(
        CalendarConnection $connection,
        string $eventId,
        array $providerState = []
    ) {
        if ($eventId === '') {
            return ['ok' => true];
        }

        $client = $this->client($connection);
        if (is_wp_error($client)) {
            return $client;
        }

        $result = $client->deleteEvent($eventId, (string)($providerState['etag'] ?? ''));
        if (is_wp_error($result)) {
            $this->connections->setHealthError($connection->id, $result->get_error_message());
            return $result;
        }

        $this->connections->setHealthSuccess($connection->id, 'write');
        return ['ok' => true];
    }

    /**
     * @return array{principal:string,home:string,calendars:array}|\WP_Error
     */
    public function discover(CalendarConnection $connection) {
        $client = $this->client($connection);
        if (is_wp_error($client)) {
            return $client;
        }
        return $client->discover();
    }

    private function client(CalendarConnection $connection) {
        $config = $this->connections->config($connection->id);
        $credentials = $this->connections->credentials($connection->id);
        if (!is_array($credentials)) {
            return new \WP_Error(
                'cemb_caldav_credentials',
                'CalDAV credentials cannot be decrypted. Re-enter the connection password.'
            );
        }

        $client = new CalDavClient(
            (string)($config['endpoint'] ?? ''),
            (string)($credentials['username'] ?? ''),
            (string)($credentials['password'] ?? '')
        );
        if (!$client->configured()) {
            return new \WP_Error('cemb_caldav_config', 'CalDAV connection is incomplete.');
        }
        return $client;
    }

    private function ics(array $booking, array $meta, string $uid) {
        try {
            $formatter = new BookingFormatter();
            $settings = Settings::get();
            return (new IcsGenerator())->generate(
                $booking,
                $meta,
                $formatter->summary($booking, $meta),
                $formatter->location($booking, $meta, $settings),
                $uid
            );
        } catch (\Throwable $e) {
            return new \WP_Error('cemb_caldav_ics', 'Calendar event could not be generated.');
        }
    }
}
