<?php
if (!defined('ABSPATH')) { exit(1); }

$id = (int)getenv('WPCB_TEST_BOOKING_ID');
$result = (new Wpcb\Booking\BookingTransitionService())->apply(
    $id,
    Wpcb\Booking\BookingStateMachine::USER_CANCELLED,
    'ci_race',
    'Concurrent partial refund cancellation'
);
if (is_wp_error($result)) {
    echo 'REJECTED ' . $result->get_error_code() . PHP_EOL;
    return;
}
echo !empty($result['changed']) ? "CANCELLED\n" : "UNCHANGED\n";
