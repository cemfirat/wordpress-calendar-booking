<?php
// Real production service, repositories, Stripe adapter and signed controller.
// HTTP/configuration and SQL storage are local doubles; see the companion DB.
require __DIR__ . '/refund-contract-support.php';
define('WPCB_VERSION', 'contract');
$root = getenv('WPCB_CONTRACT_ROOT') ?: dirname(__DIR__, 2);
foreach (['Support/Time', 'Booking/BookingStatus', 'Booking/BookingRepository',
    'Booking/BookingTypeRepository', 'Booking/BookingSeriesRepository',
    'Payments/PaymentStatus', 'Payments/PaymentRefundStatus', 'Payments/PaymentAdapterInterface',
    'Payments/RefundStatusProviderInterface', 'Payments/PaymentRepository',
    'Payments/PaymentRefundRepository', 'Payments/PaymentService', 'Payments/StripeAdapter',
    'Payments/StripeWebhookController', 'Payments/StripeReturnController', 'Admin/PaymentAdminPage'] as $class) {
    require $root . '/includes/' . $class . '.php';
}

use Wpcb\Payments\PaymentRepository;
use Wpcb\Payments\PaymentRefundRepository;
use Wpcb\Payments\PaymentService;
use Wpcb\Payments\StripeAdapter;
use Wpcb\Payments\StripeWebhookController;

$passed = 0; $failed = 0;
function fixture(int $total = 1000, int $queued = 500, int $occurrences = 1): PaymentService {
    $GLOBALS['wpdb'] = $db = new RefundContractDatabase();
    $GLOBALS['uuidCounter'] = 0;
    $GLOBALS['httpReplies'] = []; $GLOBALS['httpCalls'] = [];
    $GLOBALS['contractSecret'] = 'synthetic-test-key';
    $now = gmdate('Y-m-d H:i:s');
    for ($id = 1; $id <= $occurrences; ++$id) {
        $db->insert('wp_wpcb_bookings', [
            'id'=>$id, 'booking_uuid'=>'booking-' . $id,
            'series_id'=>$occurrences > 1 ? 1 : null, 'series_occurrence'=>$id - 1,
            'status'=>'confirmed', 'updated_at'=>$now,
        ]);
    }
    if ($occurrences > 1) $db->insert('wp_wpcb_booking_series', ['id'=>1, 'occurrence_count'=>$occurrences]);
    $db->insert('wp_wpcb_payments', [
        'id'=>1, 'payment_uuid'=>'11111111-1111-4111-8111-111111111111', 'booking_id'=>1, 'provider'=>'stripe',
        'provider_reference'=>'cs_one', 'amount_minor'=>$total, 'refund_pending_minor'=>$queued,
        'currency'=>'EUR', 'status'=>$queued > 0 ? 'refund_pending' : 'paid',
        'created_at'=>$now, 'updated_at'=>$now,
    ]);
    return new PaymentService();
}
function attempt(): object { return (new PaymentRefundRepository())->latestForPayment(1); }
function amounts(int $completed, int $queued, int $inflight, string $status): void {
    $p = (new PaymentRepository())->find(1);
    check([(int)$p->refunded_minor, (int)$p->refund_pending_minor, (int)$p->refund_inflight_minor, $p->status]
        === [$completed, $queued, $inflight, $status], 'money buckets/status: ' . wp_json_encode($p));
    check($completed + $queued + $inflight <= (int)$p->amount_minor, 'allocated refunds never exceed the paid amount');
}
function refundObject(string $status, int $amount = 500, string $id = 're_one'): array {
    return ['id'=>$id, 'status'=>$status, 'amount'=>$amount, 'currency'=>'eur', 'created'=>100];
}
function submit(PaymentService $service, string $status = 'pending', int $amount = 500, string $id = 're_one') {
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), httpReply(refundObject($status, $amount, $id))];
    $active = (new PaymentRefundRepository())->activeForPayment(1);
    if ($active && empty($active->provider_refund_id)) $GLOBALS['httpReplies'][] = httpReply(refundObject($status, $amount, $id));
    return $service->refund(1, new StripeAdapter());
}
function webhook(string $id, string $eventStatus, string $currentStatus, int $created, int $amount = 500,
    string $providerId = 're_one', ?array $currentObject = null, ?object $target = null) {
    $object = refundObject($eventStatus, $amount, $providerId);
    $object['metadata'] = ['refund_uuid'=>($target ?? attempt())->refund_uuid];
    $event = ['id'=>$id, 'type'=>'refund.updated', 'created'=>$created, 'data'=>['object'=>$object]];
    $body = wp_json_encode($event); $timestamp = time();
    $signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, 'synthetic-webhook-secret');
    if ($currentStatus !== '') $GLOBALS['httpReplies'] = [httpReply($currentObject ?? refundObject($currentStatus, $amount, $providerId))];
    return (new StripeWebhookController())->handle(new WP_REST_Request($body, $signature));
}
function ok($result): void { check(!is_wp_error($result), is_wp_error($result) ? $result->get_error_code() : 'successful result'); }
function contract(string $name, callable $test): void {
    global $passed, $failed;
    try {
        $test();
        check(empty($GLOBALS['wpdb']->held), 'refund lock released');
        check(empty($GLOBALS['httpReplies']), 'all expected provider replies consumed');
        ++$passed; echo 'PASS: ' . $name . "\n";
    } catch (Throwable $error) {
        ++$failed; echo 'FAIL: ' . $name . ': ' . $error->getMessage() . "\n";
    } finally {
        if (isset($GLOBALS['wpdb'])) { $GLOBALS['wpdb']->close(); unset($GLOBALS['wpdb']); }
    }
}

contract('pending is reserved, identified and not completed', function (): void {
    $service = fixture(); ok(submit($service)); amounts(0, 0, 500, 'refund_pending');
    check(attempt()->status === 'pending' && attempt()->provider_refund_id === 're_one', 'durable pending refund ID');
});
contract('requires_action is separate from completed money', function (): void {
    $service = fixture(); ok(submit($service, 'requires_action')); amounts(0, 0, 500, 'refund_pending');
    check(attempt()->status === 'requires_action', 'requires_action retained');
});
contract('current GET succeeds despite older object creation timestamp', function (): void {
    $service = fixture(); ok(submit($service)); ok(webhook('evt_pending', 'pending', 'pending', 200));
    $GLOBALS['httpReplies'] = [httpReply(refundObject('succeeded'))];
    ok($service->refund(1, new StripeAdapter())); amounts(500, 0, 0, 'paid');
    check((int)attempt()->last_provider_event_created_at === 200, 'event audit time retained separately');
    check(count($GLOBALS['httpCalls']) === 4 && $GLOBALS['httpCalls'][3][1]['method'] === 'GET', 'existing ID only read');
});
contract('duplicate and older pending webhooks cannot undo current success', function (): void {
    $service = fixture(); ok(submit($service)); ok(webhook('evt_success', 'succeeded', 'succeeded', 300));
    ok(webhook('evt_success', 'succeeded', '', 300));
    ok(webhook('evt_old', 'pending', 'succeeded', 150)); amounts(500, 0, 0, 'paid');
    check((int)attempt()->last_provider_event_created_at === 300, 'older event does not rewind audit timestamp');
});
contract('bank return moves success to requires_action once and can succeed again', function (): void {
    $service = fixture(500, 500); ok(submit($service, 'succeeded')); amounts(500, 0, 0, 'refunded');
    check((new PaymentRepository())->find(1)->refunded_at !== null, 'completed timestamp recorded');
    ok(webhook('evt_return', 'requires_action', 'requires_action', 400)); amounts(0, 0, 500, 'refund_pending');
    check((new PaymentRepository())->find(1)->refunded_at === null && attempt()->completed_at === null, 'returned refund no longer presented as completed');
    ok(webhook('evt_return', 'requires_action', '', 400)); amounts(0, 0, 500, 'refund_pending');
    ok(webhook('evt_recovered', 'succeeded', 'succeeded', 450)); amounts(500, 0, 0, 'refunded');
});
foreach (['failed', 'canceled'] as $status) {
    contract('pending to ' . $status . ' restores the queue once', function () use ($status): void {
        $service = fixture(); ok(submit($service)); ok(webhook('evt_end', $status, $status, 300));
        amounts(0, 500, 0, 'refund_pending');
        ok(webhook('evt_end', $status, '', 300)); amounts(0, 500, 0, 'refund_pending');
        $oldUuid = attempt()->refund_uuid;
        ok(submit($service, 'pending', 500, 're_two'));
        check(attempt()->refund_uuid !== $oldUuid, 'only confirmed failure permits a new attempt');
        amounts(0, 0, 500, 'refund_pending');
    });
    contract('later ' . $status . ' reverses confirmed success exactly once', function () use ($status): void {
        $service = fixture(); ok(submit($service, 'succeeded'));
        ok(webhook('evt_bank_return', 'pending', $status, 50)); amounts(0, 500, 0, 'refund_pending');
        ok(webhook('evt_return_again', $status, $status, 350)); amounts(0, 500, 0, 'refund_pending');
    });
}
contract('truncated HTTP 200 and failed retry prerequisites preserve the same attempt', function (): void {
    $service = fixture();
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), httpReply('{"id":')];
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_uncertain');
    $uuid = attempt()->refund_uuid; $key = attempt()->idempotency_key;
    amounts(0, 0, 500, 'refund_pending');
    $GLOBALS['httpReplies'] = [httpReply(['error'=>['message'=>'unavailable']], 404)];
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_uncertain');
    check(attempt()->refund_uuid === $uuid && attempt()->idempotency_key === $key, 'ambiguous identity retained');
    check(count($GLOBALS['httpCalls']) === 3, 'retry lookup failure sends no second POST');
    amounts(0, 0, 500, 'refund_pending');
});
contract('retry after timeout reuses the exact durable key and parameters', function (): void {
    $service = fixture();
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), new WP_Error('timeout')];
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_uncertain');
    $firstPost = $GLOBALS['httpCalls'][1][1];
    ok(submit($service));
    $secondPost = $GLOBALS['httpCalls'][3][1];
    check($firstPost['headers']['Idempotency-Key'] === $secondPost['headers']['Idempotency-Key']
        && $firstPost['body'] === $secondPost['body'], 'same request, same key');
    amounts(0, 0, 500, 'refund_pending');
});
contract('cached replay success is reconciled before it can count as completed', function (): void {
    $service = fixture();
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), new WP_Error('timeout')];
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_uncertain');
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), httpReply(refundObject('succeeded')),
        httpReply(refundObject('requires_action'))];
    ok($service->refund(1, new StripeAdapter())); amounts(0, 0, 500, 'refund_pending');
    check(attempt()->status === 'requires_action' && $GLOBALS['httpCalls'][4][1]['method'] === 'GET', 'current GET overrides cached POST status');
});
contract('recovered refund ID survives a failed current lookup and prevents another POST', function (): void {
    $service = fixture();
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), new WP_Error('timeout')];
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_uncertain');
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), httpReply(refundObject('pending')), new WP_Error('timeout')];
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_uncertain');
    check(attempt()->provider_refund_id === 're_one', 'recovered ID stored despite unavailable current status');
    amounts(0, 0, 500, 'refund_pending');
    $GLOBALS['httpReplies'] = [httpReply(refundObject('succeeded'))];
    ok($service->refund(1, new StripeAdapter())); amounts(500, 0, 0, 'paid');
    check(count($GLOBALS['httpCalls']) === 6 && $GLOBALS['httpCalls'][5][1]['method'] === 'GET', 'next recovery is a single GET');
});
contract('old uncertain attempt cannot replay a pruned idempotency key', function (): void {
    $service = fixture();
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), new WP_Error('timeout')];
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_uncertain');
    $uuid = attempt()->refund_uuid;
    $GLOBALS['wpdb']->update('wp_wpcb_payment_refunds', ['created_at'=>gmdate('Y-m-d H:i:s', time() - 24 * 3600)], ['id'=>attempt()->id]);
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_uncertain');
    check(count($GLOBALS['httpCalls']) === 2 && attempt()->refund_uuid === $uuid, 'old attempt blocked without any request');
    amounts(0, 0, 500, 'refund_pending');
});
contract('known old refund remains recoverable by read-only ID lookup', function (): void {
    $service = fixture(); ok(submit($service));
    $GLOBALS['wpdb']->update('wp_wpcb_payment_refunds', ['created_at'=>gmdate('Y-m-d H:i:s', time() - 30 * 86400)], ['id'=>attempt()->id]);
    $GLOBALS['httpReplies'] = [httpReply(refundObject('succeeded'))];
    ok($service->refund(1, new StripeAdapter())); amounts(500, 0, 0, 'paid');
    check($GLOBALS['httpCalls'][2][1]['method'] === 'GET', 'old known ID is never POSTed');
});
contract('definite first-request rejection returns queued money', function (): void {
    $service = fixture();
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), httpReply(['error'=>['message'=>'invalid']], 400)];
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_stripe_api');
    amounts(0, 500, 0, 'refund_pending'); check(attempt()->status === 'failed', 'definite rejection retained');
});
contract('provider 500 and malformed successful fields stay uncertain', function (): void {
    $service = fixture();
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), httpReply(['error'=>'unknown'], 500)];
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_uncertain');
    amounts(0, 0, 500, 'refund_pending');
    $GLOBALS['httpReplies'] = [httpReply(['payment_intent'=>'pi_one']), httpReply(refundObject('new_unknown_status'))];
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_uncertain');
    amounts(0, 0, 500, 'refund_pending');
});
foreach (['id'=>'re_wrong', 'amount'=>501, 'currency'=>'usd'] as $field=>$value) {
    contract('current provider ' . $field . ' mismatch does not alter money or consume event', function () use ($field, $value): void {
        $service = fixture(); ok(submit($service));
        $object = refundObject('succeeded'); $object[$field] = $value;
        $result = webhook('evt_bad', 'succeeded', 'succeeded', 400, 500, 're_one', $object);
        errorCode($result, 'wpcb_refund_verification_unavailable');
        check($result->get_error_data()['status'] === 503, 'lookup failure is retriable');
        check(!(new PaymentRepository())->eventExists('stripe', 'evt_bad'), 'failed verification not deduplicated');
        amounts(0, 0, 500, 'refund_pending');
    });
}
contract('failed current lookup can be retried with the same signed webhook', function (): void {
    $service = fixture(); ok(submit($service));
    $GLOBALS['httpReplies'] = [new WP_Error('timeout')];
    errorCode(webhook('evt_retry', 'succeeded', '', 400), 'wpcb_refund_verification_unavailable');
    amounts(0, 0, 500, 'refund_pending');
    ok(webhook('evt_retry', 'succeeded', 'succeeded', 400)); amounts(500, 0, 0, 'paid');
});
contract('aggregate charge refund never proves an individual pending refund succeeded', function (): void {
    $service = fixture(); ok(submit($service));
    ok($service->applyProviderEvent('stripe', 'evt_aggregate', 'cs_one', 'refund_total', 500, 'EUR'));
    amounts(0, 0, 500, 'refund_pending'); check(attempt()->status === 'pending', 'aggregate did not finalize attempt');
});
contract('separate queued delta preserves status while another refund is inflight', function (): void {
    $service = fixture(1000, 300); ok(submit($service, 'pending', 300));
    check((new PaymentRepository())->queueRefund(1, 200), 'second cancellation queued');
    ok($service->applyProviderEvent('stripe', 'evt_delta', 'cs_one', 'refunded', 200, 'EUR'));
    amounts(200, 0, 300, 'refund_pending');
});
contract('refund storage failure rolls accounting back and leaves webhook retryable', function (): void {
    $service = fixture(); ok(submit($service));
    $GLOBALS['wpdb']->beforeQuery = static fn($sql)=>!str_starts_with($sql, 'UPDATE wp_wpcb_payment_refunds');
    errorCode(webhook('evt_storage', 'succeeded', 'succeeded', 400), 'wpcb_refund_status_storage');
    $GLOBALS['wpdb']->beforeQuery = null;
    amounts(0, 0, 500, 'refund_pending'); check(attempt()->status === 'pending', 'attempt rolled back');
    check(!(new PaymentRepository())->eventExists('stripe', 'evt_storage'), 'failed write not acknowledged');
    ok(webhook('evt_storage', 'succeeded', 'succeeded', 400)); amounts(500, 0, 0, 'paid');
});
contract('busy payment lock prevents any provider request', function (): void {
    $service = fixture(); $GLOBALS['wpdb']->denyLock = true;
    errorCode($service->refund(1, new StripeAdapter()), 'wpcb_payment_refund_busy');
    check($GLOBALS['httpCalls'] === [], 'busy payment sent no request'); amounts(0, 500, 0, 'refund_pending');
});
contract('invalid webhook signature is rejected before provider access', function (): void {
    fixture(); errorCode((new StripeWebhookController())->handle(new WP_REST_Request('{}', 'invalid')), 'wpcb_stripe_signature');
    check($GLOBALS['httpCalls'] === [], 'unverified event sent no request'); amounts(0, 500, 0, 'refund_pending');
});
contract('single cancellation is idempotent and counts the inflight reservation', function (): void {
    $service = fixture(500, 0); $booking = (new Wpcb\Booking\BookingRepository())->find(1);
    $event = ['changed'=>true, 'to'=>'cancelled', 'effect_key'=>'single-cancel'];
    $service->onBookingTransition($event, $booking); $service->onBookingTransition($event, $booking);
    amounts(0, 500, 0, 'refund_pending'); ok(submit($service));
    check($service->refundAmountForBooking(1) === 0, 'inflight reservation is not offered twice');
    $service->onBookingTransition($event, $booking); amounts(0, 0, 500, 'refund_pending');
});
contract('series rounding, single and remaining allocations never exceed immutable total', function (): void {
    $service = fixture(1001, 0, 3); $bookings = new Wpcb\Booking\BookingRepository();
    check([$service->refundAmountForBooking(1), $service->refundAmountForBooking(2), $service->refundAmountForBooking(3)]
        === [334, 334, 333], 'series remainder distributed exactly');
    $first = $bookings->find(1);
    $service->onBookingTransition(['changed'=>true, 'to'=>'cancelled', 'effect_key'=>'series-1'], $first);
    ok(submit($service, 'pending', 334)); amounts(0, 0, 334, 'refund_pending');
    check($service->refundAmountForBooking(2, true) === 667, 'remaining series excludes inflight first occurrence');
    foreach ([2, 3] as $id) {
        $event = ['changed'=>true, 'to'=>'cancelled', 'effect_key'=>'series-' . $id];
        $service->onBookingTransition($event, $bookings->find($id));
        $service->onBookingTransition($event, $bookings->find($id));
    }
    amounts(0, 667, 334, 'refund_pending');
    check(!(new PaymentRepository())->queueRefund(1, 1), 'allocation cannot exceed total by a cent');
    $GLOBALS['httpReplies'] = [httpReply(refundObject('succeeded', 334))];
    ok($service->refund(1, new StripeAdapter())); amounts(334, 667, 0, 'refund_pending');
    ok(submit($service, 'succeeded', 667, 're_remaining')); amounts(1001, 0, 0, 'refunded');
});

contract('older bank return stays visible after a newer refund completed', function (): void {
    $service = fixture(); ok(submit($service, 'succeeded')); $first = attempt();
    check((new PaymentRepository())->queueRefund(1, 500), 'second refund queued');
    ok(submit($service, 'succeeded', 500, 're_two'));
    ok(webhook('evt_old_return', 'requires_action', 'requires_action', 500, 500, 're_one', null, $first));
    amounts(500, 0, 500, 'refund_pending');
    $view = (new Wpcb\Payments\StripeReturnController())->viewModel('11111111-1111-4111-8111-111111111111');
    check($view['state'] === 'refund_requires_action' && str_contains($view['message'], 'Aktion'), 'return view exposes the older action required');
    ob_start(); (new Wpcb\Admin\PaymentAdminPage())->render(); $html = ob_get_clean();
    check(str_contains($html, 're_one') && str_contains($html, 'Stripe-Status erneut prüfen')
        && !str_contains($html, '0,00 EUR erstatten'), 'admin exposes the active ID and recovery instead of a zero refund');
});
contract('multiple active refunds remain visible and selected recovery reads exactly that ID', function (): void {
    $service = fixture(); ok(submit($service, 'succeeded')); $first = attempt();
    check((new PaymentRepository())->queueRefund(1, 500), 'second refund queued');
    ok(submit($service, 'pending', 500, 're_two')); $second = attempt();
    ok(webhook('evt_first_return', 'requires_action', 'requires_action', 500, 500, 're_one', null, $first));
    amounts(0, 0, 1000, 'refund_pending');
    check(array_map(static fn($a)=>(int)$a->id, $service->activeRefundsForPayment(1)) === [(int)$first->id, (int)$second->id], 'requires_action precedes newer pending');
    $view = (new Wpcb\Payments\StripeReturnController())->viewModel('11111111-1111-4111-8111-111111111111');
    check($view['state'] === 'refund_requires_action' && str_contains($view['message'], '2 Erstattungen'), 'customer sees that multiple refunds remain open');
    ob_start(); (new Wpcb\Admin\PaymentAdminPage())->render(); $html = ob_get_clean();
    check(str_contains($html, 'select name="refund_attempt_id"') && str_contains($html, 're_one') && str_contains($html, 're_two'), 'admin can select either existing active refund');
    $before = count($GLOBALS['httpCalls']);
    $GLOBALS['httpReplies'] = [httpReply(refundObject('succeeded', 500, 're_two'))];
    $result = $service->refund(1, new StripeAdapter(), (int)$second->id); ok($result);
    check(count($GLOBALS['httpCalls']) === $before + 1 && str_ends_with($GLOBALS['httpCalls'][$before][0], '/re_two')
        && $GLOBALS['httpCalls'][$before][1]['method'] === 'GET', 'selected B is read once without POSTing A or a new refund');
    check($result->refund_attempt_status === 'succeeded' && $result->refund_provider_id === 're_two', 'recovery result describes the actual selected attempt');
    amounts(500, 0, 500, 'refund_pending');
    check((int)$service->activeRefundsForPayment(1)[0]->id === (int)$first->id, 'older action remains accessible');
});
foreach (['missing', 'foreign_payment', 'foreign_provider', 'terminal'] as $case) {
    contract('selected ' . $case . ' refund cannot fall back to another or a new submission', function () use ($case): void {
        $service = fixture(); ok(submit($service)); $selected = attempt();
        if ($case === 'foreign_payment') $GLOBALS['wpdb']->update('wp_wpcb_payment_refunds', ['payment_id'=>2], ['id'=>$selected->id]);
        if ($case === 'foreign_provider') $GLOBALS['wpdb']->update('wp_wpcb_payment_refunds', ['provider'=>'other'], ['id'=>$selected->id]);
        if ($case === 'terminal') $GLOBALS['wpdb']->update('wp_wpcb_payment_refunds', ['status'=>'failed'], ['id'=>$selected->id]);
        $before = count($GLOBALS['httpCalls']);
        errorCode($service->refund(1, new StripeAdapter(), $case === 'missing' ? 999 : (int)$selected->id), 'wpcb_refund_attempt_mismatch');
        check(count($GLOBALS['httpCalls']) === $before
            && (int)$GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM wp_wpcb_payment_refunds') === 1, 'invalid selection makes no request or attempt');
        amounts(0, 0, 500, 'refund_pending');
    });
}

echo $passed . ' contracts; ' . $failed . " failed.\n";
exit($failed > 0 ? 1 : 0);
