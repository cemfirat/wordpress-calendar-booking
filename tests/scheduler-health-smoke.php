<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_count_cron_hook(string $hook): int {
    $count = 0;
    $cron = _get_cron_array();
    if (!is_array($cron)) {
        return 0;
    }
    foreach ($cron as $events) {
        if (isset($events[$hook]) && is_array($events[$hook])) {
            $count += count($events[$hook]);
        }
    }
    return $count;
}

function wpcb_health_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

global $wpdb;
$jobs = new Wpcb\Sync\JobRepository();
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$bookingId = 0;

$wpdb->insert($wpdb->prefix . 'wpcb_bookings', [
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => 1,
    'slot_start' => '2030-01-01 10:00:00',
    'slot_end' => '2030-01-01 10:30:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'full_name' => 'Health Test',
    'email' => 'health@example.com',
    'source' => 'test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
$bookingId = (int)$wpdb->insert_id;

$pending = $jobs->enqueue('unknown_health_test', $bookingId, [], 'health:pending:' . wp_generate_uuid4());
$running = $jobs->enqueue('unknown_health_test', $bookingId, [], 'health:running:' . wp_generate_uuid4());
$failed = $jobs->enqueue('unknown_health_test', $bookingId, [], 'health:failed:' . wp_generate_uuid4());

$past = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-10 minutes'));
$wpdb->update($wpdb->prefix . 'wpcb_sync_jobs', [
    'status' => 'running',
    'lease_owner' => 'dead-worker',
    'lease_expires_at' => $past,
], ['id' => $running]);
$wpdb->update($wpdb->prefix . 'wpcb_sync_jobs', [
    'status' => 'failed',
    'attempts' => 5,
], ['id' => $failed]);

$counts = $jobs->statusCounts();
wpcb_health_assert($counts['pending'] >= 1, 'Health counters expose pending jobs.');
wpcb_health_assert($counts['running'] >= 1, 'Health counters expose running jobs.');
wpcb_health_assert($counts['failed'] >= 1, 'Health counters expose failed jobs.');
wpcb_health_assert($jobs->staleLeaseCount() >= 1, 'Health counters expose stale running leases.');

update_option(Wpcb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION, '2020-01-01 00:00:00', false);
update_option(Wpcb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION, '2020-01-01 00:00:00', false);
$health = (new Wpcb\Reliability\SchedulerHealth())->snapshot();
wpcb_health_assert($health['healthy'] === false, 'Old scheduler timestamps create a health warning.');
wpcb_health_assert(count($health['warnings']) >= 3, 'Snapshot reports stale scheduler and queue warnings.');

$privacyNext = wp_next_scheduled('wpcb_privacy_retention');
wpcb_health_assert($privacyNext !== false, 'Privacy retention is scheduled by the plugin.');
wp_unschedule_event((int)$privacyNext, 'wpcb_privacy_retention');
$missingPrivacy = (new Wpcb\Reliability\SchedulerHealth())->snapshot();
wpcb_health_assert(
    in_array('Die tägliche Datenschutz-Aufbewahrung ist nicht in WP-Cron eingeplant.', $missingPrivacy['warnings'], true),
    'Missing privacy retention schedule creates a health warning.'
);
wp_schedule_event(max(time() + 60, (int)$privacyNext), 'daily', 'wpcb_privacy_retention');

$portalNext = wp_next_scheduled('wpcb_portal_session_cleanup');
wpcb_health_assert($portalNext !== false, 'Portal session cleanup is scheduled by the plugin.');
wp_unschedule_event((int)$portalNext, 'wpcb_portal_session_cleanup');
$missingPortal = (new Wpcb\Reliability\SchedulerHealth())->snapshot();
wpcb_health_assert(
    in_array('Die tägliche Portal-Sitzungsbereinigung ist nicht in WP-Cron eingeplant.', $missingPortal['warnings'], true),
    'Missing portal session cleanup schedule creates a health warning.'
);
wp_schedule_event(max(time() + 60, (int)$portalNext), 'daily', 'wpcb_portal_session_cleanup');

(new Wpcb\Sync\QueueService())->runNow(1);
wpcb_health_assert((string)get_option(Wpcb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION, '') !== '2020-01-01 00:00:00', 'Manual queue run records its timestamp.');

$oldReminders = Wpcb\Admin\Settings::get()['reminders_enabled'] ?? 0;
Wpcb\Admin\Settings::update(['reminders_enabled' => 0]);
(new Wpcb\Frontend\Actions())->sendReminders();
wpcb_health_assert((string)get_option(Wpcb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION, '') !== '2020-01-01 00:00:00', 'Hourly reminder task records its timestamp even when reminders are disabled.');
Wpcb\Admin\Settings::update(['reminders_enabled' => $oldReminders ? 1 : 0]);

$adminSource = file_get_contents(WPCB_DIR . 'includes/Admin/Admin.php');
wpcb_health_assert(strpos($adminSource, "run_hourly_tasks") !== false, 'System health provides a manual hourly run action.');
wpcb_health_assert(strpos($adminSource, "wp_nonce_field('wpcb_admin_action')") !== false, 'Manual scheduler actions use a WordPress nonce.');
wpcb_health_assert(strpos($adminSource, "current_user_can('manage_options')") !== false, 'Manual scheduler actions require administrator capability.');

$scopeError = Wpcb\Core\Activator::validateActivationScope(true);
wpcb_health_assert(
    is_wp_error($scopeError)
    && $scopeError->get_error_code() === 'wpcb_network_activation_unsupported'
    && str_contains($scopeError->get_error_message(), 'separately on each site'),
    'Network-wide activation is rejected with a clear per-site activation instruction.'
);
wpcb_health_assert(
    Wpcb\Core\Activator::validateActivationScope(false) === null,
    'Ordinary per-site activation remains supported.'
);

$pluginCronHooks = [
    'wpcb_sync_queue',
    'wpcb_hourly_reminders',
    'wpcb_privacy_retention',
    'wpcb_portal_session_cleanup',
];
foreach ($pluginCronHooks as $hook) {
    wpcb_health_assert(wpcb_count_cron_hook($hook) === 1, $hook . ' has exactly one recurring schedule before deactivation.');
}
wp_schedule_single_event(time() + 3600, 'wpcb_waitlist_send_offer', [987654]);
wpcb_health_assert(
    wpcb_count_cron_hook('wpcb_waitlist_send_offer') >= 1,
    'Demand-driven waiting-list work is scheduled before deactivation.'
);

$persistedJobBeforeDeactivate = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_sync_jobs WHERE id IN (%d,%d,%d)",
    $pending,
    $running,
    $failed
));
$persistedBookingBeforeDeactivate = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_bookings WHERE id=%d",
    $bookingId
));
Wpcb\Core\Activator::deactivate();

foreach (array_merge($pluginCronHooks, ['wpcb_waitlist_send_offer']) as $hook) {
    wpcb_health_assert(
        wpcb_count_cron_hook($hook) === 0,
        $hook . ' is removed on plugin deactivation.'
    );
}
wpcb_health_assert(
    (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_sync_jobs WHERE id IN (%d,%d,%d)",
        $pending,
        $running,
        $failed
    )) === $persistedJobBeforeDeactivate,
    'Deactivation removes schedules without deleting durable queue intent.'
);
wpcb_health_assert(
    (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_bookings WHERE id=%d",
        $bookingId
    )) === $persistedBookingBeforeDeactivate,
    'Deactivation removes schedules without deleting bookings.'
);

Wpcb\Core\Activator::activate(false);
(new Wpcb\Sync\QueueService())->boot();
(new Wpcb\Frontend\Actions())->boot();
(new Wpcb\Privacy\PrivacyService())->boot();
(new Wpcb\Portal\CustomerPortalController())->boot();
foreach ($pluginCronHooks as $hook) {
    wpcb_health_assert(
        wpcb_count_cron_hook($hook) === 1,
        $hook . ' is recreated exactly once after per-site reactivation and boot.'
    );
}
wpcb_health_assert(
    wpcb_count_cron_hook('wpcb_waitlist_send_offer') === 0,
    'Demand-driven waiting-list work is not recreated without an active offer.'
);

foreach ([$pending, $running, $failed] as $id) {
    $wpdb->delete($wpdb->prefix . 'wpcb_sync_log', ['job_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['id' => $id]);
}
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
delete_option(Wpcb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION);
delete_option(Wpcb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION);
echo "PASS: scheduler health smoke test complete.\n";
