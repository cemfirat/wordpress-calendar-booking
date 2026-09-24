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
            || !empty($parts['pass'])
            || !self::isPublicNetworkHost((string)$parts['host'])) {
            return '';
        }

        return wp_http_validate_url($url) ? $url : '';
    }


    private static function isPublicNetworkHost(string $host): bool {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        if (!function_exists('dns_get_record')) {
            return false;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || !$records) {
            return false;
        }

        foreach ($records as $record) {
            $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '' || filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return false;
            }
        }

        return true;
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
