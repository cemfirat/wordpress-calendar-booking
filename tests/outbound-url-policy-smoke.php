<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_outbound_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

use Wpcb\Security\OutboundUrlPolicy;
use Wpcb\Admin\Settings;

wpcb_outbound_assert(
    OutboundUrlPolicy::normalizeCalendarUrl('https://8.8.8.8/calendar.ics') === 'https://8.8.8.8/calendar.ics',
    'Public HTTPS calendar targets are accepted.'
);
wpcb_outbound_assert(
    OutboundUrlPolicy::normalizeCalendarUrl('webcal://8.8.8.8/calendar.ics') === 'https://8.8.8.8/calendar.ics',
    'webcal targets normalize to HTTPS before validation.'
);

foreach ([
    'http://127.0.0.1/',
    'http://10.0.0.1/',
    'http://172.16.0.1/',
    'http://192.168.1.1/',
    'http://169.254.169.254/latest/meta-data/',
    'http://[::1]/',
    'https://user:password@8.8.8.8/calendar.ics',
    'file:///etc/passwd',
] as $blocked) {
    wpcb_outbound_assert(
        OutboundUrlPolicy::normalizeCalendarUrl($blocked) === '',
        'Unsafe calendar target is rejected: ' . $blocked
    );
    $result = OutboundUrlPolicy::get($blocked, ['timeout' => 1]);
    wpcb_outbound_assert(
        is_wp_error($result) && $result->get_error_code() === 'wpcb_outbound_url_unsafe',
        'Unsafe target fails before an HTTP request is attempted.'
    );
}

wpcb_outbound_assert(
    Settings::normalizeCalendarUrl('http://127.0.0.1/calendar.ics') === '',
    'Calendar settings use the shared outbound URL policy.'
);

$beforeSettings = get_option('wpcb_settings');
$unsafeUpdate = Settings::update(['calendar_urls' => "https://8.8.8.8/feed.ics\nhttp://127.0.0.1/private.ics"]);
wpcb_outbound_assert(
    is_wp_error($unsafeUpdate) && $unsafeUpdate->get_error_code() === 'wpcb_calendar_url_unsafe',
    'Unsafe calendar settings fail closed with an administrator-facing error.'
);
wpcb_outbound_assert(
    get_option('wpcb_settings') === $beforeSettings,
    'Rejected calendar settings do not mutate stored configuration.'
);

$feedSource = file_get_contents(WPCB_DIR . 'includes/Calendar/IcloudProvider.php');
$calDavSource = file_get_contents(WPCB_DIR . 'includes/Calendar/CalDavClient.php');
$syncSource = file_get_contents(WPCB_DIR . 'includes/Sync/CalDavClient.php');

wpcb_outbound_assert(
    strpos($feedSource, 'OutboundUrlPolicy::get') !== false
        && strpos($feedSource, 'wp_remote_get(') === false,
    'Public ICS fetching uses the safe outbound policy.'
);
wpcb_outbound_assert(
    strpos($calDavSource, 'OutboundUrlPolicy::request') !== false
        && strpos($calDavSource, 'wp_remote_request(') === false,
    'Generic CalDAV uses the safe outbound policy.'
);
wpcb_outbound_assert(
    strpos($syncSource, 'OutboundUrlPolicy::request') !== false
        && strpos($syncSource, 'wp_remote_request(') === false,
    'iCloud CalDAV sync uses the safe outbound policy.'
);

WP_CLI::success('Outbound calendar URL policy smoke test passed.');
