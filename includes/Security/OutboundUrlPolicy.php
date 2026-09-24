<?php
namespace Wpcb\Security;

final class OutboundUrlPolicy {
    public const MAX_ICS_RESPONSE_BYTES = 2097152;
    public const MAX_CALDAV_RESPONSE_BYTES = 4194304;
    public const MAX_MUTATION_RESPONSE_BYTES = 262144;

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
     * Perform a safe calendar HTTP request and reject oversized response bodies.
     *
     * Pass wpcb_max_response_bytes to select a narrower feature-specific limit.
     *
     * @return array|\WP_Error
     */
    public static function request(string $method, string $url, array $args = []) {
        $url = self::normalizeCalendarUrl($url);
        if ($url === '') {
            return self::unsafeUrlError();
        }

        $maxBytes = self::responseLimit($args, self::MAX_CALDAV_RESPONSE_BYTES);
        $args['method'] = strtoupper($method);
        $args['redirection'] = min(3, max(0, (int)($args['redirection'] ?? 3)));
        $args['limit_response_size'] = $maxBytes + 1;

        $response = wp_safe_remote_request($url, $args);
        return self::validateResponseSize($response, $maxBytes);
    }

    /**
     * @return array|\WP_Error
     */
    public static function get(string $url, array $args = []) {
        $url = self::normalizeCalendarUrl($url);
        if ($url === '') {
            return self::unsafeUrlError();
        }

        $maxBytes = self::responseLimit($args, self::MAX_ICS_RESPONSE_BYTES);
        $args['redirection'] = min(3, max(0, (int)($args['redirection'] ?? 3)));
        $args['limit_response_size'] = $maxBytes + 1;

        $response = wp_safe_remote_get($url, $args);
        return self::validateResponseSize($response, $maxBytes);
    }

    private static function responseLimit(array &$args, int $default): int {
        $requested = isset($args['wpcb_max_response_bytes'])
            ? (int)$args['wpcb_max_response_bytes']
            : $default;
        unset($args['wpcb_max_response_bytes']);

        return max(1, min(self::MAX_CALDAV_RESPONSE_BYTES, $requested));
    }

    /**
     * @param array|\WP_Error $response
     * @return array|\WP_Error
     */
    private static function validateResponseSize($response, int $maxBytes) {
        if (is_wp_error($response)) {
            return $response;
        }

        $contentLength = wp_remote_retrieve_header($response, 'content-length');
        if (is_numeric($contentLength) && (int)$contentLength > $maxBytes) {
            return self::responseTooLargeError();
        }

        if (strlen((string)wp_remote_retrieve_body($response)) > $maxBytes) {
            return self::responseTooLargeError();
        }

        return $response;
    }

    private static function unsafeUrlError(): \WP_Error {
        return new \WP_Error(
            'wpcb_outbound_url_unsafe',
            __('The configured calendar URL is not allowed.', 'wordpress-calendar-booking')
        );
    }

    private static function responseTooLargeError(): \WP_Error {
        return new \WP_Error(
            'wpcb_calendar_response_too_large',
            __('The remote calendar response is too large to process safely.', 'wordpress-calendar-booking')
        );
    }
}
