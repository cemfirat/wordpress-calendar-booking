<?php
if (!defined('ABSPATH')) { exit; }

function cemb_health_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

global $wpdb;
$jobs = new Cemb\Sync\JobRepository();
$now = Cemb\Support\Time::formatUtc(Cemb\Support\Time::nowUtc());
$bookingId = 0;

$wpdb->insert($wpdb->prefix . 'cemb_bookings', [
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => 1,
    'slot_start' => '2030-01-01 10:00:00',
    'slot_end' => '2030-01-01 10:30:00',
    'status' => Cemb\Booking\BookingStatus::CONFIRMED,
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

$past = Cemb\Support\Time::formatUtc(Cemb\Support\Time::nowUtc()->modify('-10 minutes'));
$wpdb->update($wpdb->prefix . 'cemb_sync_jobs', [
    'status' => 'running',
    'lease_owner' => 'dead-worker',
    'lease_expires_at' => $past,
], ['id' => $running]);
$wpdb->update($wpdb->prefix . 'cemb_sync_jobs', [
    'status' => 'failed',
    'attempts' => 5,
], ['id' => $failed]);

$counts = $jobs->statusCounts();
cemb_health_assert($counts['pending'] >= 1, 'Health counters expose pending jobs.');
cemb_health_assert($counts['running'] >= 1, 'Health counters expose running jobs.');
cemb_health_assert($counts['failed'] >= 1, 'Health counters expose failed jobs.');
cemb_health_assert($jobs->staleLeaseCount() >= 1, 'Health counters expose stale running leases.');

update_option(Cemb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION, '2020-01-01 00:00:00', false);
update_option(Cemb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION, '2020-01-01 00:00:00', false);
$health = (new Cemb\Reliability\SchedulerHealth())->snapshot();
cemb_health_assert($health['healthy'] === false, 'Old scheduler timestamps create a health warning.');
cemb_health_assert(count($health['warnings']) >= 3, 'Snapshot reports stale scheduler and queue warnings.');

(new Cemb\Sync\QueueService())->runNow(1);
cemb_health_assert((string)get_option(Cemb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION, '') !== '2020-01-01 00:00:00', 'Manual queue run records its timestamp.');

$oldReminders = Cemb\Admin\Settings::get()['reminders_enabled'] ?? 0;
Cemb\Admin\Settings::update(['reminders_enabled' => 0]);
(new Cemb\Frontend\Actions())->sendReminders();
cemb_health_assert((string)get_option(Cemb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION, '') !== '2020-01-01 00:00:00', 'Hourly reminder task records its timestamp even when reminders are disabled.');
Cemb\Admin\Settings::update(['reminders_enabled' => $oldReminders ? 1 : 0]);

$adminSource = file_get_contents(CEMB_PATH . 'includes/Admin/Admin.php');
cemb_health_assert(strpos($adminSource, "run_hourly_tasks") !== false, 'System health provides a manual hourly run action.');
cemb_health_assert(strpos($adminSource, "wp_nonce_field('cemb_admin_action')") !== false, 'Manual scheduler actions use a WordPress nonce.');
cemb_health_assert(strpos($adminSource, "current_user_can('manage_options')") !== false, 'Manual scheduler actions require administrator capability.');

foreach ([$pending, $running, $failed] as $id) {
    $wpdb->delete($wpdb->prefix . 'cemb_sync_log', ['job_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'cemb_sync_jobs', ['id' => $id]);
}
$wpdb->delete($wpdb->prefix . 'cemb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'cemb_bookings', ['id' => $bookingId]);
delete_option(Cemb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION);
delete_option(Cemb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION);
echo "PASS: scheduler health smoke test complete.\n";
