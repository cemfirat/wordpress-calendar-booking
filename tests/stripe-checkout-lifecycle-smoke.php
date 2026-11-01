<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_lifecycle_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$config = new Wpcb\Payments\StripeConfig();
$config->save(['enabled' => 1, 'secret_key' => 'sk_test_lifecycle', 'webhook_secret' => 'whsec_test_lifecycle']);
wpcb_lifecycle_assert($config->ready(), 'Stripe lifecycle fixture is configured.');

$calls = [];
$createReplies = [];
$expireFails = false;
add_filter('pre_http_request', function ($pre, $args, $url) use (&$calls, &$createReplies, &$expireFails) {
    if (strpos($url, 'https://api.stripe.com/') !== 0) {
        return $pre;
    }
    $method = $args['method'] ?? 'GET';
    $calls[] = ['method' => $method, 'url' => $url, 'args' => $args];
    $ok = static fn(array $body) => ['headers' => [], 'body' => wp_json_encode($body),
        'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    if ($method === 'POST' && $url === 'https://api.stripe.com/v1/checkout/sessions') {
        $next = array_shift($createReplies);
        if ($next instanceof WP_Error) {
            return $next;
        }
        $id = $next ?: 'cs_lifecycle_default';
        return $ok(['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/' . $id, 'status' => 'open']);
    }
    if ($method === 'POST' && preg_match('#/v1/checkout/sessions/([^/]+)/expire$#', $url, $m)) {
        if ($expireFails) {
            return ['headers' => [], 'body' => '{"error":{}}', 'response' => ['code' => 400, 'message' => 'Bad'], 'cookies' => [], 'filename' => null];
        }
        return $ok(['id' => $m[1], 'status' => 'expired']);
    }
    if ($method === 'GET' && preg_match('#/v1/checkout/sessions/([^/]+)$#', $url, $m)) {
        return $ok(['id' => $m[1], 'status' => 'expired', 'url' => '']);
    }
    return new WP_Error('unexpected_lifecycle_request', 'Unexpected Stripe request: ' . $url);
}, 10, 3);

$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$wpdb->insert($wpdb->prefix . 'wpcb_booking_types', [
    'name' => 'Lifecycle Fixture', 'slug' => 'lifecycle-' . wp_generate_password(6, false), 'description' => '',
    'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'capacity' => 1,
    'show_remaining_capacity' => 0, 'payment_mode' => 'required', 'price_minor' => 5000, 'currency' => 'EUR',
    'is_active' => 1, 'is_public' => 0, 'sort_order' => 99, 'created_at' => $now, 'updated_at' => $now,
]);
$typeId = (int)$wpdb->insert_id;
$resourceId = (int)get_option('wpcb_default_resource_id', 0);
$bookings = new Wpcb\Booking\BookingRepository();
$payments = new Wpcb\Payments\PaymentRepository();
$service = new Wpcb\Payments\PaymentService();
$adapter = new Wpcb\Payments\StripeAdapter($config);
$day = 0;

$makeBooking = function (string $holdModifier) use (&$day, $bookings, $typeId, $resourceId, $now): int {
    ++$day;
    $id = $bookings->create([
        'booking_uuid' => wp_generate_uuid4(), 'booking_type_id' => $typeId, 'resource_id' => $resourceId,
        'slot_start' => sprintf('2036-05-%02d 10:00:00', $day), 'slot_end' => sprintf('2036-05-%02d 10:30:00', $day),
        'status' => Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED, 'party_size' => 1,
        'full_name' => 'Lifecycle', 'email' => 'lifecycle@example.com', 'phone' => '', 'notes' => '',
        'source' => 'lifecycle-smoke', 'lang' => 'de',
        'reserved_until' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify($holdModifier)),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    if ($id < 1) {
        throw new RuntimeException('Lifecycle booking could not be created.');
    }
    return $id;
};
$headerOf = static fn(array $call, string $name) => $call['args']['headers'][$name] ?? '';
$event = static fn(string $suffix) => 'evt_lifecycle_' . $suffix . '_' . wp_generate_password(6, false);

// 1. Expiry and idempotency of Checkout creation, including response loss.
$bookingA = $makeBooking('+20 minutes');
$createReplies = [new WP_Error('synthetic_timeout', 'lost response'), 'cs_life_a'];
$lost = $service->begin($bookingA, $adapter);
wpcb_lifecycle_assert(is_wp_error($lost), 'A lost Checkout creation response is reported without storing a reference.');
$started = $service->begin($bookingA, $adapter);
wpcb_lifecycle_assert(is_object($started) && $started->provider_reference === 'cs_life_a', 'Checkout creation succeeds on retry.');
$posts = array_values(array_filter($calls, static fn($c) => $c['method'] === 'POST'));
wpcb_lifecycle_assert(count($posts) === 2, 'Both creation attempts reached the provider.');
$keyOne = $headerOf($posts[0], 'Idempotency-Key');
wpcb_lifecycle_assert($keyOne !== '' && $keyOne === $headerOf($posts[1], 'Idempotency-Key'), 'A retried attempt reuses the same creation Idempotency-Key.');
$expires = (int)($posts[1]['args']['body']['expires_at'] ?? 0);
wpcb_lifecycle_assert($expires >= time() + 1800 && $expires <= time() + 86400, 'Stripe expires_at honours the 30-minute minimum for a shorter local hold.');
wpcb_lifecycle_assert($payments->findByAttempt('stripe', 'cs_life_a') !== null, 'The checkout attempt is persisted in the attempt ledger.');

// 1b. A paid callback whose creation response was lost binds the unbound payment by signed UUID.
$bookingLost = $makeBooking('+20 minutes');
$createReplies = [new WP_Error('synthetic_timeout', 'lost response')];
wpcb_lifecycle_assert(is_wp_error($service->begin($bookingLost, $adapter)), 'Creation response lost for an unbound payment.');
$unbound = $payments->forBooking($bookingLost);
$bound = $service->applyProviderEvent('stripe', $event('lostpaid'), 'cs_life_lost', 'paid', 5000, 'EUR', (string)$unbound->payment_uuid);
wpcb_lifecycle_assert(!is_wp_error($bound), 'A verified paid callback reconciles an unbound payment through its UUID.');
$afterBound = $payments->find((int)$unbound->id);
wpcb_lifecycle_assert($afterBound->provider === 'stripe' && $afterBound->provider_reference === 'cs_life_lost', 'The unbound payment is bound to the paid session.');

// 2. Local expiry closes the provider session; a late payment is never silently kept.
$paymentA = $payments->forBooking($bookingA);
$wpdb->update($wpdb->prefix . 'wpcb_payments', ['expires_at' => '2000-01-01 00:00:00'], ['id' => $paymentA->id]);
$wpdb->update($wpdb->prefix . 'wpcb_bookings', ['reserved_until' => '2000-01-01 00:00:00'], ['id' => $bookingA]);
$calls = [];
wpcb_lifecycle_assert($service->expirePending(100, $adapter) >= 1, 'Cron expiry processes the elapsed payment hold.');
wpcb_lifecycle_assert(count(array_filter($calls, static fn($c) => str_ends_with($c['url'], '/v1/checkout/sessions/cs_life_a/expire'))) === 1, 'Local expiry asks Stripe to expire the open session.');
wpcb_lifecycle_assert($payments->find((int)$paymentA->id)->status === 'expired', 'Payment is expired locally.');

$late = $service->applyProviderEvent('stripe', $event('late'), 'cs_life_a', 'paid', 5000, 'EUR');
wpcb_lifecycle_assert(!is_wp_error($late), 'A verified late payment is accepted for reconciliation.');
$late = $payments->find((int)$paymentA->id);
wpcb_lifecycle_assert($late->status === 'refund_pending' && (int)$late->refund_pending_minor === 5000, 'A late payment queues a full refund instead of overbooking.');
wpcb_lifecycle_assert($payments->hasEventType((int)$late->id, 'late_payment_refund'), 'The late payment is marked for administrators.');
$sameEvent = 'evt_lifecycle_dup';
$service->applyProviderEvent('stripe', $sameEvent, 'cs_life_a', 'paid', 5000, 'EUR');
$service->applyProviderEvent('stripe', $sameEvent, 'cs_life_a', 'paid', 5000, 'EUR');
$service->applyProviderEvent('stripe', $event('late2'), 'cs_life_a', 'paid', 5000, 'EUR');
$after = $payments->find((int)$paymentA->id);
wpcb_lifecycle_assert((int)$after->refund_pending_minor === 5000, 'Duplicate and repeated late callbacks never queue a second refund.');

// 3. Expire failure (session already completed) is non-fatal and still reconciles.
$bookingB = $makeBooking('+20 minutes');
$createReplies = ['cs_life_b'];
$service->begin($bookingB, $adapter);
$paymentB = $payments->forBooking($bookingB);
$wpdb->update($wpdb->prefix . 'wpcb_payments', ['expires_at' => '2000-01-01 00:00:00'], ['id' => $paymentB->id]);
$wpdb->update($wpdb->prefix . 'wpcb_bookings', ['reserved_until' => '2000-01-01 00:00:00'], ['id' => $bookingB]);
$expireFails = true;
$service->expirePending(100, $adapter);
$expireFails = false;
wpcb_lifecycle_assert($payments->find((int)$paymentB->id)->status === 'expired', 'A failing provider expire call does not block local expiry.');

// 4. Attempt replacement: old-session events attach to the right payment only.
$bookingC = $makeBooking('+25 minutes');
$createReplies = ['cs_life_c1'];
$service->begin($bookingC, $adapter);
$paymentC = $payments->forBooking($bookingC);
$createReplies = ['cs_life_c2'];
$replaced = $service->begin($bookingC, $adapter);
wpcb_lifecycle_assert($replaced->provider_reference === 'cs_life_c2', 'An expired Stripe session is replaced by a new attempt.');
wpcb_lifecycle_assert($payments->findByAttempt('stripe', 'cs_life_c1') !== null && $payments->findByAttempt('stripe', 'cs_life_c2') !== null, 'Both attempts remain in the attempt history.');
$replacePosts = array_values(array_filter($calls, static fn($c) => $c['method'] === 'POST' && str_ends_with($c['url'], '/v1/checkout/sessions')));
$lastTwo = array_slice($replacePosts, -2);
wpcb_lifecycle_assert($headerOf($lastTwo[0], 'Idempotency-Key') !== $headerOf($lastTwo[1], 'Idempotency-Key'), 'A replacement attempt uses a different creation Idempotency-Key.');
$failedOld = $service->applyProviderEvent('stripe', $event('c1fail'), 'cs_life_c1', 'failed', 5000, 'EUR');
wpcb_lifecycle_assert(!is_wp_error($failedOld) && $payments->find((int)$paymentC->id)->status === 'pending', 'An ended older attempt does not fail the live attempt.');
$paidOld = $service->applyProviderEvent('stripe', $event('c1paid'), 'cs_life_c1', 'paid', 5000, 'EUR');
$paymentC = $payments->find((int)$paymentC->id);
wpcb_lifecycle_assert(!is_wp_error($paidOld) && $paymentC->status === 'paid' && $paymentC->provider_reference === 'cs_life_c1', 'Payment of an earlier valid session settles the correct payment and becomes its refund reference.');
$service->applyProviderEvent('stripe', $event('c2paid'), 'cs_life_c2', 'paid', 5000, 'EUR');
$paymentC = $payments->find((int)$paymentC->id);
wpcb_lifecycle_assert($paymentC->status === 'paid' && $payments->hasEventType((int)$paymentC->id, 'duplicate_payment_review'), 'A second paid session is flagged for manual review without changing money state.');
$wrongAmount = $service->applyProviderEvent('stripe', $event('c3'), 'cs_life_c1', 'paid', 4999, 'EUR');
wpcb_lifecycle_assert(is_wp_error($wrongAmount), 'Amount mismatches are still rejected.');

// 5. Lost creation response: unknown session resolved through the signed payment UUID.
$bookingD = $makeBooking('+25 minutes');
$createReplies = ['cs_life_d1'];
$service->begin($bookingD, $adapter);
$paymentD = $payments->forBooking($bookingD);
$orphan = $service->applyProviderEvent('stripe', $event('orphan'), 'cs_life_orphan', 'paid', 5000, 'EUR', (string)$paymentD->payment_uuid);
wpcb_lifecycle_assert(!is_wp_error($orphan) && $payments->find((int)$paymentD->id)->status === 'paid', 'An unrecorded provider session is resolved by payment UUID.');
wpcb_lifecycle_assert(is_wp_error($service->applyProviderEvent('stripe', $event('unknown'), 'cs_life_nobody', 'paid', 5000, 'EUR')), 'A session without any payment identity stays unknown.');

// 6. Cron delay: paid while the hold already elapsed but booking not yet expired.
$bookingE = $makeBooking('+25 minutes');
$createReplies = ['cs_life_e'];
$service->begin($bookingE, $adapter);
$paymentE = $payments->forBooking($bookingE);
$wpdb->update($wpdb->prefix . 'wpcb_bookings', ['reserved_until' => '2000-01-01 00:00:00'], ['id' => $bookingE]);
$service->applyProviderEvent('stripe', $event('delay'), 'cs_life_e', 'paid', 5000, 'EUR');
$paymentE = $payments->find((int)$paymentE->id);
wpcb_lifecycle_assert($paymentE->status === 'refund_pending' && (int)$paymentE->refund_pending_minor === 5000, 'Payment after an elapsed hold (cron delay) is queued for refund.');
$transition = (new Wpcb\Booking\BookingTransitionService())->apply($bookingE, Wpcb\Booking\BookingStateMachine::RESERVATION_EXPIRED, 'payment', 'cron');
$paymentE = $payments->find((int)$paymentE->id);
wpcb_lifecycle_assert((int)$paymentE->refund_pending_minor === 5000, 'Later booking expiry does not queue the refund twice.');

// 7. Payment-before-DOI, then the hold expires unconfirmed: refund is queued.
$bookingF = $makeBooking('+25 minutes');
$createReplies = ['cs_life_f'];
$service->begin($bookingF, $adapter);
$paymentF = $payments->forBooking($bookingF);
$service->applyProviderEvent('stripe', $event('pbd'), 'cs_life_f', 'paid', 5000, 'EUR');
wpcb_lifecycle_assert($payments->find((int)$paymentF->id)->status === 'paid', 'Payment inside a live hold stays paid and is not refunded.');
$wpdb->update($wpdb->prefix . 'wpcb_bookings', ['reserved_until' => '2000-01-01 00:00:00'], ['id' => $bookingF]);
(new Wpcb\Booking\BookingTransitionService())->apply($bookingF, Wpcb\Booking\BookingStateMachine::RESERVATION_EXPIRED, 'payment', 'cron');
$paymentF = $payments->find((int)$paymentF->id);
wpcb_lifecycle_assert($paymentF->status === 'refund_pending' && (int)$paymentF->refund_pending_minor === 5000, 'A paid booking whose hold expires unconfirmed is refunded.');

WP_CLI::success('Stripe Checkout lifecycle smoke test passed.');
