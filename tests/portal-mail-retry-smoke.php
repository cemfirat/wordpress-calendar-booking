<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_portal_mail_assert($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    WP_CLI::log('PASS: ' . $message);
}

$GLOBALS['wpcb_portal_mail_mode'] = 'success';
$GLOBALS['wpcb_portal_mail_calls'] = [];
function wpcb_portal_mail_transport($return, array $atts) {
    $GLOBALS['wpcb_portal_mail_calls'][] = $atts;
    if ($GLOBALS['wpcb_portal_mail_mode'] === 'fail') return false;
    if ($GLOBALS['wpcb_portal_mail_mode'] === 'throw') {
        throw new RuntimeException('Mail transport outcome unknown credential=redact-me');
    }
    return true;
}
function wpcb_portal_mail_last_job(int $bookingId): ?object {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpcb_sync_jobs
         WHERE booking_id = %d AND job_type = 'email_notification'
         ORDER BY id DESC LIMIT 1",
        $bookingId
    )) ?: null;
}
function wpcb_portal_mail_token(array $mail): string {
    $message = html_entity_decode((string)($mail['message'] ?? ''), ENT_QUOTES | ENT_HTML5);
    return preg_match('/wpcb_token=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)/', $message, $m) ? (string)$m[1] : '';
}
function wpcb_portal_mail_due(object $job): void {
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'wpcb_sync_jobs',
        ['available_at' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc())],
        ['id' => (int)$job->id, 'status' => 'pending']
    );
}

add_filter('pre_wp_mail', 'wpcb_portal_mail_transport', 10, 2);

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeId = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}wpcb_booking_types WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
$resourceId = (int)get_option('wpcb_default_resource_id', 0);
wpcb_portal_mail_assert($typeId > 0 && $resourceId > 0, 'Portal mail fixture dependencies exist.');

$bookings = new Wpcb\Booking\BookingRepository();
$bookingId = $bookings->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2034-05-06 09:00:00',
    'slot_end' => '2034-05-06 09:30:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'full_name' => 'Portal Mail Person',
    'email' => 'portal-mail@example.com',
    'phone' => '+431230000',
    'source' => 'portal-mail-test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
wpcb_portal_mail_assert($bookingId > 0, 'Portal mail booking fixture is created.');

$mailer = new Wpcb\Mail\SpecialNotificationMailer();
$tokens = new Wpcb\Tokens\TokenService();
$deliveries = new Wpcb\Reliability\DeliveryRepository();

// Login mail: queue a definite failure without persisting recipient or token.
$GLOBALS['wpcb_portal_mail_mode'] = 'fail';
wpcb_portal_mail_assert(
    $mailer->sendPortalLogin($bookingId, home_url('/customer-portal/?private=discard-this')),
    'Failed portal login mail is queued.'
);
$firstMail = $GLOBALS['wpcb_portal_mail_calls'][count($GLOBALS['wpcb_portal_mail_calls']) - 1];
$oldToken = wpcb_portal_mail_token($firstMail);
$job = wpcb_portal_mail_last_job($bookingId);
wpcb_portal_mail_assert($job && $job->status === 'pending', 'Portal login retry job is pending.');
$payload = json_decode((string)$job->payload_json, true);
wpcb_portal_mail_assert(($payload['kind'] ?? '') === 'portal_login', 'Portal login retry has a typed descriptor.');
wpcb_portal_mail_assert(($payload['return_path'] ?? '') === '/customer-portal/', 'Portal retry stores a same-site path only.');
foreach (['portal-mail@example.com', $oldToken, 'discard-this', home_url('/')] as $private) {
    wpcb_portal_mail_assert(strpos((string)$job->payload_json, $private) === false, 'Portal retry payload excludes recipient and secret-bearing URL data.');
}

$GLOBALS['wpcb_portal_mail_mode'] = 'success';
wpcb_portal_mail_due($job);
(new Wpcb\Sync\QueueService())->runNow(100);
$retryMail = $GLOBALS['wpcb_portal_mail_calls'][count($GLOBALS['wpcb_portal_mail_calls']) - 1];
$newToken = wpcb_portal_mail_token($retryMail);
wpcb_portal_mail_assert($newToken !== '' && $newToken !== $oldToken, 'Portal retry rotates the login token.');
wpcb_portal_mail_assert(($tokens->inspect($oldToken, 'portal_login')['state'] ?? '') === 'used', 'Superseded login token is revoked.');
wpcb_portal_mail_assert(($tokens->inspect($newToken, 'portal_login')['state'] ?? '') === 'valid', 'Retried login token is valid.');
$deliveryKey = (string)($payload['delivery_key'] ?? '');
wpcb_portal_mail_assert(($deliveries->findByKey($deliveryKey)->status ?? '') === 'sent', 'Portal login delivery reaches sent.');

// Email-change mail also rotates on retry and reconstructs the pending address.
$pendingEmail = 'portal-new@example.com';
$bookings->updateMeta($bookingId, 'portal_pending_email', $pendingEmail);
$GLOBALS['wpcb_portal_mail_mode'] = 'fail';
wpcb_portal_mail_assert(
    $mailer->sendPortalEmailChange($bookingId, home_url('/customer-portal/?email=discard')),
    'Failed portal email-change mail is queued.'
);
$emailOld = wpcb_portal_mail_token($GLOBALS['wpcb_portal_mail_calls'][count($GLOBALS['wpcb_portal_mail_calls']) - 1]);
$emailJob = wpcb_portal_mail_last_job($bookingId);
$emailPayload = json_decode((string)$emailJob->payload_json, true);
wpcb_portal_mail_assert(($emailPayload['kind'] ?? '') === 'portal_email_change', 'Email-change retry has a typed descriptor.');
wpcb_portal_mail_assert(strpos((string)$emailJob->payload_json, $pendingEmail) === false, 'Pending email address is not copied into the queue.');

$GLOBALS['wpcb_portal_mail_mode'] = 'success';
wpcb_portal_mail_due($emailJob);
(new Wpcb\Sync\QueueService())->runNow(100);
$emailRetry = $GLOBALS['wpcb_portal_mail_calls'][count($GLOBALS['wpcb_portal_mail_calls']) - 1];
$emailNew = wpcb_portal_mail_token($emailRetry);
wpcb_portal_mail_assert($emailNew !== '' && $emailNew !== $emailOld, 'Email-change retry rotates its token.');
wpcb_portal_mail_assert(($tokens->inspect($emailOld, 'portal_email_change')['state'] ?? '') === 'used', 'Superseded email-change token is revoked.');
wpcb_portal_mail_assert((string)($emailRetry['to'] ?? '') === $pendingEmail, 'Pending email address is reconstructed only at send time.');

// If the pending address changes before retry, the queued notification becomes obsolete.
$bookings->updateMeta($bookingId, 'portal_pending_email', 'portal-stale-a@example.com');
$GLOBALS['wpcb_portal_mail_mode'] = 'fail';
$mailer->sendPortalEmailChange($bookingId, home_url('/customer-portal/'));
$staleJob = wpcb_portal_mail_last_job($bookingId);
$stalePayload = json_decode((string)$staleJob->payload_json, true);
$bookings->updateMeta($bookingId, 'portal_pending_email', 'portal-stale-b@example.com');
$callCount = count($GLOBALS['wpcb_portal_mail_calls']);
$GLOBALS['wpcb_portal_mail_mode'] = 'success';
wpcb_portal_mail_due($staleJob);
(new Wpcb\Sync\QueueService())->runNow(100);
wpcb_portal_mail_assert(count($GLOBALS['wpcb_portal_mail_calls']) === $callCount, 'Changed pending address suppresses stale mail.');
$staleDelivery = $deliveries->findByKey((string)$stalePayload['delivery_key']);
wpcb_portal_mail_assert(($staleDelivery->last_error_code ?? '') === 'notification_obsolete', 'Stale portal notification remains visible as obsolete.');

// Unknown transport outcome is not auto-retried.
$GLOBALS['wpcb_portal_mail_mode'] = 'throw';
$mailer->sendPortalLogin($bookingId, home_url('/customer-portal/'));
$portalRows = $deliveries->search(['booking_id' => $bookingId, 'effect_type' => 'portal_login'], 10);
$uncertain = $portalRows[0] ?? null;
wpcb_portal_mail_assert($uncertain && $uncertain->status === 'uncertain', 'Unknown portal transport outcome is marked uncertain.');
$lookup = (new Wpcb\Sync\JobRepository())->findByIdempotencyKeys([
    Wpcb\Mail\EmailRetryJobRunner::jobKey((string)$uncertain->idempotency_key),
]);
wpcb_portal_mail_assert(!$lookup, 'Uncertain portal outcome is not queued for automatic duplicate delivery.');

remove_filter('pre_wp_mail', 'wpcb_portal_mail_transport', 10);
$wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_deliveries', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_tokens', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);

WP_CLI::success('Portal durable mail smoke test passed.');
