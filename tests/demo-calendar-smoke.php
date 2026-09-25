<?php
// Real WordPress/MySQL integration; never included in the distributable plugin.
if (!defined('ABSPATH')) exit(1);
(static function (): void {
    global $wpdb;
    $service = new Wpcb\Demo\DemoCalendar();
    $key = Wpcb\Demo\DemoCalendar::OPTION;
    if (get_option($key, null) !== null) throw new RuntimeException('Demo test needs an untouched isolated fixture');
    $assert = static function ($ok, $message): void { if (!$ok) throw new RuntimeException($message); WP_CLI::log('PASS: ' . $message); };
    $snapshot = static function () use ($wpdb): array {
        $out = [];
        foreach (['bookings', 'tokens', 'payments', 'resources', 'booking_types', 'sync_jobs', 'deliveries', 'video_meetings'] as $suffix) {
            $out[$suffix] = hash('sha256', wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpcb_{$suffix} ORDER BY id", ARRAY_A)));
        }
        foreach (['wpcb_settings', 'wpcb_stripe_settings', 'cron'] as $option) $out[$option] = hash('sha256', serialize(get_option($option)));
        return $out;
    };
    $mail = 0; $http = 0;
    $mailGuard = static function ($return) use (&$mail) { $mail++; return false; };
    $httpGuard = static function ($return) use (&$http) { $http++; return new WP_Error('unexpected_http'); };
    add_filter('pre_wp_mail', $mailGuard); add_filter('pre_http_request', $httpGuard);
    $before = $snapshot();
    $oldUser = get_current_user_id();
    try {
        $assert($service->read() === [], 'Activation does not generate optional demo data.');
        $created = $service->create();
        $assert(is_array($created) && count($created['entries']) === 10, 'Actual option storage contains exactly ten demo entries.');
        $assert($created === $service->create(), 'Repeated creation preserves the same complete batch.');
        $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", $key));
        $assert(in_array($autoload, ['no', 'off', 'auto-off'], true), 'Demo data is not automatically loaded on every request.');
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        $assert(count($admins) === 1, 'Admin fixture exists.');
        wp_set_current_user((int)$admins[0]->ID);
        ob_start(); (new Wpcb\Admin\DemoCalendarPage())->render(); $html = ob_get_clean();
        $assert(substr_count($html, 'data-wpcb-demo-entry') === 10, 'Admin preview lists all ten entries exactly once.');
        $assert(strpos($html, 'data-wpcb-demo-month') !== false, 'Admin preview includes the month grid.');
        $tz = new DateTimeZone($created['timezone']); $weekend = false;
        foreach ($created['entries'] as $entry) {
            $start = Wpcb\Support\Time::parseUtc($entry['start'])->setTimezone($tz);
            if ((int)$start->format('N') >= 6) { $weekend = true; $assert(strpos($html, 'data-wpcb-demo-day="' . $start->format('Y-m-d') . '"') !== false, 'Weekend day is present in the preview.'); }
        }
        $assert($weekend && substr_count($html, 'href="#demo-10"') >= 2, 'Multi-day example appears on each covered day, including weekends.');
        wp_set_current_user(0); ob_start(); (new Wpcb\Admin\DemoCalendarPage())->render(); $public = ob_get_clean();
        $assert($public === '', 'Anonymous users receive no demo preview.');
        wp_set_current_user($oldUser);
        $assert($service->remove() === true && $service->read() === [], 'Removal deletes only the demo option.');

        $injected = 0;
        $fail = static function ($sql) use ($wpdb, $key, &$injected) {
            if (stripos($sql, 'INSERT INTO') === 0 && strpos($sql, $wpdb->options) !== false && strpos($sql, $key) !== false) {
                $injected++; return "INSERT INTO {$wpdb->options} (wpcb_deliberately_missing_column) VALUES (1)";
            }
            return $sql;
        };
        $suppress = $wpdb->suppress_errors(true); add_filter('query', $fail);
        try { $failed = $service->create(); } finally { remove_filter('query', $fail); $wpdb->suppress_errors($suppress); }
        $assert($injected === 1 && is_wp_error($failed) && $service->read() === [], 'A proved real storage failure leaves no partial demo or false success.');
        $assert(count($service->create()['entries']) === 10, 'Creation recovers after database write failure.');
        $assert($service->remove() === true, 'Fixture clears before concurrency check.');

        $script = 'echo "READY\n"; fflush(STDOUT); $r=(new Wpcb\\Demo\\DemoCalendar())->create(); if(is_wp_error($r)){fwrite(STDERR,$r->get_error_code());exit(1);} echo $r["batch_id"]."|".count($r["entries"]);';
        $lock = 'wpcb_demo_' . substr(hash('sha256', home_url('/') . '|' . $wpdb->prefix), 0, 48);
        $assert((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 3)) === 1, 'Parent holds the real demo lock before workers start.');
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open(['wp', 'eval', $script, '--path=' . ABSPATH], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start actual WordPress worker');
            fclose($pipes[0]); $workers[] = [$process, $pipes];
        }
        try {
            foreach ($workers as [$process, $pipes]) {
                stream_set_timeout($pipes[1], 10);
                $assert(trim((string)fgets($pipes[1])) === 'READY', 'Independent worker is ready before releasing the demo lock.');
            }
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $assert(proc_close($process) === 0, 'Separate WordPress demo worker succeeded: ' . ($error === '' ? 'clean' : 'see stderr'));
        }
        wp_cache_delete($key, 'options'); wp_cache_delete('notoptions', 'options');
        $assert($results[0] === $results[1] && str_ends_with($results[0], '|10') && count($service->read()['entries']) === 10, 'Parallel WordPress workers reuse one ten-entry batch.');
        $assert($before === $snapshot(), 'Real booking/payment/resource/configuration/queue data remains byte-equivalent.');
        $assert($mail === 0 && $http === 0, 'Demo operations send no mail and make no provider requests.');
        $assert($service->remove() === true && $service->read() === [], 'Demo is removable after parallel creation.');
    } finally {
        wp_set_current_user($oldUser); $service->remove();
        remove_filter('pre_wp_mail', $mailGuard); remove_filter('pre_http_request', $httpGuard);
    }
})();
