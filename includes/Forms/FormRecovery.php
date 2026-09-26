<?php
namespace Wpcb\Forms;

use Wpcb\Security\SecretBox;

final class FormRecovery {
    private const TTL = 600;
    private const PREFIX = 'wpcb_form_recovery_';
    private static array $cache = [];

    /**
     * Store a short-lived encrypted form state and redirect back to the
     * same-site referring page with only a random one-time selector in the URL.
     */
    public function redirect(string $kind, array $state, string $message): void {
        $selector = $this->store($kind, $state, $message);
        if (is_wp_error($selector)) {
            wp_die(esc_html($message));
        }

        $fallback = home_url('/');
        $referer = wp_get_referer();
        $destination = wp_validate_redirect(is_string($referer) ? $referer : '', $fallback);
        $destination = remove_query_arg('wpcb_recovery', $destination);
        wp_safe_redirect(add_query_arg('wpcb_recovery', $selector, $destination), 303);
        exit;
    }

    /** @return string|\WP_Error */
    public function store(string $kind, array $state, string $message) {
        $kind = sanitize_key($kind);
        if (!in_array($kind, ['booking', 'waiting_list'], true)) {
            return new \WP_Error('wpcb_recovery_kind', __('Ungültiger Formularstatus.', 'wordpress-calendar-booking'));
        }

        $normalized = $this->normalizeState($state);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $payload = wp_json_encode([
            'kind' => $kind,
            'message' => sanitize_text_field($message),
            'state' => $normalized,
            'created_at' => time(),
        ]);
        if (!is_string($payload) || strlen($payload) > 20000) {
            return new \WP_Error(
                'wpcb_recovery_size',
                __('Die Formulareingaben sind zu umfangreich für eine sichere Wiederherstellung.', 'wordpress-calendar-booking')
            );
        }

        $encrypted = (new SecretBox())->encrypt($payload);
        if (is_wp_error($encrypted)) {
            return new \WP_Error(
                'wpcb_recovery_encrypt',
                __('Die Formulareingaben konnten nicht sicher zwischengespeichert werden.', 'wordpress-calendar-booking')
            );
        }

        try {
            $selector = bin2hex(random_bytes(16));
        } catch (\Throwable $error) {
            return new \WP_Error(
                'wpcb_recovery_random',
                __('Die Formularwiederherstellung konnte nicht vorbereitet werden.', 'wordpress-calendar-booking')
            );
        }

        if (!set_transient($this->transientKey($selector), $encrypted, self::TTL)) {
            return new \WP_Error(
                'wpcb_recovery_store',
                __('Die Formulareingaben konnten nicht sicher zwischengespeichert werden.', 'wordpress-calendar-booking')
            );
        }
        return $selector;
    }

    /**
     * Consume at most once from storage. A per-request cache lets multiple
     * instances of the same shared renderer reuse the recovered state.
     *
     * @return array{message:string,state:array}|null
     */
    public function current(string $kind): ?array {
        $selector = isset($_GET['wpcb_recovery'])
            ? sanitize_text_field(wp_unslash((string)$_GET['wpcb_recovery']))
            : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $selector)) {
            return null;
        }

        if (!array_key_exists($selector, self::$cache)) {
            $key = $this->transientKey($selector);
            $encrypted = get_transient($key);
            delete_transient($key);

            $decoded = null;
            if (is_string($encrypted) && $encrypted !== '') {
                $plain = (new SecretBox())->decrypt($encrypted);
                if (is_string($plain) && strlen($plain) <= 20000) {
                    $candidate = json_decode($plain, true);
                    if (is_array($candidate)
                        && isset($candidate['kind'], $candidate['message'], $candidate['state'])
                        && is_string($candidate['kind'])
                        && is_string($candidate['message'])
                        && is_array($candidate['state'])
                        && (!isset($candidate['created_at']) || (int)$candidate['created_at'] >= time() - self::TTL - 60)
                    ) {
                        $decoded = $candidate;
                    }
                }
            }
            self::$cache[$selector] = $decoded;
        }

        $payload = self::$cache[$selector];
        if (!is_array($payload) || (string)$payload['kind'] !== sanitize_key($kind)) {
            return null;
        }

        return [
            'message' => sanitize_text_field((string)$payload['message']),
            'state' => (array)$payload['state'],
        ];
    }

    private function normalizeState(array $state) {
        $out = [];
        if (count($state) > 50) {
            return new \WP_Error('wpcb_recovery_shape', __('Zu viele Formularwerte.', 'wordpress-calendar-booking'));
        }
        foreach ($state as $key => $value) {
            $safeKey = sanitize_key((string)$key);
            if ($safeKey === '' || $safeKey !== (string)$key) {
                return new \WP_Error('wpcb_recovery_shape', __('Ungültiger Formularwert.', 'wordpress-calendar-booking'));
            }

            if ($safeKey === 'fields') {
                if (!is_array($value) || count($value) > 100) {
                    return new \WP_Error('wpcb_recovery_shape', __('Ungültige Formularfelder.', 'wordpress-calendar-booking'));
                }
                $fields = [];
                foreach ($value as $fieldKey => $fieldValue) {
                    $normalizedKey = sanitize_key((string)$fieldKey);
                    if ($normalizedKey === '' || $normalizedKey !== (string)$fieldKey || !is_scalar($fieldValue)) {
                        return new \WP_Error('wpcb_recovery_shape', __('Ungültiger Formularfeldwert.', 'wordpress-calendar-booking'));
                    }
                    $stringValue = (string)$fieldValue;
                    if (strlen($stringValue) > 6000) {
                        return new \WP_Error('wpcb_recovery_shape', __('Ein Formularfeld ist zu lang.', 'wordpress-calendar-booking'));
                    }
                    $fields[$normalizedKey] = $stringValue;
                }
                $out[$safeKey] = $fields;
                continue;
            }

            if (!is_scalar($value)) {
                return new \WP_Error('wpcb_recovery_shape', __('Ungültiger Formularwert.', 'wordpress-calendar-booking'));
            }
            $stringValue = (string)$value;
            if (strlen($stringValue) > 1000) {
                return new \WP_Error('wpcb_recovery_shape', __('Ein Formularwert ist zu lang.', 'wordpress-calendar-booking'));
            }
            $out[$safeKey] = $stringValue;
        }
        return $out;
    }

    private function transientKey(string $selector): string {
        return self::PREFIX . substr(hash('sha256', $selector), 0, 40);
    }
}
