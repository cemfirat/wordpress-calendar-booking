<?php
/**
 * WordPress integration smoke test.
 * Run with: wp eval-file tests/wordpress-smoke.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function cemb_smoke_assert( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	WP_CLI::log( 'PASS: ' . $message );
}

cemb_smoke_assert( defined( 'CEMB_VERSION' ), 'Plugin constants are loaded.' );
cemb_smoke_assert( class_exists( 'Cemb\\Core\\Plugin' ), 'Plugin autoloader resolves core classes.' );
cemb_smoke_assert( shortcode_exists( 'cemb_booking_form' ), 'Booking form shortcode is registered.' );
cemb_smoke_assert( shortcode_exists( 'cemb_calendar' ), 'Calendar shortcode is registered.' );
cemb_smoke_assert( shortcode_exists( 'cemb_booking_calendar' ), 'Booking calendar shortcode is registered.' );
cemb_smoke_assert( false !== has_action( 'admin_post_nopriv_cemb_submit_booking' ), 'Public booking submission action is registered.' );
cemb_smoke_assert( false !== has_action( 'wp_ajax_nopriv_cemb_get_slots' ), 'Public slot AJAX action is registered.' );

global $wpdb;
$tables = array(
	'bookings',
	'booking_meta',
	'booking_types',
	'form_fields',
	'availability_rules',
	'exceptions',
	'tokens',
	'booking_status_log',
	'sync_jobs',
	'sync_log',
);
foreach ( $tables as $suffix ) {
	$table = $wpdb->prefix . 'cemb_' . $suffix;
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	cemb_smoke_assert( $table === $found, 'Database table exists: ' . $table );
}

$type_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cemb_booking_types" );
cemb_smoke_assert( $type_count >= 1, 'Default booking types are seeded.' );

$rule_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cemb_availability_rules" );
cemb_smoke_assert( $rule_count >= 1, 'Default availability rules are seeded.' );


$type_repo = new Cemb\Booking\BookingTypeRepository();
$types = $type_repo->all( true );
cemb_smoke_assert( ! empty( $types ), 'At least one public booking type is available for slot tests.' );

$type_id = (int) $types[0]->id;
$slot_service = new Cemb\Availability\SlotService();
$slots = $slot_service->getSlots( $type_id, 21 );
cemb_smoke_assert( ! empty( $slots ), 'Server generates at least one canonical slot.' );

$slot = $slots[0];
$token_service = new Cemb\Tokens\SlotTokenService();
$selection_service = new Cemb\Availability\SlotSelectionService();

$token = $token_service->issue( $type_id, $slot['start'], $slot['end'] );
$payload = $token_service->verify( $token );
cemb_smoke_assert( is_array( $payload ), 'A server-issued slot token verifies.' );
cemb_smoke_assert( $type_id === $payload['type_id'], 'Slot token binds the booking type.' );
cemb_smoke_assert( $slot['start'] === $payload['start'] && $slot['end'] === $payload['end'], 'Slot token binds the canonical start and end.' );
cemb_smoke_assert( is_array( $selection_service->resolve( $token, $type_id ) ), 'A valid token resolves only after current availability revalidation.' );

$tampered = substr( $token, 0, -1 ) . ( substr( $token, -1 ) === 'A' ? 'B' : 'A' );
cemb_smoke_assert( null === $token_service->verify( $tampered ), 'A tampered slot token is rejected.' );
cemb_smoke_assert( null === $selection_service->resolve( $token, $type_id + 9999 ), 'A slot token cannot be reused for another booking type.' );

$short_lived = $token_service->issue( $type_id, $slot['start'], $slot['end'], 60 );
cemb_smoke_assert( null === $token_service->verify( $short_lived, time() + 61 ), 'An expired slot token is rejected.' );

$off_start = date( 'Y-m-d H:i:s', strtotime( $slot['start'] . ' +5 minutes' ) );
$off_end = date( 'Y-m-d H:i:s', strtotime( $slot['end'] . ' +5 minutes' ) );
$off_grid_token = $token_service->issue( $type_id, $off_start, $off_end );
cemb_smoke_assert( null === $selection_service->resolve( $off_grid_token, $type_id ), 'A signed but non-canonical off-grid slot is rejected.' );

WP_CLI::success( 'WordPress Calendar Booking smoke test passed on WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . '.' );
