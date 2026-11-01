<?php
if (!defined('ABSPATH')) { exit(1); }

use Wpcb\Availability\SlotService;
use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingSeriesRepository;
use Wpcb\Booking\BookingStateMachine;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Booking\RecurringBookingService;
use Wpcb\Booking\ReservationService;
use Wpcb\Support\Time;
use Wpcb\Tokens\SlotTokenService;

function wpcb_series_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

$seriesMail = [];
add_filter('pre_wp_mail', static function ($pre, array $atts) use (&$seriesMail) {
    $attachments = [];
    foreach ((array)($atts['attachments'] ?? []) as $path) {
        $attachments[] = is_string($path) && is_file($path) ? (string)file_get_contents($path) : '';
    }
    $seriesMail[] = [
        'to' => (array)($atts['to'] ?? []),
        'message' => (string)($atts['message'] ?? ''),
        'attachments' => $attachments,
    ];
    return $GLOBALS['wpcb_series_mail_result'] ?? true;
}, 10, 2);
$GLOBALS['wpcb_series_mail_result'] = true;

global $wpdb;
$bookings = new BookingRepository();
$seriesRepo = new BookingSeriesRepository();
$type = null;
foreach ((new BookingTypeRepository())->all(true) as $candidate) {
    if ((string)($candidate->payment_mode ?? 'free') === 'free') {
        $type = $candidate;
        break;
    }
}
wpcb_series_assert($type !== null, 'A free public booking type exists for recurring fixture.');

$typeId = (int)$type->id;
$slots = (new SlotService())->getSlots($typeId, 21);
wpcb_series_assert(count($slots) >= 2, 'Canonical slots are available for recurring fixture.');

$anchor = $slots[0];
$token = (new SlotTokenService())->issue(
    $typeId,
    (string)$anchor['start'],
    (string)$anchor['end'],
    (int)$anchor['resource_id'],
    3600
);

$service = new RecurringBookingService();
$result = $service->reserveWeekly(
    $token,
    $typeId,
    [
        'full_name' => 'Recurring Fixture',
        'email' => 'recurring-fixture@example.com',
        'phone' => '',
        'notes' => '',
        'source' => 'ci',
        'lang' => 'de',
        'party_size' => 1,
    ],
    ['fixture' => 'recurring'],
    3,
    1
);
wpcb_series_assert(!is_wp_error($result), 'Three-occurrence weekly series reserves atomically.');
wpcb_series_assert(count($result['booking_ids']) === 3, 'Series returns three occurrence booking IDs.');
$seriesMail = [];

$seriesId = (int)$result['series_id'];
$members = $seriesRepo->members($seriesId);
wpcb_series_assert(count($members) === 3, 'Series repository returns all occurrences.');
wpcb_series_assert(array_map(fn($b) => (int)$b->series_occurrence, $members) === [0, 1, 2], 'Occurrence indexes are stable and ordered.');

$local = array_map(fn($b) => Time::utcToLocal((string)$b->slot_start), $members);
wpcb_series_assert(substr($local[0], 11) === substr($local[1], 11) && substr($local[1], 11) === substr($local[2], 11), 'Weekly series preserves local wall-clock time.');

$dates = array_map(fn($value) => new DateTimeImmutable(substr($value, 0, 10)), $local);
wpcb_series_assert((int)$dates[0]->diff($dates[1])->days === 7 && (int)$dates[1]->diff($dates[2])->days === 7, 'Weekly series keeps seven-day local cadence.');

$GLOBALS['wpcb_series_mail_result'] = false;
$seriesMail = [];
$confirmed = $service->applyRemaining(
    (int)$members[0]->id,
    BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
    'ci',
    'Recurring fixture confirmation'
);
wpcb_series_assert(!is_wp_error($confirmed), 'Series confirmation applies to all remaining occurrences.');
$members = $seriesRepo->members($seriesId);
wpcb_series_assert(count(array_filter($members, fn($b) => (string)$b->status === 'confirmed')) === 3, 'All series occurrences are confirmed together.');
$initialConfirmation = array_values(array_filter(
    $seriesMail,
    static fn($mail): bool => in_array('recurring-fixture@example.com', $mail['to'], true)
));
$initialConfirmation = $initialConfirmation[0] ?? null;
wpcb_series_assert($initialConfirmation !== null, 'Failed batch confirmation reaches the customer transport once.');
$initialConfirmationIcs = (string)($initialConfirmation['attachments'][0] ?? '');
$retryRows = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}wpcb_sync_jobs WHERE booking_id = %d AND job_type = %s ORDER BY id DESC",
    (int)$result['booking_ids'][0],
    \Wpcb\Mail\EmailRetryJobRunner::JOB_TYPE
));
$retryPayload = null;
foreach ((array)$retryRows as $retryRow) {
    $candidate = json_decode((string)$retryRow->payload_json, true);
    if (is_array($candidate) && ($candidate['kind'] ?? '') === 'template' && in_array(($candidate['template'] ?? ''), ['confirmed', 'approved'], true)) {
        $retryPayload = $candidate;
        break;
    }
}
wpcb_series_assert(is_array($retryPayload), 'Failed aggregate confirmation creates a durable email retry descriptor.');
wpcb_series_assert(
    array_values(array_map('intval', (array)($retryPayload['series_booking_ids'] ?? []))) === array_values(array_map('intval', $result['booking_ids'])),
    'Durable retry descriptor preserves the affected series occurrence IDs.'
);
$GLOBALS['wpcb_series_mail_result'] = true;
$seriesMail = [];
$retryResult = is_array($retryPayload)
    ? (new \Wpcb\Mail\EmailRetryJobRunner())->run($retryPayload, (int)$result['booking_ids'][0])
    : ['ok' => false];
$customerConfirmation = array_values(array_filter(
    $seriesMail,
    static fn($mail): bool => in_array('recurring-fixture@example.com', $mail['to'], true)
));
$customerConfirmation = $customerConfirmation[0] ?? null;
wpcb_series_assert(!empty($retryResult['ok']) && $customerConfirmation !== null, 'Aggregate confirmation retry sends successfully.');
$confirmationIcs = (string)($customerConfirmation['attachments'][0] ?? '');
wpcb_series_assert(
    strpos((string)($customerConfirmation['message'] ?? ''), 'Zeitzone:') !== false
    && substr_count($confirmationIcs, 'BEGIN:VEVENT') === 3
    && substr_count($initialConfirmationIcs, 'BEGIN:VEVENT') === 3,
    'Retry reconstructs the complete aggregate dates and three-event calendar attachment.'
);
wpcb_series_assert(strpos($confirmationIcs, 'METHOD:PUBLISH') !== false, 'Aggregate confirmation calendar attachment uses publish semantics.');

$GLOBALS['wpcb_series_mail_result'] = true;
$seriesMail = [];
$cancelled = $service->applyRemaining(
    (int)$members[1]->id,
    BookingStateMachine::USER_CANCELLED,
    'ci',
    'Recurring fixture partial cancellation'
);
wpcb_series_assert(!is_wp_error($cancelled), 'Remaining-series cancellation succeeds.');
$members = $seriesRepo->members($seriesId);
wpcb_series_assert((string)$members[0]->status === 'confirmed', 'Earlier occurrence remains confirmed.');
wpcb_series_assert((string)$members[1]->status === 'cancelled' && (string)$members[2]->status === 'cancelled', 'Selected and later occurrences are cancelled.');
$customerCancellation = array_values(array_filter(
    $seriesMail,
    static fn($mail): bool => in_array('recurring-fixture@example.com', $mail['to'], true)
));
wpcb_series_assert(count($customerCancellation) === 1, 'Batch suffix cancellation sends one aggregate customer email.');
$cancellationIcs = (string)($customerCancellation[0]['attachments'][0] ?? '');
wpcb_series_assert(substr_count($cancellationIcs, 'BEGIN:VEVENT') === 2, 'Suffix cancellation calendar attachment contains only the affected occurrences.');
wpcb_series_assert(strpos($cancellationIcs, 'METHOD:CANCEL') !== false && substr_count($cancellationIcs, 'STATUS:CANCELLED') === 2, 'Suffix cancellation calendar attachment carries cancellation semantics with stable per-occurrence events.');

$seriesMail = [];
$secondary = clone $members[1];
$secondary->status = \Wpcb\Booking\BookingStatus::CANCELLED;
(new \Wpcb\Booking\BookingTransitionEffects())->onTransition([
    'booking_id' => (int)$secondary->id,
    'event' => BookingStateMachine::USER_CANCELLED,
    'from' => BookingStateMachine::USER_CANCELLED,
    'to' => \Wpcb\Booking\BookingStatus::CANCELLED,
    'changed' => true,
    'actor' => 'ci',
    'series_scope' => 'single',
], $secondary);
$singleOccurrenceMail = array_values(array_filter(
    $seriesMail,
    static fn($mail): bool => in_array('recurring-fixture@example.com', $mail['to'], true)
));
wpcb_series_assert(count($singleOccurrenceMail) === 1, 'An independently changed secondary occurrence sends its own customer notification.');
$singleOccurrenceIcs = (string)($singleOccurrenceMail[0]['attachments'][0] ?? '');
wpcb_series_assert(substr_count($singleOccurrenceIcs, 'BEGIN:VEVENT') === 1 && strpos($singleOccurrenceIcs, 'METHOD:CANCEL') !== false, 'Single-occurrence cancellation attachment contains only that stable cancelled event.');

foreach ($members as $member) {
    $wpdb->delete($wpdb->prefix . 'wpcb_tokens', ['booking_id' => (int)$member->id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => (int)$member->id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => (int)$member->id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['booking_id' => (int)$member->id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_sync_log', ['booking_id' => (int)$member->id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_deliveries', ['booking_id' => (int)$member->id]);
    $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => (int)$member->id]);
}
$wpdb->delete($wpdb->prefix . 'wpcb_booking_series', ['id' => $seriesId]);

// All-or-nothing preflight: occupy the second weekly occurrence and verify a
// new series leaves no partial rows behind.
$secondLocal = Time::parseUtc((string)$anchor['start'])
    ->setTimezone(Time::bookingTimezone())
    ->modify('+1 week');
$secondEndLocal = Time::parseUtc((string)$anchor['end'])
    ->setTimezone(Time::bookingTimezone())
    ->modify('+1 week');
$secondStart = Time::formatUtc($secondLocal);
$secondEnd = Time::formatUtc($secondEndLocal);
$blockToken = (new SlotTokenService())->issue(
    $typeId,
    $secondStart,
    $secondEnd,
    (int)$anchor['resource_id'],
    3600
);
$blockId = (new ReservationService())->reserve(
    $blockToken,
    $typeId,
    [
        'full_name' => 'Series Blocker',
        'email' => 'series-blocker@example.com',
        'party_size' => 1,
        'source' => 'ci',
        'lang' => 'de',
    ]
);
wpcb_series_assert(!is_wp_error($blockId), 'Fixture can reserve the second occurrence independently.');

$beforeSeriesCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_series");
$failed = $service->reserveWeekly(
    $token,
    $typeId,
    [
        'full_name' => 'Should Roll Back',
        'email' => 'series-rollback@example.com',
        'party_size' => 1,
        'source' => 'ci',
        'lang' => 'de',
    ],
    [],
    3,
    1
);
wpcb_series_assert(is_wp_error($failed), 'Series creation fails when any occurrence is unavailable.');
$afterSeriesCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_series");
wpcb_series_assert($afterSeriesCount === $beforeSeriesCount, 'Failed series creates no partial series row.');
wpcb_series_assert((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_bookings WHERE email = %s",
    'series-rollback@example.com'
)) === 0, 'Failed series creates no partial occurrence bookings.');

$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => (int)$blockId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => (int)$blockId]);
$wpdb->delete($wpdb->prefix . 'wpcb_payments', ['booking_id' => (int)$blockId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => (int)$blockId]);

WP_CLI::success('Recurring booking series smoke test passed.');
