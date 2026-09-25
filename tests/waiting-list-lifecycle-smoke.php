<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_wait_lifecycle_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$originalStripe = get_option('wpcb_stripe_settings', null);
$originalSettings = get_option('wpcb_settings', []);
$originalTemplates = get_option('wpcb_email_templates', []);
$typeId = 0;
$resourceId = 0;
$ruleId = 0;
$blockingId = 0;
$waitingId = 0;
$bookingId = 0;
$mailbox = [];
$stripeCalls = [];

$mailFilter = static function ($return, array $atts) use (&$mailbox) {
    $mailbox[] = $atts;
    return true;
};
$httpFilter = static function ($pre, $args, $url) use (&$stripeCalls) {
    if (strpos($url, 'https://api.stripe.com/') !== 0) {
        return $pre;
    }
    $stripeCalls[] = ['url' => $url, 'method' => (string)($args['method'] ?? '')];
    if ($url === 'https://api.stripe.com/v1/checkout/sessions' && ($args['method'] ?? '') === 'POST') {
        return [
            'headers' => [],
            'body' => wp_json_encode([
                'id' => 'cs_waitlist_handoff',
                'url' => 'https://checkout.stripe.com/c/pay/cs_waitlist_handoff',
            ]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if ($url === 'https://api.stripe.com/v1/checkout/sessions/cs_waitlist_handoff'
        && ($args['method'] ?? '') === 'GET'
    ) {
        return [
            'headers' => [],
            'body' => wp_json_encode([
                'id' => 'cs_waitlist_handoff',
                'status' => 'open',
                'url' => 'https://checkout.stripe.com/c/pay/cs_waitlist_handoff',
            ]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    return new WP_Error('unexpected_waitlist_stripe', 'Unexpected Stripe request in waiting-list lifecycle test.');
};
add_filter('pre_wp_mail', $mailFilter, 10, 2);
add_filter('pre_http_request', $httpFilter, 10, 3);

try {
    $settings = Wpcb\Admin\Settings::get();
    $settings['notifications_enabled'] = 0;
    $settings['mode'] = 'automatic';
    $settings['token_ttl_minutes'] = 60;
    update_option('wpcb_settings', $settings);

    $templates = is_array($originalTemplates) ? $originalTemplates : [];
    $templates['doi_subject'] = 'Waiting-list DOI CI';
    $templates['doi_body'] = "Confirm waiting-list booking:\n{bestaetigungslink}";
    update_option('wpcb_email_templates', $templates);

    $stripe = new Wpcb\Payments\StripeConfig();
    wpcb_wait_lifecycle_assert(
        $stripe->save([
            'enabled' => 1,
            'secret_key' => 'sk_test_waitlist_handoff',
            'webhook_secret' => 'whsec_test_waitlist_handoff',
        ]) === true && $stripe->ready(),
        'Paid waiting-list fixture has a ready encrypted Stripe configuration.'
    );

    $now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
    $types = $wpdb->prefix . 'wpcb_booking_types';
    $wpdb->insert($types, [
        'name' => 'Paid waiting-list lifecycle CI',
        'slug' => 'paid-waitlist-' . strtolower(wp_generate_password(8, false, false)),
        'description' => '',
        'duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'capacity' => 1,
        'show_remaining_capacity' => 0,
        'payment_mode' => 'required',
        'price_minor' => 1999,
        'currency' => 'EUR',
        'is_active' => 1,
        'is_public' => 1,
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $typeId = (int)$wpdb->insert_id;
    wpcb_wait_lifecycle_assert($typeId > 0, 'Paid waiting-list booking type is created.');

    $resources = new Wpcb\Resources\ResourceRepository();
    $resource = $resources->save([
        'name' => 'Paid waiting-list resource',
        'slug' => 'paid-waitlist-' . strtolower(wp_generate_password(8, false, false)),
        'capacity' => 1,
        'is_active' => 1,
        'is_public' => 0,
    ]);
    $resourceId = is_int($resource) ? $resource : 0;
    wpcb_wait_lifecycle_assert($resourceId > 0, 'Paid waiting-list resource is created.');
    $resources->setForBookingType($typeId, [$resourceId]);

    $target = Wpcb\Support\Time::nowLocal()->modify('+8 days')->setTime(11, 0, 0);
    $targetDate = $target->format('Y-m-d');
    $wpdb->insert($wpdb->prefix . 'wpcb_availability_rules', [
        'scope_type' => 'booking_type',
        'scope_id' => $typeId,
        'weekday' => (int)$target->format('N'),
        'start_time' => '11:00:00',
        'end_time' => '12:00:00',
        'slot_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'min_notice_minutes' => 0,
        'max_days_in_advance' => 30,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $ruleId = (int)$wpdb->insert_id;

    $start = Wpcb\Support\Time::localToUtc($targetDate . ' 11:00:00');
    $end = Wpcb\Support\Time::localToUtc($targetDate . ' 11:30:00');
    wpcb_wait_lifecycle_assert(is_string($start) && is_string($end), 'Paid waiting-list slot converts to UTC.');

    $bookings = new Wpcb\Booking\BookingRepository();
    $blockingId = $bookings->create([
        'booking_uuid' => wp_generate_uuid4(),
        'booking_type_id' => $typeId,
        'resource_id' => $resourceId,
        'slot_start' => $start,
        'slot_end' => $end,
        'party_size' => 1,
        'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
        'full_name' => 'Blocking Paid Customer',
        'email' => 'blocking-paid@example.invalid',
        'source' => 'ci',
        'lang' => 'de',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    wpcb_wait_lifecycle_assert($blockingId > 0, 'Paid waiting-list slot is initially full.');

    $waiting = new Wpcb\WaitingList\WaitingListService();
    $waitingId = $waiting->join([
        'booking_type_id' => $typeId,
        'resource_id' => $resourceId,
        'slot_start' => $start,
        'slot_end' => $end,
        'party_size' => 1,
        'full_name' => 'Paid Waiter',
        'email' => 'paid-waiter@example.invalid',
        'phone' => '+4312345',
    ]);
    wpcb_wait_lifecycle_assert(is_int($waitingId) && $waitingId > 0, 'Customer joins paid full slot.');

    $wpdb->update(
        $wpdb->prefix . 'wpcb_bookings',
        ['status' => Wpcb\Booking\BookingStatus::CANCELLED, 'updated_at' => $now],
        ['id' => $blockingId]
    );
    wpcb_wait_lifecycle_assert(
        $waiting->promoteSlot($typeId, $resourceId, $start, $end) === $waitingId,
        'Released paid slot creates one promotion offer.'
    );

    $waitRepo = new Wpcb\WaitingList\WaitingListRepository();
    $offer = $waitRepo->find($waitingId);
    $verifier = $offer ? (new Wpcb\Security\SecretBox())->decrypt((string)$offer->offer_secret_enc) : null;
    $offerToken = $offer && is_string($verifier) ? (string)$offer->offer_selector . '.' . $verifier : '';
    wpcb_wait_lifecycle_assert($offerToken !== '', 'Paid waiting-list offer token is available only after authenticated decryption.');

    $bookingId = $waiting->accept($waitingId, $offerToken);
    wpcb_wait_lifecycle_assert(is_int($bookingId) && $bookingId > 0, 'Paid waiting-list acceptance creates the reservation.');

    $accepted = $bookings->find($bookingId);
    $paymentService = new Wpcb\Payments\PaymentService();
    $payment = $paymentService->paymentForBooking($bookingId);
    $doiDelivery = (new Wpcb\Reliability\DeliveryRepository())->findByKey('mail:user:' . $bookingId . ':doi');
    wpcb_wait_lifecycle_assert(
        $accepted
        && (string)$accepted->source === 'waiting_list'
        && (string)$accepted->status === Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED
        && $payment
        && (string)$payment->status === Wpcb\Payments\PaymentStatus::PENDING
        && $doiDelivery,
        'Paid waiting-list reservation enters the normal pending-payment and durable DOI lifecycle.'
    );

    $doiToken = '';
    foreach ($mailbox as $mail) {
        if (preg_match('/wpcb_token=([^&\s]+)/', (string)($mail['message'] ?? ''), $match)) {
            $doiToken = rawurldecode(html_entity_decode($match[1]));
            break;
        }
    }
    $tokens = new Wpcb\Tokens\TokenService();
    wpcb_wait_lifecycle_assert(
        $doiToken !== '' && $tokens->inspect($doiToken, 'doi')['state'] === 'valid',
        'Waiting-list DOI message contains one valid confirmation token.'
    );

    $handoffService = new Wpcb\Payments\CheckoutHandoffService();
    $handoff = $handoffService->beginForBooking($bookingId);
    wpcb_wait_lifecycle_assert(
        is_array($handoff)
        && !empty($handoff['required'])
        && (string)$handoff['provider'] === 'stripe'
        && str_contains((string)$handoff['checkout_url'], 'checkout.stripe.com/'),
        'Paid waiting-list acceptance receives the same secure hosted Checkout hand-off.'
    );
    $resumed = $handoffService->beginForBooking($bookingId);
    $postCount = count(array_filter($stripeCalls, static fn($call) => $call['method'] === 'POST'));
    $getCount = count(array_filter($stripeCalls, static fn($call) => $call['method'] === 'GET'));
    wpcb_wait_lifecycle_assert(
        is_array($resumed) && $postCount === 1 && $getCount === 1,
        'Repeating the hand-off resumes the existing Stripe session instead of creating a duplicate Checkout.'
    );

    $pendingConfirm = $tokens->consume($doiToken, 'doi', static function ($row) {
        return (new Wpcb\Booking\BookingTransitionService())->apply(
            (int)$row->booking_id,
            Wpcb\Booking\BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
            'ci',
            'Waiting-list DOI before payment'
        );
    });
    wpcb_wait_lifecycle_assert(
        is_wp_error($pendingConfirm)
        && $tokens->inspect($doiToken, 'doi')['state'] === 'valid'
        && (string)$bookings->find($bookingId)->status === Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED,
        'DOI confirmation cannot bypass pending payment and the one-time token remains usable after the rejected transition.'
    );

    $paid = $paymentService->applyProviderEvent(
        'stripe',
        'evt_waitlist_paid',
        'cs_waitlist_handoff',
        'paid',
        1999,
        'EUR'
    );
    wpcb_wait_lifecycle_assert(
        is_object($paid) && (string)$paid->status === Wpcb\Payments\PaymentStatus::PAID,
        'Verified provider state can mark the waiting-list payment paid.'
    );

    $confirmed = $tokens->consume($doiToken, 'doi', static function ($row) {
        return (new Wpcb\Booking\BookingTransitionService())->apply(
            (int)$row->booking_id,
            Wpcb\Booking\BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
            'ci',
            'Waiting-list DOI after payment'
        );
    });
    wpcb_wait_lifecycle_assert(
        is_array($confirmed)
        && !empty($confirmed['changed'])
        && $tokens->inspect($doiToken, 'doi')['state'] === 'used'
        && (string)$bookings->find($bookingId)->status === Wpcb\Booking\BookingStatus::CONFIRMED,
        'After verified payment, the same DOI token confirms the waiting-list booking exactly once.'
    );
} finally {
    remove_filter('pre_wp_mail', $mailFilter, 10);
    remove_filter('pre_http_request', $httpFilter, 10);
    update_option('wpcb_settings', $originalSettings);
    update_option('wpcb_email_templates', $originalTemplates);
    if ($originalStripe === null) {
        delete_option('wpcb_stripe_settings');
    } else {
        update_option('wpcb_stripe_settings', $originalStripe);
    }

    foreach ([$bookingId, $blockingId] as $id) {
        if ($id < 1) continue;
        $paymentIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wpcb_payments WHERE booking_id=%d",
            $id
        ));
        foreach ($paymentIds as $paymentId) {
            $wpdb->delete($wpdb->prefix . 'wpcb_payment_events', ['payment_id' => (int)$paymentId]);
        }
        $wpdb->delete($wpdb->prefix . 'wpcb_payments', ['booking_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_deliveries', ['booking_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_sync_log', ['booking_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['booking_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_tokens', ['booking_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $id]);
    }
    if ($waitingId > 0) {
        wp_clear_scheduled_hook('wpcb_waitlist_send_offer', [$waitingId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_waiting_list', ['id' => $waitingId]);
    }
    if ($ruleId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_availability_rules', ['id' => $ruleId]);
    }
    if ($typeId > 0 && $resourceId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_type_resources', ['booking_type_id' => $typeId]);
    }
    if ($resourceId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_resources', ['id' => $resourceId]);
    }
    if ($typeId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $typeId]);
    }
}

WP_CLI::success('Waiting-list DOI and paid Checkout lifecycle smoke test passed.');
