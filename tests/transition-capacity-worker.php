<?php
/** Private CI worker; run only in the disposable WordPress integration install. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { exit(1); }

$work = json_decode((string)getenv('WPCB_TRANSITION_WORK'), true);
if (!is_array($work) || empty($work['booking_id'])) {
    throw new RuntimeException('Missing transition test work descriptor.');
}
foreach (['wpcb_booking_transitioned', 'wpcb_booking_created', 'wpcb_booking_event_recorded'] as $hook) {
    remove_all_actions($hook); // Domain/concurrency test; real delivery remains covered by the browser suite.
}
$signal = (string)($work['signal'] ?? '');
if ($signal !== '') {
    add_filter('query', static function ($sql) use ($signal) {
        if (strpos($sql, 'SELECT GET_LOCK(') !== false && strpos($sql, 'wpcb_res_') !== false) {
            file_put_contents($signal, 'attempting-resource-lock');
        }
        return $sql;
    });
}
$service = new Wpcb\Booking\BookingTransitionService();
if (($work['operation'] ?? '') === 'reschedule') {
    $result = $service->reschedule((int)$work['booking_id'], $work['start'], $work['end'],
        'ci', 'Serialization probe', (int)$work['resource_id']);
} elseif (($work['operation'] ?? '') === 'expire') {
    $result = $service->applyBatch([(int)$work['booking_id']],
        Wpcb\Booking\BookingStateMachine::RESERVATION_EXPIRED, 'ci', 'Due expiry probe', false, true);
    if (is_array($result)) $result = $result[0];
} else {
    $result = $service->apply((int)$work['booking_id'],
        (string)($work['event'] ?? Wpcb\Booking\BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC), 'ci');
}
$payload = is_wp_error($result)
    ? ['error' => $result->get_error_code()]
    : ['changed' => !empty($result['changed']), 'to' => $result['to'] ?? ''];
echo 'WPCB154_RESULT:' . wp_json_encode($payload) . "\n";
