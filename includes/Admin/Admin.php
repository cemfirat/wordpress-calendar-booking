<?php
namespace Cemb\Admin;

use Cemb\Booking\BookingRepository;
use Cemb\Booking\BookingTypeRepository;
use Cemb\Booking\BookingStateMachine;
use Cemb\Booking\BookingTransitionService;
use Cemb\Sync\IcloudSyncService;
use Cemb\Sync\QueueService;
use Cemb\Sync\JobRepository;
use Cemb\Calendar\IcloudProvider;
use Cemb\Support\Time;
use Cemb\Privacy\PrivacyService;
use Cemb\Reliability\SchedulerHealth;

class Admin {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handlePost']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void {
        wp_enqueue_style('cemb-admin', CEMB_URL . 'assets/css/admin.css', [], CEMB_VERSION);
    }

    public function menu(): void {
        add_menu_page('Kalender & Buchungen', 'Kalender & Buchungen', 'manage_options', 'cemb_dashboard', [$this, 'dashboard'], 'dashicons-calendar-alt', 58);
        add_submenu_page('cemb_dashboard', 'Grundeinstellungen', 'Grundeinstellungen', 'manage_options', 'cemb_settings', [$this, 'settings']);
        add_submenu_page('cemb_dashboard', 'Terminarten', 'Terminarten', 'manage_options', 'cemb_types', [$this, 'types']);
        add_submenu_page('cemb_dashboard', 'Formularfelder', 'Formularfelder', 'manage_options', 'cemb_fields', [$this, 'fields']);
        add_submenu_page('cemb_dashboard', 'Verfügbarkeit', 'Verfügbarkeit', 'manage_options', 'cemb_availability', [$this, 'availability']);
        add_submenu_page('cemb_dashboard', 'Buchungen', 'Buchungen', 'manage_options', 'cemb_bookings', [$this, 'bookings']);
        add_submenu_page('cemb_dashboard', 'E-Mail-Vorlagen', 'E-Mail-Vorlagen', 'manage_options', 'cemb_emails', [$this, 'emails']);
        add_submenu_page('cemb_dashboard', 'Systemstatus', 'Systemstatus', 'manage_options', 'cemb_system_health', [$this, 'schedulerHealth']);
        add_submenu_page('cemb_dashboard', 'Sync-Protokoll', 'Sync-Protokoll', 'manage_options', 'cemb_sync_log', [$this, 'syncLog']);
    }

    public function handlePost(): void {
        if (!current_user_can('manage_options') || empty($_POST['cemb_admin_action'])) {
            return;
        }
        check_admin_referer('cemb_admin_action');
        global $wpdb;
        $action = sanitize_text_field(wp_unslash($_POST['cemb_admin_action']));
        switch ($action) {
            case 'save_settings':
                $settings_result = Settings::update([
                    'mode' => in_array($_POST['mode'] ?? 'automatic', ['automatic', 'approval'], true) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'automatic',
                    'sender_name' => sanitize_text_field(wp_unslash($_POST['sender_name'] ?? '')),
                    'sender_email' => sanitize_email(wp_unslash($_POST['sender_email'] ?? '')),
                    'timezone' => sanitize_text_field(wp_unslash($_POST['timezone'] ?? 'Europe/Vienna')),
                    'calendar_urls' => (string) wp_unslash($_POST['calendar_urls'] ?? ''),
                    'calendar_cache_minutes' => absint($_POST['calendar_cache_minutes'] ?? 30),
                    'notifications_enabled' => empty($_POST['notifications_enabled']) ? 0 : 1,
                    'notification_emails' => sanitize_text_field(wp_unslash($_POST['notification_emails'] ?? '')),
                    'reminders_enabled' => empty($_POST['reminders_enabled']) ? 0 : 1,
                    'reminder_hours' => absint($_POST['reminder_hours'] ?? 24),
                    'honeypot_enabled' => empty($_POST['honeypot_enabled']) ? 0 : 1,
                    'timing_enabled' => empty($_POST['timing_enabled']) ? 0 : 1,
                    'min_form_seconds' => absint($_POST['min_form_seconds'] ?? 3),
                    'rate_limit_enabled' => empty($_POST['rate_limit_enabled']) ? 0 : 1,
                    'rate_limit_requests' => absint($_POST['rate_limit_requests'] ?? 5),
                    'rate_limit_window_minutes' => absint($_POST['rate_limit_window_minutes'] ?? 15),
                    'token_ttl_minutes' => absint($_POST['token_ttl_minutes'] ?? 1440),
                    'reservation_ttl_minutes' => absint($_POST['reservation_ttl_minutes'] ?? 30),
                    'cancel_min_hours' => absint($_POST['cancel_min_hours'] ?? 2),
                    'change_min_hours' => absint($_POST['change_min_hours'] ?? 2),
                    'retention_enabled' => empty($_POST['retention_enabled']) ? 0 : 1,
                    'retention_days' => max(1, absint($_POST['retention_days'] ?? 365)),
                    'visit_address' => sanitize_textarea_field(wp_unslash($_POST['visit_address'] ?? '')),
                    'own_phone' => sanitize_text_field(wp_unslash($_POST['own_phone'] ?? '')),
                    'icloud_sync_enabled' => empty($_POST['icloud_sync_enabled']) ? 0 : 1,
                    'icloud_sync_apple_id' => sanitize_email(wp_unslash($_POST['icloud_sync_apple_id'] ?? '')),
                    'icloud_sync_password' => (string) wp_unslash($_POST['icloud_sync_password'] ?? ''),
                    'icloud_sync_target_calendar_url' => (string) wp_unslash($_POST['icloud_sync_target_calendar_url'] ?? ''),
                    'icloud_sync_target_calendar_name' => sanitize_text_field(wp_unslash($_POST['icloud_sync_target_calendar_name'] ?? 'Website Buchungen')),
                    'icloud_sync_updates' => empty($_POST['icloud_sync_updates']) ? 0 : 1,
                    'icloud_sync_cancellations' => empty($_POST['icloud_sync_cancellations']) ? 0 : 1,
                ]);
                if (is_wp_error($settings_result)) {
                    wp_safe_redirect(
                        add_query_arg(
                            [
                                'page' => 'cemb_settings',
                                'cemb_error' => $settings_result->get_error_message(),
                            ],
                            admin_url('admin.php')
                        )
                    );
                    exit;
                }
                break;
            case 'clear_calendar_cache':
                (new IcloudProvider())->clearCache();
                break;
            case 'test_icloud_sync':
                $sync = new IcloudSyncService();
                $result = $sync->testConnection();
                Settings::update(['icloud_sync_last_test' => ($result['ok'] ? 'OK: ' : 'Fehler: ') . ($result['message'] ?? '') . ' ' . current_time('mysql')]);
                break;
            case 'process_sync_queue':
                (new QueueService())->runNow();
                break;
            case 'run_hourly_tasks':
                do_action('cemb_hourly_reminders');
                break;
            case 'save_type':
                $table = $wpdb->prefix . 'cemb_booking_types';
                $data = [
                    'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                    'slug' => sanitize_title(wp_unslash($_POST['slug'] ?? '')),
                    'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
                    'duration_minutes' => absint($_POST['duration_minutes'] ?? 30),
                    'buffer_before_minutes' => absint($_POST['buffer_before_minutes'] ?? 0),
                    'buffer_after_minutes' => absint($_POST['buffer_after_minutes'] ?? 0),
                    'is_active' => empty($_POST['is_active']) ? 0 : 1,
                    'is_public' => empty($_POST['is_public']) ? 0 : 1,
                    'sort_order' => absint($_POST['sort_order'] ?? 0),
                    'updated_at' => current_time('mysql'),
                ];
                if (!empty($_POST['id'])) {
                    $wpdb->update($table, $data, ['id' => absint($_POST['id'])]);
                } else {
                    $data['created_at'] = current_time('mysql');
                    $wpdb->insert($table, $data);
                }
                break;
            case 'delete_type':
                $wpdb->delete($wpdb->prefix . 'cemb_booking_types', ['id' => absint($_POST['id'])]);
                break;
            case 'save_field':
                $table = $wpdb->prefix . 'cemb_form_fields';
                $data = [
                    'field_key' => sanitize_key(wp_unslash($_POST['field_key'] ?? '')),
                    'label' => sanitize_text_field(wp_unslash($_POST['label'] ?? '')),
                    'field_type' => sanitize_text_field(wp_unslash($_POST['field_type'] ?? 'text')),
                    'options_json' => !empty($_POST['options_raw']) ? wp_json_encode(array_values(array_filter(array_map('sanitize_text_field', array_map('trim', preg_split('/\r\n|\r|\n/', wp_unslash($_POST['options_raw']))))))) : null,
                    'is_required' => empty($_POST['is_required']) ? 0 : 1,
                    'is_active' => empty($_POST['is_active']) ? 0 : 1,
                    'sort_order' => absint($_POST['sort_order'] ?? 0),
                    'updated_at' => current_time('mysql'),
                ];
                if (!empty($_POST['id'])) {
                    $wpdb->update($table, $data, ['id' => absint($_POST['id'])]);
                } else {
                    $data['created_at'] = current_time('mysql');
                    $wpdb->insert($table, $data);
                }
                break;
            case 'delete_field':
                $wpdb->delete($wpdb->prefix . 'cemb_form_fields', ['id' => absint($_POST['id'])]);
                break;
            case 'save_rule':
                $table = $wpdb->prefix . 'cemb_availability_rules';
                $data = [
                    'scope_type' => sanitize_text_field(wp_unslash($_POST['scope_type'] ?? 'global')),
                    'scope_id' => absint($_POST['scope_id'] ?? 0) ?: null,
                    'weekday' => absint($_POST['weekday'] ?? 1),
                    'start_time' => sanitize_text_field(wp_unslash($_POST['start_time'] ?? '09:00:00')) . ':00',
                    'end_time' => sanitize_text_field(wp_unslash($_POST['end_time'] ?? '17:00:00')) . ':00',
                    'slot_duration_minutes' => absint($_POST['slot_duration_minutes'] ?? 30),
                    'buffer_before_minutes' => absint($_POST['buffer_before_minutes'] ?? 0),
                    'buffer_after_minutes' => absint($_POST['buffer_after_minutes'] ?? 0),
                    'min_notice_minutes' => absint($_POST['min_notice_minutes'] ?? 0),
                    'max_days_in_advance' => absint($_POST['max_days_in_advance'] ?? 30),
                    'is_active' => empty($_POST['is_active']) ? 0 : 1,
                    'updated_at' => current_time('mysql'),
                ];
                if (!empty($_POST['id'])) {
                    $wpdb->update($table, $data, ['id' => absint($_POST['id'])]);
                } else {
                    $data['created_at'] = current_time('mysql');
                    $wpdb->insert($table, $data);
                }
                break;
            case 'delete_rule':
                $wpdb->delete($wpdb->prefix . 'cemb_availability_rules', ['id' => absint($_POST['id'])]);
                break;
            case 'save_exception':
                $table = $wpdb->prefix . 'cemb_exceptions';
                $data = [
                    'type' => sanitize_text_field(wp_unslash($_POST['type'] ?? 'blocked_range')),
                    'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
                    'date_start' => Time::localToUtc(str_replace('T', ' ', sanitize_text_field(wp_unslash($_POST['date_start'] ?? ''))) . ':00'),
                    'date_end' => Time::localToUtc(str_replace('T', ' ', sanitize_text_field(wp_unslash($_POST['date_end'] ?? ''))) . ':00'),
                    'all_day' => empty($_POST['all_day']) ? 0 : 1,
                    'booking_type_id' => absint($_POST['booking_type_id'] ?? 0) ?: null,
                    'is_active' => empty($_POST['is_active']) ? 0 : 1,
                    'updated_at' => current_time('mysql'),
                ];
                if (!empty($_POST['id'])) {
                    $wpdb->update($table, $data, ['id' => absint($_POST['id'])]);
                } else {
                    $data['created_at'] = current_time('mysql');
                    $wpdb->insert($table, $data);
                }
                break;
            case 'delete_exception':
                $wpdb->delete($wpdb->prefix . 'cemb_exceptions', ['id' => absint($_POST['id'])]);
                break;
            case 'save_emails':
                $templates = [
                    'doi_subject', 'doi_body', 'confirmed_subject', 'confirmed_body', 'pending_subject', 'pending_body',
                    'approved_subject', 'approved_body', 'rejected_subject', 'rejected_body', 'cancelled_subject', 'cancelled_body',
                    'updated_subject', 'updated_body', 'reminder_subject', 'reminder_body', 'internal_subject', 'internal_body'
                ];
                $data = [];
                foreach ($templates as $key) {
                    $data[$key] = sanitize_textarea_field(wp_unslash($_POST[$key] ?? ''));
                }
                update_option('cemb_email_templates', $data);
                break;
            case 'booking_retention':
                $id = absint($_POST['id'] ?? 0);
                if ($id < 1) {
                    wp_die('Ungültige Buchung.');
                }
                (new PrivacyService())->setRetention($id, !empty($_POST['retain']));
                break;
            case 'booking_status':
                $id = absint($_POST['id'] ?? 0);
                $event = sanitize_key(wp_unslash($_POST['event'] ?? ''));
                $result = (new BookingTransitionService())->apply(
                    $id,
                    $event,
                    'admin',
                    'Admin booking action'
                );
                if (is_wp_error($result)) {
                    wp_die(esc_html($result->get_error_message()));
                }
                break;
        }
        wp_safe_redirect(add_query_arg(['page' => sanitize_text_field(wp_unslash($_GET['page'] ?? 'cemb_dashboard')), 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    private function formStart(): void {
        echo '<div class="wrap cemb-admin">';
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>Gespeichert.</p></div>';
        }
        if (!empty($_GET['cemb_error'])) {
            $error = sanitize_text_field(wp_unslash($_GET['cemb_error']));
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
    }
    private function formEnd(): void { echo '</div>'; }

    public function dashboard(): void {
        $this->formStart();
        $repo = new BookingRepository();
        $bookings = $repo->all(['limit' => 10]);
        echo '<h1>Kalender & Buchungen</h1><p>Shortcodes: <code>[cemb_booking_form]</code>, <code>[cemb_calendar]</code> und <code>[cemb_booking_calendar]</code></p><h2>Neueste Buchungen</h2>';
        echo '<table class="widefat"><thead><tr><th>Name</th><th>E-Mail</th><th>Termin</th><th>Status</th></tr></thead><tbody>';
        foreach ($bookings as $b) {
            echo '<tr><td>' . esc_html($b->full_name) . '</td><td>' . esc_html($b->email) . '</td><td>' . esc_html($b->slot_start) . '</td><td>' . esc_html($b->status) . '</td></tr>';
        }
        echo '</tbody></table>';
        $this->formEnd();
    }

    public function settings(): void {
        $s = Settings::get();
        $secret_status = Settings::secretStatus();
        $this->formStart();
        echo '<h1>Grundeinstellungen</h1><form method="post">';
        wp_nonce_field('cemb_admin_action');
        echo '<input type="hidden" name="cemb_admin_action" value="save_settings">';
        echo '<table class="form-table">';
        $this->row('Buchungsmodus', '<label><input type="radio" name="mode" value="automatic" ' . checked($s['mode'], 'automatic', false) . '> automatisch</label> <label><input type="radio" name="mode" value="approval" ' . checked($s['mode'], 'approval', false) . '> Admin-Freigabe</label>');
        $this->row('Absendername', '<input type="text" name="sender_name" value="' . esc_attr($s['sender_name']) . '" class="regular-text">');
        $this->row('Absender-E-Mail', '<input type="email" name="sender_email" value="' . esc_attr($s['sender_email']) . '" class="regular-text">');
        $this->row('Adresse für "Mich besuchen"', '<textarea name="visit_address" rows="3" class="regular-text">' . esc_textarea($s['visit_address']) . '</textarea>');
        $this->row('Eigene Telefonnummer', '<input type="text" name="own_phone" value="' . esc_attr($s['own_phone']) . '" class="regular-text">');
        $this->row('Öffentliche iCloud-Kalender-URLs', '<textarea name="calendar_urls" rows="6" class="large-text code" placeholder="Eine URL pro Zeile">' . esc_textarea($s['calendar_urls']) . '</textarea><p class="description">Mehrere URLs erlaubt. <code>webcal://</code> wird automatisch in <code>https://</code> umgewandelt.</p>');
        $this->row('Kalender-Cache (Min.)', '<input type="number" name="calendar_cache_minutes" value="' . esc_attr($s['calendar_cache_minutes']) . '">');
        $this->row('Interne Benachrichtigungen', '<label><input type="checkbox" name="notifications_enabled" value="1" ' . checked($s['notifications_enabled'], 1, false) . '> aktiv</label><br><input type="text" name="notification_emails" value="' . esc_attr($s['notification_emails']) . '" class="regular-text">');
        $this->row('Erinnerungen', '<label><input type="checkbox" name="reminders_enabled" value="1" ' . checked($s['reminders_enabled'], 1, false) . '> aktiv</label><br><input type="number" name="reminder_hours" value="' . esc_attr($s['reminder_hours']) . '"> Stunden vorher');
        $this->row('Token-Gültigkeit (Min.)', '<input type="number" name="token_ttl_minutes" value="' . esc_attr($s['token_ttl_minutes']) . '">');
        $this->row('Reservierungsdauer (Min.)', '<input type="number" name="reservation_ttl_minutes" value="' . esc_attr($s['reservation_ttl_minutes']) . '">');
        $this->row('Storno bis X Stunden vorher', '<input type="number" name="cancel_min_hours" value="' . esc_attr($s['cancel_min_hours']) . '">');
        $this->row('Änderung bis X Stunden vorher', '<input type="number" name="change_min_hours" value="' . esc_attr($s['change_min_hours']) . '">');
        $this->row('Datenschutz-Aufbewahrung', '<label><input type="checkbox" name="retention_enabled" value="1" ' . checked($s['retention_enabled'], 1, false) . '> automatische Anonymisierung aktivieren</label><br><input type="number" min="1" name="retention_days" value="' . esc_attr($s['retention_days']) . '"> Tage nach Terminende<p class="description">Standardmäßig deaktiviert. Persönliche Buchungsdaten werden anonymisiert, nicht der Termin-/Statusdatensatz gelöscht. Buchungen mit Aufbewahrungs-Markierung werden übersprungen.</p>');
        $this->row('Honeypot', '<label><input type="checkbox" name="honeypot_enabled" value="1" ' . checked($s['honeypot_enabled'], 1, false) . '> aktiv</label>');
        $this->row('Timing-Schutz', '<label><input type="checkbox" name="timing_enabled" value="1" ' . checked($s['timing_enabled'], 1, false) . '> aktiv</label><br><input type="number" name="min_form_seconds" value="' . esc_attr($s['min_form_seconds']) . '"> Sekunden Minimum');
        $this->row('Rate Limit', '<label><input type="checkbox" name="rate_limit_enabled" value="1" ' . checked($s['rate_limit_enabled'], 1, false) . '> aktiv</label><br><input type="number" name="rate_limit_requests" value="' . esc_attr($s['rate_limit_requests']) . '"> Anfragen in <input type="number" name="rate_limit_window_minutes" value="' . esc_attr($s['rate_limit_window_minutes']) . '"> Minuten');
        echo '</table><hr><h2>iCloud Schreibsync</h2><table class="form-table">';
        $this->row('Sync aktivieren', '<label><input type="checkbox" name="icloud_sync_enabled" value="1" ' . checked($s['icloud_sync_enabled'], 1, false) . '> neue bestätigte Buchungen nach iCloud schreiben</label>');
        $this->row('Apple-ID', '<input type="email" name="icloud_sync_apple_id" value="' . esc_attr($s['icloud_sync_apple_id']) . '" class="regular-text">');
        $secret_description = 'Noch kein Passwort gespeichert.';
        if ($secret_status['state'] === 'stored') {
            $secret_description = 'Authentifiziert verschlüsselt gespeichert (' . esc_html($secret_status['format']) . '). Das Passwort wird nie im HTML ausgegeben.';
        } elseif ($secret_status['state'] === 'reentry') {
            $secret_description = 'Das frühere Legacy-Credential wurde aus Sicherheitsgründen widerrufen. Bitte neu eingeben.';
        } elseif ($secret_status['state'] === 'invalid') {
            $secret_description = 'Das gespeicherte Credential kann nicht authentifiziert werden. Bitte neu eingeben.';
        } elseif ($secret_status['state'] === 'unavailable') {
            $secret_description = 'Sichere Speicherung ist auf diesem Server nicht verfügbar. Benötigt libsodium oder AES-256-GCM.';
        }
        $this->row('App-spezifisches Passwort', '<input type="password" name="icloud_sync_password" value="" class="regular-text" autocomplete="new-password"><p class="description">' . $secret_description . ' Leer lassen, um ein gültiges gespeichertes Passwort beizubehalten.</p>');
        $this->row('Zielkalender-URL', '<input type="url" name="icloud_sync_target_calendar_url" value="' . esc_attr($s['icloud_sync_target_calendar_url']) . '" class="large-text"><p class="description">Optional. Wenn leer, wird über den Kalendernamen gesucht.</p>');
        $this->row('Zielkalender-Name', '<input type="text" name="icloud_sync_target_calendar_name" value="' . esc_attr($s['icloud_sync_target_calendar_name']) . '" class="regular-text">');
        $this->row('Änderungen zurückschreiben', '<label><input type="checkbox" name="icloud_sync_updates" value="1" ' . checked($s['icloud_sync_updates'], 1, false) . '> aktiv</label>');
        $this->row('Stornos zurückschreiben', '<label><input type="checkbox" name="icloud_sync_cancellations" value="1" ' . checked($s['icloud_sync_cancellations'], 1, false) . '> aktiv</label>');
        $this->row('Letzter Verbindungstest', '<code>' . esc_html((string) $s['icloud_sync_last_test']) . '</code>');
        echo '</table><p><button class="button button-primary">Speichern</button></p></form>';
        echo '<p><strong>Offene Sync-Jobs:</strong> ' . (new JobRepository())->pendingCount() . '</p>';
        echo '<form method="post" style="margin-top:12px;display:inline-block">';
        wp_nonce_field('cemb_admin_action');
        echo '<input type="hidden" name="cemb_admin_action" value="test_icloud_sync"><button class="button">iCloud-Verbindung testen</button></form> ';
        echo '<form method="post" style="margin-top:12px;display:inline-block">';
        wp_nonce_field('cemb_admin_action');
        echo '<input type="hidden" name="cemb_admin_action" value="clear_calendar_cache"><button class="button">Kalender-Cache leeren</button></form> ';
        echo '<form method="post" style="margin-top:12px;display:inline-block">';
        wp_nonce_field('cemb_admin_action');
        echo '<input type="hidden" name="cemb_admin_action" value="process_sync_queue"><button class="button">Sync-Queue jetzt ausführen</button></form>';
        $this->formEnd();
    }

    public function types(): void {
        global $wpdb; $table = $wpdb->prefix . 'cemb_booking_types'; $items = $wpdb->get_results("SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC");
        $this->formStart(); echo '<h1>Terminarten</h1>'; $this->renderTypesTable($items); $this->renderTypeForm(); $this->formEnd();
    }
    public function fields(): void {
        global $wpdb; $table = $wpdb->prefix . 'cemb_form_fields'; $items = $wpdb->get_results("SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC");
        $this->formStart(); echo '<h1>Formularfelder</h1>'; $this->renderFieldsTable($items); $this->renderFieldForm(); $this->formEnd();
    }
    public function availability(): void {
        global $wpdb; $rules = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cemb_availability_rules ORDER BY scope_type ASC, weekday ASC, start_time ASC"); $exceptions = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cemb_exceptions ORDER BY date_start DESC");
        $this->formStart(); echo '<h1>Verfügbarkeit</h1>'; $this->renderRulesTable($rules); $this->renderRuleForm(); echo '<hr><h2>Ausnahmen / Sperren</h2>'; $this->renderExceptionsTable($exceptions); $this->renderExceptionForm(); $this->formEnd();
    }
    public function bookings(): void {
        $this->formStart();
        echo '<h1>Buchungen</h1>';
        $repo = new BookingRepository();
        $machine = new BookingStateMachine();
        $items = $repo->all();
        $privacy = new PrivacyService();
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Name</th><th>E-Mail</th><th>Termin</th><th>Status</th><th>Aufbewahrung</th><th>Sync</th><th>Aktion</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $meta = $repo->getMeta((int)$item->id);
            $events = $machine->adminEventsFor((string)$item->status);
            $retained = $privacy->isRetained((int)$item->id);
            echo '<tr><td>' . (int)$item->id . '</td><td>' . esc_html($item->full_name) . '</td><td>' . esc_html($item->email) . '</td><td>' . esc_html($item->slot_start) . '</td><td>' . esc_html($item->status) . '</td><td>';
            echo '<form method="post">';
            wp_nonce_field('cemb_admin_action');
            echo '<input type="hidden" name="cemb_admin_action" value="booking_retention"><input type="hidden" name="id" value="' . (int)$item->id . '">';
            echo '<label><input type="checkbox" name="retain" value="1" ' . checked($retained, true, false) . '> behalten</label> <button class="button button-small">Speichern</button></form>';
            echo '</td><td>' . esc_html((string)($meta['sync_status'] ?? '')) . (!empty($meta['sync_error']) ? '<br><small>' . esc_html((string)$meta['sync_error']) . '</small>' : '') . '</td><td>';
            if ($events) {
                echo '<form method="post">';
                wp_nonce_field('cemb_admin_action');
                echo '<input type="hidden" name="cemb_admin_action" value="booking_status"><input type="hidden" name="id" value="' . (int)$item->id . '"><select name="event">';
                foreach ($events as $event => $label) {
                    echo '<option value="' . esc_attr($event) . '">' . esc_html($label) . '</option>';
                }
                echo '</select> <button class="button">Ausführen</button></form>';
            } else {
                echo '<span class="description">Keine Aktion verfügbar</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        $this->formEnd();
    }
    public function emails(): void {
        $templates = get_option('cemb_email_templates', []);
        $this->formStart(); echo '<h1>E-Mail-Vorlagen</h1><form method="post">'; wp_nonce_field('cemb_admin_action'); echo '<input type="hidden" name="cemb_admin_action" value="save_emails">';
        $pairs = ['doi','confirmed','pending','approved','rejected','cancelled','updated','reminder','internal'];
        foreach ($pairs as $p) {
            echo '<h2>' . esc_html($p) . '</h2>';
            echo '<p><input type="text" name="' . esc_attr($p) . '_subject" value="' . esc_attr($templates[$p . '_subject'] ?? '') . '" class="large-text"></p>';
            echo '<p><textarea name="' . esc_attr($p) . '_body" rows="6" class="large-text code">' . esc_textarea($templates[$p . '_body'] ?? '') . '</textarea></p>';
        }
        echo '<p>Platzhalter: {name}, {email}, {telefon}, {terminart}, {datum}, {uhrzeit}, {bestaetigungslink}, {stornolink}, {aenderungslink}, {status}</p><p><button class="button button-primary">Speichern</button></p></form>'; $this->formEnd();
    }

    private function row(string $label, string $field): void { echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field . '</td></tr>'; }
    private function renderTypesTable(array $items): void { echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Slug</th><th>Dauer</th><th>Aktiv</th></tr></thead><tbody>'; foreach($items as $i){ echo '<tr><td>'.esc_html($i->name).'</td><td>'.esc_html($i->slug).'</td><td>'.(int)$i->duration_minutes.' Min.</td><td>'.($i->is_active?'Ja':'Nein').'</td></tr>'; } echo '</tbody></table>'; }
    private function renderTypeForm(): void { echo '<h2>Neue Terminart</h2><form method="post">'; wp_nonce_field('cemb_admin_action'); echo '<input type="hidden" name="cemb_admin_action" value="save_type"><table class="form-table">'; $this->row('Name','<input type="text" name="name" required>'); $this->row('Slug','<input type="text" name="slug" required>'); $this->row('Beschreibung','<textarea name="description"></textarea>'); $this->row('Dauer','<input type="number" name="duration_minutes" value="30">'); $this->row('Puffer davor','<input type="number" name="buffer_before_minutes" value="0">'); $this->row('Puffer danach','<input type="number" name="buffer_after_minutes" value="0">'); $this->row('Sortierung','<input type="number" name="sort_order" value="0">'); $this->row('Aktiv','<label><input type="checkbox" name="is_active" value="1" checked> aktiv</label>'); $this->row('Öffentlich','<label><input type="checkbox" name="is_public" value="1" checked> sichtbar</label>'); echo '</table><p><button class="button button-primary">Speichern</button></p></form>'; }
    private function renderFieldsTable(array $items): void { echo '<table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Typ</th><th>Optionen</th><th>Pflicht</th></tr></thead><tbody>'; foreach($items as $i){ echo '<tr><td>'.esc_html($i->field_key).'</td><td>'.esc_html($i->label).'</td><td>'.esc_html($i->field_type).'</td><td>'.esc_html(implode(', ', (array) json_decode((string)($i->options_json ?? ''), true))).'</td><td>'.($i->is_required?'Ja':'Nein').'</td></tr>'; } echo '</tbody></table>'; }
    private function renderFieldForm(): void { echo '<h2>Neues Formularfeld</h2><form method="post">'; wp_nonce_field('cemb_admin_action'); echo '<input type="hidden" name="cemb_admin_action" value="save_field"><table class="form-table">'; $this->row('Key','<input type="text" name="field_key" required>'); $this->row('Label','<input type="text" name="label" required>'); $this->row('Typ','<select name="field_type"><option value="text">Text</option><option value="email">E-Mail</option><option value="textarea">Textarea</option><option value="checkbox">Checkbox</option><option value="select">Select</option><option value="radio">Radio</option></select>'); $this->row('Optionen','<textarea name="options_raw" rows="5" class="regular-text" placeholder="Eine Option pro Zeile"></textarea>'); $this->row('Sortierung','<input type="number" name="sort_order" value="0">'); $this->row('Pflicht','<label><input type="checkbox" name="is_required" value="1"> ja</label>'); $this->row('Aktiv','<label><input type="checkbox" name="is_active" value="1" checked> ja</label>'); echo '</table><p><button class="button button-primary">Speichern</button></p></form>'; }
    private function renderRulesTable(array $items): void { echo '<h2>Regeln</h2><table class="widefat striped"><thead><tr><th>Scope</th><th>Wochentag</th><th>Zeit</th><th>Dauer</th><th>Notice</th><th>Horizont</th></tr></thead><tbody>'; foreach($items as $i){ echo '<tr><td>'.esc_html($i->scope_type . ($i->scope_id ? ' #' . $i->scope_id : '')).'</td><td>'.(int)$i->weekday.'</td><td>'.esc_html(substr($i->start_time,0,5).' - '.substr($i->end_time,0,5)).'</td><td>'.(int)$i->slot_duration_minutes.'</td><td>'.(int)$i->min_notice_minutes.'</td><td>'.(int)$i->max_days_in_advance.'</td></tr>'; } echo '</tbody></table>'; }
    private function renderRuleForm(): void { $types = (new BookingTypeRepository())->all(false); echo '<h2>Neue Regel</h2><form method="post">'; wp_nonce_field('cemb_admin_action'); echo '<input type="hidden" name="cemb_admin_action" value="save_rule"><table class="form-table">'; $options='<option value="global">global</option><option value="booking_type">Terminart</option>'; $this->row('Scope','<select name="scope_type">'.$options.'</select>'); $typeOptions='<option value="0">-</option>'; foreach($types as $type){ $typeOptions .= '<option value="'.(int)$type->id.'">'.esc_html($type->name).'</option>'; } $this->row('Terminart-ID','<select name="scope_id">'.$typeOptions.'</select>'); $this->row('Wochentag (1=Mo)','<input type="number" name="weekday" value="1" min="1" max="7">'); $this->row('Startzeit','<input type="time" name="start_time" value="09:00">'); $this->row('Endzeit','<input type="time" name="end_time" value="17:00">'); $this->row('Slot-Dauer','<input type="number" name="slot_duration_minutes" value="30">'); $this->row('Puffer davor','<input type="number" name="buffer_before_minutes" value="0">'); $this->row('Puffer danach','<input type="number" name="buffer_after_minutes" value="15">'); $this->row('Vorlaufzeit (Min.)','<input type="number" name="min_notice_minutes" value="120">'); $this->row('Max. Tage im Voraus','<input type="number" name="max_days_in_advance" value="30">'); $this->row('Aktiv','<label><input type="checkbox" name="is_active" value="1" checked> ja</label>'); echo '</table><p><button class="button button-primary">Speichern</button></p></form>'; }
    private function renderExceptionsTable(array $items): void { echo '<table class="widefat striped"><thead><tr><th>Titel</th><th>Typ</th><th>Von</th><th>Bis</th></tr></thead><tbody>'; foreach($items as $i){ echo '<tr><td>'.esc_html($i->title).'</td><td>'.esc_html($i->type).'</td><td>'.esc_html($i->date_start).'</td><td>'.esc_html($i->date_end).'</td></tr>'; } echo '</tbody></table>'; }
    private function renderExceptionForm(): void { $types = (new BookingTypeRepository())->all(false); echo '<h2>Neue Ausnahme / Sperre</h2><form method="post">'; wp_nonce_field('cemb_admin_action'); echo '<input type="hidden" name="cemb_admin_action" value="save_exception"><table class="form-table">'; $this->row('Typ','<select name="type"><option value="holiday">Feiertag</option><option value="blocked_day">Gesperrter Tag</option><option value="blocked_range">Gesperrter Zeitraum</option><option value="vacation">Urlaub</option></select>'); $this->row('Titel','<input type="text" name="title" required>'); $this->row('Von','<input type="datetime-local" name="date_start" required>'); $this->row('Bis','<input type="datetime-local" name="date_end" required>'); $typeOptions='<option value="0">alle Terminarten</option>'; foreach($types as $type){ $typeOptions .= '<option value="'.(int)$type->id.'">'.esc_html($type->name).'</option>'; } $this->row('Terminart','<select name="booking_type_id">'.$typeOptions.'</select>'); $this->row('Ganztägig','<label><input type="checkbox" name="all_day" value="1" checked> ja</label>'); $this->row('Aktiv','<label><input type="checkbox" name="is_active" value="1" checked> ja</label>'); echo '</table><p><button class="button button-primary">Speichern</button></p></form>'; }

    public function schedulerHealth(): void {
        $this->formStart();
        $health = (new SchedulerHealth())->snapshot();
        echo '<h1>Systemstatus</h1>';
        if ($health['healthy']) {
            echo '<div class="notice notice-success inline"><p>WP-Cron und Queue-Verarbeitung wirken gesund.</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>Scheduler-Warnungen:</strong></p><ul>';
            foreach ($health['warnings'] as $warning) {
                echo '<li>' . esc_html($warning) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><th>Letzter Queue-Lauf (UTC)</th><td>' . esc_html($health['last_queue_run'] ?: 'noch keiner') . '</td></tr>';
        echo '<tr><th>Nächster Queue-Lauf (UTC)</th><td>' . esc_html($health['next_queue_run'] ?: 'nicht geplant') . '</td></tr>';
        echo '<tr><th>Letzter Stundenlauf (UTC)</th><td>' . esc_html($health['last_reminder_run'] ?: 'noch keiner') . '</td></tr>';
        echo '<tr><th>Nächster Stundenlauf (UTC)</th><td>' . esc_html($health['next_reminder_run'] ?: 'nicht geplant') . '</td></tr>';
        echo '<tr><th>Queue pending / running / failed</th><td>'
            . (int)$health['counts']['pending'] . ' / '
            . (int)$health['counts']['running'] . ' / '
            . (int)$health['counts']['failed'] . '</td></tr>';
        echo '<tr><th>Abgelaufene Leases</th><td>' . (int)$health['stale_leases'] . '</td></tr>';
        echo '</tbody></table>';

        echo '<p style="margin-top:16px">';
        echo '<form method="post" style="display:inline-block;margin-right:8px">';
        wp_nonce_field('cemb_admin_action');
        echo '<input type="hidden" name="cemb_admin_action" value="process_sync_queue">';
        echo '<button class="button button-primary">Sync-Queue jetzt ausführen</button></form>';
        echo '<form method="post" style="display:inline-block">';
        wp_nonce_field('cemb_admin_action');
        echo '<input type="hidden" name="cemb_admin_action" value="run_hourly_tasks">';
        echo '<button class="button">Stündliche Aufgaben jetzt ausführen</button></form>';
        echo '</p>';
        echo '<p class="description">Für zuverlässige Produktion sollte WP-Cron durch einen echten System-Cron angestoßen werden. Siehe <code>docs/OPERATIONS.md</code>.</p>';
        $this->formEnd();
    }

    public function syncLog(): void {
        $this->formStart();
        echo '<h1>Sync-Protokoll</h1>';
        $repo = new JobRepository();
        $logs = $repo->recentLogs(100);
        echo '<table class="widefat striped"><thead><tr><th>Zeit</th><th>Booking</th><th>Level</th><th>Meldung</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            echo '<tr><td>' . esc_html($log->created_at) . '</td><td>' . (int)$log->booking_id . '</td><td>' . esc_html($log->level) . '</td><td>' . esc_html($log->message) . '</td></tr>';
        }
        echo '</tbody></table>';
        $this->formEnd();
    }
}
