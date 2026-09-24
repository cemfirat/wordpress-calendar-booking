<?php
namespace Wpcb\Security;

final class OutboundUrlPolicy {
    /**
     * Normalize a user-configurable calendar URL and reject unsafe network targets.
     *
     * @return string Empty string means the target is not allowed.
     */
    public static function normalizeCalendarUrl(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (stripos($url, 'webcal://') === 0) {
            $url = 'https://' . substr($url, 9);
        }

        $url = esc_url_raw($url, ['http', 'https']);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)
            || empty($parts['scheme'])
            || empty($parts['host'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            || !empty($parts['user'])
            || !empty($parts['pass'])) {
            return '';
        }

        return wp_http_validate_url($url) ? $url : '';
    }

    /**
     * Perform a safe calendar HTTP request. WordPress re-validates redirect
     * destinations when reject_unsafe_urls is enabled by the safe API.
     *
     * @return array|\WP_Error
     */
    public static function request(string $method, string $url, array $args = []) {
        $url = self::normalizeCalendarUrl($url);
        if ($url === '') {
            return new \WP_Error(
                'wpcb_outbound_url_unsafe',
                __('The configured calendar URL is not allowed.', 'wordpress-calendar-booking')
            );
        }

        $args['method'] = strtoupper($method);
        $args['redirection'] = min(3, max(0, (int)($args['redirection'] ?? 3)));
        return wp_safe_remote_request($url, $args);
    }

    /**
     * @return array|\WP_Error
     */
    public static function get(string $url, array $args = []) {
        $url = self::normalizeCalendarUrl($url);
        if ($url === '') {
            return new \WP_Error(
                'wpcb_outbound_url_unsafe',
                __('The configured calendar URL is not allowed.', 'wordpress-calendar-booking')
            );
        }

        $args['redirection'] = min(3, max(0, (int)($args['redirection'] ?? 3)));
        return wp_safe_remote_get($url, $args);
    }
}
