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

        $host = trim((string)$parts['host'], '[]');
        if (!self::hostResolvesPublicly($host)) {
            return '';
        }

        return wp_http_validate_url($url) ? $url : '';
    }

    /**
     * Perform a calendar HTTP request while validating every redirect hop with
     * this plugin's stricter public-network policy.
     *
     * @return array|\WP_Error
     */
    public static function request(string $method, string $url, array $args = []) {
        $current = self::normalizeCalendarUrl($url);
        if ($current === '') {
            return self::unsafeError();
        }

        $remaining = min(3, max(0, (int)($args['redirection'] ?? 3)));
        $method = strtoupper($method);
        $hasAuthorization = self::hasAuthorizationHeader((array)($args['headers'] ?? []));
        $args['redirection'] = 0;

        while (true) {
            $args['method'] = $method;
            $response = wp_safe_remote_request($current, $args);
            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            if (!in_array($code, [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            if ($remaining < 1) {
                return new \WP_Error(
                    'wpcb_outbound_redirect_limit',
                    __('The configured calendar URL redirected too many times.', 'wordpress-calendar-booking')
                );
            }

            $location = wp_remote_retrieve_header($response, 'location');
            if (!is_string($location) || trim($location) === '') {
                return new \WP_Error(
                    'wpcb_outbound_redirect_invalid',
                    __('The configured calendar URL returned an invalid redirect.', 'wordpress-calendar-booking')
                );
            }

            $next = self::resolveRedirect($current, $location);
            $next = self::normalizeCalendarUrl($next);
            if ($next === '') {
                return self::unsafeError();
            }
            if ($hasAuthorization && !self::sameOrigin($current, $next)) {
                return new \WP_Error(
                    'wpcb_outbound_redirect_credentials',
                    __('Calendar credentials cannot be redirected to a different host.', 'wordpress-calendar-booking')
                );
            }

            $current = $next;
            --$remaining;
        }
    }

    /**
     * @return array|\WP_Error
     */
    public static function get(string $url, array $args = []) {
        return self::request('GET', $url, $args);
    }

    private static function hostResolvesPublicly(string $host): bool {
        if ($host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        $addresses = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                    if ($ip !== '') {
                        $addresses[] = $ip;
                    }
                }
            }
        }

        if (!$addresses && function_exists('gethostbynamel')) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                $addresses = array_merge($addresses, $ipv4);
            }
        }

        $addresses = array_values(array_unique(array_filter($addresses)));
        if (!$addresses) {
            return false;
        }
        foreach ($addresses as $address) {
            if (!self::isPublicIp($address)) {
                return false;
            }
        }
        return true;
    }

    private static function isPublicIp(string $ip): bool {
        return false !== filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private static function resolveRedirect(string $current, string $location): string {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location) || stripos($location, 'webcal://') === 0) {
            return $location;
        }
        if (strpos($location, '//') === 0) {
            $scheme = (string)(wp_parse_url($current, PHP_URL_SCHEME) ?: 'https');
            return $scheme . ':' . $location;
        }
        return \WP_Http::make_absolute_url($location, $current);
    }

    private static function sameOrigin(string $left, string $right): bool {
        $a = wp_parse_url($left);
        $b = wp_parse_url($right);
        if (!is_array($a) || !is_array($b)) {
            return false;
        }

        $schemeA = strtolower((string)($a['scheme'] ?? ''));
        $schemeB = strtolower((string)($b['scheme'] ?? ''));
        $hostA = strtolower(trim((string)($a['host'] ?? ''), '[]'));
        $hostB = strtolower(trim((string)($b['host'] ?? ''), '[]'));
        $portA = (int)($a['port'] ?? ($schemeA === 'https' ? 443 : 80));
        $portB = (int)($b['port'] ?? ($schemeB === 'https' ? 443 : 80));

        return $schemeA === $schemeB && $hostA === $hostB && $portA === $portB;
    }

    private static function hasAuthorizationHeader(array $headers): bool {
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === 'authorization') {
                return true;
            }
        }
        return false;
    }

    private static function unsafeError(): \WP_Error {
        return new \WP_Error(
            'wpcb_outbound_url_unsafe',
            __('The configured calendar URL is not allowed.', 'wordpress-calendar-booking')
        );
    }
}
