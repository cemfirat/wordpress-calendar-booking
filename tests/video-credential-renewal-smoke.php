<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_renew_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

final class WpcbRenewHttp {
    public static array $tokenCalls = [];
    public static array $apiCalls = [];
    public static int $tokenStatus = 200;
    public static string $tokenError = 'invalid_grant';
    public static int $tokenSeq = 0;
    public static int $apiStatus = 201;
    public static array $rejectTokens = [];

    public static function reply(int $code, array $body): array {
        return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    public static function filter($pre, array $args, string $url) {
        if (strpos($url, 'https://zoom.us/oauth/token') === 0 || strpos($url, 'oauth2.googleapis.com/token') !== false || strpos($url, 'login.microsoftonline.com') !== false) {
            self::$tokenCalls[] = ['url' => $url, 'args' => $args];
            if (self::$tokenStatus !== 200) {
                return self::reply(self::$tokenStatus, ['error' => self::$tokenError, 'error_description' => 'SECRET-MUST-NOT-LEAK']);
            }
            self::$tokenSeq++;
            return self::reply(200, ['access_token' => 'TOKEN-' . self::$tokenSeq, 'expires_in' => 3600]);
        }
        if (strpos($url, 'api.zoom.us') !== false || strpos($url, 'graph.microsoft.com') !== false || strpos($url, 'meet.googleapis.com') !== false) {
            $auth = (string)($args['headers']['Authorization'] ?? '');
            self::$apiCalls[] = ['url' => $url, 'auth' => $auth];
            foreach (self::$rejectTokens as $rejected) {
                if ($auth === 'Bearer ' . $rejected) return self::reply(401, ['error' => 'expired']);
            }
            if (self::$apiStatus >= 300) return self::reply(self::$apiStatus, ['error' => 'nope']);
            if (strpos($url, 'meet.googleapis.com') !== false) {
                return self::reply(200, ['name' => 'spaces/abc', 'meetingUri' => 'https://meet.google.com/abc-defg-hij']);
            }
            return self::reply(201, ['id' => '987', 'join_url' => 'https://zoom.example.test/j/987', 'joinWebUrl' => 'https://teams.example.test/j/987']);
        }
        return $pre;
    }

    public static function reset(): void {
        self::$tokenCalls = [];
        self::$apiCalls = [];
        self::$tokenStatus = 200;
        self::$apiStatus = 201;
        self::$rejectTokens = [];
    }
}

add_filter('pre_http_request', ['WpcbRenewHttp', 'filter'], 10, 3);
add_filter('pre_wp_mail', static fn() => true);

global $wpdb;
$repo = new Wpcb\VideoMeetings\VideoMeetingConnectionRepository();
$tokens = new Wpcb\VideoMeetings\VideoTokenManager();
$zoom = new Wpcb\VideoMeetings\ZoomProvider();
$booking = ['id' => 7, 'slot_start' => '2033-05-01 10:00:00', 'slot_end' => '2033-05-01 10:30:00'];

$zoomId = $repo->save([
    'provider' => 'zoom', 'name' => 'Renewal Zoom', 'is_active' => 1,
    'account_id' => 'ACC-1', 'client_id' => 'CLIENT-1', 'client_secret' => 'CLIENT-SECRET-MUST-NOT-LEAK',
    'config' => ['user_id' => 'me'],
]);
wpcb_renew_assert(!is_wp_error($zoomId) && $zoomId > 0, 'Renewable Zoom connection is stored.');
$cipher = (string)$wpdb->get_var($wpdb->prepare("SELECT credentials_enc FROM {$wpdb->prefix}wpcb_video_connections WHERE id = %d", $zoomId));
wpcb_renew_assert($cipher !== '' && strpos($cipher, 'CLIENT-SECRET-MUST-NOT-LEAK') === false, 'Client credentials are encrypted at rest.');
$versionBefore = (string)$wpdb->get_var($wpdb->prepare("SELECT updated_at FROM {$wpdb->prefix}wpcb_video_connections WHERE id = %d", $zoomId));

$created = $zoom->create($booking, $repo->find($zoomId, true));
wpcb_renew_assert(!empty($created['ok']) && count(WpcbRenewHttp::$tokenCalls) === 1, 'First call acquires one Zoom token.');
wpcb_renew_assert(WpcbRenewHttp::$apiCalls[0]['auth'] === 'Bearer TOKEN-1', 'Acquired token authorizes the provider call.');
$basic = (string)(WpcbRenewHttp::$tokenCalls[0]['args']['headers']['Authorization'] ?? '');
wpcb_renew_assert($basic === 'Basic ' . base64_encode('CLIENT-1:CLIENT-SECRET-MUST-NOT-LEAK'), 'Zoom token request uses client credentials.');
$stored = $repo->find($zoomId, true);
wpcb_renew_assert(($stored->credentials['access_token'] ?? '') === 'TOKEN-1' && (int)$stored->credentials['expires_at'] > time() + 3000, 'Token and expiry metadata are persisted encrypted.');
wpcb_renew_assert((string)$wpdb->get_var($wpdb->prepare("SELECT updated_at FROM {$wpdb->prefix}wpcb_video_connections WHERE id = %d", $zoomId)) === $versionBefore, 'Renewal does not change the connection version used by queued jobs.');

$zoom->create($booking, $repo->find($zoomId, true));
wpcb_renew_assert(count(WpcbRenewHttp::$tokenCalls) === 1, 'A valid token is reused without another renewal.');

// Two workers load the same expired credentials; only one renews.
$expired = $stored->credentials;
$expired['expires_at'] = time() - 10;
$repo->updateCredentials($zoomId, $expired);
$workerA = $repo->find($zoomId, true);
$workerB = $repo->find($zoomId, true);
$first = $tokens->access($workerA);
wpcb_renew_assert(!empty($first['ok']) && count(WpcbRenewHttp::$tokenCalls) === 2, 'Renewal happens after expiry.');
$second = $tokens->access($workerB);
wpcb_renew_assert(!empty($second['ok']) && $second['token'] === $first['token'] && count(WpcbRenewHttp::$tokenCalls) === 2, 'Concurrent workers reuse the freshly renewed token instead of renewing twice.');

// A held renewal lock yields a retryable busy result and does not call the provider.
[$host, $port, $socket] = $wpdb->parse_db_host(DB_HOST);
$other = new mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port ?: 3306, $socket ?: null);
$lockName = 'wpcb_vidtok_' . substr(hash('sha256', (string)$zoomId), 0, 40);
$other->query("SELECT GET_LOCK('" . $other->real_escape_string($lockName) . "', 1)");
$expired['expires_at'] = time() - 10;
$repo->updateCredentials($zoomId, $expired);
$calls = count(WpcbRenewHttp::$tokenCalls);
$busy = $tokens->access($repo->find($zoomId, true));
wpcb_renew_assert(empty($busy['ok']) && $busy['code'] === 'busy' && count(WpcbRenewHttp::$tokenCalls) === $calls, 'Renewal is bounded by a per-connection lock.');
$other->query("SELECT RELEASE_LOCK('" . $other->real_escape_string($lockName) . "')");
$other->close();

// A 401 on a token that looks valid triggers one renewal and one retry.
$ok = $tokens->access($repo->find($zoomId, true));
WpcbRenewHttp::$rejectTokens = [$ok['token']];
$calls = count(WpcbRenewHttp::$tokenCalls);
$retried = $zoom->create($booking, $repo->find($zoomId, true));
wpcb_renew_assert(!empty($retried['ok']) && count(WpcbRenewHttp::$tokenCalls) === $calls + 1, 'A rejected token is renewed and the request retried once.');

// Missing scope or account policy is reported without discarding the grant.
WpcbRenewHttp::reset();
WpcbRenewHttp::$apiStatus = 403;
$denied = $zoom->create($booking, $repo->find($zoomId, true));
wpcb_renew_assert(empty($denied['ok']) && $denied['message'] === 'Provider HTTP 403', 'Missing scope surfaces as an actionable HTTP 403.');
wpcb_renew_assert(($repo->find($zoomId)->config['auth_status'] ?? '') !== 'reauth_required', 'A scope error does not mark the grant as revoked.');

// Revoked credentials need reconnecting, then recover.
WpcbRenewHttp::reset();
$expired = $repo->find($zoomId, true)->credentials;
$expired['expires_at'] = time() - 10;
$repo->updateCredentials($zoomId, $expired);
WpcbRenewHttp::$tokenStatus = 400;
$revoked = $zoom->create($booking, $repo->find($zoomId, true));
wpcb_renew_assert(empty($revoked['ok']) && strpos($revoked['message'], 'invalid_grant') !== false && strpos($revoked['message'], 'SECRET-MUST-NOT-LEAK') === false, 'Revoked grant yields an actionable error without provider secrets.');
wpcb_renew_assert(($repo->find($zoomId)->config['auth_status'] ?? '') === 'reauth_required', 'Revoked grant is recorded as reconnect required.');
WpcbRenewHttp::reset();
$repo->save(['provider' => 'zoom', 'name' => 'Renewal Zoom', 'is_active' => 1, 'client_secret' => 'NEW-CLIENT-SECRET', 'config' => ['user_id' => 'me']], $zoomId);
wpcb_renew_assert(($repo->find($zoomId)->config['auth_status'] ?? '') === 'ok', 'Reconnecting clears the reconnect-required state.');
$recovered = $zoom->create($booking, $repo->find($zoomId, true));
wpcb_renew_assert(!empty($recovered['ok']), 'Provider calls recover after reconnecting.');

// Transient token-endpoint failure is retryable and is not recorded as revoked.
$expired = $repo->find($zoomId, true)->credentials;
$expired['expires_at'] = time() - 10;
$repo->updateCredentials($zoomId, $expired);
WpcbRenewHttp::$tokenStatus = 503;
$transient = $zoom->create($booking, $repo->find($zoomId, true));
wpcb_renew_assert(empty($transient['ok']) && ($repo->find($zoomId)->config['auth_status'] ?? '') === 'ok', 'Token endpoint outages stay retryable.');
WpcbRenewHttp::reset();

// Google Meet and Teams use their own grants.
$meetId = $repo->save(['provider' => 'google_meet', 'name' => 'Renewal Meet', 'is_active' => 1, 'refresh_token' => 'R-1', 'client_id' => 'G-ID', 'client_secret' => 'G-SECRET']);
$meet = (new Wpcb\VideoMeetings\GoogleMeetProvider())->create($booking, $repo->find($meetId, true));
wpcb_renew_assert(!empty($meet['ok']) && strpos(WpcbRenewHttp::$tokenCalls[0]['url'], 'oauth2.googleapis.com/token') !== false && WpcbRenewHttp::$tokenCalls[0]['args']['body']['grant_type'] === 'refresh_token', 'Google Meet renews with its refresh token.');

WpcbRenewHttp::reset();
$teamsId = $repo->save(['provider' => 'microsoft_teams', 'name' => 'Renewal Teams', 'is_active' => 1, 'tenant_id' => 'TENANT', 'client_id' => 'M-ID', 'client_secret' => 'M-SECRET', 'config' => ['user_id' => 'user-1']]);
$teams = (new Wpcb\VideoMeetings\TeamsProvider())->create($booking + ['booking_uuid' => 'uuid-1'], $repo->find($teamsId, true));
wpcb_renew_assert(!empty($teams['ok']) && WpcbRenewHttp::$tokenCalls[0]['args']['body']['grant_type'] === 'client_credentials' && strpos(WpcbRenewHttp::$tokenCalls[0]['url'], '/TENANT/oauth2/v2.0/token') !== false, 'Teams renews with tenant client credentials.');

WpcbRenewHttp::reset();
$emptyId = $repo->save(['provider' => 'microsoft_teams', 'name' => 'No credentials', 'is_active' => 1]);
$missing = (new Wpcb\VideoMeetings\TeamsProvider())->create($booking + ['booking_uuid' => 'uuid-2'], $repo->find($emptyId, true));
wpcb_renew_assert(empty($missing['ok']) && !WpcbRenewHttp::$apiCalls && strpos($missing['message'], 'credentials are missing') !== false, 'Missing credentials fail before any provider call.');

// Required meetings stay non-blocking and can be recovered manually.
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$wpdb->insert($wpdb->prefix . 'wpcb_booking_types', [
    'name'=>'Renewal Video','slug'=>'renewal-video-' . wp_generate_password(6,false),
    'description'=>'','duration_minutes'=>30,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,
    'capacity'=>1,'show_remaining_capacity'=>0,'payment_mode'=>'free','price_minor'=>0,'currency'=>'EUR',
    'is_active'=>1,'is_public'=>1,'sort_order'=>0,'created_at'=>$now,'updated_at'=>$now,
]);
$typeId = (int)$wpdb->insert_id;
$repo->setForBookingType($typeId, [['connection_id' => $zoomId, 'is_required' => true]]);
$mapped = $repo->forBookingType($typeId);
wpcb_renew_assert(count($mapped) === 1 && (int)$mapped[0]->is_required === 1, 'The required flag is stored and returned.');

$bookings = new Wpcb\Booking\BookingRepository();
$bookingId = $bookings->create([
    'booking_uuid'=>wp_generate_uuid4(),'booking_type_id'=>$typeId,'resource_id'=>(int)get_option('wpcb_default_resource_id',0),
    'slot_start'=>'2033-05-02 10:00:00','slot_end'=>'2033-05-02 10:30:00','party_size'=>1,
    'status'=>Wpcb\Booking\BookingStatus::CONFIRMED,'full_name'=>'Renewal Customer','email'=>'renewal@example.com',
    'source'=>'test','lang'=>'de','created_at'=>$now,'updated_at'=>$now,
]);
WpcbRenewHttp::reset();
WpcbRenewHttp::$apiStatus = 403;
$runner = new Wpcb\VideoMeetings\VideoMeetingJobRunner();
$failed = $runner->run('create', $bookingId, $zoomId);
$meetings = new Wpcb\VideoMeetings\VideoMeetingRepository();
$row = $meetings->find($bookingId, $zoomId);
wpcb_renew_assert(empty($failed['ok']) && $row && $row->status === 'error', 'A failed required meeting is recorded visibly.');
wpcb_renew_assert($bookings->find($bookingId)->status === Wpcb\Booking\BookingStatus::CONFIRMED, 'A failed required meeting never rolls back the booking.');
WpcbRenewHttp::$apiStatus = 201;
wpcb_renew_assert((new Wpcb\VideoMeetings\VideoMeetingService())->retry($bookingId, $zoomId), 'Manual recovery re-queues the meeting.');
$row = $meetings->find($bookingId, $zoomId);
wpcb_renew_assert($row && $row->status === 'active' && (string)$row->join_url !== '', 'Manual recovery creates the meeting after the provider issue is fixed.');

foreach (['deliveries', 'sync_log', 'sync_jobs', 'booking_status_log', 'booking_meta'] as $table) {
    $wpdb->delete($wpdb->prefix . 'wpcb_' . $table, ['booking_id' => $bookingId]);
}
$wpdb->delete($wpdb->prefix . 'wpcb_video_meetings', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_type_video_connections', ['booking_type_id' => $typeId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $typeId]);
foreach ([$zoomId, $meetId, $teamsId, $emptyId] as $id) {
    $wpdb->delete($wpdb->prefix . 'wpcb_video_connections', ['id' => $id]);
}
remove_filter('pre_http_request', ['WpcbRenewHttp', 'filter'], 10);

echo "PASS: video credential renewal smoke test complete.\n";
