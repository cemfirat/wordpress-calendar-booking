<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_wait_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$types = $wpdb->prefix . 'wpcb_booking_types';
$wpdb->insert($types, [
    'name'=>'Waiting List Test','slug'=>'waiting-list-' . wp_generate_password(6,false),
    'description'=>'','duration_minutes'=>30,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,
    'capacity'=>1,'show_remaining_capacity'=>0,'payment_mode'=>'free','price_minor'=>0,'currency'=>'EUR',
    'is_active'=>1,'is_public'=>1,'sort_order'=>0,'created_at'=>$now,'updated_at'=>$now,
]);
$typeId=(int)$wpdb->insert_id;

$resources = new Wpcb\Resources\ResourceRepository();
$resourceId=$resources->save([
    'name'=>'Waiting Room','slug'=>'waiting-room-' . wp_generate_password(6,false),
    'public_label'=>'','description'=>'','capacity'=>1,'is_active'=>1,'is_public'=>0,'sort_order'=>0,
]);
wpcb_wait_assert(!is_wp_error($resourceId), 'Waiting-list resource is created.');
$resourceId=(int)$resourceId;
$resources->setForBookingType($typeId,[$resourceId]);

$slots=(new Wpcb\Availability\SlotService())->getSlots($typeId,21,$resourceId,1);
wpcb_wait_assert(!empty($slots), 'Fixture has a canonical bookable slot.');
$slot=$slots[0];
$start=(string)$slot['start'];
$end=(string)$slot['end'];

$bookings=new Wpcb\Booking\BookingRepository();
$blockingId=$bookings->create([
    'booking_uuid'=>wp_generate_uuid4(),'booking_type_id'=>$typeId,'resource_id'=>$resourceId,
    'slot_start'=>$start,'slot_end'=>$end,'party_size'=>1,'status'=>Wpcb\Booking\BookingStatus::CONFIRMED,
    'full_name'=>'Blocking Customer','email'=>'blocking@example.com','source'=>'test','lang'=>'de',
    'created_at'=>$now,'updated_at'=>$now,
]);
wpcb_wait_assert($blockingId>0,'Blocking booking fills the slot.');

$service=new Wpcb\WaitingList\WaitingListService();
$first=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$start,'slot_end'=>$end,
    'party_size'=>1,'full_name'=>'First Waiter','email'=>'first-waiter@example.com','phone'=>'111',
]);
$second=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$start,'slot_end'=>$end,
    'party_size'=>1,'full_name'=>'Second Waiter','email'=>'second-waiter@example.com','phone'=>'222',
]);
wpcb_wait_assert(is_int($first) && is_int($second) && $first>0 && $second>$first,'Two customers join the full slot in FIFO order.');

$duplicate=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$start,'slot_end'=>$end,
    'party_size'=>1,'full_name'=>'First Waiter','email'=>'first-waiter@example.com',
]);
wpcb_wait_assert($duplicate===$first,'Duplicate waiting-list join is idempotent.');

$cancel=(new Wpcb\Booking\BookingTransitionService())->apply(
    $blockingId,Wpcb\Booking\BookingStateMachine::USER_CANCELLED,'test','Release capacity for waiting list'
);
wpcb_wait_assert(is_array($cancel) && !empty($cancel['changed']),'Cancellation releases capacity through canonical lifecycle.');

$waitRepo=new Wpcb\WaitingList\WaitingListRepository();
$firstRow=$waitRepo->find($first);
$secondRow=$waitRepo->find($second);
wpcb_wait_assert($firstRow && $firstRow->status==='offered','First waiter receives the promotion hold.');
wpcb_wait_assert($secondRow && $secondRow->status==='waiting','Second waiter remains queued.');
wpcb_wait_assert((new Wpcb\Booking\CapacityService())->remaining($typeId,$resourceId,$start,$end)===0,'Active promotion hold removes the seat from public capacity.');

$verifier=(new Wpcb\Security\SecretBox())->decrypt((string)$firstRow->offer_secret_enc);
wpcb_wait_assert(is_string($verifier) && $verifier!=='','Promotion verifier is recoverable only through authenticated encryption.');
$token=(string)$firstRow->offer_selector . '.' . $verifier;
$accepted=$service->accept($first,$token);
wpcb_wait_assert(is_int($accepted) && $accepted>0,'Valid one-time promotion creates a reservation.');
$acceptedRow=$waitRepo->find($first);
wpcb_wait_assert($acceptedRow && $acceptedRow->status==='accepted' && (int)$acceptedRow->booking_id===$accepted,'Accepted offer links to the new booking and clears the hold token.');
$replay=$service->accept($first,$token);
wpcb_wait_assert(is_wp_error($replay),'Promotion token cannot be replayed.');

$privacy=new Wpcb\WaitingList\WaitingListPrivacy();
$export=$privacy->exporter('second-waiter@example.com',1);
wpcb_wait_assert(count($export['data'])===1,'Waiting-list data participates in WordPress privacy export.');
$erase=$privacy->eraser('second-waiter@example.com',1);
wpcb_wait_assert(!empty($erase['items_removed']) && !$waitRepo->find($second),'Waiting-list data participates in WordPress privacy erasure.');

$wpdb->delete($wpdb->prefix.'wpcb_waiting_list',['id'=>$first]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_status_log',['booking_id'=>$blockingId]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_status_log',['booking_id'=>$accepted]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_meta',['booking_id'=>$blockingId]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_meta',['booking_id'=>$accepted]);
$wpdb->delete($wpdb->prefix.'wpcb_payments',['booking_id'=>$accepted]);
$wpdb->delete($wpdb->prefix.'wpcb_bookings',['id'=>$blockingId]);
$wpdb->delete($wpdb->prefix.'wpcb_bookings',['id'=>$accepted]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_type_resources',['booking_type_id'=>$typeId]);
$wpdb->delete($wpdb->prefix.'wpcb_resources',['id'=>$resourceId]);
$wpdb->delete($types,['id'=>$typeId]);

echo "PASS: waiting-list smoke test complete.\n";
