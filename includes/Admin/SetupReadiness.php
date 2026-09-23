<?php
namespace Wpcb\Admin;

use Wpcb\Reliability\SchedulerHealth;

final class SetupReadiness {
    public function snapshot(): array {
        global $wpdb;

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
                (bool)$wpdb->get_var(
                    "SELECT ar.id
                     FROM {$wpdb->prefix}wpcb_availability_rules ar
                     WHERE ar.is_active = 1
                       AND (
                            ar.scope_type = 'global'
                            OR (ar.scope_type = 'booking_type' AND EXISTS (
                                SELECT 1 FROM {$wpdb->prefix}wpcb_booking_types t
                                WHERE t.id = ar.scope_id AND t.is_active = 1 AND t.is_public = 1
                            ))
                            OR (ar.scope_type = 'resource' AND EXISTS (
                                SELECT 1 FROM {$wpdb->prefix}wpcb_resources r
                                WHERE r.id = ar.scope_id AND r.is_active = 1
                            ))
                       )
                     ORDER BY ar.id ASC
                     LIMIT 1"
                ),
                __('Verfügbarkeit', 'wordpress-calendar-booking'),
                __('Mindestens eine aktive globale, Terminart- oder Ressourcenregel ist erforderlich.', 'wordpress-calendar-booking'),
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
                !empty($health['next_queue_run'])
                    && !empty($health['next_reminder_run'])
                    && (int)($health['counts']['failed'] ?? 0) === 0
                    && (int)($health['stale_leases'] ?? 0) === 0,
                __('Scheduler / Queue', 'wordpress-calendar-booking'),
                __('WP-Cron muss eingeplant sein und es dürfen keine fehlgeschlagenen oder verwaisten Queue-Jobs vorliegen.', 'wordpress-calendar-booking'),
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

    private function hasPublicBookingSurface(): bool {
        global $wpdb;

        $patterns = [
            '%[wpcb_booking_form%',
            '%[wpcb_booking_calendar%',
            '%<!-- wp:wpcb/booking-form%',
            '%<!-- wp:wpcb/availability-calendar%',
        ];

        $conditions = [];
        $params = [];
        foreach ($patterns as $pattern) {
            $conditions[] = 'post_content LIKE %s';
            $params[] = $pattern;
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
