<?php
namespace Wpcb\Demo;

use Wpcb\Support\Time;

/** Isolated preview data. Never stored as bookings and never sent to external services. */
final class DemoCalendar {
    public const OPTION = 'wpcb_demo_calendar';

    public function read() {
        $data = get_option(self::OPTION, null);
        if ($data === null) return [];
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['is_demo'] ?? null) !== true
            || !is_string($data['batch_id'] ?? null) || !is_string($data['timezone'] ?? null)
            || !is_array($data['entries'] ?? null) || count($data['entries']) !== 10) return $this->invalid();
        try { new \DateTimeZone($data['timezone']); } catch (\Exception $e) { return $this->invalid(); }
        $ids = []; $earliest = null; $latest = null;
        foreach ($data['entries'] as $entry) {
            if (!is_array($entry) || ($entry['is_demo'] ?? null) !== true
                || !is_string($entry['id'] ?? null) || !preg_match('/^demo-(0[1-9]|10)$/D', $entry['id'])
                || isset($ids[$entry['id']]) || !is_string($entry['start'] ?? null)
                || !is_string($entry['end'] ?? null)) return $this->invalid();
            $start = Time::parseUtc($entry['start']); $end = Time::parseUtc($entry['end']);
            if (!$start || !$end || $end <= $start || $end->getTimestamp() - $start->getTimestamp() > 3 * 86400) return $this->invalid();
            $ids[$entry['id']] = true;
            $earliest = $earliest === null || $start < $earliest ? $start : $earliest;
            $latest = $latest === null || $end > $latest ? $end : $latest;
        }
        // The preview renderer has a bounded date range, including corrupt-option cases.
        if ($latest->getTimestamp() - $earliest->getTimestamp() > 15 * 86400) return $this->invalid();
        return $data;
    }

    public function create() {
        return $this->locked(function () {
            $current = $this->read();
            if (is_wp_error($current) || $current !== []) return $current;
            $batch = $this->build(Time::nowLocal());
            if (!add_option(self::OPTION, $batch, '', false)) {
                return new \WP_Error('wpcb_demo_store', __('Die Beispiele konnten nicht gespeichert werden. Bitte erneut versuchen.', 'wordpress-calendar-booking'));
            }
            return $this->read();
        });
    }

    public function remove() {
        return $this->locked(function () {
            if (get_option(self::OPTION, null) === null) return true;
            if (!delete_option(self::OPTION)) {
                return new \WP_Error('wpcb_demo_remove', __('Die Beispiele konnten nicht entfernt werden. Bitte erneut versuchen.', 'wordpress-calendar-booking'));
            }
            return true;
        });
    }

    /** Pure generation relative to the selected local date, also used in DST boundary tests. */
    public function build(\DateTimeImmutable $now): array {
        $entries = [];
        $day = $now->modify('tomorrow')->setTime(0, 0);
        $durations = [30, 45, 60, 90, 30, 60, 45, 120, 30];
        for ($i = 0; $i < 10; $i++) {
            $start = $day->modify('+' . $i . ' days')->setTime(9 + ($i % 5), ($i % 2) * 30);
            $end = $i === 9 ? $start->modify('+2 days') : $start->modify('+' . $durations[$i] . ' minutes');
            $entries[] = ['id' => sprintf('demo-%02d', $i + 1), 'is_demo' => true,
                'start' => Time::formatUtc($start), 'end' => Time::formatUtc($end)];
        }
        return ['schema' => 1, 'is_demo' => true, 'batch_id' => wp_generate_uuid4(),
            'timezone' => $now->getTimezone()->getName(), 'created_at' => Time::formatUtc($now), 'entries' => $entries];
    }

    private function invalid(): \WP_Error {
        return new \WP_Error('wpcb_demo_invalid', __('Die Beispieldaten sind unvollständig. Entferne nur die Beispiele und erzeuge sie danach neu.', 'wordpress-calendar-booking'));
    }

    private function locked(callable $operation) {
        global $wpdb;
        $name = 'wpcb_demo_' . substr(hash('sha256', home_url('/') . '|' . $wpdb->prefix), 0, 48);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 3)) !== 1) {
            return new \WP_Error('wpcb_demo_busy', __('Die Beispiele werden gerade bearbeitet. Bitte erneut versuchen.', 'wordpress-calendar-booking'));
        }
        try {
            // A caller may have read an older state before waiting for the named lock.
            wp_cache_delete(self::OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
            return $operation();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }
}
