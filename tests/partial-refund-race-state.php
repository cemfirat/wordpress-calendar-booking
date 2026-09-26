<?php
if (!defined('ABSPATH')) { exit(1); }

global $wpdb;
$action = (string)getenv('WPCB_TEST_STATE_ACTION');
$paymentId = (int)getenv('WPCB_TEST_PAYMENT_ID');

if ($action === 'payment') {
    $p = (new Wpcb\Payments\PaymentRepository())->find($paymentId);
    if (!$p) { exit(2); }
    echo implode('|', [
        (string)$p->status,
        (int)($p->refunded_minor ?? 0),
        (int)($p->refund_pending_minor ?? 0),
        (int)$p->amount_minor,
    ]);
    return;
}

if ($action === 'cleanup') {
    $p = (new Wpcb\Payments\PaymentRepository())->find($paymentId);
    if (!$p) { echo 'CLEAN'; return; }
    $owner = (new Wpcb\Booking\BookingRepository())->find((int)$p->booking_id);
    $seriesId = $owner ? (int)($owner->series_id ?? 0) : 0;
    $typeId = $owner ? (int)$owner->booking_type_id : 0;
    $members = $seriesId ? (new Wpcb\Booking\BookingSeriesRepository())->members($seriesId, 0) : [];
    $wpdb->delete($wpdb->prefix . 'wpcb_payment_events', ['payment_id' => $paymentId]);
    $wpdb->delete($wpdb->prefix . 'wpcb_payment_refunds', ['payment_id' => $paymentId]);
    $wpdb->delete($wpdb->prefix . 'wpcb_payments', ['id' => $paymentId]);
    foreach ($members as $member) {
        foreach (['wpcb_tokens','wpcb_booking_meta','wpcb_booking_status_log','wpcb_sync_jobs','wpcb_sync_log','wpcb_deliveries'] as $suffix) {
            $wpdb->delete($wpdb->prefix . $suffix, ['booking_id' => (int)$member->id]);
        }
        $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => (int)$member->id]);
    }
    if ($seriesId) $wpdb->delete($wpdb->prefix . 'wpcb_booking_series', ['id' => $seriesId]);
    if ($typeId) $wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $typeId]);
    echo 'CLEAN';
    return;
}

exit(2);
