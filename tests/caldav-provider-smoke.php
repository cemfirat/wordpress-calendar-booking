<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_caldav_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

$requests = [];
$filter = static function ($pre, $args, $url) use (&$requests) {
    if (strpos($url, 'https://8.8.8.8') !== 0) {
        return $pre;
    }

    $method = strtoupper((string)($args['method'] ?? 'GET'));
    $headers = $args['headers'] ?? [];
    $body = (string)($args['body'] ?? '');
    $requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

    $response = static function (int $code, string $message, string $body = '', array $headers = []) {
        return [
            'headers' => $headers,
            'response' => ['code' => $code, 'message' => $message],
            'body' => $body,
            'cookies' => [],
            'filename' => null,
        ];
    };

    if ($method === 'PROPFIND' && $url === 'https://8.8.8.8/') {
        return $response(207, 'Multi-Status',
            '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop>'
            . '<d:current-user-principal><d:href>/principals/user/</d:href></d:current-user-principal>'
            . '</d:prop></d:propstat></d:response></d:multistatus>'
        );
    }

    if ($method === 'PROPFIND' && $url === 'https://8.8.8.8/principals/user/') {
        return $response(207, 'Multi-Status',
            '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response><d:propstat><d:prop>'
            . '<c:calendar-home-set><d:href>/calendars/user/</d:href></c:calendar-home-set>'
            . '</d:prop></d:propstat></d:response></d:multistatus>'
        );
    }

    if ($method === 'PROPFIND' && $url === 'https://8.8.8.8/calendars/user/') {
        return $response(207, 'Multi-Status',
            '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:response><d:href>/calendars/user/work/</d:href><d:propstat><d:prop><d:displayname>Work</d:displayname><d:resourcetype><d:collection/><c:calendar/></d:resourcetype></d:prop></d:propstat></d:response>'
            . '<d:response><d:href>/calendars/user/private/</d:href><d:propstat><d:prop><d:displayname>Private</d:displayname><d:resourcetype><d:collection/><c:calendar/></d:resourcetype></d:prop></d:propstat></d:response>'
            . '</d:multistatus>'
        );
    }

    if ($method === 'REPORT' && $url === 'https://8.8.8.8/calendars/user/work/') {
        $icsTimed = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:timed-ci\r\nDTSTART:20261102T090000Z\r\nDTEND:20261102T100000Z\r\nSUMMARY:Private Timed\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $icsAllDay = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:allday-ci\r\nDTSTART;VALUE=DATE:20261103\r\nDTEND;VALUE=DATE:20261104\r\nSUMMARY:Private All Day\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $icsTransparent = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:transparent-ci\r\nDTSTART:20261102T110000Z\r\nDTEND:20261102T120000Z\r\nTRANSP:TRANSPARENT\r\nSUMMARY:Informational\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        return $response(207, 'Multi-Status',
            '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:response><d:href>/calendars/user/work/timed.ics</d:href><d:propstat><d:prop><d:getetag>"t1"</d:getetag><c:calendar-data><![CDATA[' . $icsTimed . ']]></c:calendar-data></d:prop></d:propstat></d:response>'
            . '<d:response><d:href>/calendars/user/work/allday.ics</d:href><d:propstat><d:prop><d:getetag>"a1"</d:getetag><c:calendar-data><![CDATA[' . $icsAllDay . ']]></c:calendar-data></d:prop></d:propstat></d:response>'
            . '<d:response><d:href>/calendars/user/work/transparent.ics</d:href><d:propstat><d:prop><d:getetag>"x1"</d:getetag><c:calendar-data><![CDATA[' . $icsTransparent . ']]></c:calendar-data></d:prop></d:propstat></d:response>'
            . '</d:multistatus>'
        );
    }

    if ($method === 'PUT') {
        $ifMatch = '';
        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === 'if-match') {
                $ifMatch = (string)$value;
            }
        }
        if ($ifMatch === '"stale"') {
            return $response(412, 'Precondition Failed');
        }
        if ($ifMatch === '"v1"') {
            return $response(204, 'No Content', '', ['etag' => '"v2"']);
        }
        return $response(201, 'Created', '', ['etag' => '"v1"']);
    }

    if ($method === 'DELETE') {
        return $response(204, 'No Content');
    }

    return $response(404, 'Not Found');
};
add_filter('pre_http_request', $filter, 10, 3);

$client = new Wpcb\Calendar\CalDavClient(
    'https://8.8.8.8/',
    'calendar-user',
    'calendar-secret'
);

$calendars = $client->discoverCalendars();
if (!is_array($calendars) || count($calendars) !== 2) {
    WP_CLI::log('DEBUG discovery result: ' . (is_wp_error($calendars) ? $calendars->get_error_code() . ' / ' . $calendars->get_error_message() : wp_json_encode($calendars)));
    WP_CLI::log('DEBUG requested URLs: ' . wp_json_encode(array_map(static fn($request) => [$request['method'], $request['url']], $requests)));
}
wpcb_caldav_assert(is_array($calendars) && count($calendars) === 2, 'CalDAV discovery returns multiple calendar collections.');
wpcb_caldav_assert($calendars[0]['name'] === 'Work' && $calendars[1]['name'] === 'Private', 'CalDAV discovery keeps calendar display names.');
wpcb_caldav_assert($calendars[0]['url'] === 'https://8.8.8.8/calendars/user/work/', 'Relative CalDAV hrefs are resolved against the endpoint origin.');

$objects = $client->calendarQuery(
    'https://8.8.8.8/calendars/user/work/',
    '2026-11-01 00:00:00',
    '2026-11-05 00:00:00'
);
wpcb_caldav_assert(is_array($objects) && count($objects) === 3, 'Bounded CalDAV calendar-query returns matching objects including transparent VEVENT data.');

$reportBody = '';
foreach ($requests as $request) {
    if ($request['method'] === 'REPORT') {
        $reportBody = $request['body'];
        break;
    }
}
wpcb_caldav_assert(
    strpos($reportBody, 'start="20261101T000000Z"') !== false
    && strpos($reportBody, 'end="20261105T000000Z"') !== false,
    'CalDAV calendar-query includes the exact bounded UTC time range.'
);

$repo = new Wpcb\Calendar\CalendarConnectionRepository();
$connectionId = $repo->create(
    [
        'provider' => 'caldav',
        'name' => 'CalDAV CI',
        'remote_calendar_id' => 'https://8.8.8.8/calendars/user/work/',
        'blocks_availability' => 1,
        'receives_bookings' => 1,
        'config' => [
            'endpoint' => 'https://8.8.8.8/',
            'calendar_url' => 'https://8.8.8.8/calendars/user/work/',
            'preset' => 'generic',
        ],
    ],
    [
        'username' => 'calendar-user',
        'password' => 'calendar-secret',
    ]
);
wpcb_caldav_assert(!is_wp_error($connectionId) && $connectionId > 0, 'Generic CalDAV connection is stored with encrypted credentials.');

$connection = $repo->find((int)$connectionId);
$provider = new Wpcb\Calendar\CalDavProvider($repo);
$busy = $provider->busyBetween('2026-11-01 00:00:00', '2026-11-05 00:00:00', $connection);
wpcb_caldav_assert(is_array($busy) && count($busy) === 2, 'CalDAV provider keeps timed and all-day opaque VEVENTs busy while excluding TRANSPARENT data.');
wpcb_caldav_assert(
    $busy[0]['start'] === '2026-11-02 09:00:00',
    'Timed CalDAV event remains canonical UTC.'
);
wpcb_caldav_assert(
    $busy[1]['end'] > $busy[1]['start'],
    'All-day CalDAV event produces a non-empty busy interval.'
);

$booking = [
    'booking_uuid' => 'caldav-ci-booking',
    'booking_type_id' => 1,
    'slot_start' => '2026-11-04 13:00:00',
    'slot_end' => '2026-11-04 14:00:00',
    'notes' => 'CI',
];
$created = $provider->createEvent($booking, ['subject' => 'CalDAV CI'], $connection);
wpcb_caldav_assert(is_array($created) && !empty($created['event_id']), 'CalDAV create returns an opaque event handle.');

$updated = $provider->updateEvent($booking, ['subject' => 'CalDAV CI updated'], $connection, (string)$created['event_id']);
wpcb_caldav_assert(is_array($updated) && !empty($updated['ok']) && $updated['event_id'] !== $created['event_id'], 'CalDAV update advances the stored ETag handle.');

$conflict = $client->putEvent(
    'https://8.8.8.8/calendars/user/work/conflict.ics',
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n",
    '"stale"',
    false
);
wpcb_caldav_assert(
    is_wp_error($conflict) && $conflict->get_error_code() === 'wpcb_caldav_conflict',
    'Stale CalDAV ETag produces an explicit sync conflict instead of blind overwrite.'
);

$deleted = $provider->cancelEvent($connection, (string)$updated['event_id']);
wpcb_caldav_assert(is_array($deleted) && !empty($deleted['ok']), 'CalDAV delete succeeds with the current event handle.');

$credentials = $repo->credentials((int)$connectionId);
wpcb_caldav_assert(
    is_array($credentials) && ($credentials['password'] ?? '') === 'calendar-secret',
    'CalDAV credentials round-trip only through the encrypted secret accessor.'
);

remove_filter('pre_http_request', $filter, 10);
$repo->delete((int)$connectionId);

WP_CLI::success('Generic CalDAV provider smoke tests passed.');
