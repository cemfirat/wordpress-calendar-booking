<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_ms_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

$repo = new Wpcb\Calendar\CalendarConnectionRepository();

$workId = $repo->create(
    [
        'provider' => 'microsoft',
        'name' => 'Microsoft Work CI',
        'remote_calendar_id' => 'primary',
        'blocks_availability' => 1,
        'receives_bookings' => 1,
    ],
    [
        'access_token' => 'work-token',
        'refresh_token' => 'work-refresh',
        'expires_at' => time() + 3600,
        'scope' => 'openid profile email offline_access Calendars.ReadWrite',
        'tenant_id' => '11111111-2222-3333-4444-555555555555',
        'account_type' => 'work_school',
        'account_address' => 'work@example.com',
    ]
);
wpcb_ms_assert(!is_wp_error($workId) && $workId > 0, 'Create work/school Microsoft connection fixture.');

$personalId = $repo->create(
    [
        'provider' => 'microsoft',
        'name' => 'Microsoft Personal CI',
        'remote_calendar_id' => 'primary',
        'blocks_availability' => 1,
        'receives_bookings' => 0,
    ],
    [
        'access_token' => 'personal-token',
        'refresh_token' => 'personal-refresh',
        'expires_at' => time() + 3600,
        'scope' => 'openid profile email offline_access Calendars.ReadBasic',
        'tenant_id' => '9188040d-6c67-4c5b-b112-36a304b66dad',
        'account_type' => 'personal',
        'account_address' => 'person@example.com',
    ]
);
wpcb_ms_assert(!is_wp_error($personalId) && $personalId > 0, 'Create personal Microsoft connection fixture.');

$calls = [];
$calendarViewScenario = 'single';
$scheduleFails = false;
$filter = static function ($pre, $args, $url) use (&$calls, &$calendarViewScenario, &$scheduleFails) {
    if (strpos($url, 'https://graph.microsoft.com/v1.0') !== 0) {
        return $pre;
    }

    $calls[] = [
        'url' => $url,
        'method' => $args['method'] ?? 'GET',
        'body' => $args['body'] ?? null,
        'timeout' => $args['timeout'] ?? null,
        'limit' => $args['limit_response_size'] ?? null,
    ];
    $headers = ['content-type' => 'application/json'];

    if (str_contains($url, '/me/calendar/getSchedule')) {
        return [
            'headers' => $headers,
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => wp_json_encode($scheduleFails ? [
                'value' => [[
                    'error' => ['code' => 'ErrorInternalServerError', 'message' => 'CI synthetic schedule error'],
                    'scheduleItems' => [],
                ]],
            ] : [
                'value' => [[
                    'scheduleItems' => [[
                        'status' => 'busy',
                        'start' => ['dateTime' => '2026-11-02T09:00:00', 'timeZone' => 'UTC'],
                        'end' => ['dateTime' => '2026-11-02T10:00:00', 'timeZone' => 'UTC'],
                    ]],
                ]],
            ]),
            'cookies' => [],
            'filename' => null,
        ];
    }

    if (str_contains($url, '/calendarView?')) {
        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $page = max(1, (int)($query['page'] ?? 1));
        $path = (string)parse_url($url, PHP_URL_PATH);
        $base = 'https://graph.microsoft.com' . $path;

        if ($calendarViewScenario === 'later_error' && $page === 2) {
            return [
                'headers' => $headers,
                'response' => ['code' => 500, 'message' => 'Synthetic later-page failure'],
                'body' => '{}',
                'cookies' => [],
                'filename' => null,
            ];
        }

        $eventA = [
            'id' => 'personal-busy-a',
            'showAs' => 'busy',
            'isCancelled' => false,
            'start' => ['dateTime' => '2026-11-03T11:00:00', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-11-03T12:00:00', 'timeZone' => 'UTC'],
        ];
        $eventB = [
            'id' => 'personal-busy-b',
            'showAs' => 'busy',
            'isCancelled' => false,
            'start' => ['dateTime' => '2026-11-04T15:00:00', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-11-04T16:00:00', 'timeZone' => 'UTC'],
        ];

        $body = ['value' => [$eventA]];
        if ($calendarViewScenario === 'multi') {
            $body = $page === 1
                ? ['value' => [$eventA], '@odata.nextLink' => $base . '?page=2']
                : ['value' => [$eventA, $eventB]];
        } elseif ($calendarViewScenario === 'empty_first') {
            $body = $page === 1
                ? ['value' => [], '@odata.nextLink' => $base . '?page=2']
                : ['value' => [$eventB]];
        } elseif ($calendarViewScenario === 'untrusted') {
            $body = ['value' => [], '@odata.nextLink' => 'https://example.invalid/v1.0/me/calendar/calendarView?page=2'];
        } elseif ($calendarViewScenario === 'repeated') {
            $body = ['value' => [], '@odata.nextLink' => $base . '?page=2'];
        } elseif ($calendarViewScenario === 'limit') {
            $body = ['value' => [], '@odata.nextLink' => $base . '?page=' . ($page + 1)];
        } elseif ($calendarViewScenario === 'later_error') {
            $body = ['value' => [$eventA], '@odata.nextLink' => $base . '?page=2'];
        }

        return [
            'headers' => $headers,
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => wp_json_encode($body),
            'cookies' => [],
            'filename' => null,
        ];
    }

    if (($args['method'] ?? '') === 'POST' && str_ends_with($url, '/me/calendar/events')) {
        return [
            'headers' => $headers,
            'response' => ['code' => 201, 'message' => 'Created'],
            'body' => wp_json_encode(['id' => 'event-ci']),
            'cookies' => [],
            'filename' => null,
        ];
    }

    if (($args['method'] ?? '') === 'PATCH' && str_ends_with($url, '/me/events/event-ci')) {
        return [
            'headers' => $headers,
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => wp_json_encode(['id' => 'event-ci']),
            'cookies' => [],
            'filename' => null,
        ];
    }

    if (($args['method'] ?? '') === 'DELETE' && str_ends_with($url, '/me/events/event-ci')) {
        return [
            'headers' => $headers,
            'response' => ['code' => 204, 'message' => 'No Content'],
            'body' => '',
            'cookies' => [],
            'filename' => null,
        ];
    }

    return [
        'headers' => $headers,
        'response' => ['code' => 404, 'message' => 'Not Found'],
        'body' => '{}',
        'cookies' => [],
        'filename' => null,
    ];
};
add_filter('pre_http_request', $filter, 10, 3);

$provider = new Wpcb\Calendar\MicrosoftGraphProvider($repo, new Wpcb\Calendar\MicrosoftOAuthConfig());
$work = $repo->find((int)$workId);
$personal = $repo->find((int)$personalId);

$workBusy = $provider->busyBetween('2026-11-01 00:00:00', '2026-11-05 00:00:00', $work);
wpcb_ms_assert(is_array($workBusy) && count($workBusy) === 1, 'Work/school availability uses Graph and returns one busy interval.');
wpcb_ms_assert($workBusy[0]['source'] === 'microsoft_schedule', 'Work/school primary calendar uses getSchedule.');
wpcb_ms_assert($workBusy[0]['start'] === '2026-11-02 09:00:00', 'getSchedule interval remains canonical UTC.');

$personalBusy = $provider->busyBetween('2026-11-01 00:00:00', '2026-11-05 00:00:00', $personal);
wpcb_ms_assert(is_array($personalBusy) && count($personalBusy) === 1, 'Personal Microsoft account returns one busy interval.');
wpcb_ms_assert($personalBusy[0]['source'] === 'microsoft_calendar_view', 'Personal Microsoft account uses calendarView fallback.');

$calendarViewScenario = 'multi';
$multiBusy = $provider->busyBetween('2026-11-01 00:00:00', '2026-11-05 00:00:00', $personal);
wpcb_ms_assert(is_array($multiBusy) && count($multiBusy) === 2, 'Microsoft calendarView follows multiple pages and deduplicates intervals.');

$calendarViewScenario = 'empty_first';
$emptyFirstBusy = $provider->busyBetween('2026-11-01 00:00:00', '2026-11-05 00:00:00', $personal);
wpcb_ms_assert(is_array($emptyFirstBusy) && count($emptyFirstBusy) === 1 && $emptyFirstBusy[0]['start'] === '2026-11-04 15:00:00', 'Microsoft calendarView follows nextLink after an empty first page.');

$calendarViewScenario = 'untrusted';
$untrusted = $provider->busyBetween('2026-11-01 00:00:00', '2026-11-05 00:00:00', $personal);
wpcb_ms_assert(is_wp_error($untrusted) && $untrusted->get_error_code() === 'wpcb_microsoft_calendar_view_incomplete', 'Microsoft calendarView rejects untrusted nextLink origins.');

$calendarViewScenario = 'repeated';
$repeated = $provider->busyBetween('2026-11-01 00:00:00', '2026-11-05 00:00:00', $personal);
wpcb_ms_assert(is_wp_error($repeated), 'Microsoft calendarView rejects repeated nextLink loops.');

$calendarViewScenario = 'limit';
$limited = $provider->busyBetween('2026-11-01 00:00:00', '2026-11-05 00:00:00', $personal);
wpcb_ms_assert(is_wp_error($limited), 'Microsoft calendarView fails closed when its page limit is exhausted.');

$calendarViewScenario = 'later_error';
$laterError = $provider->busyBetween('2026-11-01 00:00:00', '2026-11-05 00:00:00', $personal);
wpcb_ms_assert(is_wp_error($laterError), 'Microsoft calendarView fails closed when a later page fails.');

$calendarViewScenario = 'multi';
$scheduleFails = true;
$workFallback = $provider->busyBetween('2026-11-01 00:00:00', '2026-11-05 00:00:00', $work);
wpcb_ms_assert(is_array($workFallback) && count($workFallback) === 2, 'Work/school embedded getSchedule error uses complete paginated calendarView fallback.');
$scheduleFails = false;

$boundedPagingRequest = false;
foreach ($calls as $call) {
    if (str_contains($call['url'], '/calendarView?') && (int)($call['limit'] ?? 0) === 2097153
        && (int)($call['timeout'] ?? 0) >= 1 && (int)($call['timeout'] ?? 0) <= 20) {
        $boundedPagingRequest = true;
        break;
    }
}
wpcb_ms_assert($boundedPagingRequest, 'Microsoft calendarView pages use bounded response size and request timeout.');

$booking = [
    'booking_uuid' => 'microsoft-ci-booking',
    'booking_type_id' => 1,
    'slot_start' => '2026-11-04 13:00:00',
    'slot_end' => '2026-11-04 14:00:00',
    'notes' => 'CI',
];
$created = $provider->createEvent($booking, ['subject' => 'Microsoft CI'], $work);
wpcb_ms_assert(is_array($created) && ($created['event_id'] ?? '') === 'event-ci', 'Microsoft event create returns the Graph event ID.');
$eventCreateCalls = array_values(array_filter(
    $calls,
    static fn($call): bool =>
        strtoupper((string)($call['method'] ?? '')) === 'POST'
        && str_ends_with((string)($call['url'] ?? ''), '/me/calendar/events')
));
$eventCreatePayload = $eventCreateCalls
    ? json_decode((string)($eventCreateCalls[array_key_last($eventCreateCalls)]['body'] ?? ''), true)
    : null;
wpcb_ms_assert(
    is_array($eventCreatePayload)
    && ($eventCreatePayload['transactionId'] ?? '') === $booking['booking_uuid'],
    'Microsoft event creation keeps the stable booking UUID transactionId for retry deduplication.'
);

$updated = $provider->updateEvent($booking, ['subject' => 'Microsoft CI updated'], $work, 'event-ci');
wpcb_ms_assert(is_array($updated) && !empty($updated['ok']), 'Microsoft event update succeeds.');

$cancelled = $provider->cancelEvent($work, 'event-ci');
wpcb_ms_assert(is_array($cancelled) && !empty($cancelled['ok']), 'Microsoft event delete/cancel succeeds.');

$calledSchedule = false;
$calledCalendarView = false;
foreach ($calls as $call) {
    $calledSchedule = $calledSchedule || str_contains($call['url'], '/getSchedule');
    $calledCalendarView = $calledCalendarView || str_contains($call['url'], '/calendarView?');
}
wpcb_ms_assert($calledSchedule && $calledCalendarView, 'Both Microsoft availability strategies are exercised.');

remove_filter('pre_http_request', $filter, 10);
$repo->delete((int)$workId);
$repo->delete((int)$personalId);

WP_CLI::success('Microsoft Graph provider smoke tests passed.');
