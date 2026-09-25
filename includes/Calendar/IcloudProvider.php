<?php
namespace Wpcb\Calendar;

use Wpcb\Admin\Settings;
use Wpcb\Security\OutboundUrlPolicy;

class IcloudProvider {
    public function events(string $from, string $to): array {
        $result = $this->eventsResult($from, $to);
        return is_wp_error($result) ? [] : $result;
    }

    /** @return array<int,array<string,mixed>>|\WP_Error */
    public function eventsResult(string $from, string $to) {
        $settings = Settings::get();
        $urls = Settings::publicCalendarUrls();
        if (!$urls) {
            return [];
        }

        $events = [];
        $parser = new Parser();
        foreach ($urls as $url) {
            $cacheKey = 'wpcb_ical_' . md5($url);
            $body = get_transient($cacheKey);
            $fetched = false;
            if ($body === false) {
                $response = OutboundUrlPolicy::get($url, [
                    'timeout' => 20,
                    'redirection' => 5,
                    'user-agent' => 'WPCB/' . WPCB_VERSION,
                ]);
                if (is_wp_error($response)) {
                    return new \WP_Error(
                        'wpcb_ical_unavailable',
                        __('Die Kalender-Verfügbarkeit kann derzeit nicht vollständig geprüft werden. Bitte später erneut versuchen.', 'wordpress-calendar-booking')
                    );
                }
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code < 200 || $code >= 300) {
                    return new \WP_Error(
                        'wpcb_ical_unavailable',
                        __('Die Kalender-Verfügbarkeit kann derzeit nicht vollständig geprüft werden. Bitte später erneut versuchen.', 'wordpress-calendar-booking')
                    );
                }
                $body = (string) wp_remote_retrieve_body($response);
                if ($body === '') {
                    return new \WP_Error(
                        'wpcb_ical_unavailable',
                        __('Die Kalender-Verfügbarkeit kann derzeit nicht vollständig geprüft werden. Bitte später erneut versuchen.', 'wordpress-calendar-booking')
                    );
                }
                $fetched = true;
            }
            $parsed = $parser->parseResult((string) $body, $from, $to);
            if (is_wp_error($parsed)) {
                delete_transient($cacheKey);
                return new \WP_Error(
                    'wpcb_ical_unavailable',
                    __('Die Kalender-Verfügbarkeit kann derzeit nicht vollständig geprüft werden. Bitte später erneut versuchen.', 'wordpress-calendar-booking')
                );
            }
            if ($fetched) {
                set_transient($cacheKey, $body, max(1, (int) $settings['calendar_cache_minutes']) * MINUTE_IN_SECONDS);
            }
            $events = array_merge($events, $parsed);
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
