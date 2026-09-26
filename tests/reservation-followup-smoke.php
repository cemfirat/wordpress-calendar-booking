<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_followup_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$originalSettings = get_option('wpcb_settings', []);
$originalTemplates = get_option('wpcb_email_templates', []);
$typeId = 0;
$bookingIds = [];
$mailMode = 'accept';
$mailbox = [];

$transport = static function ($return, array $atts) use (&$mailMode, &$mailbox) {
    $mailbox[] = $atts;
    return $mailMode === 'accept';
};
add_filter('pre_wp_mail', $transport, 10, 2);

try {
    $settings = Wpcb\Admin\Settings::get();
    $settings['notifications_enabled'] = 0;
    $settings['token_ttl_minutes'] = 60;
    update_option('wpcb_settings', $settings);

    $templates = is_array($originalTemplates) ? $originalTemplates : [];
    $templates['doi_subject'] = 'CI DOI';
    $templates['doi_body'] = "Confirm securely:\n{bestaetigungslink}";
    update_option('wpcb_email_templates', $templates);

    $now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
    $types = $wpdb->prefix . 'wpcb_booking_types';
    $wpdb->insert($types, [
        'name' => 'Reservation follow-up CI',
        'slug' => 'reservation-followup-' . strtolower(wp_generate_password(8, false, false)),
        'description' => '',
        'duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'capacity' => 1,
        'show_remaining_capacity' => 0,
        'payment_mode' => 'free',
        'price_minor' => 0,
        'currency' => 'EUR',
        'is_active' => 1,
        'is_public' => 1,
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $typeId = (int)$wpdb->insert_id;
    wpcb_followup_assert($typeId > 0, 'Follow-up fixture booking type is created.');

    $resourceId = (int)get_option('wpcb_default_resource_id', 0);
    wpcb_followup_assert($resourceId > 0, 'Follow-up fixture has a resource.');

    $bookings = new Wpcb\Booking\BookingRepository();
    $deliveries = new Wpcb\Reliability\DeliveryRepository();
    $jobs = new Wpcb\Sync\JobRepository();
    $tokens = new Wpcb\Tokens\TokenService();

    $createBooking = static function (string $email) use (
        $bookings, $typeId, $resourceId, $now, &$bookingIds
    ): int {
        $id = $bookings->create([
            'booking_uuid' => wp_generate_uuid4(),
            'booking_type_id' => $typeId,
            'resource_id' => $resourceId,
            'slot_start' => '2035-05-01 10:00:00',
            'slot_end' => '2035-05-01 10:30:00',
            'party_size' => 1,
            'status' => Wpcb\Booking\BookingStatus::RESERVED_UNCONFIRMED,
            'full_name' => 'Follow-up Fixture',
            'email' => $email,
            'phone' => '',
            'source' => 'ci-followup',
            'lang' => 'de',
            'reserved_until' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+60 minutes')),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['fixture' => 'reservation-followup'], false);
        if ($id > 0) {
            $bookingIds[] = $id;
        }
        return $id;
    };

    $firstId = $createBooking('followup-success@example.invalid');
    $firstBooking = $bookings->find($firstId);
    $outbox = new Wpcb\Booking\BookingEffectOutbox();
    wpcb_followup_assert($firstBooking && $outbox->recordCreated($firstBooking) > 0, 'Created effect is stored before DOI delivery.');
    $outbox->kick();

    $firstKey = 'mail:user:' . $firstId . ':doi';
    $firstDelivery = $deliveries->findByKey($firstKey);
    wpcb_followup_assert($firstDelivery && $firstDelivery->status === 'sent', 'Created effect produces one durable DOI delivery.');
    wpcb_followup_assert(count($mailbox) === 1, 'Initial created-effect replay sends one customer DOI message.');

    $tokenRows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpcb_tokens WHERE booking_id = %d AND token_type = 'doi' AND used_at IS NULL",
        $firstId
    ));
    wpcb_followup_assert(count($tokenRows) === 1, 'Exactly one unused DOI token exists after successful delivery.');
    preg_match('/wpcb_token=([^&\s]+)/', (string)($mailbox[0]['message'] ?? ''), $tokenMatch);
    $sentToken = isset($tokenMatch[1]) ? rawurldecode(html_entity_decode($tokenMatch[1])) : '';
    wpcb_followup_assert(
        $sentToken !== '' && $tokens->inspect($sentToken, 'doi')['state'] === 'valid',
        'The DOI link delivered to the customer contains a valid one-time token.'
    );

    (new Wpcb\Booking\ReservationFollowUpService())->onCreated($firstBooking);
    $sameTokenCount = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_tokens WHERE booking_id = %d AND token_type = 'doi' AND used_at IS NULL",
        $firstId
    ));
    wpcb_followup_assert(
        count($mailbox) === 1 && $sameTokenCount === 1 && $tokens->inspect($sentToken, 'doi')['state'] === 'valid',
        'Replaying a completed reservation follow-up does not duplicate mail or invalidate the delivered DOI link.'
    );

    $mailMode = 'reject';
    $secondId = $createBooking('followup-retry@example.invalid');
    $secondBooking = $bookings->find($secondId);
    wpcb_followup_assert($secondBooking && $outbox->recordCreated($secondBooking) > 0, 'Second created effect is stored for retry coverage.');
    $outbox->kick();

    $secondKey = 'mail:user:' . $secondId . ':doi';
    $secondDelivery = $deliveries->findByKey($secondKey);
    $retryKey = Wpcb\Mail\EmailRetryJobRunner::jobKey($secondKey);
    $retryJob = $jobs->findByIdempotencyKeys([$retryKey])[$retryKey] ?? null;
    $beforeReplayMailCount = count($mailbox);
    $beforeReplayTokenCount = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_tokens WHERE booking_id = %d AND token_type = 'doi' AND used_at IS NULL",
        $secondId
    ));
    wpcb_followup_assert(
        $secondDelivery && $secondDelivery->status === 'failed' && $retryJob,
        'Definite DOI transport failure leaves one durable retry job.'
    );

    (new Wpcb\Booking\ReservationFollowUpService())->onCreated($secondBooking);
    $afterReplayTokenCount = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_tokens WHERE booking_id = %d AND token_type = 'doi' AND used_at IS NULL",
        $secondId
    ));
    wpcb_followup_assert(
        count($mailbox) === $beforeReplayMailCount
        && $beforeReplayTokenCount === 1
        && $afterReplayTokenCount === 1,
        'Booking-effect replay notices the durable email retry and does not create another logical delivery.'
    );

    $mailMode = 'accept';
    $payload = json_decode((string)$wpdb->get_var($wpdb->prepare(
        "SELECT payload_json FROM {$wpdb->prefix}wpcb_sync_jobs WHERE id = %d",
        (int)$retryJob->id
    )), true);
    $retryResult = (new Wpcb\Mail\EmailRetryJobRunner())->run(
        is_array($payload) ? $payload : [],
        $secondId
    );
    $secondAfter = $deliveries->findByKey($secondKey);
    wpcb_followup_assert(
        !empty($retryResult['ok']) && $secondAfter && $secondAfter->status === 'sent',
        'Durable DOI retry reconstructs the link and completes the same logical delivery.'
    );
    $secondUnused = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_tokens WHERE booking_id = %d AND token_type = 'doi' AND used_at IS NULL",
        $secondId
    ));
    wpcb_followup_assert($secondUnused === 1, 'DOI retry rotation leaves exactly one valid token.');
} finally {
    remove_filter('pre_wp_mail', $transport, 10);
    update_option('wpcb_settings', $originalSettings);
    update_option('wpcb_email_templates', $originalTemplates);

    foreach ($bookingIds as $bookingId) {
        $wpdb->delete($wpdb->prefix . 'wpcb_deliveries', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_sync_log', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_sync_jobs', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_tokens', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
    }
    if ($typeId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $typeId]);
    }
}

WP_CLI::success('Durable reservation follow-up smoke test passed.');
