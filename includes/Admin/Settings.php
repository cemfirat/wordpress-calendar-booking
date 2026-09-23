<?php
namespace Wpcb\Admin;

use Wpcb\Security\SecretBox;

class Settings {
    public static function get(): array {
        $defaults = [
            'mode' => 'automatic',
            'sender_name' => get_bloginfo('name'),
            'sender_email' => get_option('admin_email'),
            'timezone' => wp_timezone_string() ?: 'Europe/Vienna',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
            'notifications_enabled' => 1,
            'notification_emails' => get_option('admin_email'),
            'reminders_enabled' => 0,
            'reminder_hours' => 24,
            'delivery_log_retention_days' => 90,
            'calendar_url' => '',
            'calendar_urls' => '',
            'calendar_cache_minutes' => 30,
            'token_ttl_minutes' => 1440,
            'reservation_ttl_minutes' => 30,
            'cancel_min_hours' => 2,
            'change_min_hours' => 2,
            'honeypot_enabled' => 1,
            'timing_enabled' => 1,
            'min_form_seconds' => 3,
            'rate_limit_enabled' => 1,
            'rate_limit_requests' => 5,
            'rate_limit_window_minutes' => 15,
            'show_calendar_limit' => 20,
            'retention_enabled' => 0,
            'retention_days' => 365,
            'delete_data_on_uninstall' => 0,
            'visit_address' => '',
            'own_phone' => '',
            'icloud_sync_enabled' => 0,
            'icloud_sync_apple_id' => '',
            'icloud_sync_password_enc' => '',
            'icloud_sync_target_calendar_url' => '',
            'icloud_sync_target_calendar_name' => 'Website Buchungen',
            'icloud_sync_updates' => 1,
            'icloud_sync_cancellations' => 1,
            'icloud_sync_last_test' => '',
        ];
        $settings = wp_parse_args((array) get_option('wpcb_settings', []), $defaults);
        if (empty($settings['calendar_urls']) && !empty($settings['calendar_url'])) {
            $settings['calendar_urls'] = (string) $settings['calendar_url'];
        }
        return $settings;
    }

    /**
     * @return true|\WP_Error
     */
    public static function update(array $data) {
        $settings = self::get();

        if (array_key_exists('timezone', $data)) {
            $data['timezone'] = self::normalizeTimezone((string)$data['timezone']);
        }
        if (array_key_exists('calendar_url', $data)) {
            $data['calendar_url'] = self::normalizeCalendarUrl((string)$data['calendar_url']);
        }
        if (array_key_exists('calendar_urls', $data)) {
            $data['calendar_urls'] = self::normalizeCalendarUrlList((string)$data['calendar_urls']);
            if (empty($data['calendar_url'])) {
                $first = self::publicCalendarUrlsFromString($data['calendar_urls']);
                $data['calendar_url'] = $first[0] ?? '';
            }
        }
        if (array_key_exists('icloud_sync_target_calendar_url', $data)) {
            $data['icloud_sync_target_calendar_url'] = self::normalizeCalendarUrl((string)$data['icloud_sync_target_calendar_url']);
        }

        if (array_key_exists('icloud_sync_password', $data)) {
            $password = trim((string)$data['icloud_sync_password']);
            unset($data['icloud_sync_password']);

            if ($password !== '') {
                $encrypted = (new SecretBox())->encrypt($password);
                if (is_wp_error($encrypted)) {
                    return $encrypted;
                }
                $data['icloud_sync_password_enc'] = $encrypted;
                delete_option('wpcb_secret_reentry_required');
            }
        }

        update_option('wpcb_settings', array_merge($settings, $data));
        return true;
    }

    public static function normalizeTimezone(string $timezone): string {
        $timezone = trim($timezone);
        $valid = \DateTimeZone::listIdentifiers();
        if ($timezone === 'UTC' || in_array($timezone, $valid, true)) {
            return $timezone;
        }

        $site = wp_timezone_string();
        if ($site === 'UTC' || in_array($site, $valid, true)) {
            return $site;
        }

        return 'UTC';
    }

    public static function publicCalendarUrls(): array {
        $settings = self::get();
        $urls = self::publicCalendarUrlsFromString((string) ($settings['calendar_urls'] ?? ''));
        if (!$urls && !empty($settings['calendar_url'])) {
            $urls = [self::normalizeCalendarUrl((string) $settings['calendar_url'])];
        }
        return array_values(array_unique(array_filter($urls)));
    }

    public static function publicCalendarUrlsFromString(string $input): array {
        $parts = preg_split('/[\r\n,]+/', $input) ?: [];
        $urls = [];
        foreach ($parts as $part) {
            $url = self::normalizeCalendarUrl(trim((string) $part));
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return array_values(array_unique($urls));
    }

    public static function normalizeCalendarUrlList(string $input): string {
        return implode("\n", self::publicCalendarUrlsFromString($input));
    }

    public static function normalizeCalendarUrl(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (stripos($url, 'webcal://') === 0) {
            $url = 'https://' . substr($url, 9);
        }
        return esc_url_raw($url);
    }

    public static function getIcloudSyncPassword(): string {
        $settings = self::get();
        $encoded = (string)($settings['icloud_sync_password_enc'] ?? '');
        if ($encoded === '') {
            return '';
        }

        $plain = (new SecretBox())->decrypt($encoded);
        return $plain === null ? '' : $plain;
    }

    /**
     * Return display-safe secret-storage diagnostics without plaintext/ciphertext.
     *
     * @return array{state:string,format:string}
     */
    public static function secretStatus(): array {
        $settings = self::get();
        $encoded = (string)($settings['icloud_sync_password_enc'] ?? '');
        $box = new SecretBox();

        if ((int)get_option('wpcb_secret_reentry_required', 0) === 1) {
            return ['state' => 'reentry', 'format' => ''];
        }

        if ($encoded === '') {
            return ['state' => $box->available() ? 'empty' : 'unavailable', 'format' => ''];
        }

        $format = $box->formatVersion($encoded);
        if ($format === '') {
            return ['state' => 'reentry', 'format' => ''];
        }

        if ($box->decrypt($encoded) === null) {
            return ['state' => 'invalid', 'format' => $format];
        }

        return ['state' => 'stored', 'format' => $format];
    }
}
