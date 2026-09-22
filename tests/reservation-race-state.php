<?php
/**
 * CI helper for inspecting/mutating reservation-race state.
 */
if (!defined('ABSPATH')) {
    exit(1);
}

global $wpdb;
$action = (string)getenv('CEMB_TEST_STATE_ACTION');

if ($action === 'count') {
    echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cemb_bookings");
    return;
}

if ($action === 'expire') {
    $past = date('Y-m-d H:i:s', current_time('timestamp') - 60);
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}cemb_bookings SET reserved_until = %s WHERE status = %s",
            $past,
            Cemb\Booking\BookingStatus::RESERVED_UNCONFIRMED
        )
    );
    echo 'EXPIRED';
    return;
}

exit(2);
