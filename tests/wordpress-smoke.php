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


$public_presenter = new Cemb\Calendar\PublicBusyPresenter();
$private_external = [
	'start' => current_time( 'Y-m-d' ) . ' 10:00:00',
	'end' => current_time( 'Y-m-d' ) . ' 11:00:00',
	'summary' => 'PRIVATE EXTERNAL TITLE',
	'location' => 'PRIVATE EXTERNAL LOCATION',
	'description' => 'PRIVATE EXTERNAL DESCRIPTION',
];
$public_external = $public_presenter->externalEvent( $private_external );
cemb_smoke_assert( 'Besetzt' === $public_external['title'], 'External events become a generic public busy label.' );
cemb_smoke_assert( false === strpos( wp_json_encode( $public_external ), 'PRIVATE EXTERNAL' ), 'Public external-event model contains no private event details.' );

$settings_before_privacy_test = get_option( 'cemb_settings', [] );
$test_calendar_url = 'https://example.test/cemb-private-calendar.ics';
$privacy_settings = Cemb\Admin\Settings::get();
$privacy_settings['calendar_urls'] = $test_calendar_url;
$privacy_settings['calendar_url'] = $test_calendar_url;
$privacy_settings['show_calendar_limit'] = 20;
update_option( 'cemb_settings', $privacy_settings );
delete_transient( 'cemb_ical_' . md5( $test_calendar_url ) );

$ics_start = gmdate( 'Ymd\\THis\\Z', time() + DAY_IN_SECONDS );
$ics_end = gmdate( 'Ymd\\THis\\Z', time() + DAY_IN_SECONDS + HOUR_IN_SECONDS );
$private_ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:cemb-private-test\r\nDTSTART:{$ics_start}\r\nDTEND:{$ics_end}\r\nSUMMARY:PRIVATE EXTERNAL TITLE\r\nLOCATION:PRIVATE EXTERNAL LOCATION\r\nDESCRIPTION:PRIVATE EXTERNAL DESCRIPTION\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$privacy_http_filter = static function ( $preempt, $args, $url ) use ( $test_calendar_url, $private_ics ) {
	if ( $url === $test_calendar_url ) {
		return [
			'headers' => [],
			'response' => [ 'code' => 200 ],
			'body' => $private_ics,
		];
	}
	return $preempt;
};
add_filter( 'pre_http_request', $privacy_http_filter, 10, 3 );
$calendar_html = ( new Cemb\Frontend\Shortcodes() )->calendarList();
remove_filter( 'pre_http_request', $privacy_http_filter, 10 );
cemb_smoke_assert( false !== strpos( $calendar_html, 'Besetzt' ), 'Public calendar list shows busy status.' );
cemb_smoke_assert( false === strpos( $calendar_html, 'PRIVATE EXTERNAL TITLE' ), 'Public calendar list does not expose external event titles.' );
cemb_smoke_assert( false === strpos( $calendar_html, 'PRIVATE EXTERNAL LOCATION' ), 'Public calendar list does not expose external event locations.' );
cemb_smoke_assert( false === strpos( $calendar_html, 'PRIVATE EXTERNAL DESCRIPTION' ), 'Public calendar list does not expose external event descriptions.' );
update_option( 'cemb_settings', $settings_before_privacy_test );
delete_transient( 'cemb_ical_' . md5( $test_calendar_url ) );

$private_booking_date = date( 'Y-m-d', strtotime( 'first monday of ' . current_time( 'Y-m' ) . '-01' ) );
$private_booking_start = $private_booking_date . ' 12:00:00';
$private_booking_end = $private_booking_date . ' 12:30:00';
$private_booking_repo = new Cemb\Booking\BookingRepository();
$private_booking_id = $private_booking_repo->create(
	[
		'booking_uuid' => wp_generate_uuid4(),
		'booking_type_id' => $type_id,
		'slot_start' => $private_booking_start,
		'slot_end' => $private_booking_end,
		'status' => Cemb\Booking\BookingStatus::CONFIRMED,
		'full_name' => 'PRIVATE CUSTOMER NAME',
		'email' => 'privacy-test@example.com',
		'phone' => 'PRIVATE PHONE',
		'notes' => 'PRIVATE NOTES',
		'source' => 'ci',
		'lang' => 'en',
		'created_at' => current_time( 'mysql' ),
		'updated_at' => current_time( 'mysql' ),
	],
	[
		'gender' => 'PRIVATE GENDER',
		'last_name' => 'PRIVATE LAST NAME',
		'subject' => 'PRIVATE SUBJECT',
		'location' => 'PRIVATE BOOKING LOCATION',
	]
);
$private_month = ( new Cemb\Availability\SlotService() )->getMonthDisplay( current_time( 'Y-m' ) );
$private_month_json = wp_json_encode( $private_month );
cemb_smoke_assert( false !== strpos( $private_month_json, 'Besetzt' ), 'Public month model exposes busy status for internal bookings.' );
foreach ( [ 'PRIVATE CUSTOMER', 'PRIVATE PHONE', 'PRIVATE NOTES', 'PRIVATE GENDER', 'PRIVATE LAST NAME', 'PRIVATE SUBJECT', 'PRIVATE BOOKING LOCATION' ] as $private_marker ) {
	cemb_smoke_assert( false === strpos( $private_month_json, $private_marker ), 'Public month model does not expose ' . $private_marker . '.' );
}
$wpdb->delete( $wpdb->prefix . 'cemb_booking_meta', [ 'booking_id' => $private_booking_id ] );
$wpdb->delete( $wpdb->prefix . 'cemb_booking_status_log', [ 'booking_id' => $private_booking_id ] );
$wpdb->delete( $wpdb->prefix . 'cemb_bookings', [ 'id' => $private_booking_id ] );

WP_CLI::success( 'WordPress Calendar Booking smoke test passed on WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . '.' );
