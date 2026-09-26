<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_multisite_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

function wpcb_multisite_cron_count(string $hook): int {
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

function wpcb_multisite_sites_by_path(): array {
    $sites = get_sites(['number' => 0]);
    $byPath = [];
    foreach ($sites as $site) {
        $byPath[(string)$site->path] = $site;
    }
    return $byPath;
}

function wpcb_multisite_with_site(int $siteId, callable $callback): void {
    switch_to_blog($siteId);
    try {
        $callback();
    } finally {
        restore_current_blog();
    }
}

function wpcb_multisite_assert_recurring_cron(): void {
    foreach ([
        'wpcb_sync_queue',
        'wpcb_hourly_reminders',
        'wpcb_privacy_retention',
        'wpcb_portal_session_cleanup',
    ] as $hook) {
        wpcb_multisite_assert(
            wpcb_multisite_cron_count($hook) === 1,
            $hook . ' has exactly one schedule on the current site.'
        );
    }
}

wpcb_multisite_assert(is_multisite(), 'WordPress is running in multisite mode.');
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$sites = wpcb_multisite_sites_by_path();
wpcb_multisite_assert(isset($sites['/'], $sites['/second/'], $sites['/third/']), 'Primary, second and subsequently-created third sites exist.');
$primaryId = (int)$sites['/']->blog_id;
$secondId = (int)$sites['/second/']->blog_id;
$thirdId = (int)$sites['/third/']->blog_id;
$phase = (string)(getenv('WPCB_MULTISITE_PHASE') ?: 'seed_isolation');

if ($phase === 'seed_isolation') {
    $prefixes = [];
    $secrets = [];
    foreach ([
        $primaryId => 'primary',
        $secondId => 'second',
        $thirdId => 'third',
    ] as $siteId => $label) {
        wpcb_multisite_with_site($siteId, function () use ($siteId, $label, &$prefixes, &$secrets) {
            global $wpdb;
            wpcb_multisite_assert(is_plugin_active(WPCB_BASENAME), "Plugin is active on {$label} site only through per-site activation.");
            wpcb_multisite_assert(\Wpcb\Database\SchemaMigration::isReady(), "Schema is ready on {$label} site.");
            $bookingTypesTable = $wpdb->prefix . 'wpcb_booking_types';
            wpcb_multisite_assert(
                $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($bookingTypesTable))) === $bookingTypesTable,
                "Plugin tables use the {$label} site's own prefix."
            );
            wpcb_multisite_assert_recurring_cron();

            $prefixes[$siteId] = $wpdb->prefix;
            update_option('wpcb_multisite_marker', $label, false);
            $secret = 'sk_test_multisite_' . $siteId;
            $webhook = 'whsec_multisite_' . $siteId;
            $saved = (new \Wpcb\Payments\StripeConfig())->save([
                'enabled' => 1,
                'secret_key' => $secret,
                'webhook_secret' => $webhook,
            ]);
            wpcb_multisite_assert($saved === true, "Encrypted payment credentials can be stored on {$label} site.");
            $config = new \Wpcb\Payments\StripeConfig();
            wpcb_multisite_assert(
                $config->secretKey() === $secret && $config->webhookSecret() === $webhook,
                "Encrypted payment credentials round-trip only within {$label} site."
            );
            $secrets[$siteId] = $secret;
        });
    }

    wpcb_multisite_assert(count(array_unique($prefixes)) === 3, 'All three sites use distinct database prefixes.');

    foreach ([
        $primaryId => 'primary',
        $secondId => 'second',
        $thirdId => 'third',
    ] as $siteId => $label) {
        wpcb_multisite_with_site($siteId, function () use ($siteId, $label, $secrets) {
            wpcb_multisite_assert(get_option('wpcb_multisite_marker') === $label, "Settings remain isolated on {$label} site.");
            $config = new \Wpcb\Payments\StripeConfig();
            wpcb_multisite_assert($config->secretKey() === $secrets[$siteId], "Credential lookup remains isolated on {$label} site.");
            foreach ($secrets as $otherSiteId => $otherSecret) {
                if ($otherSiteId === $siteId) {
                    continue;
                }
                wpcb_multisite_assert($config->secretKey() !== $otherSecret, "{$label} site cannot read another site's payment credential.");
            }
        });
    }

    WP_CLI::success('Multisite per-site initialization and isolation checks passed.');
    return;
}

if ($phase === 'deactivated_primary') {
    wpcb_multisite_with_site($primaryId, function () use ($primaryId) {
        global $wpdb;
        wpcb_multisite_assert(!is_plugin_active(WPCB_BASENAME), 'Primary site plugin is deactivated.');
        foreach (['wpcb_sync_queue','wpcb_hourly_reminders','wpcb_privacy_retention','wpcb_portal_session_cleanup','wpcb_waitlist_send_offer'] as $hook) {
            wpcb_multisite_assert(wpcb_multisite_cron_count($hook) === 0, $hook . ' is cleared from the deactivated primary site.');
        }
        $bookingTypesTable = $wpdb->prefix . 'wpcb_booking_types';
        wpcb_multisite_assert(
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($bookingTypesTable))) === $bookingTypesTable,
            'Primary-site durable tables survive deactivation.'
        );
        wpcb_multisite_assert(get_option('wpcb_multisite_marker') === 'primary', 'Primary-site settings survive deactivation.');
        wpcb_multisite_assert(
            (new \Wpcb\Payments\StripeConfig())->secretKey() === 'sk_test_multisite_' . $primaryId,
            'Primary-site encrypted credentials survive deactivation.'
        );
    });

    foreach ([$secondId => 'second', $thirdId => 'third'] as $siteId => $label) {
        wpcb_multisite_with_site($siteId, function () use ($label) {
            wpcb_multisite_assert(is_plugin_active(WPCB_BASENAME), "{$label} site remains active when primary is deactivated.");
            wpcb_multisite_assert_recurring_cron();
        });
    }

    WP_CLI::success('Multisite deactivation isolation checks passed.');
    return;
}

if ($phase === 'reactivated') {
    foreach ([$primaryId => 'primary', $secondId => 'second', $thirdId => 'third'] as $siteId => $label) {
        wpcb_multisite_with_site($siteId, function () use ($siteId, $label) {
            wpcb_multisite_assert(is_plugin_active(WPCB_BASENAME), "Plugin is active on {$label} site after reactivation.");
            wpcb_multisite_assert_recurring_cron();
            wpcb_multisite_assert(get_option('wpcb_multisite_marker') === $label, "{$label} settings survive reactivation without duplication.");
            wpcb_multisite_assert(
                (new \Wpcb\Payments\StripeConfig())->secretKey() === 'sk_test_multisite_' . $siteId,
                "{$label} encrypted credential survives reactivation."
            );
        });
    }
    WP_CLI::success('Multisite reactivation checks passed.');
    return;
}

if ($phase === 'prepare_uninstall') {
    foreach ([
        $primaryId => 0,
        $secondId => 1,
        $thirdId => 0,
    ] as $siteId => $deleteData) {
        wpcb_multisite_with_site($siteId, function () use ($deleteData) {
            $settings = (array)get_option('wpcb_settings', []);
            $settings['delete_data_on_uninstall'] = $deleteData;
            update_option('wpcb_settings', $settings, false);
            set_transient('wpcb_multisite_fixture', 'cache', HOUR_IN_SECONDS);
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'wpcb_waitlist_send_offer', [987654]);
        });
    }
    WP_CLI::success('Multisite uninstall fixtures prepared.');
    return;
}

if ($phase === 'after_uninstall') {
    foreach ([
        $primaryId => ['label' => 'primary', 'deleted' => false],
        $secondId => ['label' => 'second', 'deleted' => true],
        $thirdId => ['label' => 'third', 'deleted' => false],
    ] as $siteId => $expectation) {
        wpcb_multisite_with_site($siteId, function () use ($expectation) {
            global $wpdb;
            $tables = $wpdb->get_col(
                $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'wpcb_') . '%')
            );
            if ($expectation['deleted']) {
                wpcb_multisite_assert($tables === [], 'Destructive multisite uninstall removes second-site plugin tables.');
                wpcb_multisite_assert(
                    get_option('wpcb_multisite_marker', '__missing__') === '__missing__'
                    && get_option('wpcb_stripe_settings', '__missing__') === '__missing__',
                    'Destructive multisite uninstall removes second-site plugin options and credentials.'
                );
            } else {
                wpcb_multisite_assert($tables !== [], ucfirst($expectation['label']) . '-site durable plugin tables are preserved by default.');
                wpcb_multisite_assert(
                    get_option('wpcb_multisite_marker', '__missing__') === $expectation['label'],
                    ucfirst($expectation['label']) . '-site durable settings are preserved by default.'
                );
                $stripe = get_option('wpcb_stripe_settings', []);
                wpcb_multisite_assert(
                    is_array($stripe)
                    && !empty($stripe['secret_key_enc'])
                    && !empty($stripe['webhook_secret_enc']),
                    ucfirst($expectation['label']) . '-site encrypted credentials are preserved by default.'
                );
            }
            wpcb_multisite_assert(get_transient('wpcb_multisite_fixture') === false, 'Multisite uninstall clears plugin transients on ' . $expectation['label'] . ' site.');
            foreach (['wpcb_sync_queue','wpcb_hourly_reminders','wpcb_privacy_retention','wpcb_portal_session_cleanup','wpcb_waitlist_send_offer'] as $hook) {
                wpcb_multisite_assert(
                    wpcb_multisite_cron_count($hook) === 0,
                    $hook . ' is absent after multisite uninstall on ' . $expectation['label'] . ' site.'
                );
            }
        });
    }
    wpcb_multisite_assert(
        !is_file(WP_PLUGIN_DIR . '/wordpress-calendar-booking/wordpress-calendar-booking.php'),
        'Packaged plugin files are removed only after multisite uninstall policy completes.'
    );
    WP_CLI::success('Multisite uninstall policy checks passed.');
    return;
}

throw new RuntimeException('Unknown multisite lifecycle phase: ' . $phase);
