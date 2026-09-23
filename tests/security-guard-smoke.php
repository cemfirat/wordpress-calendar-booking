<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_guard_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

$originalSettings = get_option('wpcb_settings', []);
$settings = is_array($originalSettings) ? $originalSettings : [];
$settings['honeypot_enabled'] = 0;
$settings['timing_enabled'] = 0;
$settings['rate_limit_enabled'] = 1;
$settings['rate_limit_requests'] = 1;
$settings['rate_limit_window_minutes'] = 15;
update_option('wpcb_settings', $settings);

$originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
$testIp = '203.0.113.42';
$_SERVER['REMOTE_ADDR'] = $testIp;

$hmacKey = 'wpcb_rate_' . hash_hmac('sha256', $testIp, wp_salt('nonce'));
$legacyKey = 'wpcb_rate_' . md5($testIp);
delete_transient($hmacKey);
delete_transient($legacyKey);

try {
    $guard = new Wpcb\Security\Guard();
    $post = ['wpcb_nonce' => wp_create_nonce('wpcb_booking')];

    [$firstOk, $firstMessage] = $guard->checkSubmission($post);
    wpcb_guard_assert($firstOk === true && $firstMessage === '', 'First request inside the limit is accepted.');

    $stored = get_transient($hmacKey);
    wpcb_guard_assert(is_array($stored) && (int)($stored['count'] ?? 0) === 1, 'Rate-limit counter is stored under the keyed HMAC identifier.');
    wpcb_guard_assert(array_keys($stored) === ['count'], 'Rate-limit transient stores only the request counter.');
    wpcb_guard_assert(strpos(wp_json_encode($stored), $testIp) === false, 'Rate-limit transient value does not contain the raw IP address.');
    wpcb_guard_assert(get_transient($legacyKey) === false, 'Legacy unsalted MD5 rate-limit key is not created.');

    [$secondOk, $secondMessage] = $guard->checkSubmission($post);
    wpcb_guard_assert($secondOk === false, 'Request above the configured threshold is rejected.');
    wpcb_guard_assert($secondMessage === __('Zu viele Anfragen. Bitte später erneut versuchen.', 'wordpress-calendar-booking'), 'Rate-limit rejection uses the translatable public message.');
} finally {
    delete_transient($hmacKey);
    delete_transient($legacyKey);
    update_option('wpcb_settings', $originalSettings);
    if ($originalRemoteAddr === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
    }
}

WP_CLI::success('Booking abuse-protection smoke test passed.');
