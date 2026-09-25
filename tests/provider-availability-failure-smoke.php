<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_availability_failure_assert($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    WP_CLI::log('PASS: ' . $message);
}

$typeRepo = new Wpcb\Booking\BookingTypeRepository();
$types = $typeRepo->all(true);
wpcb_availability_failure_assert(!empty($types), 'A public booking type exists for fail-closed availability checks.');
$typeId = (int)$types[0]->id;
$resourceRepo = new Wpcb\Resources\ResourceRepository();
$resources = $resourceRepo->forBookingType($typeId, true);
wpcb_availability_failure_assert(!empty($resources), 'The fixture booking type has an assigned resource.');
$resourceId = (int)$resources[0]->id;
$baseline = (new Wpcb\Availability\SlotService())->getSlotsResult($typeId, 21, null, 1);
wpcb_availability_failure_assert(is_array($baseline) && !empty($baseline), 'Baseline availability is readable before attaching a failing provider.');
$baselineSlot = $baseline[0];

$GLOBALS['wpcb_fail_closed_mode'] = 'error';
$provider = new class implements Wpcb\Calendar\CalendarSyncProviderInterface {
    public function id(): string { return 'ci-fail-closed'; }
    public function label(): string { return 'CI fail-closed provider'; }
    public function capabilities(): array { return [Wpcb\Calendar\ProviderCapabilities::BUSY_READ]; }
    public function busyBetween(string $fromUtc, string $toUtc, Wpcb\Calendar\CalendarConnection $connection) {
        return ($GLOBALS['wpcb_fail_closed_mode'] ?? '') === 'error'
            ? new WP_Error('ci_provider_down', 'PRIVATE REMOTE DETAIL')
            : [];
    }
    public function createEvent(array $booking, array $meta, Wpcb\Calendar\CalendarConnection $connection) { return new WP_Error('unsupported', 'unsupported'); }
    public function updateEvent(array $booking, array $meta, Wpcb\Calendar\CalendarConnection $connection, string $eventId) { return new WP_Error('unsupported', 'unsupported'); }
    public function cancelEvent(Wpcb\Calendar\CalendarConnection $connection, string $eventId) { return new WP_Error('unsupported', 'unsupported'); }
};
$providerFilter = static function(array $providers) use ($provider): array { $providers[] = $provider; return $providers; };
add_filter('wpcb_calendar_providers', $providerFilter, 999);
$connectionRepo = new Wpcb\Calendar\CalendarConnectionRepository();
$connectionId = $connectionRepo->create([
    'provider'=>'ci-fail-closed','name'=>'Fail closed fixture','remote_calendar_id'=>'fixture',
    'blocks_availability'=>1,'receives_bookings'=>0,
], []);
wpcb_availability_failure_assert(is_int($connectionId) && $connectionId > 0, 'Failing blocking provider fixture is created.');
$connectionRepo->setForBookingType($typeId, [[
    'connection_id'=>$connectionId,'blocks_availability'=>1,'receives_bookings'=>0,
]]);
$service = new Wpcb\Availability\SlotService();
$failed = $service->getSlotsResult($typeId, 21, null, 1);
wpcb_availability_failure_assert(is_wp_error($failed) && $failed->get_error_code()==='wpcb_availability_unknown', 'A blocking provider failure is preserved as unknown availability instead of a successful empty calendar.');
wpcb_availability_failure_assert(strpos($failed->get_error_message(), 'PRIVATE REMOTE DETAIL')===false, 'Public availability errors do not expose provider response details.');
wpcb_availability_failure_assert(!$service->slotAvailable($typeId,(string)$baselineSlot['start'],(string)$baselineSlot['end'],null,$resourceId,1), 'Final slot validation fails closed while a blocking provider is unavailable.');
$GLOBALS['wpcb_fail_closed_mode'] = 'ok';
$recovered = (new Wpcb\Availability\SlotService())->getSlotsResult($typeId,21,null,1);
wpcb_availability_failure_assert(is_array($recovered) && !empty($recovered), 'Availability recovers after the blocking provider succeeds again.');
$connectionRepo->setForBookingType($typeId, []);
$connectionRepo->delete($connectionId);
remove_filter('wpcb_calendar_providers', $providerFilter, 999);

$googleId = $connectionRepo->create([
    'provider'=>'google','name'=>'Google embedded error fixture','remote_calendar_id'=>'primary',
    'blocks_availability'=>1,'receives_bookings'=>0,
], ['access_token'=>'CI-GOOGLE-ACCESS','expires_at'=>time()+3600]);
$googleConnection = $connectionRepo->find((int)$googleId);
$googleFilter = static function($pre,array $args,string $url) {
    if ($url !== 'https://www.googleapis.com/calendar/v3/freeBusy') return $pre;
    return ['headers'=>[],'response'=>['code'=>200,'message'=>'OK'],'body'=>wp_json_encode([
        'calendars'=>['primary'=>['errors'=>[['domain'=>'calendar','reason'=>'notFound']],'busy'=>[]]],
    ])];
};
add_filter('pre_http_request',$googleFilter,10,3);
$googleResult=(new Wpcb\Calendar\GoogleCalendarProvider($connectionRepo))->busyBetween('2026-10-01 09:00:00','2026-10-01 10:00:00',$googleConnection);
remove_filter('pre_http_request',$googleFilter,10);
wpcb_availability_failure_assert(is_wp_error($googleResult)&&$googleResult->get_error_code()==='wpcb_google_freebusy_incomplete','Google calendar-level FreeBusy errors fail closed even inside HTTP 200.');
$connectionRepo->delete((int)$googleId);

$msId=$connectionRepo->create([
    'provider'=>'microsoft','name'=>'Microsoft embedded error fixture','remote_calendar_id'=>'primary',
    'blocks_availability'=>1,'receives_bookings'=>0,
], ['access_token'=>'CI-MS-ACCESS','expires_at'=>time()+3600,'tenant_id'=>'business-tenant','account_type'=>'work','account_address'=>'calendar@example.test']);
$msConnection=$connectionRepo->find((int)$msId);
$msCalls=[];
$msFilter=static function($pre,array $args,string $url)use(&$msCalls){
    $msCalls[]=$url;
    if($url==='https://graph.microsoft.com/v1.0/me/calendar/getSchedule'){
        return ['headers'=>[],'response'=>['code'=>200,'message'=>'OK'],'body'=>wp_json_encode(['value'=>[[
            'scheduleId'=>'calendar@example.test','error'=>['code'=>'ErrorMailRecipientNotFound','message'=>'PRIVATE GRAPH DETAIL'],'scheduleItems'=>[],
        ]]])];
    }
    if(strpos($url,'https://graph.microsoft.com/v1.0/me/calendar/calendarView?')===0){
        return ['headers'=>[],'response'=>['code'=>503,'message'=>'Unavailable'],'body'=>wp_json_encode(['error'=>['code'=>'ServiceUnavailable']])];
    }
    return $pre;
};
add_filter('pre_http_request',$msFilter,10,3);
$msResult=(new Wpcb\Calendar\MicrosoftGraphProvider($connectionRepo))->busyBetween('2026-10-01 09:00:00','2026-10-01 10:00:00',$msConnection);
remove_filter('pre_http_request',$msFilter,10);
wpcb_availability_failure_assert(is_wp_error($msResult)&&count($msCalls)===2,'Microsoft schedule-level error does not become healthy empty availability and calendarView fallback is attempted.');
$connectionRepo->delete((int)$msId);

$settingsBefore=get_option('wpcb_settings',[]);
$settings=Wpcb\Admin\Settings::get();
$settings['calendar_url']='https://8.8.8.8/fail-closed.ics';
$settings['calendar_urls']='https://8.8.8.8/fail-closed.ics';
update_option('wpcb_settings',$settings);
delete_transient('wpcb_ical_'.md5('https://8.8.8.8/fail-closed.ics'));
$icsMode='http';
$icsFilter=static function($pre,array $args,string $url)use(&$icsMode){
    if($url!=='https://8.8.8.8/fail-closed.ics')return $pre;
    if($icsMode==='http')return new WP_Error('http_request_failed','PRIVATE ICS DETAIL');
    if($icsMode==='malformed')return ['headers'=>['content-length'=>'18'],'response'=>['code'=>200,'message'=>'OK'],'body'=>'NOT A VCALENDAR!!!','cookies'=>[],'filename'=>null];
    $body="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
    return ['headers'=>['content-length'=>(string)strlen($body)],'response'=>['code'=>200,'message'=>'OK'],'body'=>$body,'cookies'=>[],'filename'=>null];
};
add_filter('pre_http_request',$icsFilter,10,3);
$icsProvider=new Wpcb\Calendar\IcloudProvider();
$icsHttp=$icsProvider->eventsResult('2026-10-01 09:00:00','2026-10-01 10:00:00');
wpcb_availability_failure_assert(is_wp_error($icsHttp),'Public ICS HTTP failure is unknown availability, not a successful empty calendar.');
$icsMode='malformed';
delete_transient('wpcb_ical_'.md5('https://8.8.8.8/fail-closed.ics'));
$icsMalformed=$icsProvider->eventsResult('2026-10-01 09:00:00','2026-10-01 10:00:00');
wpcb_availability_failure_assert(is_wp_error($icsMalformed),'Malformed public ICS data fails closed.');
$icsMode='empty-calendar';
delete_transient('wpcb_ical_'.md5('https://8.8.8.8/fail-closed.ics'));
$icsEmpty=$icsProvider->eventsResult('2026-10-01 09:00:00','2026-10-01 10:00:00');
remove_filter('pre_http_request',$icsFilter,10);
delete_transient('wpcb_ical_'.md5('https://8.8.8.8/fail-closed.ics'));
update_option('wpcb_settings',$settingsBefore);
wpcb_availability_failure_assert(is_array($icsEmpty)&&$icsEmpty===[],'A valid genuinely empty public calendar remains a successful empty result.');
WP_CLI::success('Fail-closed provider availability smoke test passed.');
