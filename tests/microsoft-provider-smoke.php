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
$filter = static function ($pre, $args, $url) use (&$calls) {
    if (strpos($url, 'https://graph.microsoft.com/v1.0') !== 0) {
        return $pre;
    }

    $calls[] = ['url' => $url, 'method' => $args['method'] ?? 'GET', 'body' => $args['body'] ?? null];
    $headers = ['content-type' => 'application/json'];

    if (str_contains($url, '/me/calendar/getSchedule')) {
        return [
            'headers' => $headers,
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => wp_json_encode([
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
        return [
            'headers' => $headers,
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => wp_json_encode([
                'value' => [[
                    'id' => 'personal-busy',
                    'showAs' => 'busy',
                    'isCancelled' => false,
                    'start' => ['dateTime' => '2026-11-03T11:00:00', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-11-03T12:00:00', 'timeZone' => 'UTC'],
                ]],
            ]),
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

$booking = [
    'booking_uuid' => 'microsoft-ci-booking',
    'booking_type_id' => 1,
    'slot_start' => '2026-11-04 13:00:00',
    'slot_end' => '2026-11-04 14:00:00',
    'notes' => 'CI',
];
$created = $provider->createEvent($booking, ['subject' => 'Microsoft CI'], $work);
wpcb_ms_assert(is_array($created) && ($created['event_id'] ?? '') === 'event-ci', 'Microsoft event create returns the Graph event ID.');

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
