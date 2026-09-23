<?php
namespace Wpcb\Admin;

use Wpcb\Reliability\SchedulerHealth;

final class SetupReadiness {
    public function snapshot(): array {
        $items = [
            'booking_type' => [
                'complete' => $this->hasPublicBookingType(),
                'label' => __('Öffentliche Terminart', 'wordpress-calendar-booking'),
                'detail' => __('Mindestens eine aktive, öffentliche Terminart ist erforderlich.', 'wordpress-calendar-booking'),
                'url' => admin_url('admin.php?page=wpcb_types'),
            ],
            'resource' => [
                'complete' => $this->hasAssignedResource(),
                'label' => __('Ressource zugeordnet', 'wordpress-calendar-booking'),
                'detail' => __('Mindestens eine aktive Ressource muss einer öffentlichen Terminart zugeordnet sein.', 'wordpress-calendar-booking'),
                'url' => admin_url('admin.php?page=wpcb_resources'),
            ],
            'availability' => [
                'complete' => $this->hasEffectiveAvailability(),
                'label' => __('Verfügbarkeit eingerichtet', 'wordpress-calendar-booking'),
                'detail' => __('Mindestens eine aktive Verfügbarkeitsregel muss für eine buchbare Terminart greifen.', 'wordpress-calendar-booking'),
                'url' => admin_url('admin.php?page=wpcb_availability'),
            ],
            'settings' => [
                'complete' => $this->settingsAreValid(),
                'label' => __('Absender und Zeitzone gültig', 'wordpress-calendar-booking'),
                'detail' => __('Absender-E-Mail und Buchungszeitzone müssen gültig konfiguriert sein.', 'wordpress-calendar-booking'),
                'url' => admin_url('admin.php?page=wpcb_settings'),
            ],
            'scheduler' => [
                'complete' => $this->schedulerIsHealthy(),
                'label' => __('Scheduler gesund', 'wordpress-calendar-booking'),
                'detail' => __('Queue und stündliche Wartung müssen eingeplant sein und aktuell laufen.', 'wordpress-calendar-booking'),
                'url' => admin_url('admin.php?page=wpcb_system_health'),
            ],
            'public_surface' => [
                'complete' => $this->hasPublishedBookingSurface(),
                'label' => __('Buchung öffentlich eingebunden', 'wordpress-calendar-booking'),
                'detail' => __('Veröffentliche eine Seite mit Buchungs-Shortcode oder einem nativen Buchungsblock.', 'wordpress-calendar-booking'),
                'url' => admin_url('post-new.php?post_type=page'),
            ],
        ];

        $ready = true;
        foreach ($items as $item) {
            if (empty($item['complete'])) {
                $ready = false;
                break;
            }
        }

        return [
            'ready' => $ready,
            'items' => $items,
        ];
    }

    private function hasPublicBookingType(): bool {
        global $wpdb;
        return (bool)$wpdb->get_var(
            "SELECT 1 FROM {$wpdb->prefix}wpcb_booking_types
             WHERE is_active = 1 AND is_public = 1
             LIMIT 1"
        );
    }

    private function hasAssignedResource(): bool {
        global $wpdb;
        return (bool)$wpdb->get_var(
            "SELECT 1
             FROM {$wpdb->prefix}wpcb_booking_type_resources m
             INNER JOIN {$wpdb->prefix}wpcb_booking_types t ON t.id = m.booking_type_id
             INNER JOIN {$wpdb->prefix}wpcb_resources r ON r.id = m.resource_id
             WHERE t.is_active = 1
               AND t.is_public = 1
               AND r.is_active = 1
             LIMIT 1"
        );
    }

    private function hasEffectiveAvailability(): bool {
        global $wpdb;
        $rules = $wpdb->prefix . 'wpcb_availability_rules';
        $types = $wpdb->prefix . 'wpcb_booking_types';
        $resources = $wpdb->prefix . 'wpcb_resources';
        $mapping = $wpdb->prefix . 'wpcb_booking_type_resources';

        $global = (bool)$wpdb->get_var(
            "SELECT 1
             FROM {$rules} a
             WHERE a.is_active = 1
               AND a.scope_type = 'global'
               AND EXISTS (
                   SELECT 1
                   FROM {$mapping} m
                   INNER JOIN {$types} t ON t.id = m.booking_type_id
                   INNER JOIN {$resources} r ON r.id = m.resource_id
                   WHERE t.is_active = 1 AND t.is_public = 1 AND r.is_active = 1
                   LIMIT 1
               )
             LIMIT 1"
        );
        if ($global) {
            return true;
        }

        $typeSpecific = (bool)$wpdb->get_var(
            "SELECT 1
             FROM {$rules} a
             INNER JOIN {$types} t
               ON a.scope_type = 'booking_type' AND a.scope_id = t.id
             INNER JOIN {$mapping} m ON m.booking_type_id = t.id
             INNER JOIN {$resources} r ON r.id = m.resource_id
             WHERE a.is_active = 1
               AND t.is_active = 1
               AND t.is_public = 1
               AND r.is_active = 1
             LIMIT 1"
        );
        if ($typeSpecific) {
            return true;
        }

        return (bool)$wpdb->get_var(
            "SELECT 1
             FROM {$rules} a
             INNER JOIN {$resources} r
               ON a.scope_type = 'resource' AND a.scope_id = r.id
             INNER JOIN {$mapping} m ON m.resource_id = r.id
             INNER JOIN {$types} t ON t.id = m.booking_type_id
             WHERE a.is_active = 1
               AND r.is_active = 1
               AND t.is_active = 1
               AND t.is_public = 1
             LIMIT 1"
        );
    }

    private function settingsAreValid(): bool {
        $settings = Settings::get();
        $email = trim((string)($settings['sender_email'] ?? ''));
        $timezone = trim((string)($settings['timezone'] ?? ''));

        if (!is_email($email)) {
            return false;
        }
        if ($timezone === 'UTC') {
            return true;
        }
        return in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }

    private function schedulerIsHealthy(): bool {
        $snapshot = (new SchedulerHealth())->snapshot();
        return !empty($snapshot['healthy']);
    }

    private function hasPublishedBookingSurface(): bool {
        global $wpdb;

        $needles = [
            '[wpcb_booking_form',
            '[wpcb_calendar',
            '[wpcb_booking_calendar',
            '<!-- wp:wpcb/booking-form',
            '<!-- wp:wpcb/availability-calendar',
        ];
        $conditions = [];
        $params = ['page', 'publish'];
        foreach ($needles as $needle) {
            $conditions[] = 'post_content LIKE %s';
            $params[] = '%' . $wpdb->esc_like($needle) . '%';
        }

        $sql = "SELECT ID
                FROM {$wpdb->posts}
                WHERE post_type = %s
                  AND post_status = %s
                  AND (" . implode(' OR ', $conditions) . ")
                LIMIT 1";

        return (bool)$wpdb->get_var($wpdb->prepare($sql, ...$params));
    }
}
