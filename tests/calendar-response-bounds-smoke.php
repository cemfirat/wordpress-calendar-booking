<?php
if (!defined('ABSPATH')) { exit(1); }

use Wpcb\Security\OutboundUrlPolicy;

function wpcb_response_bound_assert($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    WP_CLI::log('PASS: ' . $message);
}

$mode = 'exact';
$seenLimit = 0;
$mock = static function ($preempt, array $args, string $url) use (&$mode, &$seenLimit) {
    if ($url !== 'https://8.8.8.8/calendar.ics') return $preempt;
    $seenLimit = (int)($args['limit_response_size'] ?? 0);
    $body = $mode === 'over' ? str_repeat('x', 17) : str_repeat('x', 16);
    $length = $mode === 'header-over' ? '17' : (string)strlen($body);
    return ['headers'=>['content-length'=>$length],'body'=>$body,'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];
};
add_filter('pre_http_request', $mock, 10, 3);
$exact = OutboundUrlPolicy::get('https://8.8.8.8/calendar.ics', ['wpcb_max_response_bytes'=>16]);
wpcb_response_bound_assert(!is_wp_error($exact), 'A response exactly at the configured limit is accepted.');
wpcb_response_bound_assert($seenLimit === 17, 'HTTP transport reads only limit plus one byte.');
$mode='over';
$over=OutboundUrlPolicy::get('https://8.8.8.8/calendar.ics',['wpcb_max_response_bytes'=>16]);
wpcb_response_bound_assert(is_wp_error($over)&&$over->get_error_code()==='wpcb_calendar_response_too_large','Body overflow fails closed.');
$mode='header-over';
$over=OutboundUrlPolicy::get('https://8.8.8.8/calendar.ics',['wpcb_max_response_bytes'=>16]);
wpcb_response_bound_assert(is_wp_error($over)&&$over->get_error_code()==='wpcb_calendar_response_too_large','Content-Length overflow fails closed.');
remove_filter('pre_http_request',$mock,10);

$defaultLimit=0;
$f=static function($pre,array $args,string $url)use(&$defaultLimit){
    if($url!=='https://8.8.8.8/default.ics')return $pre;
    $defaultLimit=(int)($args['limit_response_size']??0);
    return ['headers'=>['content-length'=>'2'],'body'=>'OK','response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];
};
add_filter('pre_http_request',$f,10,3);
$r=OutboundUrlPolicy::get('https://8.8.8.8/default.ics');
remove_filter('pre_http_request',$f,10);
wpcb_response_bound_assert(!is_wp_error($r)&&$defaultLimit===OutboundUrlPolicy::MAX_ICS_RESPONSE_BYTES+1,'Public ICS defaults to the 2 MiB ceiling plus one byte.');

$seen=[];
$f=static function($pre,array $args,string $url)use(&$seen){
    if(!in_array($url,['https://8.8.8.8/size-start','https://1.1.1.1/size-end'],true))return $pre;
    $seen[]=$url;
    if($url==='https://8.8.8.8/size-start')return ['headers'=>['location'=>'https://1.1.1.1/size-end','content-length'=>'0'],'body'=>'','response'=>['code'=>302,'message'=>'Found'],'cookies'=>[],'filename'=>null];
    return ['headers'=>['content-length'=>'17'],'body'=>str_repeat('x',17),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[],'filename'=>null];
};
add_filter('pre_http_request',$f,10,3);
$r=OutboundUrlPolicy::get('https://8.8.8.8/size-start',['redirection'=>2,'wpcb_max_response_bytes'=>16]);
remove_filter('pre_http_request',$f,10);
wpcb_response_bound_assert(is_wp_error($r)&&$r->get_error_code()==='wpcb_calendar_response_too_large'&&count($seen)===2,'Every validated redirect hop keeps the response ceiling.');

$rows='';
for($i=0;$i<251;$i++)$rows.='<d:response><d:href>/calendars/user/c'.$i.'/</d:href><d:propstat><d:prop><d:displayname>C'.$i.'</d:displayname><d:resourcetype><d:collection/><c:calendar/></d:resourcetype></d:prop></d:propstat></d:response>';
$f=static function($pre,array $args,string $url)use($rows){
    $resp=static fn(string $body)=>['headers'=>['content-length'=>(string)strlen($body)],'body'=>$body,'response'=>['code'=>207,'message'=>'Multi-Status'],'cookies'=>[],'filename'=>null];
    if($url==='https://8.8.8.8/')return $resp('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop><d:current-user-principal><d:href>/principals/user/</d:href></d:current-user-principal></d:prop></d:propstat></d:response></d:multistatus>');
    if($url==='https://8.8.8.8/principals/user/')return $resp('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response><d:propstat><d:prop><c:calendar-home-set><d:href>/calendars/user/</d:href></c:calendar-home-set></d:prop></d:propstat></d:response></d:multistatus>');
    if($url==='https://8.8.8.8/calendars/user/')return $resp('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'.$rows.'</d:multistatus>');
    return $pre;
};
add_filter('pre_http_request',$f,10,3);
$client=new Wpcb\Calendar\CalDavClient('https://8.8.8.8/','user','secret');
$r=$client->discoverCalendars();
remove_filter('pre_http_request',$f,10);
wpcb_response_bound_assert(is_wp_error($r)&&$r->get_error_code()==='wpcb_caldav_discovery_too_large','CalDAV discovery rejects more than 250 records.');

$queryMode='malformed'; $queryLimit=0; $mutationLimit=0; $rows='';
for($i=0;$i<2001;$i++)$rows.='<d:response><d:href>/event-'.$i.'.ics</d:href><d:propstat><d:prop><c:calendar-data>BEGIN:VCALENDAR&#13;&#10;END:VCALENDAR</c:calendar-data></d:prop></d:propstat></d:response>';
$f=static function($pre,array $args,string $url)use(&$queryMode,&$queryLimit,&$mutationLimit,$rows){
    $method=strtoupper((string)($args['method']??'GET'));
    if($method==='REPORT'&&$url==='https://8.8.8.8/calendars/user/work/'){
        $queryLimit=(int)($args['limit_response_size']??0);
        $body=$queryMode==='many'?'<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'.$rows.'</d:multistatus>':'<d:multistatus';
        return ['headers'=>['content-length'=>(string)strlen($body)],'body'=>$body,'response'=>['code'=>207,'message'=>'Multi-Status'],'cookies'=>[],'filename'=>null];
    }
    if($method==='PUT'&&$url==='https://8.8.8.8/calendars/user/work/test.ics'){
        $mutationLimit=(int)($args['limit_response_size']??0);
        return ['headers'=>[],'body'=>'','response'=>['code'=>201,'message'=>'Created'],'cookies'=>[],'filename'=>null];
    }
    return $pre;
};
add_filter('pre_http_request',$f,10,3);
$r=$client->calendarQuery('https://8.8.8.8/calendars/user/work/','2026-11-01 00:00:00','2026-11-05 00:00:00');
wpcb_response_bound_assert(is_wp_error($r)&&$r->get_error_code()==='wpcb_caldav_xml','Malformed/truncated CalDAV query XML fails closed.');
wpcb_response_bound_assert($queryLimit===OutboundUrlPolicy::MAX_CALDAV_RESPONSE_BYTES+1,'CalDAV reads use the 4 MiB ceiling plus one byte.');
$queryMode='many';
$r=$client->calendarQuery('https://8.8.8.8/calendars/user/work/','2026-11-01 00:00:00','2026-11-05 00:00:00');
wpcb_response_bound_assert(is_wp_error($r)&&$r->get_error_code()==='wpcb_caldav_query_too_large','CalDAV busy queries reject more than 2,000 records.');
$r=$client->putEvent('https://8.8.8.8/calendars/user/work/test.ics',"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n",null,true);
remove_filter('pre_http_request',$f,10);
wpcb_response_bound_assert(!is_wp_error($r)&&$mutationLimit===OutboundUrlPolicy::MAX_MUTATION_RESPONSE_BYTES+1,'CalDAV mutations use the 256 KiB ceiling plus one byte.');

$settingsBefore = get_option('wpcb_settings', []);
$oversizedFeedUrl = 'https://8.8.8.8/oversized-calendar.ics';
$settings = Wpcb\Admin\Settings::get();
$settings['calendar_url'] = $oversizedFeedUrl;
$settings['calendar_urls'] = $oversizedFeedUrl;
update_option('wpcb_settings', $settings);
delete_transient('wpcb_ical_' . md5($oversizedFeedUrl));
$feedMock = static function($pre, array $args, string $url) use ($oversizedFeedUrl) {
    if ($url !== $oversizedFeedUrl) return $pre;
    $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:must-not-parse\r\nDTSTART:20261102T090000Z\r\nDTEND:20261102T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    return [
        'headers' => ['content-length' => (string)(OutboundUrlPolicy::MAX_ICS_RESPONSE_BYTES + 1)],
        'body' => $body,
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
};
add_filter('pre_http_request', $feedMock, 10, 3);
$feedEvents = (new Wpcb\Calendar\IcloudProvider())->events('2026-11-01 00:00:00', '2026-11-05 00:00:00');
remove_filter('pre_http_request', $feedMock, 10);
delete_transient('wpcb_ical_' . md5($oversizedFeedUrl));
update_option('wpcb_settings', $settingsBefore);
wpcb_response_bound_assert($feedEvents === [], 'Oversized public ICS data is rejected before the iCalendar parser produces busy events.');

$repo = new Wpcb\Calendar\CalendarConnectionRepository();
$healthId = $repo->create([
    'provider' => 'caldav',
    'name' => 'Response bound health fixture',
    'remote_calendar_id' => 'https://8.8.8.8/calendars/user/work/',
    'blocks_availability' => 1,
    'receives_bookings' => 0,
    'config' => [
        'endpoint' => 'https://8.8.8.8/',
        'calendar_url' => 'https://8.8.8.8/calendars/user/work/',
        'preset' => 'generic',
    ],
], [
    'username' => 'health-user',
    'password' => 'health-secret',
]);
wpcb_response_bound_assert(!is_wp_error($healthId) && $healthId > 0, 'CalDAV health fixture is created.');
$healthConnection = $repo->find((int)$healthId);
$healthMock = static function($pre, array $args, string $url) {
    if (strtoupper((string)($args['method'] ?? 'GET')) !== 'REPORT'
        || $url !== 'https://8.8.8.8/calendars/user/work/') {
        return $pre;
    }
    return [
        'headers' => ['content-length' => (string)(OutboundUrlPolicy::MAX_CALDAV_RESPONSE_BYTES + 1)],
        'body' => 'PRIVATE REMOTE CALENDAR DETAIL',
        'response' => ['code' => 207, 'message' => 'Multi-Status'],
        'cookies' => [],
        'filename' => null,
    ];
};
add_filter('pre_http_request', $healthMock, 10, 3);
$healthResult = (new Wpcb\Calendar\CalDavProvider($repo))->busyBetween(
    '2026-11-01 00:00:00',
    '2026-11-05 00:00:00',
    $healthConnection
);
remove_filter('pre_http_request', $healthMock, 10);
$healthAfter = $repo->find((int)$healthId);
wpcb_response_bound_assert(
    is_wp_error($healthResult)
        && $healthResult->get_error_code() === 'wpcb_calendar_response_too_large'
        && $healthAfter !== null
        && $healthAfter->healthStatus === 'error'
        && stripos($healthAfter->lastErrorMessage, 'too large') !== false
        && strpos($healthAfter->lastErrorMessage, 'PRIVATE') === false,
    'Provider health records a generic bounded error without remote calendar content.'
);
$repo->delete((int)$healthId);

$syncSource=file_get_contents(WPCB_DIR.'includes/Sync/CalDavClient.php');
wpcb_response_bound_assert(strpos($syncSource,'MAX_CALDAV_DISCOVERY_RECORDS')!==false&&strpos($syncSource,'MAX_MUTATION_RESPONSE_BYTES')!==false,'iCloud/legacy CalDAV uses the shared discovery and mutation bounds.');
WP_CLI::success('External calendar response bound smoke test passed.');
