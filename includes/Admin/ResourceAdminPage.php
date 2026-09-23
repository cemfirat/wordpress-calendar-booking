<?php
namespace Wpcb\Admin;

use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Calendar\CalendarConnectionRepository;
use Wpcb\Resources\ResourceRepository;

final class ResourceAdminPage {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handlePost']);
    }

    public function menu(): void {
        add_submenu_page(
            'wpcb_dashboard',
            'Ressourcen & Mitarbeiter',
            'Ressourcen',
            'manage_options',
            'wpcb_resources',
            [$this, 'render']
        );
    }

    public function handlePost(): void {
        if (!current_user_can('manage_options') || empty($_POST['wpcb_resource_action'])) {
            return;
        }

        check_admin_referer('wpcb_resource_action');
        $action = sanitize_key(wp_unslash($_POST['wpcb_resource_action']));
        $resources = new ResourceRepository();
        $error = '';

        if ($action === 'save_resource') {
            $result = $resources->save(
                [
                    'name' => wp_unslash($_POST['name'] ?? ''),
                    'slug' => wp_unslash($_POST['slug'] ?? ''),
                    'public_label' => wp_unslash($_POST['public_label'] ?? ''),
                    'description' => wp_unslash($_POST['description'] ?? ''),
                    'capacity' => max(1, min(10000, absint($_POST['capacity'] ?? 1))),
                    'is_active' => !empty($_POST['is_active']),
                    'is_public' => !empty($_POST['is_public']),
                    'sort_order' => (int)($_POST['sort_order'] ?? 0),
                ],
                absint($_POST['resource_id'] ?? 0)
            );
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            } else {
                do_action('wpcb_capacity_changed');
            }
        } elseif ($action === 'delete_resource') {
            $result = $resources->delete(absint($_POST['resource_id'] ?? 0));
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            }
        } elseif ($action === 'assign_type') {
            $resources->setForBookingType(
                absint($_POST['booking_type_id'] ?? 0),
                array_map('absint', (array)($_POST['resource_ids'] ?? []))
            );
        } elseif ($action === 'assign_calendars') {
            $resourceId = absint($_POST['resource_id'] ?? 0);
            $selections = [];
            foreach ((array)($_POST['connections'] ?? []) as $connectionId => $flags) {
                $connectionId = absint($connectionId);
                if ($connectionId < 1 || !is_array($flags)) {
                    continue;
                }
                $selections[] = [
                    'connection_id' => $connectionId,
                    'blocks_availability' => !empty($flags['blocks']),
                    'receives_bookings' => !empty($flags['write']),
                ];
            }
            (new CalendarConnectionRepository())->setForResource($resourceId, $selections);
        }

        $args = ['page' => 'wpcb_resources', 'updated' => 1];
        if ($error !== '') {
            $args['wpcb_error'] = $error;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Nicht erlaubt.', 403);
        }

        $resourcesRepo = new ResourceRepository();
        $resources = $resourcesRepo->all(false);
        $types = (new BookingTypeRepository())->all(false);
        $connectionsRepo = new CalendarConnectionRepository();
        $connections = $connectionsRepo->all(false);
        $defaultId = (int)get_option('wpcb_default_resource_id', 0);

        echo '<div class="wrap wpcb-admin">';
        echo '<h1>Ressourcen &amp; Mitarbeiter</h1>';
        echo '<p class="description">Interne Ressourcennamen bleiben privat. Im Frontend wird ein Ressourcenname nur angezeigt, wenn „öffentlich“ aktiviert und ein separates öffentliches Label gesetzt ist.</p>';
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>Gespeichert.</p></div>';
        }
        if (!empty($_GET['wpcb_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['wpcb_error']))) . '</p></div>';
        }

        echo '<h2>Ressourcen</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Intern</th><th>Öffentliches Label</th><th>Sortierung</th><th>Status</th><th>Aktion</th></tr></thead><tbody>';
        foreach ($resources as $resource) {
            echo '<tr><td colspan="5">';
            $this->resourceForm($resource, $defaultId === (int)$resource->id);
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h3>Neue Ressource</h3>';
        $this->resourceForm(null, false);

        echo '<hr><h2>Terminarten → Ressourcen</h2>';
        foreach ($types as $type) {
            $selected = array_map(
                static fn(object $resource): int => (int)$resource->id,
                $resourcesRepo->forBookingType((int)$type->id, false)
            );
            echo '<form method="post" style="margin:12px 0;padding:12px;background:#fff;border:1px solid #ccd0d4">';
            wp_nonce_field('wpcb_resource_action');
            echo '<input type="hidden" name="wpcb_resource_action" value="assign_type">';
            echo '<input type="hidden" name="booking_type_id" value="' . (int)$type->id . '">';
            echo '<strong>' . esc_html((string)$type->name) . '</strong><br>';
            foreach ($resources as $resource) {
                echo '<label style="display:inline-block;margin:8px 14px 0 0">';
                echo '<input type="checkbox" name="resource_ids[]" value="' . (int)$resource->id . '" '
                    . checked(in_array((int)$resource->id, $selected, true), true, false) . '> ';
                echo esc_html((string)$resource->name);
                echo '</label>';
            }
            echo '<p><button class="button button-primary">Zuordnung speichern</button></p></form>';
        }

        echo '<hr><h2>Ressourcen → Kalender</h2>';
        echo '<p class="description">Ressourcen-spezifische Zuordnungen haben Vorrang. Ohne eigene Zuordnung gelten bestehende Terminart-Kalenderzuordnungen weiter.</p>';
        if (!$connections) {
            echo '<p>Noch keine Kalenderverbindungen vorhanden.</p>';
        }
        foreach ($resources as $resource) {
            $mapped = [];
            foreach ($connectionsRepo->forResource((int)$resource->id, false) as $row) {
                $mapped[(int)$row['connection']->id] = $row;
            }

            echo '<form method="post" style="margin:12px 0;padding:12px;background:#fff;border:1px solid #ccd0d4">';
            wp_nonce_field('wpcb_resource_action');
            echo '<input type="hidden" name="wpcb_resource_action" value="assign_calendars">';
            echo '<input type="hidden" name="resource_id" value="' . (int)$resource->id . '">';
            echo '<strong>' . esc_html((string)$resource->name) . '</strong>';
            if ($connections) {
                echo '<table class="widefat striped" style="margin-top:8px"><thead><tr><th>Kalender</th><th>Blockiert Verfügbarkeit</th><th>Erhält Buchungen</th></tr></thead><tbody>';
                foreach ($connections as $connection) {
                    $row = $mapped[(int)$connection->id] ?? null;
                    echo '<tr><td>' . esc_html($connection->name . ' (' . $connection->provider . ')') . '</td>';
                    echo '<td><input type="checkbox" name="connections[' . (int)$connection->id . '][blocks]" value="1" '
                        . checked($row ? $row['blocks_availability'] : false, true, false) . '></td>';
                    echo '<td><input type="checkbox" name="connections[' . (int)$connection->id . '][write]" value="1" '
                        . checked($row ? $row['receives_bookings'] : false, true, false) . '></td></tr>';
                }
                echo '</tbody></table>';
                echo '<p><button class="button button-primary">Kalenderzuordnung speichern</button></p>';
            }
            echo '</form>';
        }

        echo '</div>';
    }

    private function resourceForm(?object $resource, bool $isDefault): void {
        $id = $resource ? (int)$resource->id : 0;
        echo '<form method="post" style="display:grid;grid-template-columns:1fr 1fr 1fr auto auto auto;gap:8px;align-items:end">';
        wp_nonce_field('wpcb_resource_action');
        echo '<input type="hidden" name="wpcb_resource_action" value="save_resource">';
        echo '<input type="hidden" name="resource_id" value="' . $id . '">';
        echo '<label>Name<br><input class="regular-text" type="text" name="name" required value="' . esc_attr((string)($resource->name ?? '')) . '"></label>';
        echo '<label>Slug<br><input class="regular-text" type="text" name="slug" required value="' . esc_attr((string)($resource->slug ?? '')) . '"></label>';
        echo '<label>Öffentliches Label<br><input class="regular-text" type="text" name="public_label" value="' . esc_attr((string)($resource->public_label ?? '')) . '"></label>';
        echo '<label>Kapazität<br><input type="number" min="1" max="10000" name="capacity" value="' . esc_attr((string)($resource->capacity ?? 1)) . '"></label>';
        echo '<label>Sortierung<br><input type="number" name="sort_order" value="' . esc_attr((string)($resource->sort_order ?? 0)) . '"></label>';
        echo '<span><label><input type="checkbox" name="is_active" value="1" ' . checked($resource ? (int)$resource->is_active : 1, 1, false) . '> aktiv</label><br>';
        echo '<label><input type="checkbox" name="is_public" value="1" ' . checked($resource ? (int)$resource->is_public : 0, 1, false) . '> öffentlich</label></span>';
        echo '<label style="grid-column:1 / span 3">Beschreibung<br><textarea name="description" class="large-text" rows="2">' . esc_textarea((string)($resource->description ?? '')) . '</textarea></label>';
        echo '<p><button class="button button-primary">Speichern</button>';
        if ($isDefault) {
            echo ' <span class="description">Standardressource</span>';
        }
        echo '</p></form>';

        if ($id > 0 && !$isDefault) {
            echo '<form method="post" style="margin-top:6px">';
            wp_nonce_field('wpcb_resource_action');
            echo '<input type="hidden" name="wpcb_resource_action" value="delete_resource">';
            echo '<input type="hidden" name="resource_id" value="' . $id . '">';
            echo '<button class="button button-link-delete" type="submit">Ressource löschen</button></form>';
        }
    }
}
