<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_email_retry_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

$GLOBALS['wpcb_email_retry_mode'] = 'success';
$GLOBALS['wpcb_email_retry_calls'] = 0;
$GLOBALS['wpcb_email_retry_capture'] = null;

function wpcb_email_retry_transport($return, array $atts) {
    $GLOBALS['wpcb_email_retry_calls']++;
    $mode = (string)$GLOBALS['wpcb_email_retry_mode'];

    if ($mode === 'fail') {
        return false;
    }
    if ($mode === 'throw') {
        throw new RuntimeException('Mail transport outcome unknown token=SHOULD_NOT_PERSIST password=SHOULD_NOT_PERSIST');
    }
    if ($mode === 'success') {
        $GLOBALS['wpcb_email_retry_capture'] = $atts;
        return true;
    }
    return $return;
}

add_filter('pre_wp_mail', 'wpcb_email_retry_transport', 10, 2);

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$originalTemplates = get_option('wpcb_email_templates', []);
$templates = is_array($originalTemplates) ? $originalTemplates : [];
$templates['doi_subject'] = 'Retry DOI';
$templates['doi_body'] = "Confirm securely:\n{bestaetigungslink}";
$templates['reminder_subject'] = 'Retry reminder';
$templates['reminder_body'] = "Reminder for {name}";
update_option('wpcb_email_templates', $templates);

$typeSlug = 'email-retry-' . strtolower(wp_generate_password(8, false));
$wpdb->insert($wpdb->prefix . 'wpcb_booking_types', [
    'name' => 'Email Retry Fixture',
    'slug' => $typeSlug,
    'description' => '',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'capacity' => 1,
    'show_remaining_capacity' => 0,
    'payment_mode' => 'free',
    'price_minor' => 0,
    'currency' => 'EUR',
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 999,
    'created_at' => $now,
    'updated_at' => $now,
]);
$typeId = (int)$wpdb->insert_id;
wpcb_email_retry_assert($typeId > 0, 'Email retry booking type fixture is created.');

$bookings = new Wpcb\Booking\BookingRepository();
$bookingId = $bookings->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => null,
    'slot_start' => '2033-03-04 09:00:00',
    'slot_end' => '2033-03-04 09:30:00',
    'status' => Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED,
    'party_size' => 1,
    'full_name' => 'PRIVATE RETRY NAME',
    'email' => 'private-retry@example.com',
    'phone' => '+431234999',
    'notes' => 'PRIVATE RETRY NOTES',
    'source' => 'email-retry-test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
wpcb_email_retry_assert($bookingId > 0, 'Email retry booking fixture is created.');
$bookings->updateMeta($bookingId, 'subject', 'PRIVATE RETRY META');

$booking = (array)$bookings->find($bookingId);
$meta = $bookings->getMeta($bookingId);
$tokens = new Wpcb\Tokens\TokenService();
$oldDoi = $tokens->create($bookingId, 'doi', 1440);
$oldLink = add_query_arg(
    ['wpcb_action' => 'confirm', 'wpcb_token' => rawurlencode($oldDoi)],
    home_url('/')
);

$deliveryKey = 'mail:user:' . $bookingId . ':doi:retry-smoke';
$GLOBALS['wpcb_email_retry_mode'] = 'fail';
$mailer = new Wpcb\Mail\Mailer();
$initial = $mailer->sendTemplateOnce(
    $deliveryKey,
    'doi',
    $booking,
    $meta,
    ['confirm' => $oldLink],
    false
);
wpcb_email_retry_assert($initial === false, 'Definite wp_mail failure is reported to the caller.');

$deliveries = new Wpcb\Reliability\DeliveryRepository();
$delivery = $deliveries->findByKey($deliveryKey);
wpcb_email_retry_assert($delivery && $delivery->status === 'failed', 'Definite mail failure is recorded as failed.');

$retryKey = Wpcb\Mail\EmailRetryJobRunner::jobKey($deliveryKey);
$jobs = (new Wpcb\Sync\JobRepository())->findByIdempotencyKeys([$retryKey]);
wpcb_email_retry_assert(isset($jobs[$retryKey]) && $jobs[$retryKey]->status === 'pending', 'Definite mail failure queues a retry job.');

$payloadJson = (string)$wpdb->get_var($wpdb->prepare(
    "SELECT payload_json FROM {$wpdb->prefix}wpcb_sync_jobs WHERE idempotency_key = %s LIMIT 1",
    $retryKey
));
$payload = json_decode($payloadJson, true);
wpcb_email_retry_assert(is_array($payload) && ($payload['kind'] ?? '') === 'template', 'Retry queue stores a reconstructable notification descriptor.');
wpcb_email_retry_assert(($payload['template'] ?? '') === 'doi' && empty($payload['attach_ics']), 'Retry descriptor stores template and attachment intent only.');

foreach ([
    'PRIVATE RETRY NAME',
    'private-retry@example.com',
    '+431234999',
    'PRIVATE RETRY NOTES',
    'PRIVATE RETRY META',
    $oldDoi,
    $oldLink,
] as $secret) {
    wpcb_email_retry_assert(
        strpos($payloadJson, $secret) === false,
        'Retry descriptor does not persist booking PII or action-token secrets.'
    );
}

$GLOBALS['wpcb_email_retry_mode'] = 'success';
$GLOBALS['wpcb_email_retry_capture'] = null;
(new Wpcb\Sync\QueueService())->runNow(100);

$delivery = $deliveries->findByKey($deliveryKey);
$jobs = (new Wpcb\Sync\JobRepository())->findByIdempotencyKeys([$retryKey]);
wpcb_email_retry_assert($delivery && $delivery->status === 'sent', 'Retry worker marks the logical delivery sent after transport recovery.');
wpcb_email_retry_assert(isset($jobs[$retryKey]) && $jobs[$retryKey]->status === 'done', 'Successful email retry completes the queue job.');
wpcb_email_retry_assert($GLOBALS['wpcb_email_retry_calls'] === 2, 'Exactly one transport retry occurs after the initial failure.');

$oldInspection = $tokens->inspect($oldDoi, 'doi');
wpcb_email_retry_assert($oldInspection['state'] === 'used', 'Superseded unsent DOI token is revoked before retry.');

$capture = $GLOBALS['wpcb_email_retry_capture'];
wpcb_email_retry_assert(is_array($capture), 'Successful retry captures the reconstructed email.');
$message = html_entity_decode((string)($capture['message'] ?? ''), ENT_QUOTES | ENT_HTML5);
$matched = preg_match('/wpcb_token=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)/', $message, $tokenMatch);
wpcb_email_retry_assert($matched === 1, 'Retried DOI email contains a newly generated action token.');
$newDoi = $tokenMatch[1];
wpcb_email_retry_assert($newDoi !== $oldDoi, 'Retry rotates the DOI token rather than reusing the unsent verifier.');
wpcb_email_retry_assert($tokens->inspect($newDoi, 'doi')['state'] === 'valid', 'Rotated DOI token in the retried email is valid.');

(new Wpcb\Sync\QueueService())->runNow(100);
wpcb_email_retry_assert($GLOBALS['wpcb_email_retry_calls'] === 2, 'Repeated queue worker execution does not resend a completed logical notification.');

$uncertainKey = 'mail:user:' . $bookingId . ':reminder:uncertain-smoke';
$GLOBALS['wpcb_email_retry_mode'] = 'throw';
$uncertainResult = $mailer->sendTemplateOnce(
    $uncertainKey,
    'reminder',
    $booking,
    $meta,
    [],
    false
);
wpcb_email_retry_assert($uncertainResult === false, 'Transport exception is not reported as a successful send.');
$uncertain = $deliveries->findByKey($uncertainKey);
wpcb_email_retry_assert($uncertain && $uncertain->status === 'uncertain', 'Unknown transport outcome is recorded as uncertain.');
wpcb_email_retry_assert(strpos((string)$uncertain->last_error, 'SHOULD_NOT_PERSIST') === false, 'Uncertain transport errors are redacted before storage.');
$uncertainRetryKey = Wpcb\Mail\EmailRetryJobRunner::jobKey($uncertainKey);
$uncertainJobs = (new Wpcb\Sync\JobRepository())->findByIdempotencyKeys([$uncertainRetryKey]);
wpcb_email_retry_assert(!$uncertainJobs, 'Uncertain transport outcome is not automatically retried.');

$staleKey = 'mail:user:' . $bookingId . ':reminder:stale-inflight';
$staleDelivery = $deliveries->begin($bookingId, $staleKey, 'email', 'template:reminder', 'customer', 'wp_mail');
wpcb_email_retry_assert($deliveries->markSending((int)$staleDelivery['id']), 'Stale in-flight fixture enters sending state.');
$staleJobId = (new Wpcb\Mail\EmailRetryJobRunner())->enqueueTemplate(
    $bookingId,
    $staleKey,
    'reminder',
    false,
    (string)$booking['status']
);
wpcb_email_retry_assert($staleJobId > 0, 'Stale in-flight retry fixture is queued.');
$beforeStaleCalls = $GLOBALS['wpcb_email_retry_calls'];
$GLOBALS['wpcb_email_retry_mode'] = 'success';
(new Wpcb\Sync\QueueService())->runNow(100);
$staleDeliveryRow = $deliveries->findByKey($staleKey);
wpcb_email_retry_assert($staleDeliveryRow && $staleDeliveryRow->status === 'uncertain', 'Recovered stale sending state becomes uncertain instead of being resent.');
wpcb_email_retry_assert($GLOBALS['wpcb_email_retry_calls'] === $beforeStaleCalls, 'Stale in-flight delivery is not duplicated automatically.');

$wpdb->update(
    $wpdb->prefix . 'wpcb_bookings',
    ['status' => Wpcb\Booking\BookingStatus::CONFIRMED, 'updated_at' => $now],
    ['id' => $bookingId]
);
$booking = (array)$bookings->find($bookingId);
$terminalKey = 'mail:user:' . $bookingId . ':reminder:terminal-smoke';
$GLOBALS['wpcb_email_retry_mode'] = 'fail';
$mailer->sendTemplateOnce($terminalKey, 'reminder', $booking, $meta, [], false);
$terminalRetryKey = Wpcb\Mail\EmailRetryJobRunner::jobKey($terminalKey);

for ($attempt = 0; $attempt < 5; $attempt++) {
    $wpdb->update(
        $wpdb->prefix . 'wpcb_sync_jobs',
        ['available_at' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc())],
        ['idempotency_key' => $terminalRetryKey, 'status' => 'pending']
    );
    (new Wpcb\Sync\QueueService())->runNow(100);
}

$terminalJobs = (new Wpcb\Sync\JobRepository())->findByIdempotencyKeys([$terminalRetryKey]);
$terminalDelivery = $deliveries->findByKey($terminalKey);
wpcb_email_retry_assert(isset($terminalJobs[$terminalRetryKey]) && $terminalJobs[$terminalRetryKey]->status === 'failed', 'Repeated definite failures stop after the bounded queue retry count.');
wpcb_email_retry_assert($terminalDelivery && $terminalDelivery->status === 'failed', 'Terminal email failure remains actionable in the delivery ledger.');
wpcb_email_retry_assert((int)$terminalDelivery->attempts === 6, 'Delivery ledger counts the initial attempt plus five bounded retries.');

$adminSource = file_get_contents(WPCB_DIR . 'includes/Admin/Admin.php');
wpcb_email_retry_assert(strpos($adminSource, 'terminal fehlgeschlagen') !== false, 'Admin delivery history exposes terminal retry failure.');
wpcb_email_retry_assert(strpos($adminSource, 'manuelle Prüfung erforderlich') !== false, 'Admin delivery history exposes uncertain outcomes for manual review.');

remove_filter('pre_wp_mail', 'wpcb_email_retry_transport', 10);
update_option('wpcb_email_templates', $originalTemplates);

$wpdb->delete($wpdb->prefix . 'wpcb_sync_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_deliveries', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_tokens', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $typeId]);

WP_CLI::success('Retryable email delivery smoke test passed.');
