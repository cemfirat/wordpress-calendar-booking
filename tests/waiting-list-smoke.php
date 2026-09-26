<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_wait_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

function wpcb_wait_form(string $email, string $phone = ''): array {
    return [
        'subject' => 'Waiting-list test',
        'gender' => 'Divers',
        'first_name' => 'Waiting',
        'last_name' => 'Customer',
        'email' => $email,
        'phone' => $phone,
        'privacy' => '1',
    ];
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
    'form_data'=>wpcb_wait_form('first-waiter@example.com','111'),
]);
$second=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$start,'slot_end'=>$end,
    'party_size'=>1,'full_name'=>'Second Waiter','email'=>'second-waiter@example.com','phone'=>'222',
    'form_data'=>wpcb_wait_form('second-waiter@example.com','222'),
]);
wpcb_wait_assert(is_int($first) && is_int($second) && $first>0 && $second>$first,'Two customers join the full slot in FIFO order.');

$duplicate=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$start,'slot_end'=>$end,
    'party_size'=>1,'full_name'=>'First Waiter','email'=>'first-waiter@example.com','phone'=>'111',
    'form_data'=>wpcb_wait_form('first-waiter@example.com','111'),
]);
wpcb_wait_assert($duplicate===$first,'Duplicate waiting-list join is idempotent.');

$missingConsentForm=wpcb_wait_form('missing-consent@example.com');
unset($missingConsentForm['privacy']);
$missingConsent=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$start,'slot_end'=>$end,
    'party_size'=>1,'email'=>'missing-consent@example.com',
    'form_data'=>$missingConsentForm,
]);
wpcb_wait_assert(
    is_wp_error($missingConsent)
    && $missingConsent->get_error_code()==='wpcb_field_required'
    && (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_waiting_list WHERE email=%s",
        'missing-consent@example.com'
    ))===0,
    'Waiting-list orchestration cannot bypass configured required consent.'
);

$tooLarge=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$start,'slot_end'=>$end,
    'party_size'=>2,'full_name'=>'Too Large','email'=>'too-large@example.com',
    'form_data'=>wpcb_wait_form('too-large@example.com'),
]);
wpcb_wait_assert(is_wp_error($tooLarge) && $tooLarge->get_error_code()==='wpcb_waitlist_invalid','Waiting-list join rejects a party larger than effective capacity.');

$pastStart=Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-2 hours'));
$pastEnd=Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-90 minutes'));
$pastJoin=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$pastStart,'slot_end'=>$pastEnd,
    'party_size'=>1,'full_name'=>'Past Waiter','email'=>'past-waiter@example.com',
    'form_data'=>wpcb_wait_form('past-waiter@example.com'),
]);
wpcb_wait_assert(is_wp_error($pastJoin) && $pastJoin->get_error_code()==='wpcb_waitlist_invalid','Waiting-list join rejects past intervals.');

$wpdb->update($types,['is_public'=>0],['id'=>$typeId]);
$privateJoin=$service->join([
    'booking_type_id'=>$typeId,'resource_id'=>$resourceId,'slot_start'=>$start,'slot_end'=>$end,
    'party_size'=>1,'full_name'=>'Private Waiter','email'=>'private-waiter@example.com',
    'form_data'=>wpcb_wait_form('private-waiter@example.com'),
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
$acceptedMeta=(new Wpcb\Booking\BookingRepository())->getMeta($accepted);
wpcb_wait_assert(
    ($acceptedMeta['subject'] ?? '')==='Waiting-list test'
    && ($acceptedMeta['privacy'] ?? '')==='1'
    && (int)($acceptedMeta['waiting_list_entry_id'] ?? 0)===$first,
    'Waiting-list acceptance carries the validated custom fields and consent into booking meta.'
);
$doiDelivery=(new Wpcb\Reliability\DeliveryRepository())->findByKey('mail:user:' . $accepted . ':doi');
$doiTokenCount=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_tokens WHERE booking_id=%d AND token_type='doi' AND used_at IS NULL",
    $accepted
));
wpcb_wait_assert(
    $doiDelivery && in_array((string)$doiDelivery->status,['sent','sending','uncertain','failed'],true) && $doiTokenCount===1,
    'Waiting-list acceptance enters the same durable DOI lifecycle with one valid confirmation token.'
);
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

// Two isolated WordPress/MySQL workers contend for the same resource after the
// parent releases its lock. Only one capacity-two offer (party size two) may win.
$concurrentIds=[];
foreach(['race-one','race-two'] as $label){
    $wpdb->insert($waitingTable,[
        'entry_uuid'=>wp_generate_uuid4(),'booking_type_id'=>$typeA,'resource_id'=>$advancedResourceId,
        'slot_start'=>$advancedStart,'slot_end'=>$advancedEnd,'party_size'=>2,
        'full_name'=>'Race ' . $label,'email'=>$label . '@example.com','phone'=>'',
        'status'=>'waiting','created_at'=>$now,'updated_at'=>$now,
    ]);
    $concurrentIds[]=(int)$wpdb->insert_id;
}
$parentLock=new Wpcb\Resources\ResourceLock();
wpcb_wait_assert($parentLock->acquire($advancedResourceId,1),'Parent acquires the promotion resource lock before concurrent workers start.');
$spawnPromotion=static function() use($typeA,$advancedResourceId,$advancedStart,$advancedEnd): array {
    $pipes=[];
    $work=wp_json_encode([
        'operation'=>'promote','booking_type_id'=>$typeA,'resource_id'=>$advancedResourceId,
        'slot_start'=>$advancedStart,'slot_end'=>$advancedEnd,
    ]);
    $proc=proc_open(
        ['wp','eval-file',__DIR__ . '/waiting-list-worker.php','--path=' . ABSPATH],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
        $pipes,null,array_merge(getenv(),['WPCB_WAITLIST_WORK'=>$work])
    );
    if(!is_resource($proc)) throw new RuntimeException('Cannot start waiting-list promotion worker.');
    fclose($pipes[0]);
    return ['proc'=>$proc,'pipes'=>$pipes];
};
$workerOne=$spawnPromotion();
$workerTwo=$spawnPromotion();
usleep(200000);
$parentLock->release($advancedResourceId);
$finishPromotion=static function(array $worker): int {
    $stdout=stream_get_contents($worker['pipes'][1]);
    $stderr=stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exit=proc_close($worker['proc']);
    if($exit!==0 || !preg_match('/WPCB164_RESULT:(\{[^\r\n]+\})/',$stdout,$match)){
        throw new RuntimeException('Waiting-list worker failed: ' . $stdout . $stderr);
    }
    $decoded=json_decode($match[1],true);
    return (int)($decoded['result']??0);
};
$promotionResults=[$finishPromotion($workerOne),$finishPromotion($workerTwo)];
sort($promotionResults);
$offeredRace=(int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$waitingTable} WHERE id IN (%d,%d) AND status = 'offered'",
    $concurrentIds[0],$concurrentIds[1]
));
wpcb_wait_assert(
    $promotionResults===[0,max($promotionResults)] && max($promotionResults)>0 && $offeredRace===1,
    'Concurrent promotion workers serialize on the resource and create exactly one capacity-protecting offer.'
);
foreach($concurrentIds as $raceId){
    wp_clear_scheduled_hook('wpcb_waitlist_send_offer',[$raceId]);
    $wpdb->delete($waitingTable,['id'=>$raceId]);
}

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
    'form_data_json'=>wp_json_encode(wpcb_wait_form('atomic-waiter@example.com')),
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
$exportPairs=[];
foreach($export['data'][0]['data'] as $item){ $exportPairs[(string)$item['name']] = (string)$item['value']; }
wpcb_wait_assert(
    in_array('Waiting-list test',$exportPairs,true) && in_array('1',$exportPairs,true),
    'Waiting-list privacy export includes stored custom form data and consent.'
);
$erase=$privacy->eraser('second-waiter@example.com',1);
wpcb_wait_assert(!empty($erase['items_removed']) && !$waitRepo->find($second),'Waiting-list data participates in WordPress privacy erasure.');

foreach($advancedWaitIds as $waitId){ $wpdb->delete($wpdb->prefix.'wpcb_waiting_list',['id'=>$waitId]); }
foreach($advancedBookingIds as $bookingId){
    $wpdb->delete($wpdb->prefix.'wpcb_booking_status_log',['booking_id'=>$bookingId]);
    $wpdb->delete($wpdb->prefix.'wpcb_booking_meta',['booking_id'=>$bookingId]);
    $wpdb->delete($wpdb->prefix.'wpcb_payments',['booking_id'=>$bookingId]);
    $wpdb->delete($wpdb->prefix.'wpcb_bookings',['id'=>$bookingId]);
}
if($advancedRuleId>0) $wpdb->delete($wpdb->prefix.'wpcb_availability_rules',['id'=>$advancedRuleId]);
foreach($advancedTypeIds as $advancedTypeId){
    $wpdb->delete($wpdb->prefix.'wpcb_booking_type_resources',['booking_type_id'=>$advancedTypeId]);
    $wpdb->delete($types,['id'=>$advancedTypeId]);
}
if($advancedResourceId>0){
    $wpdb->delete($wpdb->prefix.'wpcb_resource_calendar_connections',['resource_id'=>$advancedResourceId]);
    $wpdb->delete($wpdb->prefix.'wpcb_resources',['id'=>$advancedResourceId]);
}

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
