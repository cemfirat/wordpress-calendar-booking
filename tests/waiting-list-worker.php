<?php
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { exit(1); }

$work = json_decode((string)getenv('WPCB_WAITLIST_WORK'), true);
if (!is_array($work) || ($work['operation'] ?? '') !== 'promote') {
    throw new RuntimeException('Invalid waiting-list worker request.');
}

$result = (new Wpcb\WaitingList\WaitingListService())->promoteSlot(
    (int)($work['booking_type_id'] ?? 0),
    (int)($work['resource_id'] ?? 0),
    (string)($work['slot_start'] ?? ''),
    (string)($work['slot_end'] ?? '')
);

echo 'WPCB164_RESULT:' . wp_json_encode(['result' => $result]) . PHP_EOL;
