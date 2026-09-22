<?php
namespace Cemb\Admin;

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
        $settings = wp_parse_args((array) get_option('cemb_settings', []), $defaults);
        if (empty($settings['calendar_urls']) && !empty($settings['calendar_url'])) {
            $settings['calendar_urls'] = (string) $settings['calendar_url'];
        }
        return $settings;
    }

    public static function update(array $data): void {
        $settings = self::get();
        if (array_key_exists('timezone', $data)) {
            $data['timezone'] = self::normalizeTimezone((string)$data['timezone']);
        }
        if (array_key_exists('calendar_url', $data)) {
            $data['calendar_url'] = self::normalizeCalendarUrl((string) $data['calendar_url']);
        }
        if (array_key_exists('calendar_urls', $data)) {
            $data['calendar_urls'] = self::normalizeCalendarUrlList((string) $data['calendar_urls']);
            if (empty($data['calendar_url'])) {
                $first = self::publicCalendarUrlsFromString($data['calendar_urls']);
                $data['calendar_url'] = $first[0] ?? '';
            }
        }
        if (array_key_exists('icloud_sync_target_calendar_url', $data)) {
            $data['icloud_sync_target_calendar_url'] = self::normalizeCalendarUrl((string) $data['icloud_sync_target_calendar_url']);
        }
        if (array_key_exists('icloud_sync_password', $data)) {
            $password = trim((string) $data['icloud_sync_password']);
            unset($data['icloud_sync_password']);
            if ($password !== '') {
                $data['icloud_sync_password_enc'] = self::encrypt($password);
            }
        }
        update_option('cemb_settings', array_merge($settings, $data));
    }

    public static function normalizeTimezone(string $timezone): string {
        $timezone = trim($timezone);
        $valid = DateTimeZone::listIdentifiers();
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
        return self::decrypt((string) ($settings['icloud_sync_password_enc'] ?? ''));
    }

    private static function encrypt(string $plain): string {
        if (!function_exists('openssl_encrypt')) {
            return base64_encode($plain);
        }
        $key = hash('sha256', wp_salt('auth'), true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return base64_encode($plain);
        }
        return base64_encode($iv . $cipher);
    }

    private static function decrypt(string $encoded): string {
        if ($encoded === '') {
            return '';
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return '';
        }
        if (!function_exists('openssl_decrypt') || strlen($raw) < 17) {
            return (string) $raw;
        }
        $key = hash('sha256', wp_salt('auth'), true);
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }
}
