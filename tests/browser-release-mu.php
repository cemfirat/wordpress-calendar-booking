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


add_filter('pre_http_request', static function ($pre, array $args, string $url) {
    if (strpos($url, 'https://api.stripe.com/') !== 0
        || !get_option('wpcb_e2e_waitlist_stripe', false)
    ) {
        return $pre;
    }

    $response = static function (int $code, array $body) {
        return [
            'headers' => ['content-type' => 'application/json'],
            'response' => ['code' => $code, 'message' => $code === 200 ? 'OK' : 'Error'],
            'body' => wp_json_encode($body),
            'cookies' => [],
            'filename' => null,
        ];
    };

    if ($url === 'https://api.stripe.com/v1/checkout/sessions'
        && strtoupper((string)($args['method'] ?? 'GET')) === 'POST'
    ) {
        return $response(200, [
            'id' => 'cs_waitlist_browser',
            'url' => 'https://checkout.stripe.com/c/pay/cs_waitlist_browser',
        ]);
    }

    if ($url === 'https://api.stripe.com/v1/checkout/sessions/cs_waitlist_browser'
        && strtoupper((string)($args['method'] ?? 'GET')) === 'GET'
    ) {
        return $response(200, [
            'id' => 'cs_waitlist_browser',
            'status' => 'open',
            'url' => 'https://checkout.stripe.com/c/pay/cs_waitlist_browser',
        ]);
    }

    return new WP_Error('wpcb_e2e_unexpected_stripe', 'Unexpected Stripe request in browser acceptance.');
}, 10, 3);
