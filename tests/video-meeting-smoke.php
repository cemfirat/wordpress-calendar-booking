<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_video_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

final class WpcbVideoFakeProvider implements Wpcb\VideoMeetings\VideoMeetingProviderInterface {
    public static int $creates = 0;
    public static int $updates = 0;
    public static int $deletes = 0;
    public static bool $failNextUpdate = false;

    public function code(): string { return 'zoom'; }
    public function capabilities(): array { return ['create'=>true,'update'=>true,'delete'=>true]; }
    public function create(array $booking, object $connection): array {
        self::$creates++;
        return [
            'ok'=>true,
            'remote_id'=>'fake-' . (int)$booking['id'],
            'join_url'=>'https://meet.example.test/join/' . (int)$booking['id'],
        ];
    }
    public function update(array $meeting, array $booking, object $connection): array {
        self::$updates++;
        if (self::$failNextUpdate) {
            self::$failNextUpdate = false;
            return ['ok'=>false,'message'=>'Temporary provider outage Bearer SECRET-MUST-NOT-LEAK'];
        }
        return [
            'ok'=>true,
            'remote_id'=>(string)$meeting['remote_id'],
            'join_url'=>(string)$meeting['join_url'],
        ];
    }
    public function delete(array $meeting, object $connection): array {
        self::$deletes++;
        return ['ok'=>true];
    }
}

add_filter('wpcb_video_meeting_provider', static function ($provider, string $code) {
    return $code === 'zoom' ? new WpcbVideoFakeProvider() : $provider;
}, 10, 2);
add_filter('pre_wp_mail', static fn() => true);

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeTable = $wpdb->prefix . 'wpcb_booking_types';
$wpdb->insert($typeTable, [
    'name'=>'Video Test','slug'=>'video-test-' . wp_generate_password(6,false),
    'description'=>'','duration_minutes'=>30,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,
    'capacity'=>1,'show_remaining_capacity'=>0,'payment_mode'=>'free','price_minor'=>0,'currency'=>'EUR',
    'is_active'=>1,'is_public'=>1,'sort_order'=>0,'created_at'=>$now,'updated_at'=>$now,
]);
$typeId = (int)$wpdb->insert_id;

$connections = new Wpcb\VideoMeetings\VideoMeetingConnectionRepository();
$connectionId = $connections->save([
    'provider'=>'zoom',
    'name'=>'CI Zoom',
    'access_token'=>'CI-VIDEO-ACCESS-TOKEN-SECRET',
    'config'=>['user_id'=>'me'],
    'is_active'=>1,
]);
wpcb_video_assert(!is_wp_error($connectionId) && (int)$connectionId > 0, 'Encrypted video meeting connection is created.');
$connectionId = (int)$connectionId;

$cipher = (string)$wpdb->get_var($wpdb->prepare(
    "SELECT credentials_enc FROM {$wpdb->prefix}wpcb_video_connections WHERE id = %d",
    $connectionId
));
wpcb_video_assert($cipher !== '' && strpos($cipher, 'CI-VIDEO-ACCESS-TOKEN-SECRET') === false, 'Provider access token is not stored in plaintext.');
$decrypted = $connections->find($connectionId, true);
wpcb_video_assert($decrypted && ($decrypted->credentials['access_token'] ?? '') === 'CI-VIDEO-ACCESS-TOKEN-SECRET', 'Provider token decrypts only through the shared secret infrastructure.');

$connections->setForBookingType($typeId, [[
    'connection_id'=>$connectionId,
    'is_required'=>true,
]]);
$mapped = $connections->forBookingType($typeId);
wpcb_video_assert(count($mapped) === 1 && (int)$mapped[0]->is_required === 1, 'Booking type requires the configured video meeting connection.');

$bookings = new Wpcb\Booking\BookingRepository();
$bookingId = $bookings->create([
    'booking_uuid'=>wp_generate_uuid4(),'booking_type_id'=>$typeId,'resource_id'=>(int)get_option('wpcb_default_resource_id',0),
    'slot_start'=>'2033-04-01 10:00:00','slot_end'=>'2033-04-01 10:30:00','party_size'=>1,
    'status'=>Wpcb\Booking\BookingStatus::CONFIRMED,'full_name'=>'Video Customer','email'=>'video-customer@example.com',
    'source'=>'test','lang'=>'de','created_at'=>$now,'updated_at'=>$now,
]);
wpcb_video_assert($bookingId > 0, 'Confirmed video fixture booking is created.');
$booking = $bookings->find($bookingId);

$service = new Wpcb\VideoMeetings\VideoMeetingService();
$service->onTransition([
    'changed'=>true,'booking_id'=>$bookingId,'to'=>Wpcb\Booking\BookingStatus::CONFIRMED,'event'=>'fixture_confirmed'
], $booking);

$meetings = new Wpcb\VideoMeetings\VideoMeetingRepository();
$meeting = $meetings->find($bookingId, $connectionId);
wpcb_video_assert($meeting && $meeting->status === 'active', 'Confirmation creates the provider meeting through the retry queue.');
wpcb_video_assert((string)$meeting->join_url === 'https://meet.example.test/join/' . $bookingId, 'Provider join URL is stored with the meeting record.');
wpcb_video_assert(WpcbVideoFakeProvider::$creates === 1, 'Provider create runs once.');

$service->onTransition([
    'changed'=>true,'booking_id'=>$bookingId,'to'=>Wpcb\Booking\BookingStatus::CONFIRMED,'event'=>'fixture_confirmed'
], $booking);
wpcb_video_assert(WpcbVideoFakeProvider::$creates === 1, 'Repeating the same lifecycle effect does not create a duplicate meeting.');

$delivery = (new Wpcb\Reliability\DeliveryRepository())->search([
    'booking_id'=>$bookingId,
    'effect_type'=>'video_meeting_ready',
], 10);
wpcb_video_assert(count($delivery) === 1 && $delivery[0]->status === 'sent', 'Meeting-ready notification is idempotent.');

$wpdb->update($wpdb->prefix . 'wpcb_bookings', [
    'slot_start'=>'2033-04-01 11:00:00',
    'slot_end'=>'2033-04-01 11:30:00',
    'updated_at'=>Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+1 second')),
], ['id'=>$bookingId]);
$booking = $bookings->find($bookingId);
$service->onEvent(['changed'=>true,'booking_id'=>$bookingId,'event'=>Wpcb\Booking\BookingTransitionService::RESCHEDULED], $booking);
wpcb_video_assert(WpcbVideoFakeProvider::$updates === 1, 'Reschedule updates the existing remote meeting.');

WpcbVideoFakeProvider::$failNextUpdate = true;
$wpdb->update($wpdb->prefix . 'wpcb_bookings', [
    'slot_start'=>'2033-04-01 12:00:00',
    'slot_end'=>'2033-04-01 12:30:00',
    'updated_at'=>Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+2 seconds')),
], ['id'=>$bookingId]);
$booking = $bookings->find($bookingId);
$service->onEvent(['changed'=>true,'booking_id'=>$bookingId,'event'=>Wpcb\Booking\BookingTransitionService::RESCHEDULED], $booking);

$pending = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}wpcb_sync_jobs WHERE booking_id = %d AND job_type = 'video_update' ORDER BY id DESC LIMIT 1",
    $bookingId
));
wpcb_video_assert($pending && $pending->status === 'pending', 'Transient meeting-provider failure remains queued for retry.');
wpcb_video_assert($bookings->find($bookingId)->status === Wpcb\Booking\BookingStatus::CONFIRMED, 'Provider failure never rolls back the confirmed booking.');
wpcb_video_assert(strpos((string)$pending->last_error, 'SECRET-MUST-NOT-LEAK') === false, 'Queue diagnostics redact provider authorization secrets.');

$wpdb->update($wpdb->prefix . 'wpcb_sync_jobs', [
    'available_at'=>Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()),
], ['id'=>(int)$pending->id]);
(new Wpcb\Sync\QueueService())->runNow();
$retried = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}wpcb_sync_jobs WHERE id = %d", (int)$pending->id));
wpcb_video_assert($retried && $retried->status === 'done', 'Transient meeting-provider failure succeeds on retry.');

$slots = (new Wpcb\Availability\SlotService())->getSlots($typeId, 21, null, 1);
wpcb_video_assert(strpos(wp_json_encode($slots), 'meet.example.test') === false, 'Public availability output never exposes meeting URLs.');

$wpdb->update($wpdb->prefix . 'wpcb_bookings', [
    'status'=>Wpcb\Booking\BookingStatus::CANCELLED,
    'updated_at'=>Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+3 seconds')),
], ['id'=>$bookingId]);
$booking = $bookings->find($bookingId);
$service->onTransition([
    'changed'=>true,'booking_id'=>$bookingId,'to'=>Wpcb\Booking\BookingStatus::CANCELLED,'event'=>'fixture_cancelled'
], $booking);
$meeting = $meetings->find($bookingId, $connectionId);
wpcb_video_assert(WpcbVideoFakeProvider::$deletes === 1 && $meeting && $meeting->status === 'deleted', 'Cancellation deletes the remote meeting idempotently.');

$exportBeforeErase = (new Wpcb\Privacy\PrivacyService())->exporter('video-customer@example.com', 1);
wpcb_video_assert(!empty($exportBeforeErase['data']), 'Booking remains available to the WordPress privacy exporter.');
$erase = (new Wpcb\Privacy\PrivacyService())->eraser('video-customer@example.com', 1);
wpcb_video_assert(!empty($erase['items_removed']), 'Privacy erasure anonymizes the booking.');
wpcb_video_assert($meetings->find($bookingId, $connectionId) === null, 'Privacy erasure removes local meeting access links.');

$wpdb->delete($wpdb->prefix.'wpcb_deliveries',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_sync_log',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_sync_jobs',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_status_log',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_meta',['booking_id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_bookings',['id'=>$bookingId]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_type_video_connections',['booking_type_id'=>$typeId]);
$wpdb->delete($wpdb->prefix.'wpcb_video_connections',['id'=>$connectionId]);
$wpdb->delete($typeTable,['id'=>$typeId]);

echo "PASS: video meeting smoke test complete.\n";
