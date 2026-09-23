<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_capacity_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$types = $wpdb->prefix . 'wpcb_booking_types';
$resources = new Wpcb\Resources\ResourceRepository();
$bookings = new Wpcb\Booking\BookingRepository();

$wpdb->insert($types, [
    'name'=>'Capacity Test','slug'=>'capacity-test-' . wp_generate_password(6,false),
    'description'=>'','duration_minutes'=>30,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,
    'capacity'=>4,'show_remaining_capacity'=>1,'is_active'=>1,'is_public'=>1,'sort_order'=>0,
    'created_at'=>$now,'updated_at'=>$now,
]);
$typeId=(int)$wpdb->insert_id;
$resourceId=$resources->save([
    'name'=>'Capacity Room','slug'=>'capacity-room-' . wp_generate_password(6,false),
    'public_label'=>'Room','description'=>'','capacity'=>3,'is_active'=>1,'is_public'=>1,'sort_order'=>0,
]);
wpcb_capacity_assert(!is_wp_error($resourceId), 'Capacity resource is created.');
$resourceId=(int)$resourceId;
$resources->setForBookingType($typeId,[$resourceId]);

$start='2032-03-01 10:00:00';
$end='2032-03-01 10:30:00';
$capacity=new Wpcb\Booking\CapacityService();
wpcb_capacity_assert($capacity->effectiveCapacity($typeId,$resourceId)===3,'Effective capacity uses the tighter booking-type/resource limit.');
wpcb_capacity_assert($capacity->remaining($typeId,$resourceId,$start,$end)===3,'Empty slot starts with full capacity.');

$first=$bookings->create([
    'booking_uuid'=>wp_generate_uuid4(),'booking_type_id'=>$typeId,'resource_id'=>$resourceId,
    'slot_start'=>$start,'slot_end'=>$end,'party_size'=>2,'status'=>Wpcb\Booking\BookingStatus::CONFIRMED,
    'full_name'=>'Group A','email'=>'group-a@example.com','source'=>'test','lang'=>'de','created_at'=>$now,'updated_at'=>$now,
]);
wpcb_capacity_assert($first>0,'Two-seat booking is stored.');
wpcb_capacity_assert($capacity->remaining($typeId,$resourceId,$start,$end)===1,'Remaining capacity subtracts occupied seats.');
wpcb_capacity_assert(!$capacity->canFit($typeId,$resourceId,$start,$end,2),'Two additional seats do not fit.');
wpcb_capacity_assert($capacity->canFit($typeId,$resourceId,$start,$end,1),'Last seat remains bookable.');

$wpdb->update($wpdb->prefix . 'wpcb_bookings',['status'=>Wpcb\Booking\BookingStatus::CANCELLED],['id'=>$first]);
wpcb_capacity_assert($capacity->remaining($typeId,$resourceId,$start,$end)===3,'Cancellation releases capacity.');

$wpdb->delete($wpdb->prefix.'wpcb_booking_status_log',['booking_id'=>$first]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_meta',['booking_id'=>$first]);
$wpdb->delete($wpdb->prefix.'wpcb_bookings',['id'=>$first]);
$wpdb->delete($wpdb->prefix.'wpcb_booking_type_resources',['booking_type_id'=>$typeId]);
$wpdb->delete($wpdb->prefix.'wpcb_resources',['id'=>$resourceId]);
$wpdb->delete($types,['id'=>$typeId]);

echo "PASS: capacity/group booking smoke test complete.\n";
