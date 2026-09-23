<?php
if (!defined('ABSPATH')) { exit; }

function cemb_audit_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

global $wpdb;
$now = Cemb\Support\Time::formatUtc(Cemb\Support\Time::nowUtc());
$typeId = $wpdb->insert($wpdb->prefix . 'cemb_booking_types', [
    'name' => 'Audit Fixture',
    'slug' => 'audit-fixture-' . wp_generate_password(6, false),
    'description' => '',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 0,
    'buffer_after_minutes' => 0,
    'is_active' => 1,
    'is_public' => 0,
    'sort_order' => 0,
    'created_at' => $now,
    'updated_at' => $now,
]) ? (int)$wpdb->insert_id : 0;

$bookings = new Cemb\Booking\BookingRepository();
$bookingId = $bookings->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'slot_start' => '2031-02-03 09:00:00',
    'slot_end' => '2031-02-03 09:30:00',
    'status' => Cemb\Booking\BookingStatus::PENDING_APPROVAL,
    'full_name' => 'Private Audit Person',
    'email' => 'private-audit@example.com',
    'phone' => '+431234567',
    'notes' => 'PRIVATE BOOKING NOTE',
    'source' => 'test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
cemb_audit_assert($bookingId > 0, 'Audit fixture booking is created.');

$bookings->logEvent($bookingId, Cemb\Booking\BookingStatus::PENDING_APPROVAL, 'fixture_event', 'admin', 'Generic audit note');
$otherId = $bookings->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'slot_start' => '2031-02-04 09:00:00',
    'slot_end' => '2031-02-04 09:30:00',
    'status' => Cemb\Booking\BookingStatus::CONFIRMED,
    'full_name' => 'Other Person',
    'email' => 'other@example.com',
    'phone' => '',
    'notes' => '',
    'source' => 'test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);

$audit = new Cemb\Booking\BookingAuditRepository();
$filtered = $audit->search([
    'booking_id' => $bookingId,
    'context' => 'fixture_event',
    'actor' => 'admin',
], 50);
cemb_audit_assert(count($filtered) === 1, 'Audit repository combines booking, event and actor filters.');
cemb_audit_assert((int)$filtered[0]->booking_id === $bookingId, 'Audit result belongs to the requested booking.');
cemb_audit_assert((string)$filtered[0]->note === 'Generic audit note', 'Audit result exposes the generic lifecycle note.');

$contexts = $audit->contexts();
$actors = $audit->actors();
cemb_audit_assert(in_array('fixture_event', $contexts, true), 'Audit repository exposes available event filters.');
cemb_audit_assert(in_array('admin', $actors, true), 'Audit repository exposes available actor filters.');

$admin = get_user_by('login', 'admin');
cemb_audit_assert($admin !== false, 'WordPress admin fixture user exists.');
wp_set_current_user((int)$admin->ID);

$_GET = [
    'page' => 'cemb_booking_audit',
    'booking_id' => (string)$bookingId,
    'event' => 'fixture_event',
    'actor' => 'admin',
];
ob_start();
(new Cemb\Admin\BookingAuditPage())->render();
$html = (string)ob_get_clean();

cemb_audit_assert(strpos($html, 'fixture_event') !== false, 'Admin audit screen renders the matching lifecycle event.');
cemb_audit_assert(strpos($html, 'Generic audit note') !== false, 'Admin audit screen renders the generic lifecycle note.');
cemb_audit_assert(strpos($html, 'private-audit@example.com') === false, 'Admin audit screen does not render booking email.');
cemb_audit_assert(strpos($html, 'Private Audit Person') === false, 'Admin audit screen does not render booking name.');
cemb_audit_assert(strpos($html, '+431234567') === false, 'Admin audit screen does not render booking phone.');
cemb_audit_assert(strpos($html, 'PRIVATE BOOKING NOTE') === false, 'Admin audit screen does not render booking notes.');

$wpdb->delete($wpdb->prefix . 'cemb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'cemb_booking_status_log', ['booking_id' => $otherId]);
$wpdb->delete($wpdb->prefix . 'cemb_bookings', ['id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'cemb_bookings', ['id' => $otherId]);
$wpdb->delete($wpdb->prefix . 'cemb_booking_types', ['id' => $typeId]);

echo "PASS: booking audit history smoke test complete.\n";
