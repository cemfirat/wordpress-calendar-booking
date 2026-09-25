<?php
if (!defined('ABSPATH')) {
    exit(1);
}

function wpcb_seed_recovery_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

final class WpcbSeedFaultWpdb extends wpdb {
    public int $bookingTypeInserts = 0;

    public function insert($table, $data, $format = null) {
        if ($table === $this->prefix . 'wpcb_booking_types') {
            ++$this->bookingTypeInserts;
            if ($this->bookingTypeInserts === 2) {
                $this->last_error = 'CI injected default seed insert failure';
                return false;
            }
        }
        return parent::insert($table, $data, $format);
    }
}

global $wpdb;
$rootWpdb = $wpdb;
$p = $wpdb->prefix . 'wpcb_';

wpcb_seed_recovery_assert(
    0 === (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}bookings"),
    'Default-seed recovery starts before booking smoke fixtures exist.'
);

foreach ([
    'booking_type_video_connections',
    'booking_type_calendar_connections',
    'booking_type_resources',
] as $suffix) {
    $wpdb->query("DELETE FROM {$p}{$suffix}");
}
$wpdb->query("DELETE FROM {$p}availability_rules");
$wpdb->query("DELETE FROM {$p}form_fields");
$wpdb->query("DELETE FROM {$p}booking_types");
delete_option('wpcb_settings');
delete_option('wpcb_email_templates');
delete_option('wpcb_default_seed_version');
delete_option('wpcb_default_seed_state');
update_option('wpcb_resource_model_version', 0, false);
wp_cache_flush();

wpcb_seed_recovery_assert(
    0 === (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_types")
        && get_option('wpcb_settings', null) === null,
    'Fresh-install seed fixture starts from empty configurable defaults.'
);

$fault = new WpcbSeedFaultWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$fault->set_prefix($rootWpdb->prefix);
$fault->suppress_errors(true);
$GLOBALS['wpdb'] = $fault;
$wpdb = $fault;
wp_cache_flush();

wpcb_seed_recovery_assert(
    Wpcb\Database\DefaultSeedMigration::maybeRun() === false,
    'Default seed reports failure when a required insert fails.'
);
wpcb_seed_recovery_assert(
    $fault->bookingTypeInserts === 2,
    'Fault injector reached and rejected the second default booking type.'
);
wpcb_seed_recovery_assert(
    (string)get_option('wpcb_default_seed_state', '') === 'seeding'
        && (int)get_option('wpcb_default_seed_version', 0) < Wpcb\Database\DefaultSeedMigration::currentVersion(),
    'Interrupted seed keeps its resumable state without marking completion.'
);

$GLOBALS['wpdb'] = $rootWpdb;
$wpdb = $rootWpdb;
wp_cache_flush();

wpcb_seed_recovery_assert(
    1 === (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_types"),
    'Interrupted seed leaves only the successfully written prefix of defaults.'
);
wpcb_seed_recovery_assert(
    Wpcb\Database\DefaultSeedMigration::maybeRun(),
    'Default seed resumes after storage recovers.'
);
wpcb_seed_recovery_assert(
    (int)get_option('wpcb_default_seed_version', 0) === Wpcb\Database\DefaultSeedMigration::currentVersion()
        && get_option('wpcb_default_seed_state', null) === null,
    'Successful retry records completion and clears in-progress state.'
);

$defaultSlugs = ['telefon', 'online-meeting', 'mich-besuchen'];
foreach ($defaultSlugs as $slug) {
    wpcb_seed_recovery_assert(
        1 === (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}booking_types WHERE slug = %s",
            $slug
        )),
        'Exactly one booking type exists for default slug ' . $slug . '.'
    );
}

$fieldKeys = [
    'subject', 'gender', 'first_name', 'last_name', 'email', 'company',
    'department', 'location', 'who_calls', 'phone', 'message', 'privacy',
];
foreach ($fieldKeys as $key) {
    wpcb_seed_recovery_assert(
        1 === (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}form_fields WHERE field_key = %s",
            $key
        )),
        'Exactly one default form field exists for ' . $key . '.'
    );
}

for ($weekday = 1; $weekday <= 5; ++$weekday) {
    wpcb_seed_recovery_assert(
        1 === (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}availability_rules
             WHERE scope_type = 'global' AND scope_id IS NULL AND weekday = %d",
            $weekday
        )),
        'Exactly one global default rule exists for weekday ' . $weekday . '.'
    );
}

wpcb_seed_recovery_assert(
    is_array(get_option('wpcb_settings', null))
        && is_array(get_option('wpcb_email_templates', null)),
    'Settings and email-template defaults exist after retry.'
);

wpcb_seed_recovery_assert(
    Wpcb\Resources\ResourceMigration::maybeRun(),
    'Resource migration resumes after default booking types are restored.'
);
foreach ($defaultSlugs as $slug) {
    $typeId = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$p}booking_types WHERE slug = %s",
        $slug
    ));
    wpcb_seed_recovery_assert(
        $typeId > 0
            && (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}booking_type_resources WHERE booking_type_id = %d",
                $typeId
            )) > 0,
        'Recovered default type ' . $slug . ' is mapped to a resource.'
    );
}

$countsBefore = [
    'types' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_types"),
    'fields' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}form_fields"),
    'rules' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}availability_rules"),
];
wpcb_seed_recovery_assert(
    Wpcb\Database\DefaultSeedMigration::maybeRun(),
    'Completed seed is safely idempotent.'
);
$countsAfter = [
    'types' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_types"),
    'fields' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}form_fields"),
    'rules' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}availability_rules"),
];
wpcb_seed_recovery_assert(
    $countsAfter === $countsBefore,
    'Repeated seed execution creates no duplicate defaults.'
);

$telefonId = (int)$wpdb->get_var("SELECT id FROM {$p}booking_types WHERE slug = 'telefon' LIMIT 1");
$originalName = (string)$wpdb->get_var($wpdb->prepare(
    "SELECT name FROM {$p}booking_types WHERE id = %d",
    $telefonId
));
$wpdb->update(
    $p . 'booking_types',
    ['name' => 'CI customized phone type'],
    ['id' => $telefonId]
);
delete_option('wpcb_default_seed_version');
delete_option('wpcb_default_seed_state');
wp_cache_flush();

wpcb_seed_recovery_assert(
    Wpcb\Database\DefaultSeedMigration::maybeRun(),
    'Existing configured installation adopts the seed marker without reseeding.'
);
wpcb_seed_recovery_assert(
    (string)$wpdb->get_var($wpdb->prepare(
        "SELECT name FROM {$p}booking_types WHERE id = %d",
        $telefonId
    )) === 'CI customized phone type',
    'Adoption does not overwrite an administrator-customized existing default row.'
);
wpcb_seed_recovery_assert(
    (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}booking_types") === $countsBefore['types'],
    'Adoption does not recreate intentionally missing or customized defaults.'
);

$wpdb->update(
    $p . 'booking_types',
    ['name' => $originalName],
    ['id' => $telefonId]
);

wpcb_seed_recovery_assert(
    Wpcb\Database\MigrationReadiness::isReady(),
    'Recovered installation is fully migration-ready for subsequent smoke tests.'
);

WP_CLI::success('Default seed interruption, retry, idempotency and adoption passed.');
