<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_diag_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

$GLOBALS['wpcb_diag_created'] = 0;
$GLOBALS['wpcb_diag_cancelled'] = 0;

$fixtureProvider = new class implements Wpcb\Calendar\CalendarSyncProviderInterface {
    public function id(): string { return 'diagnostic_fixture'; }
    public function label(): string { return 'Diagnostic Fixture'; }
    public function capabilities(): array {
        return [
            Wpcb\Calendar\ProviderCapabilities::BUSY_READ,
            Wpcb\Calendar\ProviderCapabilities::EVENT_CREATE,
            Wpcb\Calendar\ProviderCapabilities::EVENT_CANCEL,
        ];
    }
    public function busyBetween(string $fromUtc, string $toUtc, Wpcb\Calendar\CalendarConnection $connection) {
        return [['start' => $fromUtc, 'end' => $toUtc]];
    }
    public function createEvent(array $booking, array $meta, Wpcb\Calendar\CalendarConnection $connection) {
        $GLOBALS['wpcb_diag_created']++;
        return ['ok' => true, 'event_id' => 'temporary-diagnostic-event'];
    }
    public function updateEvent(array $booking, array $meta, Wpcb\Calendar\CalendarConnection $connection, string $eventId) {
        return ['ok' => true, 'event_id' => $eventId];
    }
    public function cancelEvent(Wpcb\Calendar\CalendarConnection $connection, string $eventId) {
        $GLOBALS['wpcb_diag_cancelled']++;
        return ['ok' => true, 'event_id' => $eventId];
    }
};
add_filter('wpcb_calendar_providers', static function (array $providers) use ($fixtureProvider): array {
    $providers[] = $fixtureProvider;
    return $providers;
}, 999);

$repo = new Wpcb\Calendar\CalendarConnectionRepository();
$secret = 'DIAGNOSTIC-SUPER-SECRET';
$token = 'eyJhbGciOiJub25lIn0.SENSITIVE-TOKEN';
$id = $repo->create(
    [
        'provider' => 'diagnostic_fixture',
        'name' => 'Diagnostics CI',
        'remote_calendar_id' => 'calendar-ci',
        'blocks_availability' => 1,
        'receives_bookings' => 1,
    ],
    [
        'password' => $secret,
        'access_token' => $token,
    ]
);
wpcb_diag_assert(!is_wp_error($id) && $id > 0, 'Create provider diagnostics fixture connection.');

$repo->setHealthSuccess((int)$id, 'read');
$repo->setHealthSuccess((int)$id, 'write');
$repo->setHealthError((int)$id, 'Authorization: Bearer ' . $token . ' access_token=' . $secret);

$service = new Wpcb\Calendar\ProviderDiagnosticsService($repo, new Wpcb\Calendar\ProviderRegistry());
$connection = $repo->find((int)$id);
$summary = $service->describe($connection);
wpcb_diag_assert($summary['provider_label'] === 'Diagnostic Fixture', 'Diagnostics resolve provider label.');
wpcb_diag_assert(in_array(Wpcb\Calendar\ProviderCapabilities::BUSY_READ, $summary['capabilities'], true), 'Diagnostics expose provider capabilities.');
wpcb_diag_assert(!empty($summary['last_read_at']) && !empty($summary['last_write_at']), 'Diagnostics expose last successful read and write timestamps.');
wpcb_diag_assert(strpos((string)$summary['last_error'], $secret) === false && strpos((string)$summary['last_error'], $token) === false, 'Diagnostics redact secret/token material from error summaries.');
wpcb_diag_assert(!array_key_exists('credentials', $summary), 'Diagnostics view model never exposes credential payloads.');

$read = $service->testRead((int)$id);
wpcb_diag_assert(!empty($read['ok']) && ($read['count'] ?? 0) === 1, 'Manual provider read test succeeds.');

$write = $service->testWrite((int)$id);
wpcb_diag_assert(!empty($write['ok']), 'Manual provider write test succeeds.');
wpcb_diag_assert($GLOBALS['wpcb_diag_created'] === 1 && $GLOBALS['wpcb_diag_cancelled'] === 1, 'Write diagnostic creates and immediately removes one temporary event.');

$controller = new Wpcb\Calendar\ProviderDiagnosticsController($service);
wp_set_current_user(0);
ob_start();
$controller->page();
$unauthorized_html = (string)ob_get_clean();
wpcb_diag_assert($unauthorized_html === '', 'Diagnostics admin page does not render without manage_options.');

$admin = get_user_by('login', 'admin');
wpcb_diag_assert($admin instanceof WP_User, 'Diagnostics smoke test resolves the WordPress administrator.');
wp_set_current_user((int)$admin->ID);
ob_start();
$controller->page();
$html = (string)ob_get_clean();
wpcb_diag_assert(strpos($html, 'Diagnostics CI') !== false, 'Diagnostics admin page renders the connection.');
wpcb_diag_assert(strpos($html, 'Test read') !== false && strpos($html, 'Test write') !== false, 'Diagnostics admin page exposes manual read/write actions.');
wpcb_diag_assert(strpos($html, '_wpnonce') !== false, 'Diagnostics manual test forms include WordPress nonces.');
wpcb_diag_assert(strpos($html, $secret) === false && strpos($html, $token) === false, 'Diagnostics admin HTML never renders stored secrets or tokens.');

wpcb_diag_assert(false !== has_action('admin_post_wpcb_provider_test_read'), 'Read diagnostic action is registered.');
wpcb_diag_assert(false !== has_action('admin_post_wpcb_provider_test_write'), 'Write diagnostic action is registered.');

$repo->delete((int)$id);
WP_CLI::success('Provider diagnostics smoke tests passed.');
