<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_idempotency_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$calendarRepo = new Wpcb\Calendar\CalendarConnectionRepository();
$bookings = new Wpcb\Booking\BookingRepository();
$resourceId = (int)get_option('wpcb_default_resource_id', 0);
wpcb_idempotency_assert($resourceId > 0, 'Provider idempotency fixture has a default resource.');

$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeTable = $wpdb->prefix . 'wpcb_booking_types';
$wpdb->insert($typeTable, [
    'name' => 'Provider idempotency fixture',
    'slug' => 'provider-idempotency-' . strtolower(wp_generate_password(8, false, false)),
    'description' => '',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'capacity' => 1,
    'show_remaining_capacity' => 0,
    'payment_mode' => 'free',
    'price_minor' => 0,
    'currency' => 'EUR',
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]);
$typeId = (int)$wpdb->insert_id;
wpcb_idempotency_assert($typeId > 0, 'Provider idempotency fixture booking type is created.');

$bookingUuid = wp_generate_uuid4();
$bookingId = $bookings->create([
    'booking_uuid' => $bookingUuid,
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2035-03-04 10:00:00',
    'slot_end' => '2035-03-04 10:30:00',
    'party_size' => 1,
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'full_name' => 'Idempotency Fixture',
    'email' => 'idempotency@example.invalid',
    'source' => 'ci',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
], ['subject' => 'Idempotency fixture'], false);
wpcb_idempotency_assert($bookingId > 0, 'Confirmed provider idempotency booking is created.');

$googleId = $calendarRepo->create([
    'provider' => 'google',
    'name' => 'Google idempotency CI',
    'remote_calendar_id' => 'primary',
    'blocks_availability' => 0,
    'receives_bookings' => 1,
], [
    'access_token' => 'CI-GOOGLE-IDEMPOTENCY',
    'expires_at' => time() + 3600,
    'scope' => 'https://www.googleapis.com/auth/calendar.events',
    'token_type' => 'Bearer',
]);
wpcb_idempotency_assert(is_int($googleId) && $googleId > 0, 'Google idempotency connection is created.');
$calendarRepo->setForResource($resourceId, [[
    'connection_id' => $googleId,
    'blocks_availability' => 0,
    'receives_bookings' => 1,
]]);

$googleRemote = null;
$googleRemoteCreates = 0;
$caldavRemote = null;
$caldavRemoteCreates = 0;
$teamsRemote = [];
$teamsRemoteCreates = 0;
$teamsExternalIds = [];
$msRemote = [];
$msRemoteCreates = 0;
$msTransactionIds = [];

$httpFilter = static function ($pre, $args, $url) use (
    &$googleRemote,
    &$googleRemoteCreates,
    &$caldavRemote,
    &$caldavRemoteCreates,
    &$teamsRemote,
    &$teamsRemoteCreates,
    &$teamsExternalIds,
    &$msRemote,
    &$msRemoteCreates,
    &$msTransactionIds
) {
    $method = strtoupper((string)($args['method'] ?? 'GET'));
    $response = static function (int $code, string $message, $body = '', array $headers = []) {
        return [
            'headers' => $headers,
            'response' => ['code' => $code, 'message' => $message],
            'body' => is_string($body) ? $body : wp_json_encode($body),
            'cookies' => [],
            'filename' => null,
        ];
    };

    if ($url === 'https://www.googleapis.com/calendar/v3/calendars/primary/events' && $method === 'POST') {
        $payload = json_decode((string)($args['body'] ?? ''), true);
        $eventId = is_array($payload) ? (string)($payload['id'] ?? '') : '';
        if ($googleRemote === null) {
            $googleRemoteCreates++;
            $googleRemote = [
                'id' => $eventId,
                'extendedProperties' => $payload['extendedProperties'] ?? [],
            ];
            // Simulate: Google committed the event, but the HTTP client lost
            // the response. The provider must reconcile by deterministic ID.
            return new WP_Error('http_request_failed', 'synthetic response loss after remote commit');
        }
        return $response(409, 'Conflict', ['error' => ['message' => 'Duplicate']]);
    }

    if (preg_match('#^https://www.googleapis.com/calendar/v3/calendars/primary/events/([^/?]+)$#', $url, $match)
        && $method === 'GET') {
        $id = rawurldecode($match[1]);
        if (is_array($googleRemote) && hash_equals((string)$googleRemote['id'], $id)) {
            return $response(200, 'OK', $googleRemote);
        }
        return $response(404, 'Not Found', ['error' => ['message' => 'Not found']]);
    }

    if (strpos($url, 'https://8.8.8.8/calendars/idempotency/') === 0) {
        if ($method === 'PUT') {
            if ($caldavRemote === null) {
                $caldavRemoteCreates++;
                $caldavRemote = [
                    'url' => $url,
                    'ics' => (string)($args['body'] ?? ''),
                    'etag' => '"ci-v1"',
                ];
                return new WP_Error('http_request_failed', 'synthetic CalDAV response loss after commit');
            }
            return $response(412, 'Precondition Failed');
        }
        if ($method === 'GET') {
            if (is_array($caldavRemote) && hash_equals((string)$caldavRemote['url'], $url)) {
                return $response(200, 'OK', (string)$caldavRemote['ics'], ['etag' => $caldavRemote['etag']]);
            }
            return $response(404, 'Not Found');
        }
    }

    if (str_contains($url, '/onlineMeetings/createOrGet') && $method === 'POST') {
        $payload = json_decode((string)($args['body'] ?? ''), true);
        $externalId = is_array($payload) ? (string)($payload['externalId'] ?? '') : '';
        $teamsExternalIds[] = $externalId;
        if (!isset($teamsRemote[$externalId])) {
            $teamsRemoteCreates++;
            $teamsRemote[$externalId] = [
                'id' => 'teams-ci-' . $teamsRemoteCreates,
                'joinWebUrl' => 'https://teams.example.invalid/join/' . $teamsRemoteCreates,
            ];
        }
        return $response(201, 'Created', $teamsRemote[$externalId]);
    }

    if ($url === 'https://graph.microsoft.com/v1.0/me/calendar/events' && $method === 'POST') {
        $payload = json_decode((string)($args['body'] ?? ''), true);
        $transactionId = is_array($payload) ? (string)($payload['transactionId'] ?? '') : '';
        $msTransactionIds[] = $transactionId;
        if (!isset($msRemote[$transactionId])) {
            $msRemoteCreates++;
            $msRemote[$transactionId] = ['id' => 'ms-event-' . $msRemoteCreates];
        }
        return $response(201, 'Created', $msRemote[$transactionId]);
    }

    return $pre;
};
add_filter('pre_http_request', $httpFilter, 10, 3);

$metaTable = $wpdb->prefix . 'wpcb_booking_meta';
$trigger = 'wpcb_meta_fail_' . strtolower(wp_generate_password(8, false, false));
$trigger = preg_replace('/[^a-z0-9_]/', '', $trigger);
$createdTrigger = $wpdb->query(
    "CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$metaTable}`
     FOR EACH ROW BEGIN
       IF NEW.meta_key LIKE 'provider_event_google_%' THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic provider meta failure';
       END IF;
     END"
);
wpcb_idempotency_assert($createdTrigger !== false, 'Remote-reference persistence fault injector is installed.');

try {
    $sync = new Wpcb\Calendar\ProviderSyncService($calendarRepo, new Wpcb\Calendar\ProviderRegistry(), $bookings);
    $first = $sync->run('create', $bookingId, (int)$googleId);
    wpcb_idempotency_assert(
        empty($first['ok']) && $googleRemoteCreates === 1,
        'Google response loss followed by local reference failure leaves one remote event and a retryable job result.'
    );
    wpcb_idempotency_assert(
        empty($bookings->getMeta($bookingId)['provider_event_google_' . $googleId]),
        'Failed local reference persistence is not reported as completed provider work.'
    );
} finally {
    $wpdb->query("DROP TRIGGER IF EXISTS `{$trigger}`");
}

$sync = new Wpcb\Calendar\ProviderSyncService($calendarRepo, new Wpcb\Calendar\ProviderRegistry(), $bookings);
$retry = $sync->run('create', $bookingId, (int)$googleId);
$googleMeta = $bookings->getMeta($bookingId);
$googleEventId = (string)($googleMeta['provider_event_google_' . $googleId] ?? '');
wpcb_idempotency_assert(
    !empty($retry['ok']) && $googleRemoteCreates === 1 && $googleEventId !== '',
    'Google retry reconciles the owned deterministic event without creating a duplicate.'
);
wpcb_idempotency_assert(
    preg_match('/\Ab[0-9a-f]{51}\z/', $googleEventId) === 1,
    'Google retry-safe event ID uses the provider-compatible deterministic format.'
);

// CalDAV uses a deterministic resource URL/UID and If-None-Match. A lost
// response or later precondition conflict must recover the same owned object.
$caldavId = $calendarRepo->create([
    'provider' => 'caldav',
    'name' => 'CalDAV idempotency CI',
    'remote_calendar_id' => 'https://8.8.8.8/calendars/idempotency/',
    'blocks_availability' => 0,
    'receives_bookings' => 1,
    'config' => [
        'endpoint' => 'https://8.8.8.8/',
        'calendar_url' => 'https://8.8.8.8/calendars/idempotency/',
    ],
], [
    'username' => 'ci-user',
    'password' => 'ci-secret',
]);
$caldavConnection = $calendarRepo->find((int)$caldavId);
$caldavProvider = new Wpcb\Calendar\CalDavProvider($calendarRepo);
$bookingArray = (array)$bookings->find($bookingId);
$bookingMeta = $bookings->getMeta($bookingId);
$caldavFirst = $caldavProvider->createEvent($bookingArray, $bookingMeta, $caldavConnection);
$caldavSecond = $caldavProvider->createEvent($bookingArray, $bookingMeta, $caldavConnection);
wpcb_idempotency_assert(
    is_array($caldavFirst) && !empty($caldavFirst['event_id'])
    && ($caldavFirst['event_id'] ?? '') === ($caldavSecond['event_id'] ?? '')
    && $caldavRemoteCreates === 1,
    'CalDAV response loss and repeated create recover the same deterministic UID/resource without duplication.'
);

// Microsoft calendar events already use transactionId. Preserve and prove that
// retrying the logical create presents the same transaction identity.
$microsoftId = $calendarRepo->create([
    'provider' => 'microsoft',
    'name' => 'Microsoft idempotency CI',
    'remote_calendar_id' => 'primary',
    'blocks_availability' => 0,
    'receives_bookings' => 1,
], [
    'access_token' => 'CI-MS-IDEMPOTENCY',
    'expires_at' => time() + 3600,
    'account_type' => 'work_school',
    'tenant_id' => '11111111-2222-3333-4444-555555555555',
]);
$microsoftConnection = $calendarRepo->find((int)$microsoftId);
$microsoftProvider = new Wpcb\Calendar\MicrosoftGraphProvider($calendarRepo, new Wpcb\Calendar\MicrosoftOAuthConfig());
$msFirst = $microsoftProvider->createEvent($bookingArray, $bookingMeta, $microsoftConnection);
$msSecond = $microsoftProvider->createEvent($bookingArray, $bookingMeta, $microsoftConnection);
wpcb_idempotency_assert(
    is_array($msFirst) && is_array($msSecond)
    && count($msTransactionIds) === 2
    && $msTransactionIds[0] === $bookingUuid
    && $msTransactionIds[1] === $bookingUuid
    && $msRemoteCreates === 1,
    'Microsoft calendar retries preserve the stable transactionId instead of creating a second logical event.'
);

// Teams supports createOrGet with an externalId. The same booking/destination
// must therefore resolve to the same meeting after a retry.
$teamsConnection = (object)[
    'id' => 424242,
    'provider' => 'microsoft_teams',
    'config' => ['user_id' => 'ci-user@example.invalid'],
    'credentials' => ['access_token' => 'CI-TEAMS-IDEMPOTENCY'],
];
$teamsProvider = new Wpcb\VideoMeetings\TeamsProvider();
$teamsFirst = $teamsProvider->create($bookingArray, $teamsConnection);
$teamsSecond = $teamsProvider->create($bookingArray, $teamsConnection);
wpcb_idempotency_assert(
    !empty($teamsFirst['ok']) && !empty($teamsSecond['ok'])
    && ($teamsFirst['remote_id'] ?? '') === ($teamsSecond['remote_id'] ?? '')
    && count($teamsExternalIds) === 2
    && $teamsExternalIds[0] !== ''
    && hash_equals($teamsExternalIds[0], $teamsExternalIds[1])
    && $teamsRemoteCreates === 1,
    'Teams retries use createOrGet with one stable externalId and one logical remote meeting.'
);

remove_filter('pre_http_request', $httpFilter, 10);
$calendarRepo->setForResource($resourceId, []);
foreach ([(int)$googleId, (int)$caldavId, (int)$microsoftId] as $connectionId) {
    if ($connectionId > 0) {
        $calendarRepo->delete($connectionId);
    }
}
$wpdb->delete($wpdb->prefix . 'wpcb_sync_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
$wpdb->delete($typeTable, ['id' => $typeId]);

WP_CLI::success('Provider create idempotency smoke test passed.');
