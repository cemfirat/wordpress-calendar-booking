<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_readiness_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;

$missing = '__wpcb_missing__';
$oldSettings = get_option('wpcb_settings', $missing);
$oldQueueRun = get_option(Wpcb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION, $missing);
$oldReminderRun = get_option(Wpcb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION, $missing);
$oldCron = get_option('cron', $missing);

$wpdb->query('START TRANSACTION');
try {
    $wpdb->query("UPDATE {$wpdb->prefix}wpcb_booking_types SET is_active = 0, is_public = 0");
    $wpdb->query("UPDATE {$wpdb->prefix}wpcb_resources SET is_active = 0");
    $wpdb->query("UPDATE {$wpdb->prefix}wpcb_availability_rules SET is_active = 0");
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE post_type = %s AND post_status = %s",
        'page',
        'publish'
    ));

    $service = new Wpcb\Admin\SetupReadiness();
    $initial = $service->snapshot();
    wpcb_readiness_assert(!$initial['ready'], 'Incomplete setup is not marked ready.');
    wpcb_readiness_assert(!$initial['items']['booking_type']['complete'], 'Missing public booking type is detected.');
    wpcb_readiness_assert(!$initial['items']['resource']['complete'], 'Missing active resource assignment is detected.');
    wpcb_readiness_assert(!$initial['items']['availability']['complete'], 'Missing effective availability is detected.');
    wpcb_readiness_assert(!$initial['items']['public_surface']['complete'], 'Missing published booking surface is detected.');

    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert($wpdb->prefix . 'wpcb_booking_types', [
        'name' => 'Readiness Fixture',
        'slug' => 'readiness-fixture',
        'description' => '',
        'duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'capacity' => 1,
        'show_remaining_capacity' => 0,
        'payment_mode' => 'free',
        'price_minor' => 0,
        'currency' => 'EUR',
        'is_active' => 1,
        'is_public' => 1,
        'sort_order' => 999,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $typeId = (int)$wpdb->insert_id;

    $wpdb->insert($wpdb->prefix . 'wpcb_resources', [
        'name' => 'Readiness Resource',
        'slug' => 'readiness-resource',
        'public_label' => '',
        'description' => '',
        'capacity' => 1,
        'is_active' => 1,
        'is_public' => 0,
        'sort_order' => 999,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $resourceId = (int)$wpdb->insert_id;

    $wpdb->insert($wpdb->prefix . 'wpcb_booking_type_resources', [
        'booking_type_id' => $typeId,
        'resource_id' => $resourceId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $wpdb->insert($wpdb->prefix . 'wpcb_availability_rules', [
        'scope_type' => 'global',
        'scope_id' => null,
        'weekday' => 1,
        'start_time' => '09:00:00',
        'end_time' => '12:00:00',
        'slot_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'min_notice_minutes' => 0,
        'max_days_in_advance' => 30,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $wpdb->insert($wpdb->posts, [
        'post_author' => 1,
        'post_date' => current_time('mysql'),
        'post_date_gmt' => current_time('mysql', true),
        'post_content' => '<!-- wp:wpcb/booking-form /-->',
        'post_title' => 'Readiness Booking Page',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'post_password' => '',
        'post_name' => 'readiness-booking-page',
        'to_ping' => '',
        'pinged' => '',
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', true),
        'post_content_filtered' => '',
        'post_parent' => 0,
        'guid' => home_url('/readiness-booking-page/'),
        'menu_order' => 0,
        'post_type' => 'page',
        'post_mime_type' => '',
        'comment_count' => 0,
    ]);

    Wpcb\Admin\Settings::update([
        'sender_email' => 'readiness@example.com',
        'timezone' => 'Europe/Vienna',
    ]);

    $wpdb->query("UPDATE {$wpdb->prefix}wpcb_sync_jobs
                  SET status = 'done', lease_owner = NULL, lease_expires_at = NULL, last_error = NULL
                  WHERE status IN ('failed', 'running')");

    update_option(
        Wpcb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION,
        Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc())
    );
    update_option(
        Wpcb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION,
        Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc())
    );
    wp_clear_scheduled_hook('wpcb_sync_queue');
    wp_clear_scheduled_hook('wpcb_hourly_reminders');
    wp_schedule_single_event(time() + 300, 'wpcb_sync_queue');
    wp_schedule_single_event(time() + 3600, 'wpcb_hourly_reminders');

    $configured = $service->snapshot();
    wpcb_readiness_assert($configured['ready'], 'Core-only configured site becomes ready without provider connections.');
    foreach (['booking_type', 'resource', 'availability', 'settings', 'scheduler', 'public_surface'] as $key) {
        wpcb_readiness_assert($configured['items'][$key]['complete'], 'Readiness item is complete: ' . $key . '.');
        wpcb_readiness_assert(!empty($configured['items'][$key]['url']), 'Readiness item has an admin action URL: ' . $key . '.');
    }

    $serialized = wp_json_encode($configured);
    wpcb_readiness_assert(strpos($serialized, 'readiness@example.com') === false, 'Readiness snapshot does not expose sender email.');
    wpcb_readiness_assert(strpos($serialized, 'Private') === false, 'Readiness snapshot contains no booking/customer private payload.');

    $admin = get_user_by('login', 'admin');
    wpcb_readiness_assert($admin !== false, 'Admin fixture user exists.');
    wp_set_current_user((int)$admin->ID);
    ob_start();
    (new Wpcb\Admin\Admin())->dashboard();
    $html = (string)ob_get_clean();
    wpcb_readiness_assert(strpos($html, 'Bereit für Buchungen') !== false, 'Dashboard renders the ready state.');
    wpcb_readiness_assert(strpos($html, 'wpcb_resources') !== false, 'Dashboard links to resource setup.');
    wpcb_readiness_assert(strpos($html, 'wpcb_system_health') !== false, 'Dashboard links to scheduler health.');
    wpcb_readiness_assert(strpos($html, 'post-new.php?post_type=page') !== false, 'Dashboard links to page creation for public integration.');

    $wpdb->query('ROLLBACK');
} catch (Throwable $error) {
    $wpdb->query('ROLLBACK');
    throw $error;
} finally {
    if ($oldSettings === $missing) {
        delete_option('wpcb_settings');
    } else {
        update_option('wpcb_settings', $oldSettings);
    }
    if ($oldQueueRun === $missing) {
        delete_option(Wpcb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION);
    } else {
        update_option(Wpcb\Reliability\SchedulerHealth::QUEUE_LAST_RUN_OPTION, $oldQueueRun);
    }
    if ($oldReminderRun === $missing) {
        delete_option(Wpcb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION);
    } else {
        update_option(Wpcb\Reliability\SchedulerHealth::REMINDER_LAST_RUN_OPTION, $oldReminderRun);
    }
    if ($oldCron === $missing) {
        delete_option('cron');
    } else {
        update_option('cron', $oldCron);
    }
    wp_cache_delete('cron', 'options');
    wp_cache_delete('wpcb_settings', 'options');
}

WP_CLI::success('Setup readiness smoke test passed.');
