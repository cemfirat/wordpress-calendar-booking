<?php
/**
 * CI helper for creating public-link flow fixtures.
 */
if (!defined('ABSPATH')) {
    exit(1);
}

use Cemb\Admin\Settings;
use Cemb\Availability\SlotService;
use Cemb\Booking\BookingRepository;
use Cemb\Booking\BookingStateMachine;
use Cemb\Booking\BookingTransitionService;
use Cemb\Booking\BookingTypeRepository;
use Cemb\Booking\ReservationService;
use Cemb\Support\Time;
use Cemb\Tokens\SlotTokenService;
use Cemb\Tokens\TokenService;

global $wpdb;
$action = (string)getenv('CEMB_PUBLIC_FIXTURE_ACTION');

function cemb_fixture_settings(): void {
    $settings = Settings::get();
    $settings['mode'] = 'automatic';
    $settings['notifications_enabled'] = 0;
    $settings['icloud_sync_enabled'] = 0;
    $settings['cancel_min_hours'] = 0;
    $settings['change_min_hours'] = 0;
    update_option('cemb_settings', $settings);
}

function cemb_fixture_booking(bool $confirm): int {
    $types = (new BookingTypeRepository())->all(true);
    if (!$types) {
        throw new RuntimeException('No booking type available.');
    }

    $typeId = (int)$types[0]->id;
    $slots = (new SlotService())->getSlots($typeId, 30);
    if (!$slots) {
        throw new RuntimeException('No canonical slot available.');
    }

    $slot = $slots[0];
    $slotToken = (new SlotTokenService())->issue($typeId, $slot['start'], $slot['end']);
    $bookingId = (new ReservationService())->reserve(
        $slotToken,
        $typeId,
        [
            'full_name' => 'Public Flow Fixture',
            'email' => 'public-flow@example.com',
            'phone' => '',
            'notes' => '',
            'source' => 'ci',
            'lang' => 'en',
        ],
        []
    );

    if (is_wp_error($bookingId)) {
        throw new RuntimeException($bookingId->get_error_message());
    }

    $bookingId = (int)$bookingId;
    if ($confirm) {
        // The fixture only needs lifecycle state; HTTP flow side effects are
        // tested separately and should not send real CI email.
        remove_all_actions('cemb_booking_transitioned');
        $result = (new BookingTransitionService())->apply(
            $bookingId,
            BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
            'system',
            'HTTP flow fixture setup'
        );
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
    }

    return $bookingId;
}

function cemb_fixture_payload(int $bookingId, string $action, string $token): array {
    return [
        'booking_id' => $bookingId,
        'token' => $token,
        'url' => add_query_arg(
            ['cemb_action' => $action, 'cemb_token' => rawurlencode($token)],
            home_url('/')
        ),
    ];
}

cemb_fixture_settings();
$tokens = new TokenService();

if ($action === 'create_confirm') {
    $bookingId = cemb_fixture_booking(false);
    $token = $tokens->create($bookingId, 'doi', 60);
    echo wp_json_encode(cemb_fixture_payload($bookingId, 'confirm', $token));
    return;
}

if ($action === 'create_cancel') {
    $bookingId = cemb_fixture_booking(true);
    $token = $tokens->create($bookingId, 'cancel', 60);
    echo wp_json_encode(cemb_fixture_payload($bookingId, 'cancel', $token));
    return;
}

if ($action === 'create_update') {
    $bookingId = cemb_fixture_booking(true);
    $token = $tokens->create($bookingId, 'update', 60);
    echo wp_json_encode(cemb_fixture_payload($bookingId, 'update', $token));
    return;
}

if ($action === 'create_expired') {
    $bookingId = cemb_fixture_booking(false);
    $token = $tokens->create($bookingId, 'doi', 60);
    $tokenId = (int)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cemb_tokens WHERE booking_id = %d AND token_type = %s ORDER BY id DESC LIMIT 1",
            $bookingId,
            'doi'
        )
    );
    $wpdb->update(
        $wpdb->prefix . 'cemb_tokens',
        ['expires_at' => Time::formatUtc(Time::nowUtc()->modify('-5 minutes'))],
        ['id' => $tokenId]
    );
    echo wp_json_encode(cemb_fixture_payload($bookingId, 'confirm', $token));
    return;
}

if ($action === 'cleanup') {
    $bookingId = (int)getenv('CEMB_PUBLIC_BOOKING_ID');
    if ($bookingId > 0) {
        $wpdb->delete($wpdb->prefix . 'cemb_tokens', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'cemb_booking_meta', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'cemb_booking_status_log', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'cemb_sync_jobs', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'cemb_sync_log', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'cemb_bookings', ['id' => $bookingId]);
    }
    echo wp_json_encode(['cleaned' => $bookingId]);
    return;
}

if ($action === 'state') {
    $bookingId = (int)getenv('CEMB_PUBLIC_BOOKING_ID');
    $booking = (new BookingRepository())->find($bookingId);
    if (!$booking) {
        echo wp_json_encode(['missing' => true]);
        return;
    }
    echo wp_json_encode([
        'booking_id' => (int)$booking->id,
        'status' => (string)$booking->status,
        'slot_start' => (string)$booking->slot_start,
        'slot_end' => (string)$booking->slot_end,
    ]);
    return;
}

exit(2);
