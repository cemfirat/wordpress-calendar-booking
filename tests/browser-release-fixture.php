<?php
if (!defined('ABSPATH')) {
    exit(1);
}

$action = getenv('WPCB_E2E_ACTION') ?: '';
global $wpdb;
$prefix = $wpdb->prefix . 'wpcb_';
$testEmail = 'browser-e2e@example.com';
$testSlug = 'browser-e2e';
$waitlistEmail = 'waitlist-browser@example.com';
$waitlistSlug = 'browser-waitlist-paid';

$cleanup = static function () use ($wpdb, $prefix, $testEmail, $testSlug, $waitlistEmail, $waitlistSlug): void {
    $bookingIds = array_map(
        'intval',
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}bookings WHERE email = %s",
            $testEmail
        ))
    );

    foreach ($bookingIds as $bookingId) {
        foreach (['booking_meta', 'booking_status_log', 'tokens', 'sync_jobs', 'deliveries', 'sync_log'] as $suffix) {
            $wpdb->delete($prefix . $suffix, ['booking_id' => $bookingId]);
        }
        $wpdb->delete($prefix . 'bookings', ['id' => $bookingId]);
    }

    $typeIds = array_map(
        'intval',
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}booking_types WHERE slug = %s",
            $testSlug
        ))
    );

    foreach ($typeIds as $typeId) {
        $wpdb->delete($prefix . 'availability_rules', ['scope_type' => 'booking_type', 'scope_id' => $typeId]);
        $wpdb->delete($prefix . 'exceptions', ['booking_type_id' => $typeId]);
        $wpdb->delete($prefix . 'booking_type_calendar_connections', ['booking_type_id' => $typeId]);
        $wpdb->delete($prefix . 'booking_types', ['id' => $typeId]);
    }

    foreach (['wpcb-e2e-shortcodes', 'wpcb-e2e-blocks'] as $pagePath) {
        $page = get_page_by_path($pagePath, OBJECT, 'page');
        if ($page) {
            wp_delete_post((int)$page->ID, true);
        }
    }

    $waitlistBookingIds = array_map(
        'intval',
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}bookings WHERE email = %s OR source = 'waiting_list_browser'",
            $waitlistEmail
        ))
    );
    foreach ($waitlistBookingIds as $bookingId) {
        $paymentIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}payments WHERE booking_id = %d",
            $bookingId
        )));
        foreach ($paymentIds as $paymentId) {
            $wpdb->delete($prefix . 'payment_events', ['payment_id' => $paymentId]);
        }
        $wpdb->delete($prefix . 'payments', ['booking_id' => $bookingId]);
        foreach (['booking_meta', 'booking_status_log', 'tokens', 'sync_jobs', 'deliveries', 'sync_log'] as $suffix) {
            $wpdb->delete($prefix . $suffix, ['booking_id' => $bookingId]);
        }
        $wpdb->delete($prefix . 'bookings', ['id' => $bookingId]);
    }

    $waitlistTypeIds = array_map(
        'intval',
        $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}booking_types WHERE slug = %s",
            $waitlistSlug
        ))
    );
    foreach ($waitlistTypeIds as $typeId) {
        $wpdb->delete($prefix . 'waiting_list', ['booking_type_id' => $typeId]);
        $wpdb->delete($prefix . 'availability_rules', ['scope_type' => 'booking_type', 'scope_id' => $typeId]);
        $wpdb->delete($prefix . 'booking_type_resources', ['booking_type_id' => $typeId]);
        $wpdb->delete($prefix . 'booking_types', ['id' => $typeId]);
    }
    $resourceIds = array_map(
        'intval',
        $wpdb->get_col("SELECT id FROM {$prefix}resources WHERE slug = 'browser-waitlist-resource'")
    );
    foreach ($resourceIds as $resourceId) {
        $wpdb->delete($prefix . 'resource_calendar_connections', ['resource_id' => $resourceId]);
        $wpdb->delete($prefix . 'resources', ['id' => $resourceId]);
    }

    $wpdb->query("DELETE FROM {$prefix}customer_sessions");
    delete_option('wpcb_e2e_mailbox');
    delete_option('wpcb_e2e_waitlist_stripe');
    delete_option('wpcb_stripe_settings');
};

if ($action === 'setup') {
    $cleanup();

    $settings = (array)get_option('wpcb_settings', []);
    $settings['mode'] = 'approval';
    $settings['timezone'] = 'UTC';
    $settings['timing_enabled'] = 0;
    $settings['min_form_seconds'] = 0;
    $settings['rate_limit_enabled'] = 0;
    $settings['cancel_min_hours'] = 0;
    $settings['change_min_hours'] = 0;
    $settings['notifications_enabled'] = 0;
    $settings['calendar_urls'] = '';
    update_option('wpcb_settings', $settings, false);
    update_option('timezone_string', 'UTC');
    update_option('wpcb_e2e_mailbox', [], false);

    $shortcodePage = wp_insert_post([
        'post_title' => 'WPCB E2E Shortcodes',
        'post_name' => 'wpcb-e2e-shortcodes',
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_content' => "[wpcb_booking_calendar]\n\n[wpcb_booking_form]\n\n[wpcb_customer_portal]",
    ], true);

    $blockPage = wp_insert_post([
        'post_title' => 'WPCB E2E Blocks',
        'post_name' => 'wpcb-e2e-blocks',
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_content' => "<!-- wp:wpcb/availability-calendar /-->\n\n<!-- wp:wpcb/booking-form /-->",
    ], true);

    if (is_wp_error($shortcodePage) || is_wp_error($blockPage)) {
        throw new RuntimeException('Unable to create E2E pages.');
    }

    echo wp_json_encode([
        'shortcode_page_id' => (int)$shortcodePage,
        'block_page_id' => (int)$blockPage,
    ]);
    return;
}

if ($action === 'mailbox') {
    echo wp_json_encode(array_values((array)get_option('wpcb_e2e_mailbox', [])));
    return;
}

if ($action === 'status') {
    $booking = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, booking_type_id, slot_start, slot_end, status, email
             FROM {$prefix}bookings
             WHERE email = %s
             ORDER BY id DESC
             LIMIT 1",
            $testEmail
        ),
        ARRAY_A
    );
    echo wp_json_encode($booking ?: []);
    return;
}

if ($action === 'waitlist_setup') {
    $cleanup();

    $settings = (array)get_option('wpcb_settings', []);
    $settings['mode'] = 'automatic';
    $settings['timezone'] = 'UTC';
    $settings['timing_enabled'] = 0;
    $settings['min_form_seconds'] = 0;
    $settings['rate_limit_enabled'] = 0;
    $settings['notifications_enabled'] = 0;
    update_option('wpcb_settings', $settings, false);
    update_option('timezone_string', 'UTC');
    update_option('wpcb_e2e_mailbox', [], false);
    update_option('wpcb_e2e_waitlist_stripe', 1, false);

    $stripe = new Wpcb\Payments\StripeConfig();
    $saved = $stripe->save([
        'enabled' => 1,
        'secret_key' => 'sk_test_browser_waitlist',
        'webhook_secret' => 'whsec_browser_waitlist',
    ]);
    if ($saved !== true || !$stripe->ready()) {
        throw new RuntimeException('Unable to configure browser waiting-list Stripe fixture.');
    }

    $now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
    $wpdb->insert($prefix . 'booking_types', [
        'name' => 'Browser paid waiting list',
        'slug' => $waitlistSlug,
        'description' => '',
        'duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'capacity' => 1,
        'show_remaining_capacity' => 0,
        'payment_mode' => 'required',
        'price_minor' => 2500,
        'currency' => 'EUR',
        'is_active' => 1,
        'is_public' => 1,
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $typeId = (int)$wpdb->insert_id;

    $resources = new Wpcb\Resources\ResourceRepository();
    $resourceId = $resources->save([
        'name' => 'Browser waitlist resource',
        'slug' => 'browser-waitlist-resource',
        'capacity' => 1,
        'is_active' => 1,
        'is_public' => 0,
    ]);
    if (is_wp_error($resourceId) || $typeId < 1) {
        throw new RuntimeException('Unable to create browser waiting-list fixture.');
    }
    $resourceId = (int)$resourceId;
    $resources->setForBookingType($typeId, [$resourceId]);

    $target = Wpcb\Support\Time::nowLocal()->modify('+7 days')->setTime(10, 0, 0);
    $wpdb->insert($prefix . 'availability_rules', [
        'scope_type' => 'booking_type',
        'scope_id' => $typeId,
        'weekday' => (int)$target->format('N'),
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'slot_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'min_notice_minutes' => 0,
        'max_days_in_advance' => 30,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $start = Wpcb\Support\Time::localToUtc($target->format('Y-m-d') . ' 10:00:00');
    $end = Wpcb\Support\Time::localToUtc($target->format('Y-m-d') . ' 10:30:00');
    if (!is_string($start) || !is_string($end)) {
        throw new RuntimeException('Browser waiting-list slot could not be normalized.');
    }

    $bookings = new Wpcb\Booking\BookingRepository();
    $blockingId = $bookings->create([
        'booking_uuid' => wp_generate_uuid4(),
        'booking_type_id' => $typeId,
        'resource_id' => $resourceId,
        'slot_start' => $start,
        'slot_end' => $end,
        'party_size' => 1,
        'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
        'full_name' => 'Browser Blocker',
        'email' => 'waitlist-blocker@example.invalid',
        'source' => 'waiting_list_browser',
        'lang' => 'de',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if ($blockingId < 1) {
        throw new RuntimeException('Browser waiting-list blocking booking could not be created.');
    }

    $service = new Wpcb\WaitingList\WaitingListService();
    $entryId = $service->join([
        'booking_type_id' => $typeId,
        'resource_id' => $resourceId,
        'slot_start' => $start,
        'slot_end' => $end,
        'party_size' => 1,
        'full_name' => 'Browser Waiter',
        'email' => $waitlistEmail,
        'phone' => '',
        'form_data' => [
            'subject' => 'Browser waiting-list test',
            'gender' => 'Divers',
            'first_name' => 'Browser',
            'last_name' => 'Waiter',
            'email' => $waitlistEmail,
            'phone' => '',
            'privacy' => '1',
        ],
    ]);
    if (!is_int($entryId) || $entryId < 1) {
        throw new RuntimeException('Browser customer could not join the waiting list.');
    }

    $wpdb->update(
        $prefix . 'bookings',
        ['status' => Wpcb\Booking\BookingStatus::CANCELLED, 'updated_at' => $now],
        ['id' => $blockingId]
    );
    if ($service->promoteSlot($typeId, $resourceId, $start, $end) !== $entryId) {
        throw new RuntimeException('Browser waiting-list offer could not be promoted.');
    }

    $entry = (new Wpcb\WaitingList\WaitingListRepository())->find($entryId);
    $verifier = $entry ? (new Wpcb\Security\SecretBox())->decrypt((string)$entry->offer_secret_enc) : null;
    if (!$entry || !is_string($verifier) || $verifier === '') {
        throw new RuntimeException('Browser waiting-list offer token could not be recovered.');
    }
    $token = (string)$entry->offer_selector . '.' . $verifier;
    $offerUrl = add_query_arg([
        'wpcb_waitlist_action' => 'accept',
        'wpcb_waitlist_id' => $entryId,
        'wpcb_waitlist_token' => $token,
    ], home_url('/'));

    echo wp_json_encode([
        'offer_url' => $offerUrl,
        'entry_id' => $entryId,
        'email' => $waitlistEmail,
    ]);
    return;
}

if ($action === 'waitlist_status') {
    $booking = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, booking_type_id, status, email FROM {$prefix}bookings
             WHERE email = %s ORDER BY id DESC LIMIT 1",
            $waitlistEmail
        ),
        ARRAY_A
    );
    $payment = $booking
        ? (new Wpcb\Payments\PaymentService())->paymentForBooking((int)$booking['id'])
        : null;
    echo wp_json_encode([
        'booking' => $booking ?: [],
        'payment_status' => $payment ? (string)$payment->status : '',
        'provider_reference' => $payment ? (string)($payment->provider_reference ?? '') : '',
    ]);
    return;
}

if ($action === 'waitlist_mark_paid') {
    $bookingId = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$prefix}bookings WHERE email = %s ORDER BY id DESC LIMIT 1",
        $waitlistEmail
    ));
    $payment = $bookingId > 0
        ? (new Wpcb\Payments\PaymentService())->paymentForBooking($bookingId)
        : null;
    if (!$payment || (string)($payment->provider_reference ?? '') !== 'cs_waitlist_browser') {
        throw new RuntimeException('Browser waiting-list payment is not ready to mark paid.');
    }
    $updated = (new Wpcb\Payments\PaymentService())->applyProviderEvent(
        'stripe',
        'evt_waitlist_browser_paid',
        'cs_waitlist_browser',
        'paid',
        (int)$payment->amount_minor,
        (string)$payment->currency
    );
    if (is_wp_error($updated) || (string)$updated->status !== Wpcb\Payments\PaymentStatus::PAID) {
        throw new RuntimeException('Browser waiting-list payment could not be marked paid.');
    }
    echo 'OK';
    return;
}

if ($action === 'cleanup') {
    $cleanup();
    echo 'OK';
    return;
}

fwrite(STDERR, "Unknown WPCB_E2E_ACTION.\n");
exit(1);
