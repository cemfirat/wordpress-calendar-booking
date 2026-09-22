<?php
/**
 * One reservation attempt for the CI concurrency test.
 * Environment:
 * - CEMB_TEST_SLOT_TOKEN
 * - CEMB_TEST_TYPE_ID
 * - CEMB_TEST_EMAIL
 */
if (!defined('ABSPATH')) {
    exit(1);
}

$token = (string)getenv('CEMB_TEST_SLOT_TOKEN');
$typeId = (int)getenv('CEMB_TEST_TYPE_ID');
$email = (string)getenv('CEMB_TEST_EMAIL');

$result = (new Cemb\Booking\ReservationService())->reserve(
    $token,
    $typeId,
    [
        'full_name' => 'Concurrency Test',
        'email' => $email ?: 'concurrency@example.com',
        'phone' => '',
        'notes' => '',
        'source' => 'ci',
        'lang' => 'en',
    ],
    ['test_case' => 'concurrency']
);

if (is_wp_error($result)) {
    echo 'REJECTED ' . $result->get_error_code() . PHP_EOL;
    return;
}

echo 'RESERVED ' . (int)$result . PHP_EOL;
