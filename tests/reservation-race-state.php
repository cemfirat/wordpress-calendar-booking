<?php
/**
 * CI helper for inspecting/mutating reservation-race state.
 */
if (!defined('ABSPATH')) {
    exit(1);
}

global $wpdb;
$action = (string)getenv('WPCB_TEST_STATE_ACTION');

if ($action === 'count') {
    echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_bookings");
    return;
}

if ($action === 'expire') {
    $past = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('-1 minute'));
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}wpcb_bookings SET reserved_until = %s WHERE status = %s",
            $past,
            Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED
        )
    );
    echo 'EXPIRED';
    return;
}

exit(2);
