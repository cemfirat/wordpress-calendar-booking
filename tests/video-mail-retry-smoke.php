<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_video_mail_assert($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    WP_CLI::log('PASS: ' . $message);
}

$GLOBALS['wpcb_video_mail_mode'] = 'success';
$GLOBALS['wpcb_video_mail_calls'] = [];
function wpcb_video_mail_transport($return, array $atts) {
    $GLOBALS['wpcb_video_mail_calls'][] = $atts;
    return $GLOBALS['wpcb_video_mail_mode'] === 'fail' ? false : true;
}
function wpcb_video_mail_job(int $bookingId, string $recipientClass): ?object {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpcb_sync_jobs
         WHERE booking_id = %d AND job_type = 'email_notification'
         ORDER BY id DESC LIMIT 50",
        $bookingId
    ));
    foreach ($rows as $row) {
        $payload = json_decode((string)$row->payload_json, true);
        if (($payload['kind'] ?? '') === 'video_ready'
            && ($payload['recipient_class'] ?? '') === $recipientClass
        ) return $row;
    }
    return null;
}
function wpcb_video_mail_due(object $job): void {
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'wpcb_sync_jobs',
        ['available_at' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc())],
        ['id' => (int)$job->id, 'status' => 'pending']
    );
}

add_filter('pre_wp_mail', 'wpcb_video_mail_transport', 10, 2);

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeId = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}wpcb_booking_types WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
$resourceId = (int)get_option('wpcb_default_resource_id', 0);
wpcb_video_mail_assert($typeId > 0 && $resourceId > 0, 'Video mail fixture dependencies exist.');

$bookings = new Wpcb\Booking\BookingRepository();
$bookingId = $bookings->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2034-05-08 11:00:00',
    'slot_end' => '2034-05-08 11:30:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'full_name' => 'Video Mail Person',
    'email' => 'video-mail@example.com',
    'phone' => '+431230002',
    'source' => 'video-mail-test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
wpcb_video_mail_assert($bookingId > 0, 'Video mail booking fixture is created.');

$wpdb->insert($wpdb->prefix . 'wpcb_video_connections', [
    'provider' => 'zoom',
    'name' => 'Video Mail Fixture',
    'config_json' => '{}',
    'credentials_enc' => '',
    'is_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);
$connectionId = (int)$wpdb->insert_id;
wpcb_video_mail_assert($connectionId > 0, 'Video mail connection fixture is created.');

$joinUrl = 'https://meet.example.test/join/abc123xyz';
$meetings = new Wpcb\VideoMeetings\VideoMeetingRepository();
$meetings->upsert($bookingId, $connectionId, 'zoom', 'remote-video-mail', $joinUrl, 'active');

$mailer = new Wpcb\Mail\SpecialNotificationMailer();
$GLOBALS['wpcb_video_mail_mode'] = 'fail';
wpcb_video_mail_assert($mailer->sendVideoReady($bookingId, $connectionId, 'customer'), 'Failed customer video-ready mail is queued.');

$job = wpcb_video_mail_job($bookingId, 'customer');
wpcb_video_mail_assert($job && $job->status === 'pending', 'Customer video-ready retry job is pending.');
$payload = json_decode((string)$job->payload_json, true);
wpcb_video_mail_assert(($payload['connection_id'] ?? 0) === $connectionId, 'Video retry stores only the connection reference.');
foreach ([$joinUrl, 'video-mail@example.com', 'abc123xyz'] as $private) {
    wpcb_video_mail_assert(strpos((string)$job->payload_json, $private) === false, 'Video retry payload contains no join URL or customer address.');
}

$GLOBALS['wpcb_video_mail_mode'] = 'success';
wpcb_video_mail_due($job);
(new Wpcb\Sync\QueueService())->runNow(100);
$last = $GLOBALS['wpcb_video_mail_calls'][count($GLOBALS['wpcb_video_mail_calls']) - 1];
$message = html_entity_decode((string)($last['message'] ?? ''), ENT_QUOTES | ENT_HTML5);
wpcb_video_mail_assert(strpos($message, $joinUrl) !== false, 'Customer video retry resolves the join URL only at send time.');
wpcb_video_mail_assert((string)($last['to'] ?? '') === 'video-mail@example.com', 'Customer video recipient is reconstructed from the booking.');
$delivery = (new Wpcb\Reliability\DeliveryRepository())->findByKey((string)$payload['delivery_key']);
wpcb_video_mail_assert($delivery && $delivery->status === 'sent', 'Customer video-ready delivery reaches sent.');

$originalSettings = get_option('wpcb_settings', []);
$testSettings = is_array($originalSettings) ? $originalSettings : [];
$testSettings['notifications_enabled'] = 1;
$testSettings['notification_emails'] = 'video-admin@example.com';
update_option('wpcb_settings', $testSettings);

$GLOBALS['wpcb_video_mail_mode'] = 'fail';
wpcb_video_mail_assert($mailer->sendVideoReady($bookingId, $connectionId, 'admin'), 'Failed admin video-ready mail is queued.');
$adminJob = wpcb_video_mail_job($bookingId, 'admin');
wpcb_video_mail_assert($adminJob && $adminJob->status === 'pending', 'Admin video-ready retry job is pending.');
$adminPayload = json_decode((string)$adminJob->payload_json, true);
foreach ([$joinUrl, 'video-admin@example.com', 'abc123xyz'] as $private) {
    wpcb_video_mail_assert(strpos((string)$adminJob->payload_json, $private) === false, 'Admin video retry payload contains no address or join URL.');
}

$GLOBALS['wpcb_video_mail_mode'] = 'success';
wpcb_video_mail_due($adminJob);
(new Wpcb\Sync\QueueService())->runNow(100);
$adminMail = $GLOBALS['wpcb_video_mail_calls'][count($GLOBALS['wpcb_video_mail_calls']) - 1];
$adminRecipients = (array)($adminMail['to'] ?? []);
wpcb_video_mail_assert(in_array('video-admin@example.com', $adminRecipients, true), 'Admin recipients are reconstructed from current settings.');
wpcb_video_mail_assert(
    strpos(html_entity_decode((string)($adminMail['message'] ?? ''), ENT_QUOTES | ENT_HTML5), $joinUrl) !== false,
    'Admin video retry resolves the join URL only during execution.'
);

// A changed meeting version suppresses the obsolete queued join-link notification.
$staleJoinUrl = 'https://meet.example.test/join/version-two';
$meetings->upsert($bookingId, $connectionId, 'zoom', 'remote-video-mail', $staleJoinUrl, 'active');
$GLOBALS['wpcb_video_mail_mode'] = 'fail';
$mailer->sendVideoReady($bookingId, $connectionId, 'customer');
$staleJob = wpcb_video_mail_job($bookingId, 'customer');
wpcb_video_mail_assert($staleJob && $staleJob->status === 'pending', 'Changed-version video notification is queued.');
$stalePayload = json_decode((string)$staleJob->payload_json, true);
$meetings->upsert(
    $bookingId,
    $connectionId,
    'zoom',
    'remote-video-mail',
    'https://meet.example.test/join/version-three',
    'active'
);
$callCount = count($GLOBALS['wpcb_video_mail_calls']);
$GLOBALS['wpcb_video_mail_mode'] = 'success';
wpcb_video_mail_due($staleJob);
(new Wpcb\Sync\QueueService())->runNow(100);
wpcb_video_mail_assert(count($GLOBALS['wpcb_video_mail_calls']) === $callCount, 'Changed meeting version suppresses stale video-ready mail.');
$staleDelivery = (new Wpcb\Reliability\DeliveryRepository())->findByKey((string)$stalePayload['delivery_key']);
wpcb_video_mail_assert(($staleDelivery->last_error_code ?? '') === 'notification_obsolete', 'Stale video notification is recorded as obsolete.');

update_option('wpcb_settings', $originalSettings);
remove_filter('pre_wp_mail', 'wpcb_video_mail_transport', 10);

$wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_deliveries', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_video_meetings', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_video_connections', ['id' => $connectionId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);

WP_CLI::success('Video durable mail smoke test passed.');
