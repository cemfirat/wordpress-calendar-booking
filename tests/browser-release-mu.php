<?php
/**
 * Test-only mail capture for release browser acceptance.
 *
 * This file is copied to wp-content/mu-plugins by CI and is never packaged
 * with the plugin release.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_filter('pre_wp_mail', static function ($return, array $atts) {
    $mailbox = get_option('wpcb_e2e_mailbox', []);
    if (!is_array($mailbox)) {
        $mailbox = [];
    }

    $mailbox[] = [
        'to' => is_array($atts['to'] ?? null) ? array_values($atts['to']) : [(string)($atts['to'] ?? '')],
        'subject' => (string)($atts['subject'] ?? ''),
        'message' => (string)($atts['message'] ?? ''),
    ];

    update_option('wpcb_e2e_mailbox', array_slice($mailbox, -50), false);
    return true;
}, 10, 2);
