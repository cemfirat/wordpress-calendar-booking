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

WP_CLI::success( 'WordPress Calendar Booking smoke test passed on WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . '.' );
