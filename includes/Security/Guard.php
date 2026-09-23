<?php
namespace Wpcb\Security;

use Wpcb\Admin\Settings;

class Guard {
    public function checkSubmission(array $post): array {
        $settings = Settings::get();
        if (!isset($post['wpcb_nonce']) || !wp_verify_nonce(sanitize_text_field($post['wpcb_nonce']), 'wpcb_booking')) {
            return [false, 'Sicherheitsprüfung fehlgeschlagen.'];
        }
        if (!empty($settings['honeypot_enabled']) && !empty($post['website'])) {
            return [false, 'Spam erkannt.'];
        }
        if (!empty($settings['timing_enabled'])) {
            $ts = isset($post['wpcb_form_ts']) ? (int) $post['wpcb_form_ts'] : 0;
            if ($ts > 0 && (current_time('timestamp') - $ts) < (int) $settings['min_form_seconds']) {
                return [false, 'Formular wurde zu schnell abgesendet.'];
            }
        }
        if (!empty($settings['rate_limit_enabled'])) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
            $key = 'wpcb_rate_' . md5($ip);
            $data = get_transient($key);
            $count = is_array($data) ? (int) $data['count'] : 0;
            if ($count >= (int) $settings['rate_limit_requests']) {
                return [false, 'Zu viele Anfragen. Bitte später erneut versuchen.'];
            }
            set_transient($key, ['count' => $count + 1], max(1, (int) $settings['rate_limit_window_minutes']) * MINUTE_IN_SECONDS);
        }
        return [true, ''];
    }
}
