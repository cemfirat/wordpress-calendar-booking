<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_site_health_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$tests = apply_filters('site_status_tests', []);
wpcb_site_health_assert(isset($tests['direct']['wpcb_core_readiness']), 'Core readiness Site Health test is registered.');
wpcb_site_health_assert(isset($tests['direct']['wpcb_scheduler_health']), 'Scheduler Site Health test is registered.');
wpcb_site_health_assert(isset($tests['direct']['wpcb_mail_health']), 'Mail Site Health test is registered.');

$siteHealth = new Wpcb\Admin\SiteHealth();
$core = $siteHealth->coreReadinessTest();
$scheduler = $siteHealth->schedulerTest();
$mail = $siteHealth->mailTest();

wpcb_site_health_assert(in_array($core['status'], ['good', 'recommended'], true), 'Core readiness returns a valid Site Health status.');
wpcb_site_health_assert(in_array($scheduler['status'], ['good', 'critical'], true), 'Scheduler returns a valid Site Health status.');
wpcb_site_health_assert(in_array($mail['status'], ['good', 'recommended', 'critical'], true), 'Mail transport returns a valid Site Health status.');
wpcb_site_health_assert(($core['badge']['label'] ?? '') === 'WordPress Calendar Booking', 'Site Health result is clearly attributed to the plugin.');

$settingsBefore = get_option('wpcb_settings', []);
$mailBefore = get_option(Wpcb\Mail\MailDiagnostics::LAST_RESULT_OPTION, null);

$settings = (array)$settingsBefore;
$settings['stripe_secret_key_enc'] = 'TOP-SECRET-STRIPE-SITE-HEALTH';
$settings['google_client_secret'] = 'TOP-SECRET-GOOGLE-SITE-HEALTH';
$settings['icloud_sync_password_enc'] = 'TOP-SECRET-CALDAV-SITE-HEALTH';
update_option('wpcb_settings', $settings, false);
update_option(Wpcb\Mail\MailDiagnostics::LAST_RESULT_OPTION, [
    'status' => 'failed',
    'tested_at' => '2031-01-02 03:04:05',
    'error_code' => 'smtp_auth_failed',
], false);

$debug = apply_filters('debug_information', []);
wpcb_site_health_assert(isset($debug['wordpress-calendar-booking']), 'Site Health debug information section is registered.');
$section = $debug['wordpress-calendar-booking'];
$json = wp_json_encode($section);

wpcb_site_health_assert(strpos($json, 'TOP-SECRET-STRIPE-SITE-HEALTH') === false, 'Debug information excludes Stripe secrets.');
wpcb_site_health_assert(strpos($json, 'TOP-SECRET-GOOGLE-SITE-HEALTH') === false, 'Debug information excludes OAuth client secrets.');
wpcb_site_health_assert(strpos($json, 'TOP-SECRET-CALDAV-SITE-HEALTH') === false, 'Debug information excludes calendar credentials.');
wpcb_site_health_assert(strpos($json, '@example.com') === false, 'Debug information contains no customer or test-recipient email addresses.');
wpcb_site_health_assert(isset($section['fields']['version'], $section['fields']['schema_version'], $section['fields']['core_ready'], $section['fields']['scheduler_healthy'], $section['fields']['mail_status']), 'Debug information exposes bounded operational metadata.');
wpcb_site_health_assert(($section['fields']['mail_status']['value'] ?? '') === 'failed', 'Debug information reports redacted mail diagnostic state.');

update_option('wpcb_settings', $settingsBefore, false);
if ($mailBefore === null) {
    delete_option(Wpcb\Mail\MailDiagnostics::LAST_RESULT_OPTION);
} else {
    update_option(Wpcb\Mail\MailDiagnostics::LAST_RESULT_OPTION, $mailBefore, false);
}

echo "PASS: WordPress Site Health integration smoke test complete.\n";
