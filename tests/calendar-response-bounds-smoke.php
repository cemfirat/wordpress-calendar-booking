<?php
if (!defined('ABSPATH')) {
    exit(1);
}

use Wpcb\Security\OutboundUrlPolicy;

function wpcb_response_bound_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

$mode = 'exact';
$seenLimit = 0;
$mock = static function ($preempt, array $args, string $url) use (&$mode, &$seenLimit) {
    if ($url !== 'https://8.8.8.8/calendar.ics') {
        return $preempt;
    }
    $seenLimit = (int)($args['limit_response_size'] ?? 0);
    $body = $mode === 'over' ? str_repeat('x', 17) : str_repeat('x', 16);
    $contentLength = $mode === 'header-over' ? '17' : (string)strlen($body);
    return [
        'headers' => ['content-length' => $contentLength],
        'body' => $body,
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies' => [],
        'filename' => null,
    ];
};
add_filter('pre_http_request', $mock, 10, 3);

$exact = OutboundUrlPolicy::get('https://8.8.8.8/calendar.ics', [
    'wpcb_max_response_bytes' => 16,
]);
wpcb_response_bound_assert(!is_wp_error($exact), 'A response exactly at the configured limit is accepted.');
wpcb_response_bound_assert($seenLimit === 17, 'HTTP transport reads only limit plus one byte for overflow detection.');

$mode = 'over';
$over = OutboundUrlPolicy::get('https://8.8.8.8/calendar.ics', [
    'wpcb_max_response_bytes' => 16,
]);
wpcb_response_bound_assert(
    is_wp_error($over) && $over->get_error_code() === 'wpcb_calendar_response_too_large',
    'A body exceeding the configured limit fails closed.'
);

$mode = 'header-over';
$headerOver = OutboundUrlPolicy::get('https://8.8.8.8/calendar.ics', [
    'wpcb_max_response_bytes' => 16,
]);
wpcb_response_bound_assert(
    is_wp_error($headerOver) && $headerOver->get_error_code() === 'wpcb_calendar_response_too_large',
    'An oversized Content-Length fails closed even before a large body is processed.'
);

remove_filter('pre_http_request', $mock, 10);

$policySource = file_get_contents(WPCB_DIR . 'includes/Security/OutboundUrlPolicy.php');
$feedSource = file_get_contents(WPCB_DIR . 'includes/Calendar/IcloudProvider.php');
$calDavSource = file_get_contents(WPCB_DIR . 'includes/Calendar/CalDavClient.php');
$syncSource = file_get_contents(WPCB_DIR . 'includes/Sync/CalDavClient.php');

wpcb_response_bound_assert(
    strpos($policySource, 'MAX_ICS_RESPONSE_BYTES = 2097152') !== false
        && strpos($policySource, 'MAX_CALDAV_RESPONSE_BYTES = 4194304') !== false
        && strpos($policySource, 'MAX_MUTATION_RESPONSE_BYTES = 262144') !== false,
    'Calendar response byte ceilings are centralized in the outbound policy.'
);
wpcb_response_bound_assert(
    strpos($feedSource, 'OutboundUrlPolicy::get') !== false,
    'Public ICS feeds inherit the bounded safe GET policy.'
);
wpcb_response_bound_assert(
    strpos($calDavSource, 'MAX_QUERY_RESPONSES = 2000') !== false
        && strpos($calDavSource, 'MAX_DISCOVERED_CALENDARS = 250') !== false,
    'Generic CalDAV bounds query and discovery record cardinality.'
);
wpcb_response_bound_assert(
    strpos($syncSource, 'MAX_DISCOVERED_CALENDARS = 250') !== false,
    'iCloud CalDAV discovery record cardinality is bounded.'
);

WP_CLI::success('External calendar response bound smoke test passed.');
