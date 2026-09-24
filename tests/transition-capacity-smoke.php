<?php
/**
 * Real WordPress/MySQL lock and transaction regressions, invoked by the existing
 * capacity integration step on both WordPress/PHP environments. The workers use
 * separate database connections; no remote provider or actual mail is sent.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { exit(1); }

(function (): void {
    global $wpdb, $wp_filter;
    $assert = static function (bool $ok, string $message): void {
        if (!$ok) throw new RuntimeException('Transition regression: ' . $message);
        echo 'PASS: ' . $message . "\n";
    };
    $prefix = $wpdb->prefix . 'wpcb_';
    $repo = new Wpcb\Booking\BookingRepository();
    $locks = new Wpcb\Resources\ResourceLock();
    $resources = new Wpcb\Resources\ResourceRepository();
    $service = new Wpcb\Booking\BookingTransitionService();
    $bookingIds = $resourceIds = $seriesIds = $workers = $savedHooks = [];
    $typeId = 0;
    $savedSettings = get_option('wpcb_settings', []);
    $dir = sys_get_temp_dir() . '/wpcb-154-' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create test barrier directory.');
    $effects = [];
    foreach (['wpcb_booking_transitioned', 'wpcb_booking_created', 'wpcb_booking_event_recorded'] as $hook) {
        $savedHooks[$hook] = isset($wp_filter[$hook]) ? clone $wp_filter[$hook] : null;
        remove_all_actions($hook);
    }
    add_action('wpcb_booking_transitioned', static function ($event) use (&$effects) { $effects[] = $event; });

    $spawn = static function (array $work, bool $barrier = true) use (&$workers, $dir): int {
        $key = count($workers);
        if ($barrier) $work['signal'] = $dir . '/signal-' . $key;
        $pipes = [];
        $proc = proc_open(['wp', 'eval-file', __DIR__ . '/transition-capacity-worker.php', '--path=' . ABSPATH],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            array_merge(getenv(), ['WPCB_TRANSITION_WORK' => wp_json_encode($work)]));
        if (!is_resource($proc)) throw new RuntimeException('Cannot start isolated WordPress worker.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $workers[$key] = ['process' => $proc, 'pipes' => $pipes, 'signal' => $work['signal'] ?? '',
            'stdout' => '', 'stderr' => '', 'closed' => false];
        return $key;
    };
    $drain = static function (int $key) use (&$workers): array {
        $worker = &$workers[$key];
        $worker['stdout'] .= stream_get_contents($worker['pipes'][1]);
        $worker['stderr'] .= stream_get_contents($worker['pipes'][2]);
        return proc_get_status($worker['process']);
    };
    $waiting = static function (int $key) use (&$workers, $drain, $assert): void {
        $until = microtime(true) + 10;
        do {
            $status = $drain($key);
            if (is_file($workers[$key]['signal'])) {
                $assert($status['running'], 'Worker reaches the real resource lock while owner holds it.');
                return;
            }
            if (!$status['running']) throw new RuntimeException('Worker exited before acquiring the resource lock: ' . $workers[$key]['stdout'] . $workers[$key]['stderr']);
            usleep(10000);
        } while (microtime(true) < $until);
        throw new RuntimeException('Worker did not reach its resource-lock barrier.');
    };
    $finish = static function (int $key) use (&$workers, $drain): array {
        $until = microtime(true) + 15;
        do {
            $status = $drain($key);
            if (!$status['running']) {
                $worker = &$workers[$key];
                $worker['stdout'] .= stream_get_contents($worker['pipes'][1]);
                $worker['stderr'] .= stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]); fclose($worker['pipes'][2]);
                $exit = proc_close($worker['process']);
                $worker['closed'] = true;
                if ($status['exitcode'] !== 0 && $exit !== 0) throw new RuntimeException('Worker failed: ' . $worker['stderr']);
                if (!preg_match('/WPCB154_RESULT:(\{[^\r\n]+\})/', $worker['stdout'], $m)) {
                    throw new RuntimeException('Missing worker result: ' . $worker['stdout'] . $worker['stderr']);
                }
                return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
            }
            usleep(10000);
        } while (microtime(true) < $until);
        throw new RuntimeException('Isolated worker timed out.');
    };
    $errorIs = static fn($value, $code): bool => is_wp_error($value) && $value->get_error_code() === $code;
    $lockName = static fn(int $id): string => 'wpcb_res_' . substr(hash('sha256', home_url('/') . '|' . $id), 0, 48);
    $lockOwner = static function (int $id) use ($wpdb, $lockName) {
        return $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $lockName($id)));
    };
    try {
        $settings = Wpcb\Admin\Settings::get();
        $settings['timezone'] = 'UTC'; $settings['calendar_urls'] = ''; $settings['calendar_url'] = '';
        update_option('wpcb_settings', $settings);
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($prefix . 'booking_types', ['name' => 'Transition fixture',
            'slug' => 'transition-' . wp_generate_password(12, false), 'description' => '',
            'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'capacity' => 2, 'payment_mode' => 'free', 'price_minor' => 0, 'currency' => 'EUR',
            'is_active' => 1, 'is_public' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $typeId = (int)$wpdb->insert_id;
        $assert($typeId > 0, 'Transition fixture booking type created.');
        foreach ([1, 2] as $i) {
            $id = $resources->save(['name' => 'Transition resource ' . $i,
                'slug' => 'transition-' . wp_generate_password(12, false),
                'capacity' => 2, 'is_active' => 1, 'is_public' => 0]);
            $assert(is_int($id) && $id > 0, 'Isolated transition resource created.');
            $resourceIds[] = $id;
        }
        [$a, $b] = $resourceIds;
        $resources->setForBookingType($typeId, $resourceIds);
        foreach (range(1, 7) as $weekday) {
            $wpdb->insert($prefix . 'availability_rules', ['scope_type' => 'booking_type', 'scope_id' => $typeId,
                'weekday' => $weekday, 'start_time' => '08:00:00', 'end_time' => '18:00:00',
                'slot_duration_minutes' => 30, 'min_notice_minutes' => 0, 'max_days_in_advance' => 90,
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        $index = 0;
        $make = static function (array $override = []) use ($repo, $typeId, $a, $now, &$bookingIds, &$index): int {
            ++$index;
            $start = gmdate('Y-m-d 09:00:00', time() + (7 + $index) * DAY_IN_SECONDS);
            $end = substr($start, 0, 11) . '09:30:00';
            $id = $repo->create(array_merge(['booking_uuid' => wp_generate_uuid4(), 'booking_type_id' => $typeId,
                'resource_id' => $a, 'slot_start' => $start, 'slot_end' => $end, 'party_size' => 1,
                'status' => 'reserved_unconfirmed', 'reserved_until' => gmdate('Y-m-d H:i:s', time() + 1800),
                'full_name' => 'CI fixture', 'email' => 'transition-fixture@example.test',
                'source' => 'ci-transition-154', 'lang' => 'en', 'created_at' => $now, 'updated_at' => $now], $override), [], false);
            if ($id < 1) throw new RuntimeException('Cannot create transition booking fixture.');
            $bookingIds[] = $id;
            return $id;
        };

        $assert($locks->acquire($a) && $locks->acquire($a), 'MySQL allows nested resource acquisition.');
        $locks->release($a);
        $assert($lockOwner($a) !== null, 'One release retains the outer resource lock.');
        $locks->release($a);
        $assert($lockOwner($a) === null, 'The final release frees the MySQL resource lock.');
        $locks->acquire($a); $locks->acquire($a); $locks->acquire($b); $locks->releaseAll();
        $assert($lockOwner($a) === null && $lockOwner($b) === null, 'releaseAll frees every acquisition.');

        $id = $make(['reserved_until' => '2000-01-01 00:00:00']);
        $tokens = new Wpcb\Tokens\TokenService();
        $token = $tokens->create($id, 'confirm', 60);
        $result = $tokens->consume($token, 'confirm', static fn($row) => $service->apply((int)$row->booking_id, 'email_confirmed_automatic'));
        $assert($errorIs($result, 'wpcb_reservation_expired'), 'A valid DOI token cannot resurrect an expired reservation.');
        $assert($tokens->inspect($token, 'confirm')['state'] === 'valid', 'A failed domain action does not consume its one-time token.');

        $id = $make();
        $result = $service->apply($id, 'email_confirmed_approval');
        $assert(is_array($result) && $repo->find($id)->status === 'pending_approval', 'DOI approval flow succeeds under the resource lock.');
        $result = $service->apply($id, 'admin_approved');
        $assert(is_array($result) && $repo->find($id)->status === 'confirmed', 'Administrator approval succeeds under the same lock.');
        $count = count($effects);
        $result = $service->apply($id, 'admin_approved');
        $assert(is_array($result) && !$result['changed'] && count($effects) === $count, 'Idempotent approval has no second side effect.');
        $assert($lockOwner($a) === null, 'Successful confirmation leaves no MySQL lock.');

        $series = new Wpcb\Booking\BookingSeriesRepository();
        $sid = $series->create(['booking_type_id' => $typeId, 'resource_id' => $a, 'occurrence_count' => 2, 'timezone' => 'UTC']);
        $assert($sid > 0, 'Real series fixture created.'); $seriesIds[] = $sid;
        $first = $make(['series_id' => $sid, 'series_occurrence' => 0]);
        $last = $make(['series_id' => $sid, 'series_occurrence' => 1, 'reserved_until' => '2000-01-01 00:00:00']);
        $count = count($effects);
        $result = (new Wpcb\Booking\RecurringBookingService())->applyRemaining($first, 'email_confirmed_automatic');
        $assert($errorIs($result, 'wpcb_reservation_expired'), 'An expired later occurrence rejects the complete confirmation batch.');
        $assert($repo->find($first)->status === 'reserved_unconfirmed' && $repo->find($last)->status === 'reserved_unconfirmed', 'MySQL rolls back the earlier occurrence when a later occurrence fails.');
        $assert(count($effects) === $count, 'A rolled-back series emits no confirmation callbacks.');
        $wpdb->update($prefix . 'bookings', ['reserved_until' => gmdate('Y-m-d H:i:s', time() + 1800)], ['id' => $last]);
        $result = (new Wpcb\Booking\RecurringBookingService())->applyRemaining($first, 'email_confirmed_automatic');
        $assert(is_array($result) && count($result['changed_booking_ids']) === 2, 'A valid series confirms all occurrences.');

        $first = $make(); $last = $make(); $count = count($effects);
        $failWrite = static function ($sql) use ($last, $prefix) {
            if (strpos($sql, 'UPDATE `' . $prefix . 'bookings`') !== false
                && strpos($sql, '`id` = ' . $last) !== false) {
                return 'UPDATE `' . $prefix . 'bookings` SET `wpcb_deliberately_missing_column` = 1 WHERE id = 0';
            }
            return $sql;
        };
        $oldSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $failWrite);
        try { $result = $service->applyBatch([$first, $last], 'email_confirmed_automatic'); }
        finally { remove_filter('query', $failWrite); $wpdb->suppress_errors($oldSuppress); }
        $assert($errorIs($result, 'wpcb_transition_race'), 'Injected second-member database write failure is reported.');
        $assert($repo->find($first)->status === 'reserved_unconfirmed' && count($effects) === $count, 'Actual database failure rolls back earlier writes without callbacks.');
        $assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}booking_status_log WHERE booking_id = %d AND context = %s", $first, 'email_confirmed_automatic')) === 0, 'The transition audit row is rolled back with the failed batch.');

        // Hold a real DB lock in this process while a second WordPress process
        // reaches GET_LOCK. Change the fixture under that lock, then release it.
        $id = $make(['status' => 'pending_approval']);
        $locks->acquire($a);
        $key = $spawn(['booking_id' => $id, 'event' => 'admin_approved']); $waiting($key);
        $wpdb->update($prefix . 'bookings', ['status' => 'cancelled'], ['id' => $id]);
        $locks->release($a); $r = $finish($key);
        $assert(($r['error'] ?? '') === 'wpcb_transition_illegal' && $repo->find($id)->status === 'cancelled', 'Waiting approval re-reads cancellation on a separate MySQL connection.');

        $id = $make(); $locks->acquire($a);
        $key = $spawn(['booking_id' => $id]); $waiting($key);
        $wpdb->update($prefix . 'bookings', ['reserved_until' => '2000-01-01 00:00:00'], ['id' => $id]);
        $locks->release($a); $r = $finish($key);
        $assert(($r['error'] ?? '') === 'wpcb_reservation_expired', 'Confirmation rechecks a deadline that elapsed while waiting.');

        $id = $make(['reserved_until' => '2000-01-01 00:00:00']); $locks->acquire($a);
        $key = $spawn(['booking_id' => $id, 'operation' => 'expire']); $waiting($key);
        $wpdb->update($prefix . 'bookings', ['reserved_until' => gmdate('Y-m-d H:i:s', time() + 1800)], ['id' => $id]);
        $locks->release($a); $r = $finish($key);
        $assert(empty($r['error']) && !$r['changed'] && $repo->find($id)->status === 'reserved_unconfirmed', 'Due expiry rechecks a renewed hold after waiting.');

        $id = $make(); $locks->acquire($a); $locks->acquire($b);
        $key = $spawn(['booking_id' => $id]); $waiting($key);
        $wpdb->update($prefix . 'bookings', ['resource_id' => $b], ['id' => $id]);
        $locks->release($a); $r = $finish($key); $locks->release($b);
        $assert(($r['error'] ?? '') === 'wpcb_transition_race', 'A moved booking cannot be confirmed under its obsolete resource lock.');

        $id = $make(['resource_id' => $b]); $locks->acquire($a);
        $key = $spawn(['booking_id' => $id], false); $r = $finish($key);
        $assert(empty($r['error']) && $r['changed'] && $lockOwner($a) !== null, 'An independent resource confirms while another resource remains locked.');
        $locks->release($a);

        $id = $make(['status' => 'pending_approval']); $booking = $repo->find($id);
        $locks->acquire($a);
        $key = $spawn(['booking_id' => $id, 'operation' => 'reschedule', 'resource_id' => $b,
            'start' => $booking->slot_start, 'end' => $booking->slot_end]); $waiting($key);
        $wpdb->update($prefix . 'bookings', ['status' => 'cancelled'], ['id' => $id]);
        $locks->release($a); $r = $finish($key);
        $assert(($r['error'] ?? '') === 'wpcb_event_race' && (int)$repo->find($id)->resource_id === $a, 'Cross-resource rescheduling locks the source and sees a concurrent cancellation.');

        $first = $make(); $firstBooking = $repo->find($first);
        $second = $make(['slot_start' => $firstBooking->slot_start, 'slot_end' => $firstBooking->slot_end]);
        $one = $spawn(['booking_id' => $first], false);
        $two = $spawn(['booking_id' => $second], false);
        $r1 = $finish($one); $r2 = $finish($two);
        $assert(empty($r1['error']) && empty($r2['error']) && $r1['changed'] && $r2['changed'], 'Two separate confirmation workers share group capacity safely.');
        $assert($repo->occupiedSeats($firstBooking->slot_start, $firstBooking->slot_end, $a) === 2, 'Concurrent confirmations preserve the exact occupied seat total.');

        $first = $make(['status' => 'pending_approval']); $firstBooking = $repo->find($first);
        $second = $make(['status' => 'pending_approval', 'resource_id' => $b]); $secondBooking = $repo->find($second);
        $locks->acquire($a);
        $one = $spawn(['booking_id' => $first, 'operation' => 'reschedule', 'resource_id' => $b,
            'start' => $firstBooking->slot_start, 'end' => $firstBooking->slot_end]);
        $two = $spawn(['booking_id' => $second, 'operation' => 'reschedule', 'resource_id' => $a,
            'start' => $secondBooking->slot_start, 'end' => $secondBooking->slot_end]);
        $waiting($one); $waiting($two); $locks->release($a);
        $r1 = $finish($one); $r2 = $finish($two);
        $assert(empty($r1['error']) && empty($r2['error']), 'Opposite cross-resource moves finish without lock-order deadlock.');

        // A real reservation wins the last seat while an obsolete confirmation
        // waits. Its normal signed-token path must agree with the transition lock.
        $wpdb->update($prefix . 'resources', ['capacity' => 1], ['id' => $a]);
        $slots = (new Wpcb\Availability\SlotService())->getSlotsForResource($typeId, $a, 7);
        $assert(!empty($slots), 'Real canonical slots exist for the reservation race.');
        $slot = $slots[0];
        $id = $make(['slot_start' => $slot['start'], 'slot_end' => $slot['end']]);
        $slotToken = (new Wpcb\Tokens\SlotTokenService())->issue($typeId, $slot['start'], $slot['end'], $a);
        $locks->acquire($a); $key = $spawn(['booking_id' => $id]); $waiting($key);
        $wpdb->update($prefix . 'bookings', ['reserved_until' => '2000-01-01 00:00:00'], ['id' => $id]);
        $replacement = (new Wpcb\Booking\ReservationService())->reserve($slotToken, $typeId,
            ['email' => 'replacement@example.test', 'full_name' => 'Replacement fixture']);
        $assert(is_int($replacement) && $replacement > 0, 'Normal reservation takes capacity released by expiry.');
        $bookingIds[] = $replacement;
        $locks->release($a); $r = $finish($key);
        $assert(($r['error'] ?? '') === 'wpcb_reservation_expired', 'The waiting obsolete confirmation cannot steal a newly reserved seat.');
        $assert($repo->occupiedSeats($slot['start'], $slot['end'], $a) === 1, 'Combined reservation/confirmation race retains capacity one.');
        $assert($lockOwner($a) === null && $lockOwner($b) === null, 'All real resource locks are released at completion.');
        echo "PASS: transition capacity and MySQL serialization regression suite complete.\n";
    } finally {
        $locks->releaseAll();
        foreach ($workers as $worker) {
            if (!$worker['closed']) {
                proc_terminate($worker['process']);
                fclose($worker['pipes'][1]); fclose($worker['pipes'][2]); proc_close($worker['process']);
            }
        }
        foreach ($bookingIds as $id) {
            foreach (['booking_status_log', 'booking_meta', 'tokens'] as $suffix) $wpdb->delete($prefix . $suffix, ['booking_id' => $id]);
            $wpdb->delete($prefix . 'bookings', ['id' => $id]);
        }
        foreach ($seriesIds as $id) $wpdb->delete($prefix . 'booking_series', ['id' => $id]);
        if ($typeId) {
            $wpdb->delete($prefix . 'availability_rules', ['scope_type' => 'booking_type', 'scope_id' => $typeId]);
            $wpdb->delete($prefix . 'booking_type_resources', ['booking_type_id' => $typeId]);
            $wpdb->delete($prefix . 'booking_types', ['id' => $typeId]);
        }
        foreach ($resourceIds as $id) $wpdb->delete($prefix . 'resources', ['id' => $id]);
        update_option('wpcb_settings', $savedSettings);
        foreach ($savedHooks as $hook => $value) {
            if ($value === null) unset($wp_filter[$hook]); else $wp_filter[$hook] = $value;
        }
        foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
        rmdir($dir);
    }
})();
