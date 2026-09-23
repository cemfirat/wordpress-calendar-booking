<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clear every scheduled event owned by the plugin, including events with
 * per-entry arguments such as waiting-list offer delivery.
 */
function wpcb_uninstall_clear_cron(): void {
    $hooks = [
        'wpcb_sync_queue',
        'wpcb_hourly_reminders',
        'wpcb_privacy_retention',
        'wpcb_portal_session_cleanup',
        'wpcb_waitlist_send_offer',
    ];

    if (function_exists('_get_cron_array')) {
        $cron = _get_cron_array();
        if (is_array($cron)) {
            foreach ($cron as $timestamp => $events) {
                foreach ($hooks as $hook) {
                    if (empty($events[$hook]) || !is_array($events[$hook])) {
                        continue;
                    }
                    foreach ($events[$hook] as $event) {
                        wp_unschedule_event(
                            (int)$timestamp,
                            $hook,
                            isset($event['args']) && is_array($event['args']) ? $event['args'] : []
                        );
                    }
                }
            }
        }
    }

    foreach ($hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

/**
 * Remove disposable caches/rate limits while preserving durable plugin data.
 */
function wpcb_uninstall_clear_transients(): void {
    global $wpdb;

    $patterns = [
        $wpdb->esc_like('_transient_wpcb_') . '%',
        $wpdb->esc_like('_transient_timeout_wpcb_') . '%',
        $wpdb->esc_like('_site_transient_wpcb_') . '%',
        $wpdb->esc_like('_site_transient_timeout_wpcb_') . '%',
    ];

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s OR option_name LIKE %s
            OR option_name LIKE %s OR option_name LIKE %s",
        ...$patterns
    ));

    if (is_multisite() && !empty($wpdb->sitemeta)) {
        $sitePatterns = [
            $wpdb->esc_like('_site_transient_wpcb_') . '%',
            $wpdb->esc_like('_site_transient_timeout_wpcb_') . '%',
        ];
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->sitemeta}
             WHERE meta_key LIKE %s OR meta_key LIKE %s",
            ...$sitePatterns
        ));
    }
}

/**
 * Delete only tables and options in the plugin-owned wpcb_ namespace.
 */
function wpcb_uninstall_delete_data(): void {
    global $wpdb;

    $tableLike = $wpdb->esc_like($wpdb->prefix . 'wpcb_') . '%';
    $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $tableLike));
    $requiredPrefix = $wpdb->prefix . 'wpcb_';

    foreach ($tables as $table) {
        $table = (string)$table;
        if (strpos($table, $requiredPrefix) !== 0
            || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }

    $optionLike = $wpdb->esc_like('wpcb_') . '%';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $optionLike
    ));
}

/**
 * Clean one site's runtime state and, only after explicit opt-in, durable data.
 */
function wpcb_uninstall_site(): void {
    $settings = (array)get_option('wpcb_settings', []);
    $deleteData = !empty($settings['delete_data_on_uninstall']);

    wpcb_uninstall_clear_cron();
    wpcb_uninstall_clear_transients();

    if ($deleteData) {
        wpcb_uninstall_delete_data();
    }
}

if (is_multisite()) {
    $siteIds = get_sites([
        'fields' => 'ids',
        'number' => 0,
    ]);
    foreach ($siteIds as $siteId) {
        switch_to_blog((int)$siteId);
        wpcb_uninstall_site();
        restore_current_blog();
    }
} else {
    wpcb_uninstall_site();
}
