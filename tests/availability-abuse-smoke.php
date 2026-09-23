<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_availability_guard_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

if (!did_action('rest_api_init')) {
    do_action('rest_api_init');
}

function wpcb_test_browser_availability_limit(): int { return 2; }
function wpcb_test_rest_availability_limit(): int { return 2; }
function wpcb_test_availability_window(): int { return 60; }
function wpcb_test_one_resource_limit(): int { return 1; }

add_filter('wpcb_availability_browser_limit', 'wpcb_test_browser_availability_limit');
add_filter('wpcb_availability_rest_limit', 'wpcb_test_rest_availability_limit');
add_filter('wpcb_availability_window_seconds', 'wpcb_test_availability_window');

global $wpdb;
$typeId = (int)$wpdb->get_var(
    "SELECT id FROM {$wpdb->prefix}wpcb_booking_types WHERE is_active = 1 AND is_public = 1 ORDER BY id ASC LIMIT 1"
);
wpcb_availability_guard_assert($typeId > 0, 'Public booking type fixture exists.');

$oldRemote = $_SERVER['REMOTE_ADDR'] ?? null;
$oldForwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.8';

$guard = new Wpcb\Security\AvailabilityRequestGuard();
$browserKey = $guard->transientKeyForCurrentClient('browser');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.77';
wpcb_availability_guard_assert(
    $browserKey === $guard->transientKeyForCurrentClient('browser'),
    'Forwarded-for headers do not influence the availability limiter key.'
);
wpcb_availability_guard_assert(
    strpos($browserKey, '203.0.113.42') === false,
    'Availability limiter key does not contain the raw client IP address.'
);

delete_transient($browserKey);
$first = $guard->browserBudget();
$second = $guard->browserBudget();
$third = $guard->browserBudget();
wpcb_availability_guard_assert($first['allowed'] && $second['allowed'], 'Browser availability budget allows requests below the threshold.');
wpcb_availability_guard_assert(!$third['allowed'] && $third['retry_after'] > 0, 'Browser availability budget rejects requests over the threshold.');

$stored = get_transient($browserKey);
wpcb_availability_guard_assert(
    is_array($stored) && strpos(maybe_serialize($stored), '203.0.113.42') === false,
    'Limiter state stores counters only and no raw client IP address.'
);
$stored['reset_at'] = time() - 1;
set_transient($browserKey, $stored, MINUTE_IN_SECONDS);
$recovered = $guard->browserBudget();
wpcb_availability_guard_assert($recovered['allowed'], 'Availability budget recovers after its window expires.');

$_SERVER['REMOTE_ADDR'] = '203.0.113.43';
$restKey = $guard->transientKeyForCurrentClient('rest');
delete_transient($restKey);

$makeRequest = static function () use ($typeId): WP_REST_Response {
    $request = new WP_REST_Request('GET', '/wpcb/v1/availability');
    $request->set_param('booking_type_id', $typeId);
    $request->set_param('days', 1);
    $request->set_param('party_size', 1);
    return rest_do_request($request);
};

$restOne = $makeRequest();
$restTwo = $makeRequest();
$restThree = $makeRequest();
wpcb_availability_guard_assert($restOne->get_status() === 200 && $restTwo->get_status() === 200, 'REST availability has an independent machine-client request budget.');
wpcb_availability_guard_assert($restThree->get_status() === 429, 'REST availability returns HTTP 429 after its request budget is exhausted.');
$rateHeaders = $restThree->get_headers();
wpcb_availability_guard_assert(
    !empty($rateHeaders['Retry-After']) && (int)$rateHeaders['X-RateLimit-Limit'] === 2,
    'REST rate-limit response exposes deterministic retry and limit headers.'
);

$type = (new Wpcb\Booking\BookingTypeRepository())->find($typeId);
$capacity = max(1, (int)($type->capacity ?? 1));
if ($capacity < Wpcb\Security\AvailabilityRequestGuard::MAX_PARTY_SIZE) {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.44';
    $tooLarge = new WP_REST_Request('GET', '/wpcb/v1/availability');
    $tooLarge->set_param('booking_type_id', $typeId);
    $tooLarge->set_param('days', 1);
    $tooLarge->set_param('party_size', $capacity + 1);
    $tooLargeResponse = rest_do_request($tooLarge);
    wpcb_availability_guard_assert(
        $tooLargeResponse->get_status() === 400,
        'Availability rejects party sizes above the booking type capacity before slot generation.'
    );
}

$resources = new Wpcb\Resources\ResourceRepository();
$originalResourceIds = array_map(
    static fn($resource): int => (int)$resource->id,
    $resources->forBookingType($typeId, true)
);
$tempResourceId = $resources->save([
    'name' => 'Availability Guard Fixture',
    'slug' => 'availability-guard-' . wp_generate_password(8, false),
    'public_label' => '',
    'description' => '',
    'capacity' => 1,
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 999,
]);
wpcb_availability_guard_assert(!is_wp_error($tempResourceId), 'Temporary resource fixture is created.');
$resources->setForBookingType($typeId, array_merge($originalResourceIds, [(int)$tempResourceId]));
add_filter('wpcb_availability_max_resources', 'wpcb_test_one_resource_limit');

$fanout = $guard->validateQuery($typeId, 1, 1);
wpcb_availability_guard_assert(
    is_wp_error($fanout) && $fanout->get_error_code() === 'wpcb_availability_resource_fanout',
    'Availability rejects resource fan-out above the configured public bound.'
);

remove_filter('wpcb_availability_max_resources', 'wpcb_test_one_resource_limit');
$resources->setForBookingType($typeId, $originalResourceIds);
$resources->delete((int)$tempResourceId);

delete_transient($browserKey);
delete_transient($restKey);
delete_transient($guard->transientKeyForCurrentClient('rest'));

remove_filter('wpcb_availability_browser_limit', 'wpcb_test_browser_availability_limit');
remove_filter('wpcb_availability_rest_limit', 'wpcb_test_rest_availability_limit');
remove_filter('wpcb_availability_window_seconds', 'wpcb_test_availability_window');

if ($oldRemote === null) {
    unset($_SERVER['REMOTE_ADDR']);
} else {
    $_SERVER['REMOTE_ADDR'] = $oldRemote;
}
if ($oldForwarded === null) {
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
} else {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $oldForwarded;
}

WP_CLI::success('Availability abuse/cost guard smoke test passed.');
