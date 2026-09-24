<?php
if (!defined('ABSPATH')) { exit; }

function wpcb_backup_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

global $wpdb;
$service = new Wpcb\Admin\ConfigurationBackupService();
$config = new Wpcb\Admin\ConfigurationService();
$resources = new Wpcb\Resources\ResourceRepository();

$originalSettings = get_option('wpcb_settings', []);
$originalTemplates = get_option('wpcb_email_templates', []);
$settings = (array)$originalSettings;
$settings['timezone'] = 'Europe/Vienna';
$settings['icloud_sync_password_enc'] = 'SECRET-CALDAV-CIPHER-TEXT';
$settings['stripe_secret_key_enc'] = 'SECRET-STRIPE-KEY-TEXT';
$settings['oauth_access_token'] = 'SECRET-OAUTH-TOKEN-TEXT';
update_option('wpcb_settings', $settings, false);

$fixtureType = $config->saveBookingType([
    'name' => 'Backup Fixture Type',
    'slug' => 'backup-fixture-type',
    'description' => 'Safe configuration description',
    'duration_minutes' => 30,
    'buffer_before_minutes' => 5,
    'buffer_after_minutes' => 10,
    'capacity' => 2,
    'show_remaining_capacity' => 1,
    'payment_mode' => 'free',
    'price_minor' => 0,
    'currency' => 'EUR',
    'is_active' => 1,
    'is_public' => 1,
    'sort_order' => 77,
]);
$defaultResource = $resources->ensureDefault();
$resources->setForBookingType($fixtureType, [$defaultResource]);

$now = gmdate('Y-m-d H:i:s');
$wpdb->insert($wpdb->prefix . 'wpcb_bookings', [
    'booking_uuid' => wp_generate_uuid4(),
    'booking_type_id' => $fixtureType,
    'resource_id' => $defaultResource,
    'slot_start' => '2033-01-10 09:00:00',
    'slot_end' => '2033-01-10 09:30:00',
    'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
    'party_size' => 1,
    'full_name' => 'CONFIG-BACKUP-CUSTOMER-NAME',
    'email' => 'config-backup-customer@example.com',
    'phone' => '+43123456789',
    'notes' => 'CONFIG-BACKUP-CUSTOMER-NOTE',
    'source' => 'test',
    'lang' => 'de',
    'created_at' => $now,
    'updated_at' => $now,
]);
$fixtureBooking = (int)$wpdb->insert_id;
wpcb_backup_assert($fixtureBooking > 0, 'Customer-data fixture booking is created.');

$snapshot = $service->exportSnapshot();
$json = (string)wp_json_encode($snapshot);
wpcb_backup_assert(($snapshot['format'] ?? '') === Wpcb\Admin\ConfigurationBackupService::FORMAT, 'Export uses the versioned configuration format.');
wpcb_backup_assert(strpos($json, 'CONFIG-BACKUP-CUSTOMER-NAME') === false, 'Export excludes customer names.');
wpcb_backup_assert(strpos($json, 'config-backup-customer@example.com') === false, 'Export excludes customer email addresses.');
wpcb_backup_assert(strpos($json, 'CONFIG-BACKUP-CUSTOMER-NOTE') === false, 'Export excludes booking notes.');
wpcb_backup_assert(strpos($json, 'SECRET-CALDAV-CIPHER-TEXT') === false, 'Export excludes encrypted calendar credentials.');
wpcb_backup_assert(strpos($json, 'SECRET-STRIPE-KEY-TEXT') === false, 'Export excludes payment credentials.');
wpcb_backup_assert(strpos($json, 'SECRET-OAUTH-TOKEN-TEXT') === false, 'Export excludes OAuth tokens.');
wpcb_backup_assert(strpos($json, 'backup-fixture-type') !== false, 'Export includes booking configuration.');

$unknown = $snapshot;
$unknown['unexpected_secret_bucket'] = ['x' => 'y'];
wpcb_backup_assert(is_wp_error($service->validate($unknown)), 'Unknown top-level fields fail closed.');

$restore = [
    'format' => Wpcb\Admin\ConfigurationBackupService::FORMAT,
    'format_version' => Wpcb\Admin\ConfigurationBackupService::FORMAT_VERSION,
    'plugin_version' => WPCB_VERSION,
    'exported_at_utc' => $now,
    'settings' => [
        'mode' => 'approval',
        'timezone' => 'UTC',
        'notifications_enabled' => 0,
    ],
    'email_templates' => ['confirmed_subject' => 'Restored subject'],
    'booking_types' => [[
        'name'=>'Restored Type','slug'=>'backup-restore-type','description'=>'restored',
        'duration_minutes'=>45,'buffer_before_minutes'=>5,'buffer_after_minutes'=>5,
        'capacity'=>3,'show_remaining_capacity'=>1,'payment_mode'=>'free','price_minor'=>0,
        'currency'=>'EUR','is_active'=>1,'is_public'=>1,'sort_order'=>91,
    ]],
    'resources' => [[
        'name'=>'Restored Resource','slug'=>'backup-restore-resource','public_label'=>'Public Restored',
        'description'=>'restored resource','capacity'=>4,'is_active'=>1,'is_public'=>1,'sort_order'=>92,
    ]],
    'booking_type_resources' => [[
        'booking_type_slug'=>'backup-restore-type','resource_slug'=>'backup-restore-resource',
    ]],
    'form_fields' => [[
        'field_key'=>'backup_restore_company','label'=>'Company','field_type'=>'text',
        'is_required'=>0,'is_active'=>1,'options_json'=>null,'validation_rules_json'=>null,'sort_order'=>93,
    ]],
    'availability_rules' => [[
        'scope_type'=>'booking_type','weekday'=>2,'start_time'=>'10:00:00','end_time'=>'12:00:00',
        'slot_duration_minutes'=>45,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,
        'min_notice_minutes'=>60,'max_days_in_advance'=>60,'is_active'=>1,
        'booking_type_slug'=>'backup-restore-type','resource_slug'=>null,
    ]],
    'exceptions' => [[
        'type'=>'blocked_range','title'=>'Restore exception','date_start'=>'2033-02-10 10:00:00',
        'date_end'=>'2033-02-10 11:00:00','all_day'=>0,'is_active'=>1,
        'booking_type_slug'=>'backup-restore-type','resource_slug'=>'backup-restore-resource',
    ]],
];

$beforeTypeCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_types WHERE slug='backup-restore-type'");
$beforeResourceCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_resources WHERE slug='backup-restore-resource'");
$settingsBeforeDryRun = get_option('wpcb_settings', []);
$plan = $service->import($restore, true);
wpcb_backup_assert(!is_wp_error($plan), 'Dry-run validates a safe restore snapshot.');
wpcb_backup_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_types WHERE slug='backup-restore-type'") === $beforeTypeCount, 'Dry-run creates no booking type.');
wpcb_backup_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_resources WHERE slug='backup-restore-resource'") === $beforeResourceCount, 'Dry-run creates no resource.');
wpcb_backup_assert(get_option('wpcb_settings', []) === $settingsBeforeDryRun, 'Dry-run does not mutate settings.');

$applied = $service->import($restore, false);
wpcb_backup_assert(!is_wp_error($applied) && !empty($applied['applied']), 'Validated restore applies successfully.');

$restoredType = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}wpcb_booking_types WHERE slug='backup-restore-type'");
$restoredResource = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}wpcb_resources WHERE slug='backup-restore-resource'");
wpcb_backup_assert($restoredType > 0 && $restoredResource > 0, 'Restore creates booking type and resource.');
wpcb_backup_assert((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_type_resources WHERE booking_type_id=%d AND resource_id=%d",
    $restoredType,
    $restoredResource
)) === 1, 'Restore remaps booking type/resource relationships.');
wpcb_backup_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_form_fields WHERE field_key='backup_restore_company'") === 1, 'Restore recreates form fields.');
wpcb_backup_assert((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_availability_rules WHERE scope_type='booking_type' AND scope_id=%d AND weekday=2",
    $restoredType
)) === 1, 'Restore recreates scoped availability.');
wpcb_backup_assert((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_exceptions WHERE booking_type_id=%d AND resource_id=%d AND title='Restore exception'",
    $restoredType,
    $restoredResource
)) === 1, 'Restore recreates remapped exceptions.');
wpcb_backup_assert((int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_bookings WHERE id=%d AND email='config-backup-customer@example.com'",
    $fixtureBooking
)) === 1, 'Restore never rewrites existing booking history.');

$settingsAfterApply = get_option('wpcb_settings', []);
wpcb_backup_assert(($settingsAfterApply['mode'] ?? '') === 'approval' && ($settingsAfterApply['timezone'] ?? '') === 'UTC', 'Restore applies allowed global settings.');
wpcb_backup_assert(($settingsAfterApply['icloud_sync_password_enc'] ?? '') === 'SECRET-CALDAV-CIPHER-TEXT', 'Restore does not overwrite existing encrypted credentials.');

$rollback = $restore;
$rollback['booking_types'][0]['slug'] = 'backup-rollback-type';
$rollback['booking_types'][0]['name'] = 'Rollback Type';
$rollback['resources'][0]['slug'] = 'backup-rollback-resource';
$rollback['resources'][0]['name'] = '';
$rollback['booking_type_resources'][0] = [
    'booking_type_slug'=>'backup-rollback-type',
    'resource_slug'=>'backup-rollback-resource',
];
$rollback['availability_rules'] = [];
$rollback['exceptions'] = [];
$rollback['form_fields'] = [];
$rollback['settings'] = ['mode'=>'automatic','timezone'=>'Europe/London'];

$settingsBeforeRollback = get_option('wpcb_settings', []);
$rolledBack = $service->import($rollback, false);
wpcb_backup_assert(is_wp_error($rolledBack), 'Mid-import storage failure is reported.');
wpcb_backup_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcb_booking_types WHERE slug='backup-rollback-type'") === 0, 'Failed import rolls back earlier booking-type writes.');
wpcb_backup_assert(get_option('wpcb_settings', []) === $settingsBeforeRollback, 'Failed import rolls back option changes.');

$wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id'=>$fixtureBooking]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id'=>$fixtureBooking]);
$wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id'=>$fixtureBooking]);
$wpdb->delete($wpdb->prefix . 'wpcb_exceptions', ['booking_type_id'=>$restoredType]);
$wpdb->delete($wpdb->prefix . 'wpcb_availability_rules', ['scope_type'=>'booking_type','scope_id'=>$restoredType]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_type_resources', ['booking_type_id'=>$restoredType]);
$wpdb->delete($wpdb->prefix . 'wpcb_form_fields', ['field_key'=>'backup_restore_company']);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id'=>$restoredType]);
$wpdb->delete($wpdb->prefix . 'wpcb_resources', ['id'=>$restoredResource]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_type_resources', ['booking_type_id'=>$fixtureType]);
$wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id'=>$fixtureType]);
update_option('wpcb_settings', $originalSettings, false);
update_option('wpcb_email_templates', $originalTemplates, false);

wpcb_backup_assert(false !== has_action('admin_post_wpcb_configuration_export'), 'Protected configuration export action is registered.');
wpcb_backup_assert(false !== has_action('admin_post_wpcb_configuration_import'), 'Protected configuration import action is registered.');

echo "PASS: configuration backup/restore smoke test complete.\n";
