<?php
namespace Wpcb\Database;

use Wpcb\Support\Time;

/**
 * Resumable creation of first-install defaults.
 *
 * Existing installations without this marker are adopted rather than rewritten.
 * A new seed records its in-progress state before the first data write; retries
 * then only add missing stable identifiers.
 */
final class DefaultSeedMigration {
    private const OPTION = 'wpcb_default_seed_version';
    private const STATE_OPTION = 'wpcb_default_seed_state';
    private const VERSION = 1;
    private const LOCK_SECONDS = 5;

    public static function maybeRun(): bool {
        if (self::isReady()) {
            return true;
        }

        global $wpdb;
        $lockName = self::lockName();
        $locked = (int)$wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, self::LOCK_SECONDS)
        );
        if ($locked !== 1) {
            return false;
        }

        try {
            if (self::isReady()) {
                return true;
            }

            $state = (string)get_option(self::STATE_OPTION, '');
            if ($state === '') {
                if (self::hasExistingConfiguration()) {
                    return self::markComplete();
                }

                update_option(self::STATE_OPTION, 'seeding', false);
                if ((string)get_option(self::STATE_OPTION, '') !== 'seeding') {
                    return false;
                }
            }

            if (!self::ensureSettings()
                || !self::ensureBookingTypes()
                || !self::ensureFormFields()
                || !self::ensureAvailabilityRules()
                || !self::ensureEmailTemplates()
                || !self::verifyDefaults()
            ) {
                return false;
            }

            if (!self::markComplete()) {
                return false;
            }

            delete_option(self::STATE_OPTION);
            return true;
        } catch (\Throwable $error) {
            return false;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    public static function isReady(): bool {
        return (int)get_option(self::OPTION, 0) >= self::VERSION;
    }

    public static function currentVersion(): int {
        return self::VERSION;
    }

    public static function lockName(): string {
        global $wpdb;
        $blogId = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
        return 'wpcb_seed_' . substr(hash('sha256', $wpdb->prefix . '|' . $blogId), 0, 40);
    }

    private static function markComplete(): bool {
        update_option(self::OPTION, self::VERSION, false);
        return self::isReady();
    }

    /**
     * Upgrades must not recreate defaults that an administrator intentionally
     * removed. Only a completely empty installation starts a new seed. Once a
     * new seed starts, STATE_OPTION distinguishes its partial data from an
     * established installation and makes retries resumable.
     */
    private static function hasExistingConfiguration(): bool {
        global $wpdb;
        if (get_option('wpcb_settings', null) !== null
            || get_option('wpcb_email_templates', null) !== null
        ) {
            return true;
        }

        foreach (['booking_types', 'form_fields', 'availability_rules'] as $suffix) {
            $table = $wpdb->prefix . 'wpcb_' . $suffix;
            if ((int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}") > 0) {
                return true;
            }
        }
        return false;
    }

    private static function ensureSettings(): bool {
        if (get_option('wpcb_settings', null) !== null) {
            return true;
        }

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
        return get_option('wpcb_settings', null) !== null;
    }

    private static function ensureBookingTypes(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_booking_types';
        $types = [
            ['Telefon', 'telefon', 'Telefontermin', 30, 0, 15, 1],
            ['Online-Meeting', 'online-meeting', 'Videotermin', 60, 0, 15, 2],
            ['Mich besuchen', 'mich-besuchen', 'Vor-Ort-Termin', 60, 0, 15, 3],
        ];

        foreach ($types as $type) {
            if ((int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE slug = %s",
                $type[1]
            )) > 0) {
                continue;
            }

            $now = Time::formatUtc(Time::nowUtc());
            if ($wpdb->insert($table, [
                'name' => $type[0],
                'slug' => $type[1],
                'description' => $type[2],
                'duration_minutes' => $type[3],
                'buffer_before_minutes' => $type[4],
                'buffer_after_minutes' => $type[5],
                'is_active' => 1,
                'is_public' => 1,
                'sort_order' => $type[6],
                'created_at' => $now,
                'updated_at' => $now,
            ]) === false) {
                return false;
            }
        }
        return true;
    }

    private static function ensureFormFields(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_form_fields';
        $fields = [
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

        foreach ($fields as $field) {
            if ((int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE field_key = %s",
                $field[0]
            )) > 0) {
                continue;
            }

            $now = Time::formatUtc(Time::nowUtc());
            if ($wpdb->insert($table, [
                'field_key' => $field[0],
                'label' => $field[1],
                'field_type' => $field[2],
                'is_required' => $field[3],
                'is_active' => $field[4],
                'options_json' => $field[5],
                'validation_rules_json' => $field[6],
                'sort_order' => $field[7],
                'created_at' => $now,
                'updated_at' => $now,
            ]) === false) {
                return false;
            }
        }
        return true;
    }

    private static function ensureAvailabilityRules(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_availability_rules';

        for ($weekday = 1; $weekday <= 5; ++$weekday) {
            if ((int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE scope_type = 'global' AND scope_id IS NULL AND weekday = %d",
                $weekday
            )) > 0) {
                continue;
            }

            $now = Time::formatUtc(Time::nowUtc());
            if ($wpdb->insert($table, [
                'scope_type' => 'global',
                'scope_id' => null,
                'weekday' => $weekday,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'slot_duration_minutes' => 30,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 15,
                'min_notice_minutes' => 120,
                'max_days_in_advance' => 30,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]) === false) {
                return false;
            }
        }
        return true;
    }

    private static function ensureEmailTemplates(): bool {
        if (get_option('wpcb_email_templates', null) !== null) {
            return true;
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
        return get_option('wpcb_email_templates', null) !== null;
    }

    private static function verifyDefaults(): bool {
        global $wpdb;
        $typeTable = $wpdb->prefix . 'wpcb_booking_types';
        $fieldTable = $wpdb->prefix . 'wpcb_form_fields';
        $ruleTable = $wpdb->prefix . 'wpcb_availability_rules';

        if (get_option('wpcb_settings', null) === null
            || get_option('wpcb_email_templates', null) === null
        ) {
            return false;
        }

        foreach (['telefon', 'online-meeting', 'mich-besuchen'] as $slug) {
            if ((int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$typeTable} WHERE slug = %s",
                $slug
            )) !== 1) {
                return false;
            }
        }

        foreach ([
            'subject', 'gender', 'first_name', 'last_name', 'email', 'company',
            'department', 'location', 'who_calls', 'phone', 'message', 'privacy',
        ] as $fieldKey) {
            if ((int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$fieldTable} WHERE field_key = %s",
                $fieldKey
            )) !== 1) {
                return false;
            }
        }

        for ($weekday = 1; $weekday <= 5; ++$weekday) {
            if ((int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$ruleTable}
                 WHERE scope_type = 'global' AND scope_id IS NULL AND weekday = %d",
                $weekday
            )) !== 1) {
                return false;
            }
        }
        return true;
    }
}
