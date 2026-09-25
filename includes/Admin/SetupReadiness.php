<?php
namespace Wpcb\Admin;

use Wpcb\Reliability\SchedulerHealth;
use Wpcb\Database\SchemaMigration;
use Wpcb\Database\MigrationReadiness;

final class SetupReadiness {
    public function snapshot(): array {
        global $wpdb;

        if (!SchemaMigration::isReady()) {
            $items = [
                $this->item(
                    'schema',
                    false,
                    __('Datenbankstruktur', 'wordpress-calendar-booking'),
                    __('Die erforderlichen Plugin-Tabellen, Spalten oder Indizes sind noch nicht vollständig verifiziert. Neue Buchungen bleiben bis zur erfolgreichen Reparatur gesperrt.', 'wordpress-calendar-booking'),
                    admin_url('site-health.php')
                ),
            ];
            return ['ready' => false, 'items' => $items];
        }

        if (!MigrationReadiness::isReady()) {
            $items = [
                $this->item(
                    'data_migrations',
                    false,
                    __('Datenmigrationen', 'wordpress-calendar-booking'),
                    __('Mindestens eine erforderliche Datenmigration ist noch nicht vollständig abgeschlossen. Neue Buchungen bleiben bis zur erfolgreichen Wiederholung gesperrt.', 'wordpress-calendar-booking'),
                    admin_url('site-health.php')
                ),
            ];
            return ['ready' => false, 'items' => $items];
        }

        $settings = Settings::get();
        $health = (new SchedulerHealth())->snapshot();

        $items = [
            $this->item(
                'booking_type',
                (bool)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}wpcb_booking_types WHERE is_active = 1 AND is_public = 1 ORDER BY id ASC LIMIT 1"),
                __('Öffentliche Terminart', 'wordpress-calendar-booking'),
                __('Mindestens eine aktive, öffentliche Terminart ist erforderlich.', 'wordpress-calendar-booking'),
                admin_url('admin.php?page=wpcb_types')
            ),
            $this->item(
                'resource',
                (bool)$wpdb->get_var(
                    "SELECT m.resource_id
                     FROM {$wpdb->prefix}wpcb_booking_type_resources m
                     INNER JOIN {$wpdb->prefix}wpcb_resources r ON r.id = m.resource_id
                     INNER JOIN {$wpdb->prefix}wpcb_booking_types t ON t.id = m.booking_type_id
                     WHERE r.is_active = 1 AND t.is_active = 1 AND t.is_public = 1
                     ORDER BY m.booking_type_id ASC, m.resource_id ASC
                     LIMIT 1"
                ),
                __('Ressource / Mitarbeiter', 'wordpress-calendar-booking'),
                __('Mindestens eine aktive Ressource muss einer öffentlichen Terminart zugeordnet sein.', 'wordpress-calendar-booking'),
                admin_url('admin.php?page=wpcb_resources')
            ),
            $this->item(
                'availability',
                $this->hasEffectiveAvailability(),
                __('Verfügbarkeit', 'wordpress-calendar-booking'),
                __('Mindestens eine tatsächlich buchbare Terminart-/Ressourcen-Kombination benötigt eine wirksame Verfügbarkeitsregel.', 'wordpress-calendar-booking'),
                admin_url('admin.php?page=wpcb_availability')
            ),
            $this->item(
                'settings',
                is_email((string)($settings['sender_email'] ?? '')) !== false
                    && $this->validTimezone((string)($settings['timezone'] ?? '')),
                __('Grundeinstellungen', 'wordpress-calendar-booking'),
                __('Absender-E-Mail und Buchungszeitzone müssen gültig konfiguriert sein.', 'wordpress-calendar-booking'),
                admin_url('admin.php?page=wpcb_settings')
            ),
            $this->item(
                'scheduler',
                !empty($health['healthy']),
                __('Scheduler / Queue', 'wordpress-calendar-booking'),
                __('WP-Cron und Queue müssen eingeplant sein, regelmäßig laufen und dürfen keine fehlgeschlagenen oder verwaisten Jobs aufweisen.', 'wordpress-calendar-booking'),
                admin_url('admin.php?page=wpcb_system_health')
            ),
            $this->item(
                'public_surface',
                $this->hasPublicBookingSurface(),
                __('Öffentliche Buchungsseite', 'wordpress-calendar-booking'),
                __('Veröffentliche mindestens eine Seite mit Buchungsformular, Buchungskalender oder entsprechendem Gutenberg-Block.', 'wordpress-calendar-booking'),
                admin_url('edit.php?post_type=page')
            ),
        ];

        return [
            'ready' => !in_array(false, array_column($items, 'ready'), true),
            'items' => $items,
        ];
    }

    private function item(string $key, bool $ready, string $label, string $detail, string $url): array {
        return [
            'key' => $key,
            'ready' => $ready,
            'label' => $label,
            'detail' => $detail,
            'url' => $url,
        ];
    }

    private function validTimezone(string $timezone): bool {
        if ($timezone === 'UTC') {
            return true;
        }
        return in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Match the same precedence used by AvailabilityRepository:
     * resource rules override booking-type rules, which override global rules.
     * A rule only counts when it is effective for an active/public type that
     * is assigned to an active resource.
     */
    private function hasEffectiveAvailability(): bool {
        global $wpdb;

        $rules = $wpdb->prefix . 'wpcb_availability_rules';
        $types = $wpdb->prefix . 'wpcb_booking_types';
        $resources = $wpdb->prefix . 'wpcb_resources';
        $mapping = $wpdb->prefix . 'wpcb_booking_type_resources';

        $sql = "SELECT m.booking_type_id
                FROM {$mapping} m
                INNER JOIN {$types} t ON t.id = m.booking_type_id
                INNER JOIN {$resources} r ON r.id = m.resource_id
                WHERE t.is_active = 1
                  AND t.is_public = 1
                  AND r.is_active = 1
                  AND EXISTS (
                      SELECT 1
                      FROM {$rules} ar
                      WHERE ar.is_active = 1
                        AND (
                            (ar.scope_type = 'resource' AND ar.scope_id = r.id)
                            OR (
                                ar.scope_type = 'booking_type'
                                AND ar.scope_id = t.id
                                AND NOT EXISTS (
                                    SELECT 1 FROM {$rules} rr
                                    WHERE rr.is_active = 1
                                      AND rr.scope_type = 'resource'
                                      AND rr.scope_id = r.id
                                )
                            )
                            OR (
                                ar.scope_type = 'global'
                                AND NOT EXISTS (
                                    SELECT 1 FROM {$rules} rr
                                    WHERE rr.is_active = 1
                                      AND rr.scope_type = 'resource'
                                      AND rr.scope_id = r.id
                                )
                                AND NOT EXISTS (
                                    SELECT 1 FROM {$rules} tr
                                    WHERE tr.is_active = 1
                                      AND tr.scope_type = 'booking_type'
                                      AND tr.scope_id = t.id
                                )
                            )
                        )
                  )
                ORDER BY m.booking_type_id ASC, m.resource_id ASC
                LIMIT 1";

        return (bool)$wpdb->get_var($sql);
    }

    private function hasPublicBookingSurface(): bool {
        global $wpdb;

        $needles = [
            '[wpcb_booking_form',
            '[wpcb_booking_calendar',
            '<!-- wp:wpcb/booking-form',
            '<!-- wp:wpcb/availability-calendar',
        ];

        $conditions = [];
        $params = [];
        foreach ($needles as $needle) {
            $conditions[] = 'post_content LIKE %s';
            $params[] = '%' . $wpdb->esc_like($needle) . '%';
        }

        $sql = "SELECT ID
                FROM {$wpdb->posts}
                WHERE post_status = 'publish'
                  AND post_type IN ('page', 'post')
                  AND (" . implode(' OR ', $conditions) . ")
                ORDER BY ID ASC
                LIMIT 1";

        return (bool)$wpdb->get_var($wpdb->prepare($sql, ...$params));
    }
}
