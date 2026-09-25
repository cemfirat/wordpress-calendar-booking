<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_schema_recovery_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

final class WpcbSchemaDenyDdlWpdb extends wpdb {
    public array $blockedStatements = [];

    public function query($query) {
        $sql = ltrim((string)$query);
        if (preg_match('/^(CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i', $sql)) {
            $this->blockedStatements[] = strtoupper((string)strtok($sql, " \t\r\n"));
            $this->last_error = 'CI injected DDL denial';
            return false;
        }
        return parent::query($query);
    }
}

global $wpdb;

wpcb_schema_recovery_assert(
    Wpcb\Database\SchemaMigration::isReady(),
    'Schema starts from a verified current state.'
);

$payments = $wpdb->prefix . 'wpcb_payments';
$videoMeetings = $wpdb->prefix . 'wpcb_video_meetings';
$bookingTypes = $wpdb->prefix . 'wpcb_booking_types';
$mapping = $wpdb->prefix . 'wpcb_booking_type_resources';

$typeId = (int)$wpdb->get_var("SELECT id FROM {$bookingTypes} ORDER BY id ASC LIMIT 1");
$resourceId = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT resource_id FROM {$mapping} WHERE booking_type_id = %d ORDER BY resource_id ASC LIMIT 1",
    $typeId
));
wpcb_schema_recovery_assert($typeId > 0 && $resourceId > 0, 'Existing populated 3.x scheduling data is available for preservation checks.');

$repo = new Wpcb\Booking\BookingRepository();
$now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
$bookingId = $repo->create([
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $typeId,
    'resource_id' => $resourceId,
    'slot_start' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+180 days')),
    'slot_end' => Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc()->modify('+180 days +30 minutes')),
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'full_name' => 'Schema Recovery Fixture',
    'email' => 'schema-recovery@example.invalid',
    'source' => 'ci',
    'lang' => 'en',
    'created_at' => $now,
    'updated_at' => $now,
], ['subject' => 'SCHEMA-PRESERVE']);
wpcb_schema_recovery_assert($bookingId > 0, 'Synthetic existing booking is created before migration failure.');
$bookingBefore = (array)$repo->find($bookingId);
$metaBefore = $repo->getMeta($bookingId);
$settingsBefore = get_option('wpcb_settings', []);

$indexExists = (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = '" . esc_sql($payments) . "'
       AND index_name = 'provider_reference'"
);
wpcb_schema_recovery_assert($indexExists > 0, 'Required payments provider_reference index exists before fault injection.');
wpcb_schema_recovery_assert(
    0 === (int)$wpdb->get_var("SELECT COUNT(*) FROM {$videoMeetings}"),
    'Video-meeting table is empty before the destructive CI-only schema fault.'
);

wpcb_schema_recovery_assert(
    false !== $wpdb->query("ALTER TABLE {$payments} DROP INDEX provider_reference"),
    'CI fixture removes one required index.'
);
wpcb_schema_recovery_assert(
    false !== $wpdb->query("DROP TABLE {$videoMeetings}"),
    'CI fixture removes one empty required table.'
);

update_option('wpcb_schema_version', Wpcb\Database\SchemaMigration::currentVersion(), false);
delete_option('wpcb_schema_verified_version');
delete_option('wpcb_schema_migration_error');

$rootWpdb = $wpdb;
$deniedWpdb = new WpcbSchemaDenyDdlWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$deniedWpdb->set_prefix($rootWpdb->prefix);
$deniedWpdb->suppress_errors(true);
$GLOBALS['wpdb'] = $deniedWpdb;
$wpdb = $deniedWpdb;
wp_cache_flush();

$failed = Wpcb\Database\SchemaMigration::maybeRun();
wpcb_schema_recovery_assert($failed === false, 'Migration does not report success when required DDL is denied.');
wpcb_schema_recovery_assert(!Wpcb\Database\SchemaMigration::isReady(), 'Incomplete schema is not marked verified.');
wpcb_schema_recovery_assert(
    in_array('CREATE', $deniedWpdb->blockedStatements, true)
        && in_array('ALTER', $deniedWpdb->blockedStatements, true),
    'Fault injector proves both CREATE and ALTER were denied.'
);

$failure = Wpcb\Database\SchemaMigration::lastFailure();
wpcb_schema_recovery_assert(
    ($failure['code'] ?? '') === 'schema_incomplete' || ($failure['code'] ?? '') === 'migration_exception',
    'Failure stores only a bounded migration failure code.'
);

$readiness = (new Wpcb\Admin\SetupReadiness())->snapshot();
wpcb_schema_recovery_assert(
    empty($readiness['ready'])
        && ($readiness['items'][0]['key'] ?? '') === 'schema',
    'Setup readiness fails closed without querying missing booking tables.'
);

wp_set_current_user(1);
ob_start();
Wpcb\Database\SchemaMigration::renderAdminNotice();
$notice = (string)ob_get_clean();
wpcb_schema_recovery_assert(strpos($notice, 'notice-error') !== false, 'Administrators receive an actionable error notice.');
wpcb_schema_recovery_assert(stripos($notice, 'ALTER TABLE') === false, 'Administrator notice contains no raw SQL.');
wpcb_schema_recovery_assert(stripos($notice, 'CI injected DDL denial') === false, 'Administrator notice contains no raw database error.');

$GLOBALS['wpdb'] = $rootWpdb;
$wpdb = $rootWpdb;
wp_cache_flush();

wpcb_schema_recovery_assert(
    Wpcb\Database\SchemaMigration::maybeRun(),
    'Migration safely retries after DDL is available again.'
);
wpcb_schema_recovery_assert(
    Wpcb\Database\SchemaMigration::isReady(),
    'Schema is marked current only after structural verification succeeds.'
);

$verification = Wpcb\Database\SchemaVerifier::verify();
wpcb_schema_recovery_assert(!empty($verification['ready']), 'Recovered schema contains every required table, column and index.');

$repairedIndex = (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = '" . esc_sql($payments) . "'
       AND index_name = 'provider_reference'"
);
wpcb_schema_recovery_assert($repairedIndex > 0, 'Retry recreates the missing required index.');
wpcb_schema_recovery_assert(
    $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $videoMeetings)) === $videoMeetings,
    'Retry recreates the missing required table.'
);

$bookingAfter = (array)$repo->find($bookingId);
$metaAfter = $repo->getMeta($bookingId);
wpcb_schema_recovery_assert($bookingAfter === $bookingBefore, 'Existing booking row survives failed and retried schema work.');
wpcb_schema_recovery_assert($metaAfter === $metaBefore, 'Existing booking metadata survives failed and retried schema work.');
wpcb_schema_recovery_assert(get_option('wpcb_settings', []) === $settingsBefore, 'Existing settings survive failed and retried schema work.');

$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);

WP_CLI::success('Schema migration failure/retry recovery passed.');
