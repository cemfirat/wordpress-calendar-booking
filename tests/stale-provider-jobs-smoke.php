<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_stale_assert($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    WP_CLI::log('PASS: ' . $message);
}

$GLOBALS['wpcb_stale_calendar_creates'] = 0;
$GLOBALS['wpcb_stale_calendar_updates'] = 0;
$GLOBALS['wpcb_stale_calendar_cancels'] = 0;
$calendarProvider = new class implements Wpcb\Calendar\CalendarSyncProviderInterface {
    public function id(): string { return 'stale_fixture'; }
    public function label(): string { return 'Stale fixture'; }
    public function capabilities(): array {
        return [Wpcb\Calendar\ProviderCapabilities::EVENT_CREATE, Wpcb\Calendar\ProviderCapabilities::EVENT_UPDATE, Wpcb\Calendar\ProviderCapabilities::EVENT_CANCEL];
    }
    public function busyBetween(string $fromUtc, string $toUtc, Wpcb\Calendar\CalendarConnection $connection) { return []; }
    public function createEvent(array $booking, array $meta, Wpcb\Calendar\CalendarConnection $connection) {
        $GLOBALS['wpcb_stale_calendar_creates']++;
        return ['ok'=>true,'event_id'=>'stale-event-' . (int)$connection->id];
    }
    public function updateEvent(array $booking, array $meta, Wpcb\Calendar\CalendarConnection $connection, string $eventId) {
        $GLOBALS['wpcb_stale_calendar_updates']++;
        return ['ok'=>true,'event_id'=>$eventId];
    }
    public function cancelEvent(Wpcb\Calendar\CalendarConnection $connection, string $eventId) {
        $GLOBALS['wpcb_stale_calendar_cancels']++;
        return ['ok'=>true,'event_id'=>$eventId];
    }
};
add_filter('wpcb_calendar_providers', static function(array $providers) use ($calendarProvider): array {
    $providers[] = $calendarProvider; return $providers;
}, 999);

final class WpcbStaleVideoProvider implements Wpcb\VideoMeetings\VideoMeetingProviderInterface {
    public static int $creates=0;
    public static int $updates=0;
    public static int $deletes=0;
    public function code(): string { return 'zoom'; }
    public function capabilities(): array { return ['create'=>true,'update'=>true,'delete'=>true]; }
    public function create(array $booking, object $connection): array {
        self::$creates++; return ['ok'=>true,'remote_id'=>'stale-video-' . (int)$connection->id,'join_url'=>'https://meet.example.test/stale'];
    }
    public function update(array $meeting, array $booking, object $connection): array {
        self::$updates++; return ['ok'=>true,'remote_id'=>(string)$meeting['remote_id'],'join_url'=>(string)$meeting['join_url']];
    }
    public function delete(array $meeting, object $connection): array { self::$deletes++; return ['ok'=>true]; }
}
add_filter('wpcb_video_meeting_provider', static fn($provider,string $code) => $code==='zoom' ? new WpcbStaleVideoProvider() : $provider, 10, 2);
add_filter('pre_wp_mail', static fn() => true);

global $wpdb;
$now=Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeTable=$wpdb->prefix.'wpcb_booking_types';
$wpdb->insert($typeTable,[
    'name'=>'Stale work fixture','slug'=>'stale-work-' . wp_generate_password(8,false),'description'=>'',
    'duration_minutes'=>30,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,'capacity'=>1,
    'show_remaining_capacity'=>0,'payment_mode'=>'free','price_minor'=>0,'currency'=>'EUR',
    'is_active'=>1,'is_public'=>0,'sort_order'=>0,'created_at'=>$now,'updated_at'=>$now,
]);
$typeId=(int)$wpdb->insert_id;
$resourceId=(int)get_option('wpcb_default_resource_id',0);
wpcb_stale_assert($typeId>0 && $resourceId>0,'Stale-work fixture has booking type and resource.');

$calendarRepo=new Wpcb\Calendar\CalendarConnectionRepository();
$calendarA=$calendarRepo->create(['provider'=>'stale_fixture','name'=>'Old calendar','remote_calendar_id'=>'old','receives_bookings'=>1],[]);
$calendarB=$calendarRepo->create(['provider'=>'stale_fixture','name'=>'New calendar','remote_calendar_id'=>'new','receives_bookings'=>1],[]);
wpcb_stale_assert(is_int($calendarA)&&is_int($calendarB),'Two calendar destinations are created.');
$calendarRepo->setForResource($resourceId,[['connection_id'=>$calendarA,'receives_bookings'=>1,'blocks_availability'=>0]]);

$videoRepo=new Wpcb\VideoMeetings\VideoMeetingConnectionRepository();
$videoA=$videoRepo->save(['provider'=>'zoom','name'=>'Old video','access_token'=>'CI-OLD','is_active'=>1]);
$videoB=$videoRepo->save(['provider'=>'zoom','name'=>'New video','access_token'=>'CI-NEW','is_active'=>1]);
wpcb_stale_assert(is_int($videoA)&&is_int($videoB),'Two video destinations are created.');
$videoRepo->setForBookingType($typeId,[['connection_id'=>$videoA,'is_required'=>true]]);

$bookings=new Wpcb\Booking\BookingRepository();
$bookingId=$bookings->create([
    'booking_uuid'=>wp_generate_uuid4(),'booking_type_id'=>$typeId,'resource_id'=>$resourceId,
    'slot_start'=>'2034-02-01 10:00:00','slot_end'=>'2034-02-01 10:30:00','party_size'=>1,
    'status'=>Wpcb\Booking\BookingStatus::CONFIRMED,'full_name'=>'Stale Fixture','email'=>'stale@example.com',
    'source'=>'test','lang'=>'de','created_at'=>$now,'updated_at'=>$now,
],[],false);
wpcb_stale_assert($bookingId>0,'Confirmed stale-work fixture booking is created.');
$booking=$bookings->find($bookingId);
$calAObj=$calendarRepo->find((int)$calendarA);
$vidAObj=$videoRepo->find((int)$videoA,false);

$jobs=new Wpcb\Sync\JobRepository();
$staleCalendarJob=$jobs->enqueue('provider_create',$bookingId,[
    'connection_id'=>(int)$calendarA,
    'desired_version'=>Wpcb\Calendar\ProviderSyncService::desiredVersion($booking,$calAObj),
],'ci:stale:calendar:create:' . $bookingId);
$staleVideoJob=$jobs->enqueue('video_create',$bookingId,[
    'connection_id'=>(int)$videoA,
    'desired_version'=>Wpcb\VideoMeetings\VideoMeetingJobRunner::desiredVersion($booking,$vidAObj),
],'ci:stale:video:create:' . $bookingId);
wpcb_stale_assert($staleCalendarJob>0 && $staleVideoJob>0,'Old create work is queued before cancellation.');

$wpdb->update($wpdb->prefix.'wpcb_bookings',[
    'status'=>Wpcb\Booking\BookingStatus::CANCELLED,
    'updated_at'=>Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+1 second')),
],['id'=>$bookingId]);
(new Wpcb\Sync\QueueService())->runNow(20);
wpcb_stale_assert($GLOBALS['wpcb_stale_calendar_creates']===0,'Obsolete calendar create cannot recreate a cancelled booking.');
wpcb_stale_assert(WpcbStaleVideoProvider::$creates===0,'Obsolete video create cannot recreate a cancelled booking.');
$staleRows=$wpdb->get_results($wpdb->prepare(
    "SELECT status,last_error FROM {$wpdb->prefix}wpcb_sync_jobs WHERE id IN (%d,%d) ORDER BY id",
    $staleCalendarJob,$staleVideoJob
));
wpcb_stale_assert(count($staleRows)===2 && $staleRows[0]->status==='done' && $staleRows[1]->status==='done','Obsolete jobs complete without retry storms.');

$wpdb->update($wpdb->prefix.'wpcb_bookings',[
    'status'=>Wpcb\Booking\BookingStatus::CONFIRMED,
    'updated_at'=>Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+2 seconds')),
],['id'=>$bookingId]);
$calendarRepo->setForResource($resourceId,[['connection_id'=>$calendarA,'receives_bookings'=>1,'blocks_availability'=>0]]);
$videoRepo->setForBookingType($typeId,[['connection_id'=>$videoA,'is_required'=>true]]);
$q=new Wpcb\Sync\QueueService();
wpcb_stale_assert($q->enqueueCreate($bookingId)>0,'Current calendar destination is queued.');
$q->runNow(20);
$videoService=new Wpcb\VideoMeetings\VideoMeetingService();
$videoService->onTransition(['changed'=>true,'to'=>Wpcb\Booking\BookingStatus::CONFIRMED,'event'=>'ci_confirmed'],$bookings->find($bookingId));
wpcb_stale_assert($GLOBALS['wpcb_stale_calendar_creates']===1,'Current calendar destination is created once.');
wpcb_stale_assert(WpcbStaleVideoProvider::$creates===1,'Current video destination is created once.');

$calendarRepo->setForResource($resourceId,[['connection_id'=>$calendarB,'receives_bookings'=>1,'blocks_availability'=>0]]);
$videoRepo->setForBookingType($typeId,[['connection_id'=>$videoB,'is_required'=>true]]);
$wpdb->update($wpdb->prefix.'wpcb_bookings',[
    'slot_start'=>'2034-02-01 11:00:00','slot_end'=>'2034-02-01 11:30:00',
    'updated_at'=>Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+3 seconds')),
],['id'=>$bookingId]);
wpcb_stale_assert($q->enqueueUpdate($bookingId)>0,'Routing-change reconciliation queues calendar work.');
$q->runNow(20);
$videoService->onEvent(['changed'=>true,'event'=>Wpcb\Booking\BookingTransitionService::RESCHEDULED],$bookings->find($bookingId));

$meta=$bookings->getMeta($bookingId);
wpcb_stale_assert($GLOBALS['wpcb_stale_calendar_cancels']===1,'Routing change removes the old calendar destination.');
wpcb_stale_assert($GLOBALS['wpcb_stale_calendar_creates']===2,'Routing change creates the new calendar destination.');
wpcb_stale_assert(empty($meta['provider_event_stale_fixture_' . (int)$calendarA]),'Old calendar event reference is cleared after cleanup.');
wpcb_stale_assert(!empty($meta['provider_event_stale_fixture_' . (int)$calendarB]),'New calendar event reference is persisted.');
wpcb_stale_assert(WpcbStaleVideoProvider::$deletes===1,'Routing change removes the old video meeting.');
wpcb_stale_assert(WpcbStaleVideoProvider::$creates===2,'Routing change creates the new video meeting.');

$oldMeeting=(new Wpcb\VideoMeetings\VideoMeetingRepository())->find($bookingId,(int)$videoA);
$newMeeting=(new Wpcb\VideoMeetings\VideoMeetingRepository())->find($bookingId,(int)$videoB);
wpcb_stale_assert($oldMeeting && $oldMeeting->status==='deleted','Old video destination is marked deleted.');
wpcb_stale_assert($newMeeting && $newMeeting->status==='active','New video destination is active.');

$logs=$jobs->recentLogs(200);
$obsolete=array_filter($logs,static fn($row):bool => (int)$row->booking_id===$bookingId && stripos((string)$row->message,'Obsolete')!==false);
wpcb_stale_assert(count($obsolete)>=2,'Diagnostics distinguish obsolete queue work from provider success.');

$wpdb->delete($wpdb->prefix.'wpcb_deliveries',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_sync_log',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_sync_jobs',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_video_meetings',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_status_log',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_meta',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_bookings',['id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_type_video_connections',['booking_type_id'=>$typeId]);
$wpdb->delete($wpdb->prefix.'wpcb_video_connections',['id'=>$videoA]);
$wpdb->delete($wpdb->prefix.'wpcb_video_connections',['id'=>$videoB]);
$calendarRepo->setForResource($resourceId,[]);
$calendarRepo->delete((int)$calendarA);
$calendarRepo->delete((int)$calendarB);
$wpdb->delete($typeTable,['id'=>$typeId]);

WP_CLI::success('Stale calendar/video side-effect smoke test passed.');
