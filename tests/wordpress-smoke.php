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

$settings_before_time_test = get_option( 'cemb_settings', [] );
$time_settings = Cemb\Admin\Settings::get();
$time_settings['timezone'] = 'Europe/Vienna';
update_option( 'cemb_settings', $time_settings );

cemb_smoke_assert( 'Europe/Vienna' === Cemb\Support\Time::bookingTimezoneName(), 'Booking domain uses the configured IANA timezone.' );
cemb_smoke_assert( '2026-01-15 08:00:00' === Cemb\Support\Time::localToUtc( '2026-01-15 09:00:00' ), 'Winter wall time converts to UTC with CET offset.' );
cemb_smoke_assert( '2026-07-15 07:00:00' === Cemb\Support\Time::localToUtc( '2026-07-15 09:00:00' ), 'Summer wall time converts to UTC with CEST offset.' );
cemb_smoke_assert( null === Cemb\Support\Time::parseLocal( '2026-03-29 02:30:00' ), 'Non-existent spring-forward wall time is rejected.' );
cemb_smoke_assert( null === Cemb\Support\Time::parseLocal( '2026-10-25 02:30:00' ), 'Ambiguous fall-back wall time is rejected.' );
cemb_smoke_assert( '2026-07-15 09:00:00' === Cemb\Support\Time::utcToLocal( '2026-07-15 07:00:00' ), 'UTC storage converts back to booking wall time.' );
cemb_smoke_assert( 'UTC' === Cemb\Admin\Settings::normalizeTimezone( 'GMT+2' ), 'Fixed/invalid timezone strings are rejected in favor of a canonical IANA fallback.' );

$recurring_ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CEMB CI//EN\r\n"
	. "BEGIN:VTIMEZONE\r\nTZID:Europe/Vienna\r\n"
	. "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nTZNAME:CET\r\nEND:STANDARD\r\n"
	. "BEGIN:DAYLIGHT\r\nDTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nTZNAME:CEST\r\nEND:DAYLIGHT\r\nEND:VTIMEZONE\r\n"
	. "BEGIN:VEVENT\r\nUID:weekly-ci\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;TZID=Europe/Vienna:20261005T090000\r\nDTEND;TZID=Europe/Vienna:20261005T093000\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\nEXDATE;TZID=Europe/Vienna:20261019T090000\r\nSUMMARY:PRIVATE SERIES\r\nEND:VEVENT\r\n"
	. "BEGIN:VEVENT\r\nUID:weekly-ci\r\nDTSTAMP:20260101T000000Z\r\nRECURRENCE-ID;TZID=Europe/Vienna:20261012T090000\r\nDTSTART;TZID=Europe/Vienna:20261012T110000\r\nDTEND;TZID=Europe/Vienna:20261012T113000\r\nSUMMARY:PRIVATE OVERRIDE\r\nEND:VEVENT\r\n"
	. "BEGIN:VEVENT\r\nUID:cancelled-ci\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20261008T090000Z\r\nDTEND:20261008T100000Z\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\n"
	. "BEGIN:VEVENT\r\nUID:allday-ci\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:20261010\r\nDTEND;VALUE=DATE:20261011\r\nSUMMARY:PRIVATE ALL DAY\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

$calendar_parser = new Cemb\Calendar\Parser();
$expanded_events = $calendar_parser->parse( $recurring_ics, '2026-10-01 00:00:00', '2026-11-05 00:00:00' );
$weekly_events = array_values( array_filter( $expanded_events, static fn( $event ) => ( $event['uid'] ?? '' ) === 'weekly-ci' ) );
cemb_smoke_assert( 3 === count( $weekly_events ), 'RRULE expands occurrences while EXDATE removes the excluded instance.' );
cemb_smoke_assert( '2026-10-05 07:00:00' === $weekly_events[0]['start'], 'Recurring CEST occurrence is normalized to UTC.' );
cemb_smoke_assert( '2026-10-12 09:00:00' === $weekly_events[1]['start'], 'RECURRENCE-ID override replaces and moves one occurrence.' );
cemb_smoke_assert( '2026-10-26 08:00:00' === $weekly_events[2]['start'], 'Recurring series follows the DST transition from CEST to CET.' );
cemb_smoke_assert(
	0 === count( array_filter( $expanded_events, static fn( $event ) => ( $event['uid'] ?? '' ) === 'cancelled-ci' ) ),
	'Cancelled events do not block availability.'
);
$all_day_events = array_values( array_filter( $expanded_events, static fn( $event ) => ( $event['uid'] ?? '' ) === 'allday-ci' ) );
cemb_smoke_assert( 1 === count( $all_day_events ) && ! empty( $all_day_events[0]['all_day'] ), 'All-day events retain all-day semantics.' );
cemb_smoke_assert( '2026-10-09 22:00:00' === $all_day_events[0]['start'], 'All-day local date is normalized to the correct UTC boundary.' );

global $wpdb;
$migration_booking_id = 0;
$wpdb->insert(
	$wpdb->prefix . 'cemb_bookings',
	[
		'booking_uuid' => wp_generate_uuid4(),
		'booking_type_id' => 1,
		'slot_start' => '2026-01-15 09:00:00',
		'slot_end' => '2026-01-15 10:00:00',
		'status' => Cemb\Booking\BookingStatus::CANCELLED,
		'full_name' => 'Time Migration Test',
		'email' => 'time-migration@example.com',
		'source' => 'ci',
		'lang' => 'en',
		'created_at' => '2026-01-01 12:00:00',
		'updated_at' => '2026-01-01 12:00:00',
	]
);
$migration_booking_id = (int) $wpdb->insert_id;
update_option( 'cemb_time_storage_version', 0, false );
Cemb\Support\TimeMigration::maybeRun();
$migrated_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cemb_bookings WHERE id = %d", $migration_booking_id ) );
cemb_smoke_assert( '2026-01-15 08:00:00' === $migrated_booking->slot_start, 'Legacy local booking start is migrated to the same UTC instant.' );
cemb_smoke_assert( '2026-01-15 09:00:00' === $migrated_booking->slot_end, 'Legacy local booking end is migrated to the same UTC instant.' );
$migrated_start_once = $migrated_booking->slot_start;
Cemb\Support\TimeMigration::maybeRun();
$migrated_booking_again = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cemb_bookings WHERE id = %d", $migration_booking_id ) );
cemb_smoke_assert( $migrated_start_once === $migrated_booking_again->slot_start, 'UTC storage migration is idempotent.' );
$wpdb->delete( $wpdb->prefix . 'cemb_booking_status_log', [ 'booking_id' => $migration_booking_id ] );
$wpdb->delete( $wpdb->prefix . 'cemb_bookings', [ 'id' => $migration_booking_id ] );
update_option( 'cemb_settings', $settings_before_time_test );

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

/* Authenticated calendar/provider secret storage. */
$secret_settings_backup = get_option( 'cemb_settings', [] );
$secret_version_backup = get_option( 'cemb_secret_storage_version', null );
$secret_reentry_backup = get_option( 'cemb_secret_reentry_required', null );
$secret_plaintext = 'PRIVATE-CALENDAR-PASSWORD-ci-42';

$secret_box = new Cemb\Security\SecretBox();
cemb_smoke_assert( $secret_box->available(), 'At least one authenticated secret-storage backend is available in WordPress CI.' );
$encrypted_secret = $secret_box->encrypt( $secret_plaintext );
cemb_smoke_assert( is_string( $encrypted_secret ) && 0 === strpos( $encrypted_secret, 'v2:' ), 'Provider secret ciphertext is explicitly versioned.' );
cemb_smoke_assert( false === strpos( $encrypted_secret, $secret_plaintext ), 'Provider ciphertext does not contain the raw secret.' );
cemb_smoke_assert( $secret_plaintext === $secret_box->decrypt( $encrypted_secret ), 'Authenticated provider ciphertext decrypts correctly.' );

$encrypted_parts = explode( ':', $encrypted_secret );
$last_part_index = count( $encrypted_parts ) - 1;
$tampered_part = $encrypted_parts[$last_part_index];
$tamper_position = min( 5, strlen( $tampered_part ) - 1 );
$tampered_part[$tamper_position] = $tampered_part[$tamper_position] === 'A' ? 'B' : 'A';
$encrypted_parts[$last_part_index] = $tampered_part;
$tampered_secret = implode( ':', $encrypted_parts );
cemb_smoke_assert( null === $secret_box->decrypt( $tampered_secret ), 'Tampered provider ciphertext fails closed.' );

$force_aesgcm = static fn() => 'aesgcm';
add_filter( 'cemb_secret_storage_backend', $force_aesgcm );
$aes_box = new Cemb\Security\SecretBox();
if ( $aes_box->backend() === 'aesgcm' ) {
	$aes_ciphertext = $aes_box->encrypt( $secret_plaintext );
	cemb_smoke_assert(
		is_string( $aes_ciphertext )
		&& 0 === strpos( $aes_ciphertext, 'v2:aesgcm:' )
		&& $secret_plaintext === $aes_box->decrypt( $aes_ciphertext ),
		'AES-256-GCM authenticated fallback round-trips when available.'
	);
}
remove_filter( 'cemb_secret_storage_backend', $force_aesgcm );

$secure_update = Cemb\Admin\Settings::update(
	[
		'icloud_sync_password' => $secret_plaintext,
		'icloud_sync_enabled' => 1,
	]
);
cemb_smoke_assert( true === $secure_update, 'Settings accept a provider secret only after authenticated encryption.' );
$secure_settings = Cemb\Admin\Settings::get();
$stored_ciphertext = (string) $secure_settings['icloud_sync_password_enc'];
cemb_smoke_assert(
	0 === strpos( $stored_ciphertext, 'v2:' )
	&& false === strpos( wp_json_encode( $secure_settings ), $secret_plaintext ),
	'WordPress options contain ciphertext but never the raw provider secret.'
);
cemb_smoke_assert(
	$secret_plaintext === Cemb\Admin\Settings::getIcloudSyncPassword(),
	'Provider client can recover an authenticated stored credential.'
);
cemb_smoke_assert(
	'stored' === Cemb\Admin\Settings::secretStatus()['state'],
	'Admin secret diagnostics report valid authenticated storage without revealing the secret.'
);

ob_start();
( new Cemb\Admin\Admin() )->settings();
$settings_html = (string) ob_get_clean();
cemb_smoke_assert( false === strpos( $settings_html, $secret_plaintext ), 'Settings HTML never renders provider plaintext.' );
cemb_smoke_assert( false === strpos( $settings_html, $stored_ciphertext ), 'Settings HTML never renders provider ciphertext.' );
cemb_smoke_assert(
	false !== strpos( $settings_html, 'name="icloud_sync_password" value=""' ),
	'Credential input is always blank in rendered settings HTML.'
);

$previous_user_id = get_current_user_id();
wp_set_current_user( 1 );
$rest_settings_response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );
$rest_settings_json = wp_json_encode( $rest_settings_response->get_data() );
cemb_smoke_assert(
	200 === $rest_settings_response->get_status()
	&& false === strpos( $rest_settings_json, $secret_plaintext )
	&& false === strpos( $rest_settings_json, $stored_ciphertext ),
	'Provider plaintext and ciphertext are not exposed through WordPress REST settings.'
);
wp_set_current_user( $previous_user_id );

$no_crypto_filter = static fn() => '';
add_filter( 'cemb_secret_storage_backend', $no_crypto_filter );
$settings_before_failed_secret_save = Cemb\Admin\Settings::get();
$no_crypto_result = Cemb\Admin\Settings::update(
	[
		'sender_name' => 'MUST-NOT-BE-SAVED',
		'icloud_sync_password' => 'MUST-NOT-BE-STORED',
	]
);
remove_filter( 'cemb_secret_storage_backend', $no_crypto_filter );
cemb_smoke_assert(
	is_wp_error( $no_crypto_result )
	&& 'cemb_secret_crypto_unavailable' === $no_crypto_result->get_error_code(),
	'Missing authenticated crypto support rejects secret storage with a clear error.'
);
$settings_after_failed_secret_save = Cemb\Admin\Settings::get();
cemb_smoke_assert(
	$settings_before_failed_secret_save['sender_name'] === $settings_after_failed_secret_save['sender_name']
	&& $stored_ciphertext === $settings_after_failed_secret_save['icloud_sync_password_enc'],
	'Failed secret encryption leaves all settings unchanged.'
);

$tampered_settings = $settings_after_failed_secret_save;
$tampered_settings['icloud_sync_password_enc'] = $tampered_secret;
update_option( 'cemb_settings', $tampered_settings );
cemb_smoke_assert( '' === Cemb\Admin\Settings::getIcloudSyncPassword(), 'Tampered stored credential never yields plaintext.' );
cemb_smoke_assert( 'invalid' === Cemb\Admin\Settings::secretStatus()['state'], 'Tampered stored credential is visible only as an invalid diagnostic state.' );

$legacy_settings = $settings_after_failed_secret_save;
$legacy_settings['icloud_sync_password_enc'] = base64_encode( 'legacy-unauthenticated-secret' );
$legacy_settings['icloud_sync_enabled'] = 1;
update_option( 'cemb_settings', $legacy_settings );
delete_option( 'cemb_secret_reentry_required' );
update_option( 'cemb_secret_storage_version', 0, false );
Cemb\Security\SecretMigration::maybeRun();
$migrated_secret_settings = Cemb\Admin\Settings::get();
cemb_smoke_assert(
	'' === $migrated_secret_settings['icloud_sync_password_enc']
	&& empty( $migrated_secret_settings['icloud_sync_enabled'] ),
	'Legacy unauthenticated credential is revoked and write-back is disabled.'
);
cemb_smoke_assert(
	1 === (int) get_option( 'cemb_secret_reentry_required', 0 )
	&& 'reentry' === Cemb\Admin\Settings::secretStatus()['state'],
	'Legacy credential migration explicitly requires administrator re-entry.'
);
cemb_smoke_assert(
	Cemb\Security\SecretMigration::currentVersion() === (int) get_option( 'cemb_secret_storage_version', 0 ),
	'Authenticated secret-storage migration is recorded.'
);

$reentry_result = Cemb\Admin\Settings::update(
	[
		'icloud_sync_password' => $secret_plaintext,
		'icloud_sync_enabled' => 1,
	]
);
cemb_smoke_assert(
	true === $reentry_result
	&& false === get_option( 'cemb_secret_reentry_required', false )
	&& $secret_plaintext === Cemb\Admin\Settings::getIcloudSyncPassword(),
	'Re-entering the credential clears the legacy warning and stores it authentically.'
);

update_option( 'cemb_settings', $secret_settings_backup );
if ( $secret_version_backup === null ) {
	delete_option( 'cemb_secret_storage_version' );
} else {
	update_option( 'cemb_secret_storage_version', $secret_version_backup, false );
}
if ( $secret_reentry_backup === null ) {
	delete_option( 'cemb_secret_reentry_required' );
} else {
	update_option( 'cemb_secret_reentry_required', $secret_reentry_backup, false );
}

/* Indexed selector/verifier one-time-token storage. */
$token_table = $wpdb->prefix . 'cemb_tokens';

/* Simulate an existing pre-selector install and prove the code upgrade repairs it. */
$wpdb->query( "ALTER TABLE {$token_table} DROP INDEX token_selector, DROP COLUMN token_selector" );
update_option( 'cemb_schema_version', 1, false );
Cemb\Database\SchemaMigration::maybeRun();

$selector_column = $wpdb->get_row(
	$wpdb->prepare( "SHOW COLUMNS FROM {$token_table} LIKE %s", 'token_selector' )
);
cemb_smoke_assert( $selector_column && 'token_selector' === $selector_column->Field, 'Token schema includes the indexed selector column.' );
$selector_index = $wpdb->get_row( "SHOW INDEX FROM {$token_table} WHERE Key_name = 'token_selector'" );
cemb_smoke_assert(
	$selector_index && 0 === (int) $selector_index->Non_unique,
	'Token selector uses a unique database index.'
);
cemb_smoke_assert(
	Cemb\Database\SchemaMigration::currentVersion() === (int) get_option( 'cemb_schema_version', 0 ),
	'Current database schema migration is recorded.'
);

$one_time_tokens = new Cemb\Tokens\TokenService();
$token_fixture_booking_id = 987654;
$indexed_token = $one_time_tokens->create( $token_fixture_booking_id, 'ci_indexed', 60 );
cemb_smoke_assert(
	1 === preg_match( '/^[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}$/', $indexed_token ),
	'One-time token uses selector.verifier format with high-entropy components.'
);
[ $selector, $verifier ] = explode( '.', $indexed_token, 2 );
$stored_token = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$token_table} WHERE token_selector = %s AND token_type = %s LIMIT 1",
		$selector,
		'ci_indexed'
	)
);
cemb_smoke_assert( $stored_token && $selector === $stored_token->token_selector, 'Selector is stored for direct indexed lookup.' );
cemb_smoke_assert(
	64 === strlen( (string) $stored_token->token_hash )
	&& false === strpos( wp_json_encode( $stored_token ), $verifier ),
	'Raw verifier secret is never stored; only a fixed-length HMAC is persisted.'
);
cemb_smoke_assert(
	'valid' === $one_time_tokens->inspect( $indexed_token, 'ci_indexed' )['state'],
	'Indexed selector/verifier token validates successfully.'
);
$tamper_pos = 5;
$tampered_verifier = substr( $verifier, 0, $tamper_pos )
	. ( $verifier[$tamper_pos] === 'A' ? 'B' : 'A' )
	. substr( $verifier, $tamper_pos + 1 );
cemb_smoke_assert(
	'invalid' === $one_time_tokens->inspect( $selector . '.' . $tampered_verifier, 'ci_indexed' )['state'],
	'Verifier tampering is rejected.'
);
cemb_smoke_assert(
	'invalid' === $one_time_tokens->inspect( $indexed_token, 'ci_other_type' )['state'],
	'One-time token is bound to its token type.'
);

$rotated_token = $one_time_tokens->rotate( $token_fixture_booking_id, 'ci_indexed', 60 );
cemb_smoke_assert(
	'used' === $one_time_tokens->inspect( $indexed_token, 'ci_indexed' )['state'],
	'Rotation revokes the previous token.'
);
cemb_smoke_assert(
	'valid' === $one_time_tokens->inspect( $rotated_token, 'ci_indexed' )['state'],
	'Rotation issues a fresh indexed token.'
);
cemb_smoke_assert(
	1 === $one_time_tokens->revokeForBooking( $token_fixture_booking_id, 'ci_indexed' ),
	'Explicit revocation marks an active booking token used.'
);
cemb_smoke_assert(
	'used' === $one_time_tokens->inspect( $rotated_token, 'ci_indexed' )['state'],
	'Revoked token renders as used rather than remaining valid.'
);

$retained_expired_token = $one_time_tokens->create( $token_fixture_booking_id, 'ci_expired', 60 );
[ $retained_selector ] = explode( '.', $retained_expired_token, 2 );
$wpdb->update(
	$token_table,
	[ 'expires_at' => Cemb\Support\Time::formatUtc( Cemb\Support\Time::nowUtc()->modify( '-5 minutes' ) ) ],
	[ 'token_selector' => $retained_selector ]
);
cemb_smoke_assert(
	'expired' === $one_time_tokens->inspect( $retained_expired_token, 'ci_expired' )['state'],
	'Expired indexed token remains inspectable as expired.'
);
$one_time_tokens->cleanup( 30 );
cemb_smoke_assert(
	1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$token_table} WHERE token_selector = %s", $retained_selector ) ),
	'Recent expired tokens are retained for non-destructive status pages.'
);
$wpdb->update(
	$token_table,
	[ 'expires_at' => Cemb\Support\Time::formatUtc( Cemb\Support\Time::nowUtc()->modify( '-31 days' ) ) ],
	[ 'token_selector' => $retained_selector ]
);
cemb_smoke_assert(
	$one_time_tokens->cleanup( 30 ) >= 1
	&& 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$token_table} WHERE token_selector = %s", $retained_selector ) ),
	'Retention cleanup removes old expired tokens.'
);

$legacy_token_secret = 'legacy-ci-token-secret';
$wpdb->insert(
	$token_table,
	[
		'booking_id' => $token_fixture_booking_id,
		'token_type' => 'legacy_ci',
		'token_selector' => null,
		'token_hash' => wp_hash_password( $legacy_token_secret ),
		'expires_at' => Cemb\Support\Time::formatUtc( Cemb\Support\Time::nowUtc()->modify( '+1 hour' ) ),
		'used_at' => null,
		'created_at' => Cemb\Support\Time::formatUtc( Cemb\Support\Time::nowUtc() ),
	]
);
$legacy_token_id = (int) $wpdb->insert_id;
update_option( 'cemb_token_storage_version', 0, false );
Cemb\Tokens\TokenMigration::maybeRun();
$legacy_token_row = $wpdb->get_row(
	$wpdb->prepare( "SELECT * FROM {$token_table} WHERE id = %d", $legacy_token_id )
);
cemb_smoke_assert(
	$legacy_token_row && ! empty( $legacy_token_row->used_at ),
	'Legacy unindexed tokens are explicitly revoked during migration.'
);
cemb_smoke_assert(
	'invalid' === $one_time_tokens->inspect( $legacy_token_secret, 'legacy_ci' )['state'],
	'Legacy raw-token format is not kept through an O(n) compatibility scan.'
);
cemb_smoke_assert(
	(int) get_option( 'cemb_legacy_tokens_revoked', 0 ) >= 1,
	'Legacy-token revocation count is recorded for diagnostics.'
);
cemb_smoke_assert(
	Cemb\Tokens\TokenMigration::currentVersion() === (int) get_option( 'cemb_token_storage_version', 0 ),
	'Indexed token storage migration is recorded.'
);
$wpdb->delete( $token_table, [ 'booking_id' => $token_fixture_booking_id ] );

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

/* Booking state machine, legacy migration and audit coverage. */
$legacy_now = Cemb\Support\Time::formatUtc( Cemb\Support\Time::nowUtc() );
$wpdb->insert(
	$wpdb->prefix . 'cemb_bookings',
	[
		'booking_uuid' => wp_generate_uuid4(),
		'booking_type_id' => $type_id,
		'slot_start' => '2032-01-15 08:00:00',
		'slot_end' => '2032-01-15 08:30:00',
		'status' => 'updated',
		'email' => 'legacy-status@example.com',
		'source' => 'ci',
		'lang' => 'en',
		'created_at' => $legacy_now,
		'updated_at' => $legacy_now,
	]
);
$legacy_status_booking_id = (int) $wpdb->insert_id;
$wpdb->insert(
	$wpdb->prefix . 'cemb_booking_status_log',
	[
		'booking_id' => $legacy_status_booking_id,
		'old_status' => 'pending_admin_approval',
		'new_status' => 'updated',
		'context' => 'legacy_test',
		'changed_by' => 'system',
		'note' => 'Legacy status fixture',
		'created_at' => $legacy_now,
	]
);
$legacy_log_id = (int) $wpdb->insert_id;
update_option( 'cemb_booking_status_version', 0, false );
Cemb\Booking\BookingStatusMigration::maybeRun();
$legacy_booking_status = (string) $wpdb->get_var(
	$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cemb_bookings WHERE id = %d", $legacy_status_booking_id )
);
$legacy_log = $wpdb->get_row(
	$wpdb->prepare( "SELECT old_status, new_status FROM {$wpdb->prefix}cemb_booking_status_log WHERE id = %d", $legacy_log_id )
);
cemb_smoke_assert( Cemb\Booking\BookingStatus::CONFIRMED === $legacy_booking_status, 'Legacy updated booking status migrates to confirmed.' );
cemb_smoke_assert(
	Cemb\Booking\BookingStatus::PENDING_APPROVAL === $legacy_log->old_status
	&& Cemb\Booking\BookingStatus::CONFIRMED === $legacy_log->new_status,
	'Legacy audit status values migrate to canonical lifecycle states.'
);
$wpdb->delete( $wpdb->prefix . 'cemb_booking_status_log', [ 'booking_id' => $legacy_status_booking_id ] );
$wpdb->delete( $wpdb->prefix . 'cemb_bookings', [ 'id' => $legacy_status_booking_id ] );

$machine = new Cemb\Booking\BookingStateMachine();
cemb_smoke_assert(
	[] === $machine->adminEventsFor( Cemb\Booking\BookingStatus::RESERVED_UNCONFIRMED ),
	'Admin cannot bypass Double Opt-In for an unconfirmed reservation.'
);
$pending_admin_events = $machine->adminEventsFor( Cemb\Booking\BookingStatus::PENDING_APPROVAL );
cemb_smoke_assert(
	isset(
		$pending_admin_events[Cemb\Booking\BookingStateMachine::ADMIN_APPROVED],
		$pending_admin_events[Cemb\Booking\BookingStateMachine::ADMIN_REJECTED],
		$pending_admin_events[Cemb\Booking\BookingStateMachine::ADMIN_CANCELLED]
	),
	'Pending approval exposes only legal admin lifecycle actions.'
);
cemb_smoke_assert(
	false !== has_action( 'cemb_booking_transitioned' ),
	'Mail/calendar transition effects are subscribed to lifecycle transitions.'
);
cemb_smoke_assert(
	false !== has_action( 'cemb_booking_event_recorded' ),
	'Reschedule effects are subscribed to lifecycle events.'
);

/* Disable outbound side effects for the state-machine integration fixture itself. */
remove_all_actions( 'cemb_booking_transitioned' );
remove_all_actions( 'cemb_booking_event_recorded' );

$lifecycle_booking_id = ( new Cemb\Booking\ReservationService() )->reserve(
	$token,
	$type_id,
	[
		'full_name' => 'Lifecycle Fixture',
		'email' => 'lifecycle@example.com',
		'phone' => '',
		'notes' => '',
		'source' => 'ci',
		'lang' => 'en',
	],
	[]
);
cemb_smoke_assert( ! is_wp_error( $lifecycle_booking_id ) && (int) $lifecycle_booking_id > 0, 'Lifecycle fixture creates a real reserved booking.' );
$lifecycle_booking_id = (int) $lifecycle_booking_id;
$lifecycle_repo = new Cemb\Booking\BookingRepository();
$lifecycle_service = new Cemb\Booking\BookingTransitionService();
$lifecycle_booking = $lifecycle_repo->find( $lifecycle_booking_id );
cemb_smoke_assert(
	Cemb\Booking\BookingStatus::RESERVED_UNCONFIRMED === $lifecycle_booking->status,
	'New bookings enter reserved_unconfirmed.'
);

$doi_transition = $lifecycle_service->apply(
	$lifecycle_booking_id,
	Cemb\Booking\BookingStateMachine::EMAIL_CONFIRMED_APPROVAL,
	'user',
	'DOI fixture'
);
cemb_smoke_assert( is_array( $doi_transition ) && ! empty( $doi_transition['changed'] ), 'Double Opt-In moves reserved booking to pending approval.' );
cemb_smoke_assert(
	Cemb\Booking\BookingStatus::PENDING_APPROVAL === $lifecycle_repo->find( $lifecycle_booking_id )->status,
	'Pending approval is persisted.'
);
$direct_status_write_blocked = false;
try {
	$lifecycle_repo->update( $lifecycle_booking_id, [ 'status' => Cemb\Booking\BookingStatus::CONFIRMED ] );
} catch ( InvalidArgumentException $error ) {
	$direct_status_write_blocked = true;
}
cemb_smoke_assert( $direct_status_write_blocked, 'Generic repository updates cannot bypass the booking state machine.' );

$doi_retry = $lifecycle_service->apply(
	$lifecycle_booking_id,
	Cemb\Booking\BookingStateMachine::EMAIL_CONFIRMED_APPROVAL,
	'user',
	'DOI retry fixture'
);
cemb_smoke_assert( is_array( $doi_retry ) && empty( $doi_retry['changed'] ), 'Repeating an already-applied lifecycle event is idempotent.' );

$illegal_transition = $lifecycle_service->apply(
	$lifecycle_booking_id,
	Cemb\Booking\BookingStateMachine::EMAIL_CONFIRMED_AUTOMATIC,
	'user',
	'Illegal fixture'
);
cemb_smoke_assert( is_wp_error( $illegal_transition ), 'An illegal lifecycle transition is rejected.' );

$lifecycle_booking = $lifecycle_repo->find( $lifecycle_booking_id );
$conflict_id = $lifecycle_repo->create(
	[
		'booking_uuid' => wp_generate_uuid4(),
		'booking_type_id' => $type_id,
		'slot_start' => (string) $lifecycle_booking->slot_start,
		'slot_end' => (string) $lifecycle_booking->slot_end,
		'status' => Cemb\Booking\BookingStatus::CONFIRMED,
		'full_name' => 'Conflict Fixture',
		'email' => 'conflict@example.com',
		'source' => 'ci',
		'lang' => 'en',
		'created_at' => $legacy_now,
		'updated_at' => $legacy_now,
	],
	[]
);
$blocked_approval = $lifecycle_service->apply(
	$lifecycle_booking_id,
	Cemb\Booking\BookingStateMachine::ADMIN_APPROVED,
	'admin',
	'Approval conflict fixture'
);
cemb_smoke_assert(
	is_wp_error( $blocked_approval ) && 'cemb_slot_unavailable' === $blocked_approval->get_error_code(),
	'Admin approval revalidates availability and refuses a newly conflicting slot.'
);
$wpdb->delete( $wpdb->prefix . 'cemb_booking_status_log', [ 'booking_id' => $conflict_id ] );
$wpdb->delete( $wpdb->prefix . 'cemb_booking_meta', [ 'booking_id' => $conflict_id ] );
$wpdb->delete( $wpdb->prefix . 'cemb_bookings', [ 'id' => $conflict_id ] );

$approval = $lifecycle_service->apply(
	$lifecycle_booking_id,
	Cemb\Booking\BookingStateMachine::ADMIN_APPROVED,
	'admin',
	'Approval fixture'
);
cemb_smoke_assert( is_array( $approval ) && ! empty( $approval['changed'] ), 'Admin approval succeeds after the conflict is removed.' );
cemb_smoke_assert(
	Cemb\Booking\BookingStatus::CONFIRMED === $lifecycle_repo->find( $lifecycle_booking_id )->status,
	'Approved booking becomes confirmed.'
);
$approval_retry = $lifecycle_service->apply(
	$lifecycle_booking_id,
	Cemb\Booking\BookingStateMachine::ADMIN_APPROVED,
	'admin',
	'Approval retry fixture'
);
cemb_smoke_assert( is_array( $approval_retry ) && empty( $approval_retry['changed'] ), 'Repeated admin approval is idempotent.' );

$alternative_slots = $slot_service->getSlots( $type_id, 21, $lifecycle_booking_id );
$current_lifecycle = $lifecycle_repo->find( $lifecycle_booking_id );
$alternative_slot = null;
foreach ( $alternative_slots as $candidate ) {
	if ( $candidate['start'] !== $current_lifecycle->slot_start ) {
		$alternative_slot = $candidate;
		break;
	}
}
cemb_smoke_assert( is_array( $alternative_slot ), 'At least one alternate canonical slot is available for reschedule testing.' );
$reschedule = $lifecycle_service->reschedule(
	$lifecycle_booking_id,
	$alternative_slot['start'],
	$alternative_slot['end'],
	'user',
	'Reschedule fixture'
);
cemb_smoke_assert( is_array( $reschedule ) && ! empty( $reschedule['changed'] ), 'Rescheduling is recorded as a lifecycle event.' );
$rescheduled_booking = $lifecycle_repo->find( $lifecycle_booking_id );
cemb_smoke_assert(
	Cemb\Booking\BookingStatus::CONFIRMED === $rescheduled_booking->status,
	'Rescheduling does not invent a separate updated status.'
);
cemb_smoke_assert(
	$alternative_slot['start'] === $rescheduled_booking->slot_start,
	'Reschedule atomically updates the canonical slot.'
);

$cancel = $lifecycle_service->apply(
	$lifecycle_booking_id,
	Cemb\Booking\BookingStateMachine::USER_CANCELLED,
	'user',
	'Cancel fixture'
);
cemb_smoke_assert( is_array( $cancel ) && ! empty( $cancel['changed'] ), 'A confirmed booking can transition to cancelled.' );
$cancel_retry = $lifecycle_service->apply(
	$lifecycle_booking_id,
	Cemb\Booking\BookingStateMachine::USER_CANCELLED,
	'user',
	'Cancel retry fixture'
);
cemb_smoke_assert( is_array( $cancel_retry ) && empty( $cancel_retry['changed'] ), 'Repeated cancellation is idempotent.' );
$post_cancel_approval = $lifecycle_service->apply(
	$lifecycle_booking_id,
	Cemb\Booking\BookingStateMachine::ADMIN_APPROVED,
	'admin',
	'Illegal post-cancel fixture'
);
cemb_smoke_assert( is_wp_error( $post_cancel_approval ), 'Terminal cancelled state rejects later approval.' );

$lifecycle_logs = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT old_status, new_status, context, changed_by, note FROM {$wpdb->prefix}cemb_booking_status_log WHERE booking_id = %d ORDER BY id ASC",
		$lifecycle_booking_id
	)
);
$lifecycle_log_json = wp_json_encode( $lifecycle_logs );
cemb_smoke_assert( false !== strpos( $lifecycle_log_json, Cemb\Booking\BookingStateMachine::ADMIN_APPROVED ), 'Audit history records semantic transition events.' );
cemb_smoke_assert( false !== strpos( $lifecycle_log_json, Cemb\Booking\BookingTransitionService::RESCHEDULED ), 'Audit history records reschedule without changing state.' );
cemb_smoke_assert( false === strpos( $lifecycle_log_json, 'lifecycle@example.com' ), 'Audit transition history does not copy customer email addresses.' );
cemb_smoke_assert( false === strpos( $lifecycle_log_json, 'Lifecycle Fixture' ), 'Audit transition history does not copy customer names.' );

$wpdb->delete( $wpdb->prefix . 'cemb_booking_meta', [ 'booking_id' => $lifecycle_booking_id ] );
$wpdb->delete( $wpdb->prefix . 'cemb_booking_status_log', [ 'booking_id' => $lifecycle_booking_id ] );
$wpdb->delete( $wpdb->prefix . 'cemb_bookings', [ 'id' => $lifecycle_booking_id ] );

$expired_fixture_id = $lifecycle_repo->create(
	[
		'booking_uuid' => wp_generate_uuid4(),
		'booking_type_id' => $type_id,
		'slot_start' => '2031-02-01 09:00:00',
		'slot_end' => '2031-02-01 09:30:00',
		'status' => Cemb\Booking\BookingStatus::RESERVED_UNCONFIRMED,
		'full_name' => 'Expiry Fixture',
		'email' => 'expiry@example.com',
		'source' => 'ci',
		'lang' => 'en',
		'reserved_until' => Cemb\Support\Time::formatUtc( Cemb\Support\Time::nowUtc()->modify( '-5 minutes' ) ),
		'created_at' => $legacy_now,
		'updated_at' => $legacy_now,
	],
	[]
);
$expired_count = $lifecycle_service->expireReservations();
cemb_smoke_assert( $expired_count >= 1, 'Hourly lifecycle sweep expires stale unconfirmed reservations.' );
cemb_smoke_assert(
	Cemb\Booking\BookingStatus::EXPIRED === $lifecycle_repo->find( $expired_fixture_id )->status,
	'Expired reservation persists the terminal expired state.'
);
$wpdb->delete( $wpdb->prefix . 'cemb_booking_status_log', [ 'booking_id' => $expired_fixture_id ] );
$wpdb->delete( $wpdb->prefix . 'cemb_booking_meta', [ 'booking_id' => $expired_fixture_id ] );
$wpdb->delete( $wpdb->prefix . 'cemb_bookings', [ 'id' => $expired_fixture_id ] );


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
