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

$tooLarge=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$start,'slot_end'=>$end,
    'party_size'=>2,'full_name'=>'Too Large','email'=>'too-large@example.com',
]);
wpcb_wait_assert(is_wp_error($tooLarge) && $tooLarge->get_error_code()==='wpcb_waitlist_invalid','Waiting-list join rejects a party larger than effective capacity.');

$pastStart=Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-2 hours'));
$pastEnd=Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-90 minutes'));
$pastJoin=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$pastStart,'slot_end'=>$pastEnd,
    'party_size'=>1,'full_name'=>'Past Waiter','email'=>'past-waiter@example.com',
]);
wpcb_wait_assert(is_wp_error($pastJoin) && $pastJoin->get_error_code()==='wpcb_waitlist_invalid','Waiting-list join rejects past intervals.');

$wpdb->update($types,['is_public'=>0],['id'=>$typeId]);
$privateJoin=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$start,'slot_end'=>$end,
    'party_size'=>1,'full_name'=>'Private Waiter','email'=>'private-waiter@example.com',
]);
wpcb_wait_assert(is_wp_error($privateJoin) && $privateJoin->get_error_code()==='wpcb_waitlist_invalid','Waiting-list join rejects inactive/non-public booking-type policy.');
$wpdb->update($types,['is_public'=>1],['id'=>$typeId]);

$cancel=(new Wpcb\Booking\BookingTransitionService())->apply(
    $blockingId,Wpcb\Booking\BookingStateMachine::USER_CANCELLED,'test','Release capacity for waiting list'
);
wpcb_wait_assert(is_array($cancel) && !empty($cancel['changed']),'Cancellation releases capacity through canonical lifecycle.');

$waitRepo=new Wpcb\WaitingList\WaitingListRepository();
$firstRow=$waitRepo->find($first);
$secondRow=$waitRepo->find($second);
wpcb_wait_assert($firstRow && $firstRow->status==='offered','First waiter receives the promotion hold.');
wpcb_wait_assert($secondRow && $secondRow->status==='waiting','Second waiter remains queued.');
wpcb_wait_assert($service->promoteSlot($typeId,$resourceId,$start,$end)===0,'A second promotion cannot create a duplicate hold for already protected capacity.');
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

// Cross-type and buffered waiting-list holds share resource capacity.
$advancedTypeIds=[];
$advancedWaitIds=[];
$advancedBookingIds=[];
$advancedRuleId=0;
$advancedResourceId=0;
$makeAdvancedType=static function(string $name,int $duration,int $before,int $after) use($wpdb,$types,$now,&$advancedTypeIds): int {
    $wpdb->insert($types,[
        'name'=>$name,'slug'=>sanitize_title($name . '-' . wp_generate_password(6,false)),
        'description'=>'','duration_minutes'=>$duration,'buffer_before_minutes'=>$before,'buffer_after_minutes'=>$after,
        'capacity'=>2,'show_remaining_capacity'=>0,'payment_mode'=>'free','price_minor'=>0,'currency'=>'EUR',
        'is_active'=>1,'is_public'=>1,'sort_order'=>0,'created_at'=>$now,'updated_at'=>$now,
    ]);
    $id=(int)$wpdb->insert_id;
    if($id>0) $advancedTypeIds[]=$id;
    return $id;
};
$typeA=$makeAdvancedType('Wait overlap A',30,0,15);
$typeB=$makeAdvancedType('Wait overlap B',60,15,0);
wpcb_wait_assert($typeA>0 && $typeB>0,'Cross-type waiting-list fixtures are created.');

$advancedResource=$resources->save([
    'name'=>'Waiting overlap capacity two','slug'=>'waiting-overlap-' . wp_generate_password(6,false),
    'public_label'=>'','description'=>'','capacity'=>2,'is_active'=>1,'is_public'=>0,'sort_order'=>0,
]);
wpcb_wait_assert(!is_wp_error($advancedResource) && (int)$advancedResource>0,'Capacity-two waiting-list resource is created.');
$advancedResourceId=(int)$advancedResource;
$resources->setForBookingType($typeA,[$advancedResourceId]);
$resources->setForBookingType($typeB,[$advancedResourceId]);

$target=Wpcb\Support\Time::nowLocal()->modify('+6 days')->setTime(10,0,0);
$targetDate=$target->format('Y-m-d');
$wpdb->insert($wpdb->prefix.'wpcb_availability_rules',[
    'scope_type'=>'booking_type','scope_id'=>$typeA,'weekday'=>(int)$target->format('N'),
    'start_time'=>'10:00:00','end_time'=>'12:00:00','slot_duration_minutes'=>30,
    'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,'min_notice_minutes'=>0,'max_days_in_advance'=>30,
    'is_active'=>1,'created_at'=>$now,'updated_at'=>$now,
]);
$advancedRuleId=(int)$wpdb->insert_id;
wpcb_wait_assert($advancedRuleId>0,'Canonical advanced waiting-list rule is created.');

$advancedStart=Wpcb\Support\Time::localToUtc($targetDate . ' 10:00:00');
$advancedEnd=Wpcb\Support\Time::localToUtc($targetDate . ' 10:30:00');
$otherStart=Wpcb\Support\Time::localToUtc($targetDate . ' 10:45:00');
$otherEnd=Wpcb\Support\Time::localToUtc($targetDate . ' 11:45:00');
wpcb_wait_assert(is_string($advancedStart)&&is_string($advancedEnd)&&is_string($otherStart)&&is_string($otherEnd),'Advanced waiting-list intervals convert to UTC.');

$waitingTable=$wpdb->prefix.'wpcb_waiting_list';
$futureExpiry=Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+30 minutes'));
$expiredAt=Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-1 minute'));
$insertHold=static function(int $type,int $resource,string $s,string $e,int $party,string $status,string $expiry,string $email) use($wpdb,$waitingTable,$now,&$advancedWaitIds): int {
    $wpdb->insert($waitingTable,[
        'entry_uuid'=>wp_generate_uuid4(),'booking_type_id'=>$type,'resource_id'=>$resource,
        'slot_start'=>$s,'slot_end'=>$e,'party_size'=>$party,'full_name'=>'Overlap Hold',
        'email'=>$email,'phone'=>'','status'=>$status,'offer_expires_at'=>$expiry,
        'created_at'=>$now,'updated_at'=>$now,
    ]);
    $id=(int)$wpdb->insert_id;
    if($id>0) $advancedWaitIds[]=$id;
    return $id;
};

$crossHold=$insertHold($typeB,$advancedResourceId,$otherStart,$otherEnd,1,'offered',$futureExpiry,'cross-hold@example.com');
$advancedCapacity=new Wpcb\Booking\CapacityService();
wpcb_wait_assert(
    $crossHold>0 && $advancedCapacity->remaining($typeA,$advancedResourceId,$advancedStart,$advancedEnd)===1,
    'Different type/duration hold consumes one seat through overlapping effective buffers on capacity two.'
);
wpcb_wait_assert(
    $advancedCapacity->remaining($typeA,$advancedResourceId,$advancedStart,$advancedEnd,null,null,$crossHold)===2,
    'Capacity conversion excludes exactly the current waiting-list hold.'
);

$claimingHold=$insertHold($typeB,$advancedResourceId,$otherStart,$otherEnd,1,'claiming',$futureExpiry,'claiming-hold@example.com');
wpcb_wait_assert(
    $advancedCapacity->remaining($typeA,$advancedResourceId,$advancedStart,$advancedEnd)===0,
    'Claiming holds stay capacity-protecting for every other caller.'
);
$wpdb->delete($waitingTable,['id'=>$claimingHold]);
$advancedWaitIds=array_values(array_diff($advancedWaitIds,[$claimingHold]));

$expiredHold=$insertHold($typeB,$advancedResourceId,$otherStart,$otherEnd,2,'offered',$expiredAt,'expired-hold@example.com');
wpcb_wait_assert(
    $advancedCapacity->remaining($typeA,$advancedResourceId,$advancedStart,$advancedEnd)===1,
    'Expired promotion holds do not consume capacity.'
);
$wpdb->delete($waitingTable,['id'=>$expiredHold]);
$advancedWaitIds=array_values(array_diff($advancedWaitIds,[$expiredHold]));
$wpdb->delete($waitingTable,['id'=>$crossHold]);
$advancedWaitIds=array_values(array_diff($advancedWaitIds,[$crossHold]));

// Acceptance keeps its hold while converting and finalizes the waiting-list row
// in the same database transaction as the new reservation.
$selector=bin2hex(random_bytes(8));
$verifier=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$secret=(new Wpcb\Security\SecretBox())->encrypt($verifier);
wpcb_wait_assert(!is_wp_error($secret),'Advanced offer verifier encrypts.');
$wpdb->insert($waitingTable,[
    'entry_uuid'=>wp_generate_uuid4(),'booking_type_id'=>$typeA,'resource_id'=>$advancedResourceId,
    'slot_start'=>$advancedStart,'slot_end'=>$advancedEnd,'party_size'=>1,
    'full_name'=>'Atomic Waiter','email'=>'atomic-waiter@example.com','phone'=>'',
    'status'=>'offered','offer_selector'=>$selector,'offer_hash'=>hash('sha256',$verifier),
    'offer_secret_enc'=>$secret,'offer_expires_at'=>$futureExpiry,'offered_at'=>$now,
    'created_at'=>$now,'updated_at'=>$now,
]);
$atomicId=(int)$wpdb->insert_id;
$advancedWaitIds[]=$atomicId;
$atomicToken=$selector . '.' . $verifier;
$beforeFailure=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_bookings WHERE source = %s AND resource_id = %d",
    'waiting_list',$advancedResourceId
));
$trigger='wpcb_wait_fail_' . strtolower(wp_generate_password(6,false,false));
$trigger=preg_replace('/[^a-z0-9_]/','',$trigger);
$triggerOk=$wpdb->query(
    "CREATE TRIGGER " . $trigger . " BEFORE UPDATE ON " . $waitingTable .
    " FOR EACH ROW BEGIN IF OLD.id = " . (int)$atomicId .
    " AND NEW.status = 'accepted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic waitlist finalize failure'; END IF; END"
);
wpcb_wait_assert($triggerOk!==false,'Waiting-list finalization fault injector is installed.');
try {
    $failedAccept=(new Wpcb\WaitingList\WaitingListService())->accept($atomicId,$atomicToken);
    wpcb_wait_assert(is_wp_error($failedAccept),'Acceptance finalization failure is surfaced.');
} finally {
    $wpdb->query("DROP TRIGGER IF EXISTS " . $trigger);
}
$afterFailure=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_bookings WHERE source = %s AND resource_id = %d",
    'waiting_list',$advancedResourceId
));
$atomicRow=$waitRepo->find($atomicId);
wpcb_wait_assert(
    $beforeFailure===$afterFailure && $atomicRow && $atomicRow->status==='offered',
    'Failed acceptance rolls back the reservation and restores the same offered hold.'
);

$acceptedAdvanced=(new Wpcb\WaitingList\WaitingListService())->accept($atomicId,$atomicToken);
wpcb_wait_assert(is_int($acceptedAdvanced) && $acceptedAdvanced>0,'Retry converts the protected offer after the transient finalization failure.');
$advancedBookingIds[]=$acceptedAdvanced;

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
