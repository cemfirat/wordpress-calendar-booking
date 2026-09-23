<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_wait_mail_assert($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    WP_CLI::log('PASS: ' . $message);
}

$GLOBALS['wpcb_wait_mail_mode'] = 'success';
$GLOBALS['wpcb_wait_mail_calls'] = [];
function wpcb_wait_mail_transport($return, array $atts) {
    $GLOBALS['wpcb_wait_mail_calls'][] = $atts;
    return $GLOBALS['wpcb_wait_mail_mode'] === 'fail' ? false : true;
}
function wpcb_wait_mail_token(array $mail): string {
    $message = html_entity_decode((string)($mail['message'] ?? ''), ENT_QUOTES | ENT_HTML5);
    return preg_match('/wpcb_waitlist_token=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)/', $message, $m) ? (string)$m[1] : '';
}

add_filter('pre_wp_mail', 'wpcb_wait_mail_transport', 10, 2);

global $wpdb;
$typeId = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}wpcb_booking_types WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
$resourceId = (int)get_option('wpcb_default_resource_id', 0);
wpcb_wait_mail_assert($typeId > 0 && $resourceId > 0, 'Waiting-list mail fixture dependencies exist.');

$repo = new Wpcb\WaitingList\WaitingListRepository();
$entryId = $repo->create([
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2034-05-07 10:00:00',
    'slot_end' => '2034-05-07 10:30:00',
    'party_size' => 1,
    'full_name' => 'Waiting Mail Person',
    'email' => 'wait-mail@example.com',
    'phone' => '+431230001',
]);
wpcb_wait_mail_assert($entryId > 0, 'Waiting-list mail fixture is created.');

$oldSelector = bin2hex(random_bytes(8));
$oldVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$encrypted = (new Wpcb\Security\SecretBox())->encrypt($oldVerifier);
wpcb_wait_mail_assert(!is_wp_error($encrypted), 'Waiting-list verifier encrypts.');
$expires = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+30 minutes'));
wpcb_wait_mail_assert(
    $repo->markOffered($entryId, $oldSelector, hash('sha256', $oldVerifier), (string)$encrypted, $expires),
    'Waiting-list fixture enters offered state.'
);

$mailer = new Wpcb\Mail\SpecialNotificationMailer();
$GLOBALS['wpcb_wait_mail_mode'] = 'fail';
wpcb_wait_mail_assert($mailer->sendWaitingListOffer($entryId), 'Failed waiting-list offer is queued.');

$firstMail = $GLOBALS['wpcb_wait_mail_calls'][count($GLOBALS['wpcb_wait_mail_calls']) - 1];
$oldToken = wpcb_wait_mail_token($firstMail);
$jobRows = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}wpcb_sync_jobs
     WHERE booking_id = 0 AND job_type = 'email_notification'
     ORDER BY id DESC LIMIT 20"
);
$job = null;
foreach ($jobRows as $candidate) {
    $payload = json_decode((string)$candidate->payload_json, true);
    if (($payload['kind'] ?? '') === 'waiting_list_offer') {
        $job = $candidate;
        break;
    }
}
wpcb_wait_mail_assert($job !== null && $job->status === 'pending', 'Waiting-list retry job is pending.');
$payload = json_decode((string)$job->payload_json, true);
$payloadJson = (string)$job->payload_json;
wpcb_wait_mail_assert(($payload['entry_id'] ?? 0) === $entryId, 'Waiting-list retry stores only the technical entry reference.');
foreach (['wait-mail@example.com', 'Waiting Mail Person', '+431230001', $oldVerifier, $oldToken] as $private) {
    wpcb_wait_mail_assert(strpos($payloadJson, $private) === false, 'Waiting-list queue payload contains no PII or offer verifier.');
}

$GLOBALS['wpcb_wait_mail_mode'] = 'success';
$wpdb->update(
    $wpdb->prefix . 'wpcb_sync_jobs',
    ['available_at' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc())],
    ['id' => (int)$job->id, 'status' => 'pending']
);
(new Wpcb\Sync\QueueService())->runNow(100);

$retryMail = $GLOBALS['wpcb_wait_mail_calls'][count($GLOBALS['wpcb_wait_mail_calls']) - 1];
$newToken = wpcb_wait_mail_token($retryMail);
wpcb_wait_mail_assert($newToken !== '' && $newToken !== $oldToken, 'Waiting-list retry rotates the offer token.');

[$oldS, $oldV] = array_pad(explode('.', $oldToken, 2), 2, '');
[$newS, $newV] = array_pad(explode('.', $newToken, 2), 2, '');
wpcb_wait_mail_assert($repo->acceptIfTokenMatches($entryId, $oldS, $oldV) === null, 'Superseded waiting-list token is invalid.');
wpcb_wait_mail_assert($repo->acceptIfTokenMatches($entryId, $newS, $newV) !== null, 'Retried waiting-list token matches the canonical offer.');

$delivery = (new Wpcb\Reliability\DeliveryRepository())->findByKey((string)$payload['delivery_key']);
wpcb_wait_mail_assert($delivery && $delivery->status === 'sent', 'Waiting-list delivery reaches sent after retry.');
$jobAfter = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wpcb_sync_jobs WHERE id = %d", (int)$job->id));
wpcb_wait_mail_assert($jobAfter && $jobAfter->status === 'done', 'Waiting-list retry job completes.');

remove_filter('pre_wp_mail', 'wpcb_wait_mail_transport', 10);
$wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['id' => (int)$job->id]);
$wpdb->delete($wpdb->prefix . 'wpcb_deliveries', ['idempotency_key' => (string)$payload['delivery_key']]);
$wpdb->delete($wpdb->prefix . 'wpcb_waiting_list', ['id' => $entryId]);

WP_CLI::success('Waiting-list durable mail smoke test passed.');
