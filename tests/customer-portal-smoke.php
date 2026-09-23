<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_portal_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$typeId = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}wpcb_booking_types WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
$resourceId = (int)get_option('wpcb_default_resource_id', 0);
wpcb_portal_assert($typeId > 0 && $resourceId > 0, 'Portal booking fixtures have a booking type and resource.');

$repo = new Wpcb\Booking\BookingRepository();
$ownEmail = 'portal-owner@example.com';
$otherEmail = 'portal-other@example.com';

$ownId = $repo->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2033-02-03 09:00:00',
    'slot_end' => '2033-02-03 09:30:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'full_name' => 'Portal Owner',
    'email' => $ownEmail,
    'phone' => '+431111111',
    'notes' => 'OWNER PRIVATE NOTE',
    'source' => 'portal-smoke',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
$otherId = $repo->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => '2033-02-04 09:00:00',
    'slot_end' => '2033-02-04 09:30:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'full_name' => 'OTHER PRIVATE CUSTOMER',
    'email' => $otherEmail,
    'phone' => '+432222222',
    'notes' => 'OTHER PRIVATE NOTE',
    'source' => 'portal-smoke',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
wpcb_portal_assert($ownId > 0 && $otherId > 0, 'Portal fixture bookings are created.');

$tokens = new Wpcb\Tokens\TokenService();
$magic = $tokens->create($ownId, 'portal_login', 30);
wpcb_portal_assert(($tokens->inspect($magic, 'portal_login')['state'] ?? '') === 'valid', 'Portal magic login token starts valid.');
$consumed = $tokens->consume($magic, 'portal_login', static fn($row) => (int)$row->booking_id);
wpcb_portal_assert($consumed === $ownId, 'Portal magic login token authenticates only its booking owner context.');
wpcb_portal_assert(is_wp_error($tokens->consume($magic, 'portal_login', static fn() => true)), 'Portal magic login token cannot be replayed.');

$sessions = new Wpcb\Portal\CustomerSessionRepository();
$sessionToken = $sessions->create($ownEmail, 60);
wpcb_portal_assert(is_string($sessionToken), 'Secure customer session is created.');
$session = $sessions->authenticate((string)$sessionToken);
wpcb_portal_assert(is_array($session) && $session['email'] === $ownEmail, 'Customer session authenticates the expected email.');
wpcb_portal_assert(strlen((string)$session['csrf']) === 64, 'Customer session derives a per-session CSRF token.');
wpcb_portal_assert($sessions->authenticate((string)$sessionToken . 'x') === null, 'Tampered customer session token is rejected.');

$stored = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}wpcb_customer_sessions ORDER BY id DESC LIMIT 1");
wpcb_portal_assert($stored !== null, 'Customer session is persisted.');
wpcb_portal_assert(strpos((string)$stored->email_enc, $ownEmail) === false, 'Customer session email is encrypted at rest.');
wpcb_portal_assert((string)$stored->email_hash !== $ownEmail, 'Customer session lookup uses a one-way email hash.');

$_COOKIE[Wpcb\Portal\CustomerSessionRepository::COOKIE] = (string)$sessionToken;
$_SERVER['REQUEST_URI'] = '/customer-portal/';
$_SERVER['HTTP_HOST'] = 'example.org';
$_GET = [];

$controller = new Wpcb\Portal\CustomerPortalController();
$listHtml = $controller->shortcode();
wpcb_portal_assert(strpos($listHtml, $ownEmail) !== false, 'Authenticated portal shows the current customer identity.');
wpcb_portal_assert(strpos($listHtml, $otherEmail) === false, 'Portal list never exposes another customer email.');
wpcb_portal_assert(strpos($listHtml, 'OTHER PRIVATE CUSTOMER') === false, 'Portal list never exposes another customer name.');

$_GET['wpcb_booking'] = $ownId;
$ownHtml = $controller->shortcode();
wpcb_portal_assert(strpos($ownHtml, $ownEmail) !== false, 'Customer can view their own booking detail.');
wpcb_portal_assert(strpos($ownHtml, 'wpcb_portal_cancel') !== false, 'Own active booking exposes the canonical cancel action.');
wpcb_portal_assert(strpos($ownHtml, 'wpcb_portal_reschedule') !== false, 'Own active booking exposes the canonical reschedule action.');
wpcb_portal_assert(strpos($ownHtml, (string)$session['csrf']) !== false, 'Portal mutations include the per-session CSRF token.');
wpcb_portal_assert(strpos($ownHtml, 'admin_notes') === false, 'Portal detail does not expose administrator-only notes.');

$_GET['wpcb_booking'] = $otherId;
$otherHtml = $controller->shortcode();
wpcb_portal_assert(strpos($otherHtml, 'Buchung nicht gefunden') !== false, 'Customer cannot open another customer booking.');
foreach ([$otherEmail, 'OTHER PRIVATE CUSTOMER', '+432222222', 'OTHER PRIVATE NOTE'] as $private) {
    wpcb_portal_assert(strpos($otherHtml, $private) === false, 'Unauthorized booking detail leaks no private customer data.');
}

$export = (new Wpcb\Privacy\PrivacyService())->exporter($ownEmail, 1);
wpcb_portal_assert(!empty($export['data']), 'Portal customer booking remains available to the WordPress privacy exporter.');

$erase = (new Wpcb\Privacy\PrivacyService())->eraser($ownEmail, 1);
wpcb_portal_assert(!empty($erase['items_removed']), 'Privacy erasure anonymizes the portal customer booking.');
wpcb_portal_assert($sessions->authenticate((string)$sessionToken) === null, 'Privacy erasure revokes active portal sessions for the erased email.');

unset($_COOKIE[Wpcb\Portal\CustomerSessionRepository::COOKIE], $_GET['wpcb_booking']);
$wpdb->delete($wpdb->prefix . 'wpcb_customer_sessions', ['email_hash' => (string)($stored->email_hash ?? '')]);
foreach ([$ownId, $otherId] as $id) {
    $wpdb->delete($wpdb->prefix . 'wpcb_tokens', ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_sync_log', ['booking_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $id]);
}

WP_CLI::success('Customer portal smoke test passed.');
