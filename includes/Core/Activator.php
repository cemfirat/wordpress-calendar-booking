<?php
namespace Wpcb\Core;

use Wpcb\Database\SchemaMigration;
use Wpcb\Support\Time;
use Wpcb\Support\TimeMigration;
use Wpcb\Tokens\TokenMigration;
use Wpcb\Security\SecretMigration;
use Wpcb\Resources\ResourceMigration;
use Wpcb\Booking\BookingStatusMigration;

class Activator {
    public static function activate(): void {
        if (!SchemaMigration::maybeRun()) {
            return;
        }
        if (!TokenMigration::maybeRun()
            || !SecretMigration::maybeRun()
            || !TimeMigration::maybeRun()
            || !BookingStatusMigration::maybeRun()
        ) {
            return;
        }
        self::seed_defaults();
        if (!ResourceMigration::maybeRun()) {
            return;
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('wpcb_portal_session_cleanup');
        flush_rewrite_rules();
    }

    private static function seed_defaults(): void {
        $defaults = [
            'mode' => 'automatic',
            'sender_name' => get_bloginfo('name'),
            'sender_email' => get_option('admin_email'),
            'timezone' => wp_timezone_string() ?: 'Europe/Vienna',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
            'notifications_enabled' => 1,
            'notification_emails' => get_option('admin_email'),
            'reminders_enabled' => 0,
            'reminder_hours' => 24,
            'delivery_log_retention_days' => 90,
            'calendar_url' => '',
            'calendar_urls' => '',
            'calendar_cache_minutes' => 30,
            'token_ttl_minutes' => 60 * 24,
            'reservation_ttl_minutes' => 30,
            'cancel_min_hours' => 2,
            'change_min_hours' => 2,
            'honeypot_enabled' => 1,
            'timing_enabled' => 1,
            'min_form_seconds' => 3,
            'rate_limit_enabled' => 1,
            'rate_limit_requests' => 5,
            'rate_limit_window_minutes' => 15,
            'show_calendar_limit' => 20,
            'visit_address' => '',
            'own_phone' => '',
            'icloud_sync_enabled' => 0,
            'icloud_sync_apple_id' => '',
            'icloud_sync_password_enc' => '',
            'icloud_sync_target_calendar_url' => '',
            'icloud_sync_target_calendar_name' => 'Website Buchungen',
            'icloud_sync_updates' => 1,
            'icloud_sync_cancellations' => 1,
            'icloud_sync_last_test' => '',
        ];
        add_option('wpcb_settings', $defaults);

        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_booking_types';
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count === 0) {
            $types = [
                ['Telefon', 'telefon', 'Telefontermin', 30, 0, 15, 1],
                ['Online-Meeting', 'online-meeting', 'Videotermin', 60, 0, 15, 2],
                ['Mich besuchen', 'mich-besuchen', 'Vor-Ort-Termin', 60, 0, 15, 3],
            ];
            foreach ($types as $t) {
                $wpdb->insert($table, [
                    'name' => $t[0], 'slug' => $t[1], 'description' => $t[2], 'duration_minutes' => $t[3],
                    'buffer_before_minutes' => $t[4], 'buffer_after_minutes' => $t[5], 'is_active' => 1, 'is_public' => 1,
                    'sort_order' => $t[6], 'created_at' => Time::formatUtc(Time::nowUtc()), 'updated_at' => Time::formatUtc(Time::nowUtc()),
                ]);
            }
        }

        $fields = $wpdb->prefix . 'wpcb_form_fields';
        $field_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$fields}");
        if ($field_count === 0) {
            $seedFields = [
                ['subject', 'Betreff', 'text', 1, 1, null, null, 1],
                ['gender', 'Geschlecht', 'select', 1, 1, wp_json_encode(['Herr', 'Frau', 'Divers']), null, 2],
                ['first_name', 'Vorname', 'text', 1, 1, null, null, 3],
                ['last_name', 'Nachname', 'text', 1, 1, null, null, 4],
                ['email', 'E-Mail', 'email', 1, 1, null, null, 5],
                ['company', 'Firma', 'text', 0, 1, null, null, 6],
                ['department', 'Position / Abteilung', 'text', 0, 1, null, null, 7],
                ['location', 'Ort', 'text', 0, 1, null, null, 8],
                ['who_calls', 'Wer ruft an?', 'select', 0, 1, wp_json_encode(['Ich rufe an', 'Die Kundin ruft an']), null, 9],
                ['phone', 'Telefonnummer', 'text', 0, 1, null, null, 10],
                ['message', 'Nachricht', 'textarea', 0, 1, null, null, 11],
                ['privacy', 'Ich stimme der Verarbeitung meiner Daten zu.', 'checkbox', 1, 1, null, wp_json_encode(['must_be_checked' => true]), 12],
            ];
            foreach ($seedFields as $f) {
                $wpdb->insert($fields, [
                    'field_key' => $f[0], 'label' => $f[1], 'field_type' => $f[2], 'is_required' => $f[3], 'is_active' => $f[4],
                    'options_json' => $f[5], 'validation_rules_json' => $f[6], 'sort_order' => $f[7],
                    'created_at' => Time::formatUtc(Time::nowUtc()), 'updated_at' => Time::formatUtc(Time::nowUtc()),
                ]);
            }
        }

        $rules = $wpdb->prefix . 'wpcb_availability_rules';
        $rule_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rules}");
        if ($rule_count === 0) {
            for ($weekday = 1; $weekday <= 5; $weekday++) {
                $wpdb->insert($rules, [
                    'scope_type' => 'global','scope_id' => null,'weekday' => $weekday,'start_time' => '09:00:00','end_time' => '17:00:00',
                    'slot_duration_minutes' => 30,'buffer_before_minutes' => 0,'buffer_after_minutes' => 15,'min_notice_minutes' => 120,
                    'max_days_in_advance' => 30,'is_active' => 1,'created_at' => Time::formatUtc(Time::nowUtc()),'updated_at' => Time::formatUtc(Time::nowUtc()),
                ]);
            }
        }

        add_option('wpcb_email_templates', [
            'doi_subject' => 'Bitte Terminbuchung bestätigen',
            'doi_body' => "Hallo {name},\n\nbitte bestätige deine Terminbuchung über diesen Link:\n{bestaetigungslink}\n\nTerminart: {terminart}\nTeilnehmer: {teilnehmer}\nTermin: {datum} {uhrzeit}",
            'confirmed_subject' => 'Termin bestätigt',
            'confirmed_body' => "Hallo {name},\n\ndein Termin ist bestätigt.\n\nTerminart: {terminart}\nTeilnehmer: {teilnehmer}\nTermin: {datum} {uhrzeit}\nOrt/Kontakt: {ort}\nVideo-Meeting: {meeting_link}\n\nStornieren: {stornolink}\nÄndern: {aenderungslink}",
            'pending_subject' => 'Termin wartet auf Freigabe',
            'pending_body' => "Hallo {name},\n\ndeine E-Mail wurde bestätigt. Dein Termin wartet jetzt auf Freigabe.\n\nTerminart: {terminart}\nTermin: {datum} {uhrzeit}",
            'approved_subject' => 'Termin freigegeben',
            'approved_body' => "Hallo {name},\n\ndein Termin wurde freigegeben.\n\nTerminart: {terminart}\nTermin: {datum} {uhrzeit}\nOrt/Kontakt: {ort}\nVideo-Meeting: {meeting_link}\n\nStornieren: {stornolink}\nÄndern: {aenderungslink}",
            'rejected_subject' => 'Termin konnte nicht bestätigt werden',
            'rejected_body' => "Hallo {name},\n\nleider konnte dein Termin nicht bestätigt werden.",
            'cancelled_subject' => 'Termin storniert',
            'cancelled_body' => "Hallo {name},\n\ndein Termin wurde storniert.",
            'updated_subject' => 'Termin geändert',
            'updated_body' => "Hallo {name},\n\ndein Termin wurde geändert.\n\nNeuer Termin: {datum} {uhrzeit}\nOrt/Kontakt: {ort}\nVideo-Meeting: {meeting_link}",
            'reminder_subject' => 'Erinnerung an deinen Termin',
            'reminder_body' => "Hallo {name},\n\nhier ist deine Erinnerung an den Termin am {datum} um {uhrzeit}.\nOrt/Kontakt: {ort}",
            'internal_subject' => 'Neue Termin-Aktion',
            'internal_body' => "Status: {status}\nName: {name}\nE-Mail: {email}\nTerminart: {terminart}\nTeilnehmer: {teilnehmer}\nTermin: {datum} {uhrzeit}\nBetreff: {betreff}\nOrt/Kontakt: {ort}\nVideo-Meeting: {meeting_link}",
        ]);
    }
}
