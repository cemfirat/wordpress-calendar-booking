<?php
if (!defined('ABSPATH')) { exit(1); }
(static function (): void {
    global $wpdb;
    $option = get_option('wpcb_stripe_settings', null);
    $guard = new Wpcb\Frontend\BookingEntryGuard();
    $config = new Wpcb\Payments\StripeConfig();
    $id = 0;
    $assert = static function ($v, string $m): void {
        if (!$v) throw new RuntimeException($m);
        WP_CLI::log('PASS: ' . $m);
    };
    $disabled = static function (string $html, string $value): bool {
        $p = new WP_HTML_Tag_Processor($html);
        while ($p->next_tag('OPTION')) {
            if ((string)$p->get_attribute('value') === $value) return $p->get_attribute('disabled') !== null;
        }
        throw new RuntimeException('Expected booking option missing');
    };
    $html = '<form><select data-wpcb-type-select><option value="">Choose</option><option value="1" data-payment-mode="free">Free</option><option value="2" data-payment-mode="required" selected>Paid</option></select><select><option value="3">Unrelated</option></select><button type="submit">Book</button></form>';
    try {
        update_option('wpcb_stripe_settings', [], false);
        $filtered = apply_filters('wpcb_render_booking_form_markup', $html, false);
        $assert(!$disabled($filtered, '1') && $disabled($filtered, '2'), 'Shared form hook disables unavailable paid choices and preserves free choices.');
        $assert(!$disabled($filtered, '3'), 'Unrelated field choices are not disabled.');
        $assert(strpos($filtered, 'data-wpcb-payment-unavailable') !== false, 'Unavailable payment is explained before submission.');
        $assert(strpos($guard->paymentChoices(str_replace('data-payment-mode="free"', 'data-payment-mode="required"', $html)), 'data-wpcb-payment-blocked') !== false, 'Paid-only form disables submission.');
        $assert(strpos($guard->paymentChoices($html), 'selected') === false, 'Unavailable default selection is removed.');
        $config->save(['enabled' => 1, 'secret_key' => 'sk_test_synthetic_entry']);
        $assert($disabled($guard->paymentChoices($html), '2'), 'Missing signing secret keeps the paid option unavailable.');
        $config->save(['enabled' => 1, 'webhook_secret' => 'whsec_synthetic_entry']);
        $assert($guard->paymentChoices($html) === $html, 'Locally configured payments preserve the form without claiming live connectivity.');
        $settings = $config->get();
        $settings['webhook_secret_enc'] = 'invalid'; update_option('wpcb_stripe_settings', $settings, false);
        $assert($disabled($guard->paymentChoices($html), '2'), 'Undecryptable signing secret blocks the paid choice.');
        $config->save(['enabled' => 1, 'webhook_secret' => 'whsec_synthetic_entry']);
        $settings = $config->get(); $settings['secret_key_enc'] = ''; update_option('wpcb_stripe_settings', $settings, false);
        $assert($disabled($guard->paymentChoices($html), '2'), 'Missing API secret also blocks the paid choice.');
        $config->save(['enabled' => 0, 'secret_key' => 'sk_test_synthetic_entry']);
        $assert($disabled($guard->paymentChoices($html), '2'), 'Disabled provider stays disabled even with credentials.');
        set_error_handler(static function ($code, $message) { throw new RuntimeException($message); });
        try { $id = (new Wpcb\Admin\ConfigurationService())->saveBookingType(['name' => 'Entry smoke', 'slug' => 'entry-smoke', 'is_active' => 1, 'is_public' => 1]); }
        finally { restore_error_handler(); }
        $type = (new Wpcb\Booking\BookingTypeRepository())->find($id);
        $assert($type && $type->payment_mode === 'free', 'Real database creation defaults missing payment mode to free without warnings.');
        $wpdb->update($wpdb->prefix . 'wpcb_booking_types', ['payment_mode' => 'required'], ['id' => $id]);
        $form = (new Wpcb\Frontend\ComponentRenderer())->bookingForm();
        $assert($disabled($form, (string)$id), 'Real shared renderer receives the payment guard.');
        $user = get_current_user_id();
        $oldPage = $_GET['page'] ?? null;
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        $assert(count($admins) === 1, 'Administrator exists for the readiness notice.');
        try {
            wp_set_current_user((int)$admins[0]->ID); $_GET['page'] = 'wpcb_types';
            ob_start(); $guard->paymentNotice(); $notice = ob_get_clean();
            $assert(strpos($notice, 'wpcb_payments') !== false, 'Administrator gets a payment setup link.');
            $assert(strpos($notice, 'sk_test_synthetic_entry') === false && strpos($notice, 'whsec_synthetic_entry') === false, 'Administrator notice does not disclose credentials.');
            wp_set_current_user(0); ob_start(); $guard->paymentNotice(); $notice = ob_get_clean();
            $assert($notice === '', 'Payment configuration details are restricted to administrators.');
        } finally {
            wp_set_current_user($user);
            if ($oldPage === null) unset($_GET['page']); else $_GET['page'] = $oldPage;
        }
        $assert(strpos($form, 'sk_test_synthetic_entry') === false && strpos($form, 'whsec_synthetic_entry') === false, 'Public form contains no payment credentials.');
        $assert(strpos(Wpcb\Frontend\BookingEntryGuard::freshBookingUrl(), 'wpcb_action=book') !== false, 'Recovery goes to a dedicated fresh form.');
        $assert(strpos(Wpcb\Frontend\BookingEntryGuard::freshBookingUrl(), 'token') === false, 'Recovery URL contains no action token.');
        $book = (object)['status' => 'reserved_unconfirmed', 'reserved_until' => gmdate('Y-m-d H:i:s', time() - 10)];
        $assert($guard->expiredReservation($book), 'Read-only recovery recognizes an expired reservation.');
        $book->reserved_until = null;
        $assert(!$guard->expiredReservation($book), 'Null reservation lifetime retains its existing meaning.');
    } finally {
        if ($id) $wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $id]);
        if ($option === null) delete_option('wpcb_stripe_settings'); else update_option('wpcb_stripe_settings', $option, false);
    }
})();
