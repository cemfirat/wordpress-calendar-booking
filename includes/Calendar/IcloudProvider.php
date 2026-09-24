<?php
namespace Wpcb\Calendar;

use Wpcb\Admin\Settings;

class IcloudProvider {
    public function events(string $from, string $to): array {
        $settings = Settings::get();
        $urls = Settings::publicCalendarUrls();
        if (!$urls) {
            return [];
        }

        $events = [];
        foreach ($urls as $url) {
            $cacheKey = 'wpcb_ical_' . md5($url);
            $body = get_transient($cacheKey);
            if ($body === false) {
                $response = OutboundUrlPolicy::get($url, [
                    'timeout' => 20,
                    'redirection' => 5,
                    'user-agent' => 'WPCB/' . WPCB_VERSION,
                ]);
                if (is_wp_error($response)) {
                    continue;
                }
                $body = (string) wp_remote_retrieve_body($response);
                set_transient($cacheKey, $body, max(1, (int) $settings['calendar_cache_minutes']) * MINUTE_IN_SECONDS);
            }
            if ($body !== '') {
                $events = array_merge($events, (new Parser())->parse((string) $body, $from, $to));
            }
        }

        $unique = [];
        foreach ($events as $event) {
            // Recurring instances intentionally share one UID. Include the
            // occurrence timing so weekly/monthly instances are not collapsed.
            $identity = implode('|', [
                (string)($event['uid'] ?? ''),
                (string)($event['recurrence_id'] ?? ''),
                (string)($event['start'] ?? ''),
                (string)($event['end'] ?? ''),
            ]);
            $unique[$identity !== '|||' ? $identity : md5(wp_json_encode($event))] = $event;
        }
        usort($unique, static fn($a, $b) => strcmp((string) $a['start'], (string) $b['start']));
        return array_values($unique);
    }

    public function clearCache(): void {
        foreach (Settings::publicCalendarUrls() as $url) {
            delete_transient('wpcb_ical_' . md5($url));
        }
    }
}
