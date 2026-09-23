<?php
namespace Wpcb\Admin;

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Booking\BookingStateMachine;
use Wpcb\Booking\BookingTransitionService;
use Wpcb\Sync\IcloudSyncService;
use Wpcb\Sync\QueueService;
use Wpcb\Sync\JobRepository;
use Wpcb\Calendar\IcloudProvider;
use Wpcb\Support\Time;
use Wpcb\Privacy\PrivacyService;
use Wpcb\Reliability\SchedulerHealth;
use Wpcb\Reliability\DeliveryRepository;
use Wpcb\Resources\ResourceRepository;
use Wpcb\Mail\MailDiagnostics;

class Admin {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handlePost']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_wpcb_export_bookings', [$this, 'exportBookings']);
    }

    public function assets(): void {
        wp_enqueue_style('wpcb-admin', WPCB_URL . 'assets/css/admin.css', [], WPCB_VERSION);
    }

    public function menu(): void {
        $root = __('Kalender & Buchungen', 'wordpress-calendar-booking');
        add_menu_page($root, $root, 'manage_options', 'wpcb_dashboard', [$this, 'dashboard'], 'dashicons-calendar-alt', 58);
        add_submenu_page('wpcb_dashboard', __('Grundeinstellungen', 'wordpress-calendar-booking'), __('Grundeinstellungen', 'wordpress-calendar-booking'), 'manage_options', 'wpcb_settings', [$this, 'settings']);
        add_submenu_page('wpcb_dashboard', __('Terminarten', 'wordpress-calendar-booking'), __('Terminarten', 'wordpress-calendar-booking'), 'manage_options', 'wpcb_types', [$this, 'types']);
        add_submenu_page('wpcb_dashboard', __('Formularfelder', 'wordpress-calendar-booking'), __('Formularfelder', 'wordpress-calendar-booking'), 'manage_options', 'wpcb_fields', [$this, 'fields']);
        add_submenu_page('wpcb_dashboard', __('Verfügbarkeit', 'wordpress-calendar-booking'), __('Verfügbarkeit', 'wordpress-calendar-booking'), 'manage_options', 'wpcb_availability', [$this, 'availability']);
        add_submenu_page('wpcb_dashboard', __('Buchungen', 'wordpress-calendar-booking'), __('Buchungen', 'wordpress-calendar-booking'), 'manage_options', 'wpcb_bookings', [$this, 'bookings']);
        add_submenu_page('wpcb_dashboard', __('E-Mail-Vorlagen', 'wordpress-calendar-booking'), __('E-Mail-Vorlagen', 'wordpress-calendar-booking'), 'manage_options', 'wpcb_emails', [$this, 'emails']);
        add_submenu_page('wpcb_dashboard', __('Versandprotokoll', 'wordpress-calendar-booking'), __('Versandprotokoll', 'wordpress-calendar-booking'), 'manage_options', 'wpcb_delivery_log', [$this, 'deliveryLog']);
        add_submenu_page('wpcb_dashboard', __('Systemstatus', 'wordpress-calendar-booking'), __('Systemstatus', 'wordpress-calendar-booking'), 'manage_options', 'wpcb_system_health', [$this, 'schedulerHealth']);
        add_submenu_page('wpcb_dashboard', __('Sync-Protokoll', 'wordpress-calendar-booking'), __('Sync-Protokoll', 'wordpress-calendar-booking'), 'manage_options', 'wpcb_sync_log', [$this, 'syncLog']);
    }

    public function handlePost(): void {
        if (!current_user_can('manage_options') || empty($_POST['wpcb_admin_action'])) {
            return;
        }
        check_admin_referer('wpcb_admin_action');
        global $wpdb;
        $action = sanitize_text_field(wp_unslash($_POST['wpcb_admin_action']));
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
                    'delivery_log_retention_days' => max(1, absint($_POST['delivery_log_retention_days'] ?? 90)),
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
                    'delete_data_on_uninstall' => empty($_POST['delete_data_on_uninstall']) ? 0 : 1,
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
                                'page' => 'wpcb_settings',
                                'wpcb_error' => $settings_result->get_error_message(),
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
                do_action('wpcb_hourly_reminders');
                break;
            case 'test_mail_delivery':
                $recipient = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
                if (!is_email($recipient)) {
                    $recipient = sanitize_email((string)wp_get_current_user()->user_email);
                }
                if (!is_email($recipient)) {
                    $recipient = sanitize_email((string)get_option('admin_email'));
                }
                (new MailDiagnostics())->run($recipient);
                break;
            case 'save_type':
                $table = $wpdb->prefix . 'wpcb_booking_types';
                $data = [
                    'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                    'slug' => sanitize_title(wp_unslash($_POST['slug'] ?? '')),
                    'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
                    'duration_minutes' => absint($_POST['duration_minutes'] ?? 30),
                    'buffer_before_minutes' => absint($_POST['buffer_before_minutes'] ?? 0),
                    'buffer_after_minutes' => absint($_POST['buffer_after_minutes'] ?? 0),
                    'capacity' => max(1, min(10000, absint($_POST['capacity'] ?? 1))),
                    'show_remaining_capacity' => empty($_POST['show_remaining_capacity']) ? 0 : 1,
                    'payment_mode' => in_array(($_POST['payment_mode'] ?? 'free'), ['free', 'required'], true) ? sanitize_key(wp_unslash($_POST['payment_mode'])) : 'free',
                    'price_minor' => max(0, absint($_POST['price_minor'] ?? 0)),
                    'currency' => preg_match('/^[A-Z]{3}$/', strtoupper(sanitize_text_field(wp_unslash($_POST['currency'] ?? 'EUR')))) ? strtoupper(sanitize_text_field(wp_unslash($_POST['currency'] ?? 'EUR'))) : 'EUR',
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
                do_action('wpcb_capacity_changed');
                break;
            case 'delete_type':
                $wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => absint($_POST['id'])]);
                break;
            case 'save_field':
                $table = $wpdb->prefix . 'wpcb_form_fields';
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
                $wpdb->delete($wpdb->prefix . 'wpcb_form_fields', ['id' => absint($_POST['id'])]);
                break;
            case 'save_rule':
                $table = $wpdb->prefix . 'wpcb_availability_rules';
                $scopeType = sanitize_key(wp_unslash($_POST['scope_type'] ?? 'global'));
                if (!in_array($scopeType, ['global', 'booking_type', 'resource'], true)) {
                    $scopeType = 'global';
                }
                $scopeId = $scopeType === 'resource'
                    ? absint($_POST['resource_scope_id'] ?? 0)
                    : absint($_POST['scope_id'] ?? 0);
                $data = [
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId ?: null,
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
                $wpdb->delete($wpdb->prefix . 'wpcb_availability_rules', ['id' => absint($_POST['id'])]);
                break;
            case 'save_exception':
                $table = $wpdb->prefix . 'wpcb_exceptions';
                $data = [
                    'type' => sanitize_text_field(wp_unslash($_POST['type'] ?? 'blocked_range')),
                    'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
                    'date_start' => Time::localToUtc(str_replace('T', ' ', sanitize_text_field(wp_unslash($_POST['date_start'] ?? ''))) . ':00'),
                    'date_end' => Time::localToUtc(str_replace('T', ' ', sanitize_text_field(wp_unslash($_POST['date_end'] ?? ''))) . ':00'),
                    'all_day' => empty($_POST['all_day']) ? 0 : 1,
                    'booking_type_id' => absint($_POST['booking_type_id'] ?? 0) ?: null,
                    'resource_id' => absint($_POST['resource_id'] ?? 0) ?: null,
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
                $wpdb->delete($wpdb->prefix . 'wpcb_exceptions', ['id' => absint($_POST['id'])]);
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
                update_option('wpcb_email_templates', $data);
                break;
            case 'booking_retention':
                $id = absint($_POST['id'] ?? 0);
                if ($id < 1) {
                    wp_die(esc_html__('Ungültige Buchung.', 'wordpress-calendar-booking'));
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
        wp_safe_redirect(add_query_arg(['page' => sanitize_text_field(wp_unslash($_GET['page'] ?? 'wpcb_dashboard')), 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    private function formStart(): void {
        echo '<div class="wrap wpcb-admin">';
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Gespeichert.', 'wordpress-calendar-booking') . '</p></div>';
        }
        if (!empty($_GET['wpcb_error'])) {
            $error = sanitize_text_field(wp_unslash($_GET['wpcb_error']));
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }
    }
    private function formEnd(): void { echo '</div>'; }

    public function dashboard(): void {
        $this->formStart();
        $repo = new BookingRepository();
        $bookings = $repo->all(['limit' => 10]);
        $readiness = (new SetupReadiness())->snapshot();

        echo '<h1>' . esc_html__('Kalender & Buchungen', 'wordpress-calendar-booking') . '</h1>';
        echo '<h2>' . esc_html__('Einrichtung & Bereitschaft', 'wordpress-calendar-booking') . '</h2>';

        if (!empty($readiness['ready'])) {
            echo '<div class="notice notice-success inline"><p><strong>' . esc_html__('Bereit für Buchungen.', 'wordpress-calendar-booking') . '</strong> ' . esc_html__('Die erforderlichen Core-Einstellungen sind vollständig.', 'wordpress-calendar-booking') . '</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Einrichtung unvollständig.', 'wordpress-calendar-booking') . '</strong> ' . esc_html__('Arbeite die offenen Punkte ab, bevor du die Buchungsseite veröffentlichst.', 'wordpress-calendar-booking') . '</p></div>';
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Status', 'wordpress-calendar-booking') . '</th><th>' . esc_html__('Schritt', 'wordpress-calendar-booking') . '</th><th>' . esc_html__('Hinweis', 'wordpress-calendar-booking') . '</th><th>' . esc_html__('Aktion', 'wordpress-calendar-booking') . '</th></tr></thead><tbody>';
        foreach ((array)($readiness['items'] ?? []) as $item) {
            $ready = !empty($item['ready']);
            echo '<tr>';
            echo '<td><strong>' . esc_html($ready ? __('OK', 'wordpress-calendar-booking') : __('Offen', 'wordpress-calendar-booking')) . '</strong></td>';
            echo '<td>' . esc_html((string)$item['label']) . '</td>';
            echo '<td>' . esc_html((string)$item['detail']) . '</td>';
            echo '<td><a class="button' . ($ready ? '' : ' button-primary') . '" href="' . esc_url((string)$item['url']) . '">' . esc_html($ready ? __('Prüfen', 'wordpress-calendar-booking') : __('Einrichten', 'wordpress-calendar-booking')) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<p>' . esc_html__('Optionale Kalender-, Zahlungs-, Webhook- und Video-Meeting-Verbindungen blockieren die Core-Bereitschaft nicht.', 'wordpress-calendar-booking') . '</p>';
        echo '<p>' . esc_html__('Shortcodes:', 'wordpress-calendar-booking') . ' <code>[wpcb_booking_form]</code>, <code>[wpcb_calendar]</code> ' . esc_html__('und', 'wordpress-calendar-booking') . ' <code>[wpcb_booking_calendar]</code></p>';

        echo '<h2>' . esc_html__('Neueste Buchungen', 'wordpress-calendar-booking') . '</h2>';
        echo '<table class="widefat"><thead><tr>';
        foreach ([__('Name','wordpress-calendar-booking'), __('E-Mail','wordpress-calendar-booking'), __('Termin','wordpress-calendar-booking'), __('Status','wordpress-calendar-booking')] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
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
        echo '<h1>' . esc_html__('Grundeinstellungen', 'wordpress-calendar-booking') . '</h1><form method="post">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="save_settings">';
        echo '<table class="form-table">';
        $this->row(__('Buchungsmodus', 'wordpress-calendar-booking'), '<label><input type="radio" name="mode" value="automatic" ' . checked($s['mode'], 'automatic', false) . '> ' . esc_html__('automatisch', 'wordpress-calendar-booking') . '</label> <label><input type="radio" name="mode" value="approval" ' . checked($s['mode'], 'approval', false) . '> ' . esc_html__('Admin-Freigabe', 'wordpress-calendar-booking') . '</label>');
        $this->row(__('Absendername', 'wordpress-calendar-booking'), '<input type="text" name="sender_name" value="' . esc_attr($s['sender_name']) . '" class="regular-text">');
        $this->row(__('Absender-E-Mail', 'wordpress-calendar-booking'), '<input type="email" name="sender_email" value="' . esc_attr($s['sender_email']) . '" class="regular-text">');
        $this->row(__('Adresse für "Mich besuchen"', 'wordpress-calendar-booking'), '<textarea name="visit_address" rows="3" class="regular-text">' . esc_textarea($s['visit_address']) . '</textarea>');
        $this->row(__('Eigene Telefonnummer', 'wordpress-calendar-booking'), '<input type="text" name="own_phone" value="' . esc_attr($s['own_phone']) . '" class="regular-text">');
        $this->row(__('Öffentliche iCloud-Kalender-URLs', 'wordpress-calendar-booking'), '<textarea name="calendar_urls" rows="6" class="large-text code" placeholder="' . esc_attr__('Eine URL pro Zeile', 'wordpress-calendar-booking') . '">' . esc_textarea($s['calendar_urls']) . '</textarea><p class="description">' . wp_kses_post(__('Mehrere URLs erlaubt. <code>webcal://</code> wird automatisch in <code>https://</code> umgewandelt.', 'wordpress-calendar-booking')) . '</p>');
        $this->row(__('Kalender-Cache (Min.)', 'wordpress-calendar-booking'), '<input type="number" name="calendar_cache_minutes" value="' . esc_attr($s['calendar_cache_minutes']) . '">');
        $this->row(__('Interne Benachrichtigungen', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="notifications_enabled" value="1" ' . checked($s['notifications_enabled'], 1, false) . '> ' . esc_html__('aktiv', 'wordpress-calendar-booking') . '</label><br><input type="text" name="notification_emails" value="' . esc_attr($s['notification_emails']) . '" class="regular-text">');
        $this->row(__('Erinnerungen', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="reminders_enabled" value="1" ' . checked($s['reminders_enabled'], 1, false) . '> ' . esc_html__('aktiv', 'wordpress-calendar-booking') . '</label><br><input type="number" name="reminder_hours" value="' . esc_attr($s['reminder_hours']) . '"> ' . esc_html__('Stunden vorher', 'wordpress-calendar-booking'));
        $this->row(__('Versandprotokoll-Aufbewahrung', 'wordpress-calendar-booking'), '<input type="number" min="1" name="delivery_log_retention_days" value="' . esc_attr($s['delivery_log_retention_days']) . '"> ' . esc_html__('Tage', 'wordpress-calendar-booking') . '<p class="description">' . esc_html__('Gesendete und fehlgeschlagene Versanddatensätze werden danach automatisch entfernt. Nachrichtentexte werden nicht im Versandprotokoll gespeichert.', 'wordpress-calendar-booking') . '</p>');
        $this->row(__('Token-Gültigkeit (Min.)', 'wordpress-calendar-booking'), '<input type="number" name="token_ttl_minutes" value="' . esc_attr($s['token_ttl_minutes']) . '">');
        $this->row(__('Reservierungsdauer (Min.)', 'wordpress-calendar-booking'), '<input type="number" name="reservation_ttl_minutes" value="' . esc_attr($s['reservation_ttl_minutes']) . '">');
        $this->row(__('Storno bis X Stunden vorher', 'wordpress-calendar-booking'), '<input type="number" name="cancel_min_hours" value="' . esc_attr($s['cancel_min_hours']) . '">');
        $this->row(__('Änderung bis X Stunden vorher', 'wordpress-calendar-booking'), '<input type="number" name="change_min_hours" value="' . esc_attr($s['change_min_hours']) . '">');
        $this->row(__('Datenschutz-Aufbewahrung', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="retention_enabled" value="1" ' . checked($s['retention_enabled'], 1, false) . '> ' . esc_html__('automatische Anonymisierung aktivieren', 'wordpress-calendar-booking') . '</label><br><input type="number" min="1" name="retention_days" value="' . esc_attr($s['retention_days']) . '"> ' . esc_html__('Tage nach Terminende', 'wordpress-calendar-booking') . '<p class="description">' . esc_html__('Standardmäßig deaktiviert. Persönliche Buchungsdaten werden anonymisiert, nicht der Termin-/Statusdatensatz gelöscht. Buchungen mit Aufbewahrungs-Markierung werden übersprungen.', 'wordpress-calendar-booking') . '</p>');
        $this->row(__('Daten bei Deinstallation', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="delete_data_on_uninstall" value="1" ' . checked($s['delete_data_on_uninstall'], 1, false) . '> ' . esc_html__('alle WordPress Calendar Booking-Daten beim Löschen des Plugins endgültig entfernen', 'wordpress-calendar-booking') . '</label><p class="description">' . wp_kses_post(__('<strong>Achtung:</strong> Standardmäßig bleiben Buchungen, Kunden-, Zahlungs-, Kalender- und Konfigurationsdaten bei einer Deinstallation erhalten. Diese Option löscht beim Deinstallieren alle <code>wpcb_*</code>-Tabellen und -Optionen unwiderruflich. Für normale Datenschutzlöschung die WordPress-Datenschutzwerkzeuge bzw. Aufbewahrung verwenden.', 'wordpress-calendar-booking')) . '</p>');
        $this->row(__('Honeypot', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="honeypot_enabled" value="1" ' . checked($s['honeypot_enabled'], 1, false) . '> ' . esc_html__('aktiv', 'wordpress-calendar-booking') . '</label>');
        $this->row(__('Timing-Schutz', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="timing_enabled" value="1" ' . checked($s['timing_enabled'], 1, false) . '> ' . esc_html__('aktiv', 'wordpress-calendar-booking') . '</label><br><input type="number" name="min_form_seconds" value="' . esc_attr($s['min_form_seconds']) . '"> ' . esc_html__('Sekunden Minimum', 'wordpress-calendar-booking'));
        $this->row(__('Rate Limit', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="rate_limit_enabled" value="1" ' . checked($s['rate_limit_enabled'], 1, false) . '> ' . esc_html__('aktiv', 'wordpress-calendar-booking') . '</label><br><input type="number" name="rate_limit_requests" value="' . esc_attr($s['rate_limit_requests']) . '"> ' . esc_html__('Anfragen in', 'wordpress-calendar-booking') . ' <input type="number" name="rate_limit_window_minutes" value="' . esc_attr($s['rate_limit_window_minutes']) . '"> ' . esc_html__('Minuten', 'wordpress-calendar-booking'));
        echo '</table><hr><h2>' . esc_html__('iCloud Schreibsync', 'wordpress-calendar-booking') . '</h2><table class="form-table">';
        $this->row(__('Sync aktivieren', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="icloud_sync_enabled" value="1" ' . checked($s['icloud_sync_enabled'], 1, false) . '> ' . esc_html__('neue bestätigte Buchungen nach iCloud schreiben', 'wordpress-calendar-booking') . '</label>');
        $this->row(__('Apple-ID', 'wordpress-calendar-booking'), '<input type="email" name="icloud_sync_apple_id" value="' . esc_attr($s['icloud_sync_apple_id']) . '" class="regular-text">');
        $secret_description = __('Noch kein Passwort gespeichert.', 'wordpress-calendar-booking');
        if ($secret_status['state'] === 'stored') {
            $secret_description = sprintf(
                __('Authentifiziert verschlüsselt gespeichert (%s). Das Passwort wird nie im HTML ausgegeben.', 'wordpress-calendar-booking'),
                (string)$secret_status['format']
            );
        } elseif ($secret_status['state'] === 'reentry') {
            $secret_description = __('Das frühere Legacy-Credential wurde aus Sicherheitsgründen widerrufen. Bitte neu eingeben.', 'wordpress-calendar-booking');
        } elseif ($secret_status['state'] === 'invalid') {
            $secret_description = __('Das gespeicherte Credential kann nicht authentifiziert werden. Bitte neu eingeben.', 'wordpress-calendar-booking');
        } elseif ($secret_status['state'] === 'unavailable') {
            $secret_description = __('Sichere Speicherung ist auf diesem Server nicht verfügbar. Benötigt libsodium oder AES-256-GCM.', 'wordpress-calendar-booking');
        }
        $this->row(__('App-spezifisches Passwort', 'wordpress-calendar-booking'), '<input type="password" name="icloud_sync_password" value="" class="regular-text" autocomplete="new-password"><p class="description">' . esc_html($secret_description) . ' ' . esc_html__('Leer lassen, um ein gültiges gespeichertes Passwort beizubehalten.', 'wordpress-calendar-booking') . '</p>');
        $this->row(__('Zielkalender-URL', 'wordpress-calendar-booking'), '<input type="url" name="icloud_sync_target_calendar_url" value="' . esc_attr($s['icloud_sync_target_calendar_url']) . '" class="large-text"><p class="description">' . esc_html__('Optional. Wenn leer, wird über den Kalendernamen gesucht.', 'wordpress-calendar-booking') . '</p>');
        $this->row(__('Zielkalender-Name', 'wordpress-calendar-booking'), '<input type="text" name="icloud_sync_target_calendar_name" value="' . esc_attr($s['icloud_sync_target_calendar_name']) . '" class="regular-text">');
        $this->row(__('Änderungen zurückschreiben', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="icloud_sync_updates" value="1" ' . checked($s['icloud_sync_updates'], 1, false) . '> ' . esc_html__('aktiv', 'wordpress-calendar-booking') . '</label>');
        $this->row(__('Stornos zurückschreiben', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="icloud_sync_cancellations" value="1" ' . checked($s['icloud_sync_cancellations'], 1, false) . '> ' . esc_html__('aktiv', 'wordpress-calendar-booking') . '</label>');
        $this->row(__('Letzter Verbindungstest', 'wordpress-calendar-booking'), '<code>' . esc_html((string)$s['icloud_sync_last_test']) . '</code>');
        echo '</table><p><button class="button button-primary">' . esc_html__('Speichern', 'wordpress-calendar-booking') . '</button></p></form>';
        echo '<p><strong>' . esc_html__('Offene Sync-Jobs:', 'wordpress-calendar-booking') . '</strong> ' . (int)(new JobRepository())->pendingCount() . '</p>';
        echo '<form method="post" style="margin-top:12px;display:inline-block">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="test_icloud_sync"><button class="button">' . esc_html__('iCloud-Verbindung testen', 'wordpress-calendar-booking') . '</button></form> ';
        echo '<form method="post" style="margin-top:12px;display:inline-block">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="clear_calendar_cache"><button class="button">' . esc_html__('Kalender-Cache leeren', 'wordpress-calendar-booking') . '</button></form> ';
        echo '<form method="post" style="margin-top:12px;display:inline-block">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="process_sync_queue"><button class="button">' . esc_html__('Sync-Queue jetzt ausführen', 'wordpress-calendar-booking') . '</button></form>';
        $this->formEnd();
    }

    public function types(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_booking_types';
        $items = $wpdb->get_results("SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC");
        $this->formStart();
        echo '<h1>' . esc_html__('Terminarten', 'wordpress-calendar-booking') . '</h1>';
        $this->renderTypesTable($items);
        $this->renderTypeForm();
        $this->formEnd();
    }
    public function fields(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_form_fields';
        $items = $wpdb->get_results("SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC");
        $this->formStart();
        echo '<h1>' . esc_html__('Formularfelder', 'wordpress-calendar-booking') . '</h1>';
        $this->renderFieldsTable($items);
        $this->renderFieldForm();
        $this->formEnd();
    }
    public function availability(): void {
        global $wpdb;
        $rules = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpcb_availability_rules ORDER BY scope_type ASC, weekday ASC, start_time ASC");
        $exceptions = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpcb_exceptions ORDER BY date_start DESC");
        $this->formStart();
        echo '<h1>' . esc_html__('Verfügbarkeit', 'wordpress-calendar-booking') . '</h1>';
        $this->renderRulesTable($rules);
        $this->renderRuleForm();
        echo '<hr><h2>' . esc_html__('Ausnahmen / Sperren', 'wordpress-calendar-booking') . '</h2>';
        $this->renderExceptionsTable($exceptions);
        $this->renderExceptionForm();
        $this->formEnd();
    }
    public function bookings(): void {
        $this->formStart();
        echo '<h1>' . esc_html__('Buchungen', 'wordpress-calendar-booking') . '</h1>';

        $repo = new BookingRepository();
        $machine = new BookingStateMachine();
        $filters = $this->bookingFilters($_GET);
        $perPage = 50;
        $total = $repo->count($filters);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $requestedPage = max(1, absint($_GET['paged'] ?? 1));
        $page = min($requestedPage, $totalPages);

        $pageFilters = $filters;
        $pageFilters['limit'] = $perPage;
        $pageFilters['offset'] = ($page - 1) * $perPage;
        $items = $repo->all($pageFilters);

        $bookingIds = array_map(
            static fn($item): int => (int)$item->id,
            $items
        );
        $pageMeta = $repo->metaForBookings(
            $bookingIds,
            ['sync_status', 'sync_error', 'privacy_retain']
        );

        $types = (new BookingTypeRepository())->all(false);
        echo '<form method="get" style="margin:12px 0;padding:12px;background:#fff;border:1px solid #ccd0d4">';
        echo '<input type="hidden" name="page" value="wpcb_bookings">';
        echo '<label>' . esc_html__('Von', 'wordpress-calendar-booking') . ' <input type="date" name="from" value="' . esc_attr($filters['from_date'] ?? '') . '"></label> ';
        echo '<label>' . esc_html__('Bis', 'wordpress-calendar-booking') . ' <input type="date" name="to" value="' . esc_attr($filters['to_date'] ?? '') . '"></label> ';
        echo '<label>' . esc_html__('Status', 'wordpress-calendar-booking') . ' <select name="status"><option value="">' . esc_html__('alle', 'wordpress-calendar-booking') . '</option>';
        foreach (\Wpcb\Booking\BookingStatus::all() as $status) {
            echo '<option value="' . esc_attr($status) . '" ' . selected($filters['status'] ?? '', $status, false) . '>' . esc_html($status) . '</option>';
        }
        echo '</select></label> <label>' . esc_html__('Terminart', 'wordpress-calendar-booking') . ' <select name="booking_type_id"><option value="0">' . esc_html__('alle', 'wordpress-calendar-booking') . '</option>';
        foreach ($types as $type) {
            echo '<option value="' . (int)$type->id . '" ' . selected((int)($filters['booking_type_id'] ?? 0), (int)$type->id, false) . '>' . esc_html($type->name) . '</option>';
        }
        echo '</select></label> <button class="button">' . esc_html__('Filtern', 'wordpress-calendar-booking') . '</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=wpcb_bookings')) . '">' . esc_html__('Zurücksetzen', 'wordpress-calendar-booking') . '</a></form>';

        $exportArgs = [
            'action' => 'wpcb_export_bookings',
            '_wpnonce' => wp_create_nonce('wpcb_export_bookings'),
            'from' => $filters['from_date'] ?? '',
            'to' => $filters['to_date'] ?? '',
            'status' => $filters['status'] ?? '',
            'booking_type_id' => (int)($filters['booking_type_id'] ?? 0),
        ];
        echo '<p><a class="button button-primary" href="' . esc_url(add_query_arg($exportArgs, admin_url('admin-post.php'))) . '">' . esc_html__('CSV exportieren', 'wordpress-calendar-booking') . '</a></p>';
        echo '<p class="description">' . esc_html(sprintf(
            _n('%s Buchung', '%s Buchungen', $total, 'wordpress-calendar-booking'),
            number_format_i18n($total)
        )) . '</p>';

        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('ID', 'wordpress-calendar-booking'),
            __('Name', 'wordpress-calendar-booking'),
            __('E-Mail', 'wordpress-calendar-booking'),
            __('Termin', 'wordpress-calendar-booking'),
            __('Status', 'wordpress-calendar-booking'),
            __('Aufbewahrung', 'wordpress-calendar-booking'),
            __('Sync', 'wordpress-calendar-booking'),
            __('Aktion', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            $meta = $pageMeta[(int)$item->id] ?? [];
            $events = $machine->adminEventsFor((string)$item->status);
            $retained = '1' === (string)($meta['privacy_retain'] ?? '');

            echo '<tr><td>' . (int)$item->id . '</td><td>' . esc_html($item->full_name) . '</td><td>' . esc_html($item->email) . '</td><td>' . esc_html($item->slot_start) . '<br><small>' . max(1, (int)($item->party_size ?? 1)) . ' ' . esc_html__('Teilnehmer', 'wordpress-calendar-booking') . '</small></td><td>' . esc_html($item->status) . '</td><td>';
            echo '<form method="post">';
            wp_nonce_field('wpcb_admin_action');
            echo '<input type="hidden" name="wpcb_admin_action" value="booking_retention"><input type="hidden" name="id" value="' . (int)$item->id . '">';
            echo '<label><input type="checkbox" name="retain" value="1" ' . checked($retained, true, false) . '> ' . esc_html__('behalten', 'wordpress-calendar-booking') . '</label> <button class="button button-small">' . esc_html__('Speichern', 'wordpress-calendar-booking') . '</button></form>';
            echo '</td><td>' . esc_html((string)($meta['sync_status'] ?? '')) . (!empty($meta['sync_error']) ? '<br><small>' . esc_html((string)$meta['sync_error']) . '</small>' : '') . '</td><td>';

            if ($events) {
                echo '<form method="post">';
                wp_nonce_field('wpcb_admin_action');
                echo '<input type="hidden" name="wpcb_admin_action" value="booking_status"><input type="hidden" name="id" value="' . (int)$item->id . '"><select name="event">';
                foreach ($events as $event => $label) {
                    echo '<option value="' . esc_attr($event) . '">' . esc_html($label) . '</option>';
                }
                echo '</select> <button class="button">' . esc_html__('Ausführen', 'wordpress-calendar-booking') . '</button></form>';
            } else {
                echo '<span class="description">' . esc_html__('Keine Aktion verfügbar', 'wordpress-calendar-booking') . '</span>';
            }
            echo '</td></tr>';
        }

        if (!$items) {
            echo '<tr><td colspan="8">' . esc_html__('Keine Buchungen gefunden.', 'wordpress-calendar-booking') . '</td></tr>';
        }
        echo '</tbody></table>';

        if ($totalPages > 1) {
            $paginationArgs = ['page' => 'wpcb_bookings'];
            if (!empty($filters['from_date'])) {
                $paginationArgs['from'] = $filters['from_date'];
            }
            if (!empty($filters['to_date'])) {
                $paginationArgs['to'] = $filters['to_date'];
            }
            if (!empty($filters['status'])) {
                $paginationArgs['status'] = $filters['status'];
            }
            if (!empty($filters['booking_type_id'])) {
                $paginationArgs['booking_type_id'] = (int)$filters['booking_type_id'];
            }

            $paginationBase = add_query_arg(
                array_merge($paginationArgs, ['paged' => 999999999]),
                admin_url('admin.php')
            );
            $links = paginate_links([
                'base' => str_replace('999999999', '%#%', esc_url($paginationBase)),
                'format' => '',
                'current' => $page,
                'total' => $totalPages,
                'type' => 'list',
                'prev_text' => __('« Zurück', 'wordpress-calendar-booking'),
                'next_text' => __('Weiter »', 'wordpress-calendar-booking'),
            ]);
            if ($links) {
                echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
            }
        }

        $this->formEnd();
    }

    public function exportBookings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'wordpress-calendar-booking'), '', ['response' => 403]);
        }
        check_admin_referer('wpcb_export_bookings');
        $filters = $this->bookingFilters($_GET);
        $items = (new BookingRepository())->all($filters);
        $history = get_option('wpcb_booking_export_audit', []);
        if (!is_array($history)) {
            $history = [];
        }
        array_unshift($history, [
            'exported_at' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
            'filters' => [
                'from' => $filters['from_date'] ?? '',
                'to' => $filters['to_date'] ?? '',
                'status' => $filters['status'] ?? '',
                'booking_type_id' => (int)($filters['booking_type_id'] ?? 0),
            ],
            'row_count' => count($items),
        ]);
        update_option('wpcb_booking_export_audit', array_slice($history, 0, 50), false);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="calendar-bookings-' . gmdate('Y-m-d-His') . '.csv"');
        $out = fopen('php://output', 'wb');
        if (!$out) {
            wp_die(esc_html__('CSV-Ausgabe konnte nicht geöffnet werden.', 'wordpress-calendar-booking'));
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [__('ID', 'wordpress-calendar-booking'), __('Terminart-ID', 'wordpress-calendar-booking'), __('Start (UTC)', 'wordpress-calendar-booking'), __('Ende (UTC)', 'wordpress-calendar-booking'), __('Status', 'wordpress-calendar-booking'), __('Name', 'wordpress-calendar-booking'), __('E-Mail', 'wordpress-calendar-booking'), __('Telefon', 'wordpress-calendar-booking'), __('Notiz', 'wordpress-calendar-booking'), __('Quelle', 'wordpress-calendar-booking'), __('Sprache', 'wordpress-calendar-booking'), __('Erstellt (UTC)', 'wordpress-calendar-booking')]);
        foreach ($items as $item) {
            fputcsv($out, [
                (int)$item->id,
                (int)$item->booking_type_id,
                (string)$item->slot_start,
                (string)$item->slot_end,
                (string)$item->status,
                (string)$item->full_name,
                (string)$item->email,
                (string)$item->phone,
                (string)$item->notes,
                (string)$item->source,
                (string)$item->lang,
                (string)$item->created_at,
            ]);
        }
        fclose($out);
        exit;
    }

    private function bookingFilters(array $input): array {
        $filters = [];
        $status = sanitize_key(wp_unslash($input['status'] ?? ''));
        if ($status !== '' && in_array($status, \Wpcb\Booking\BookingStatus::all(), true)) {
            $filters['status'] = $status;
        }
        $bookingTypeId = absint($input['booking_type_id'] ?? 0);
        if ($bookingTypeId > 0) {
            $filters['booking_type_id'] = $bookingTypeId;
        }
        $from = sanitize_text_field(wp_unslash($input['from'] ?? ''));
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $from)) {
            $filters['from_date'] = $from;
            $filters['from'] = Time::localToUtc($from . ' 00:00:00');
        }
        $to = sanitize_text_field(wp_unslash($input['to'] ?? ''));
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $to)) {
            $filters['to_date'] = $to;
            $filters['to'] = Time::localToUtc($to . ' 23:59:59');
        }
        return $filters;
    }

    public function deliveryLog(): void {
        $this->formStart();
        echo '<h1>' . esc_html__('Versandprotokoll', 'wordpress-calendar-booking') . '</h1>';
        $repo = new DeliveryRepository();
        $filters = [
            'booking_id' => absint($_GET['booking_id'] ?? 0),
            'status' => sanitize_key(wp_unslash($_GET['delivery_status'] ?? '')),
            'effect_type' => sanitize_text_field(wp_unslash($_GET['delivery_type'] ?? '')),
            'recipient_class' => sanitize_key(wp_unslash($_GET['recipient_class'] ?? '')),
        ];
        $items = $repo->search($filters, 200);
        $types = $repo->effectTypes();

        echo '<form method="get" style="margin:12px 0;padding:12px;background:#fff;border:1px solid #ccd0d4">';
        echo '<input type="hidden" name="page" value="wpcb_delivery_log">';
        echo '<label>' . esc_html__('Buchung', 'wordpress-calendar-booking') . ' <input type="number" min="1" name="booking_id" value="' . esc_attr($filters['booking_id'] ?: '') . '"></label> ';
        echo '<label>' . esc_html__('Status', 'wordpress-calendar-booking') . ' <select name="delivery_status"><option value="">' . esc_html__('alle', 'wordpress-calendar-booking') . '</option>';
        foreach (['pending','sending','sent','failed'] as $status) {
            echo '<option value="' . esc_attr($status) . '" ' . selected($filters['status'], $status, false) . '>' . esc_html($status) . '</option>';
        }
        echo '</select></label> <label>' . esc_html__('Typ', 'wordpress-calendar-booking') . ' <select name="delivery_type"><option value="">' . esc_html__('alle', 'wordpress-calendar-booking') . '</option>';
        foreach ($types as $type) {
            echo '<option value="' . esc_attr($type) . '" ' . selected($filters['effect_type'], $type, false) . '>' . esc_html($type) . '</option>';
        }
        echo '</select></label> <label>' . esc_html__('Empfänger', 'wordpress-calendar-booking') . ' <select name="recipient_class"><option value="">' . esc_html__('alle', 'wordpress-calendar-booking') . '</option>';
        foreach (['customer' => __('Kunde', 'wordpress-calendar-booking'), 'admin' => __('Admin', 'wordpress-calendar-booking')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($filters['recipient_class'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> <button class="button">' . esc_html__('Filtern', 'wordpress-calendar-booking') . '</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=wpcb_delivery_log')) . '">' . esc_html__('Zurücksetzen', 'wordpress-calendar-booking') . '</a></form>';

        echo '<p class="description">' . esc_html__('Das Protokoll enthält keine Nachrichtentexte, OAuth-Tokens oder Kalender-Zugangsdaten.', 'wordpress-calendar-booking') . '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Versuch (UTC)', 'wordpress-calendar-booking'),
            __('Buchung', 'wordpress-calendar-booking'),
            __('Empfänger', 'wordpress-calendar-booking'),
            __('Typ', 'wordpress-calendar-booking'),
            __('Status', 'wordpress-calendar-booking'),
            __('Provider', 'wordpress-calendar-booking'),
            __('Fehlercode', 'wordpress-calendar-booking'),
            __('Idempotency-Key', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $item) {
            $attemptAt = (string)($item->last_attempt_at ?: $item->created_at);
            echo '<tr><td>' . esc_html($attemptAt) . '</td><td>' . (int)$item->booking_id . '</td><td>' . esc_html((string)$item->recipient_class) . '</td><td>' . esc_html((string)$item->effect_type) . '</td><td>' . esc_html((string)$item->status) . '</td><td>' . esc_html((string)$item->provider_code) . '</td><td>' . esc_html((string)$item->last_error_code) . '</td><td><code>' . esc_html((string)$item->idempotency_key) . '</code></td></tr>';
        }
        echo '</tbody></table>';
        $this->formEnd();
    }

    public function emails(): void {
        $templates = get_option('wpcb_email_templates', []);
        $this->formStart();
        echo '<h1>' . esc_html__('E-Mail-Vorlagen', 'wordpress-calendar-booking') . '</h1><form method="post">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="save_emails">';
        $pairs = ['doi','confirmed','pending','approved','rejected','cancelled','updated','reminder','internal'];
        foreach ($pairs as $p) {
            echo '<h2>' . esc_html($p) . '</h2>';
            echo '<p><input type="text" name="' . esc_attr($p) . '_subject" value="' . esc_attr($templates[$p . '_subject'] ?? '') . '" class="large-text"></p>';
            echo '<p><textarea name="' . esc_attr($p) . '_body" rows="6" class="large-text code">' . esc_textarea($templates[$p . '_body'] ?? '') . '</textarea></p>';
        }
        echo '<p>' . esc_html__('Platzhalter:', 'wordpress-calendar-booking') . ' {name}, {email}, {telefon}, {terminart}, {teilnehmer}, {datum}, {uhrzeit}, {bestaetigungslink}, {stornolink}, {aenderungslink}, {status}</p>';
        echo '<p><button class="button button-primary">' . esc_html__('Speichern', 'wordpress-calendar-booking') . '</button></p></form>';
        $this->formEnd();
    }

    private function row(string $label, string $field): void {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field . '</td></tr>';
    }

    private function renderTypesTable(array $items): void {
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Name', 'wordpress-calendar-booking'),
            __('Slug', 'wordpress-calendar-booking'),
            __('Dauer', 'wordpress-calendar-booking'),
            __('Kapazität', 'wordpress-calendar-booking'),
            __('Zahlung', 'wordpress-calendar-booking'),
            __('Aktiv', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $i) {
            $payment = (string)($i->payment_mode ?? 'free') === 'required'
                ? number_format(((int)($i->price_minor ?? 0)) / 100, 2, ',', '.') . ' ' . esc_html((string)($i->currency ?? 'EUR'))
                : esc_html__('Kostenlos', 'wordpress-calendar-booking');
            echo '<tr><td>' . esc_html($i->name) . '</td><td>' . esc_html($i->slug) . '</td><td>' . (int)$i->duration_minutes . ' ' . esc_html__('Min.', 'wordpress-calendar-booking') . '</td><td>' . max(1, (int)($i->capacity ?? 1)) . '</td><td>' . $payment . '</td><td>' . esc_html($i->is_active ? __('Ja', 'wordpress-calendar-booking') : __('Nein', 'wordpress-calendar-booking')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderTypeForm(): void {
        echo '<h2>' . esc_html__('Neue Terminart', 'wordpress-calendar-booking') . '</h2><form method="post">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="save_type"><table class="form-table">';
        $this->row(__('Name', 'wordpress-calendar-booking'), '<input type="text" name="name" required>');
        $this->row(__('Slug', 'wordpress-calendar-booking'), '<input type="text" name="slug" required>');
        $this->row(__('Beschreibung', 'wordpress-calendar-booking'), '<textarea name="description"></textarea>');
        $this->row(__('Dauer', 'wordpress-calendar-booking'), '<input type="number" name="duration_minutes" value="30">');
        $this->row(__('Puffer davor', 'wordpress-calendar-booking'), '<input type="number" name="buffer_before_minutes" value="0">');
        $this->row(__('Puffer danach', 'wordpress-calendar-booking'), '<input type="number" name="buffer_after_minutes" value="0">');
        $this->row(__('Kapazität', 'wordpress-calendar-booking'), '<input type="number" name="capacity" min="1" max="10000" value="1">');
        $this->row(__('Restplätze öffentlich', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="show_remaining_capacity" value="1"> ' . esc_html__('anzeigen', 'wordpress-calendar-booking') . '</label>');
        $this->row(__('Zahlung', 'wordpress-calendar-booking'), '<select name="payment_mode"><option value="free">' . esc_html__('Kostenlos', 'wordpress-calendar-booking') . '</option><option value="required">' . esc_html__('Zahlung erforderlich', 'wordpress-calendar-booking') . '</option></select>');
        $this->row(__('Preis (Cent)', 'wordpress-calendar-booking'), '<input type="number" name="price_minor" min="0" value="0">');
        $this->row(__('Währung', 'wordpress-calendar-booking'), '<input type="text" name="currency" maxlength="3" value="EUR">');
        $this->row(__('Sortierung', 'wordpress-calendar-booking'), '<input type="number" name="sort_order" value="0">');
        $this->row(__('Aktiv', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="is_active" value="1" checked> ' . esc_html__('aktiv', 'wordpress-calendar-booking') . '</label>');
        $this->row(__('Öffentlich', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="is_public" value="1" checked> ' . esc_html__('sichtbar', 'wordpress-calendar-booking') . '</label>');
        echo '</table><p><button class="button button-primary">' . esc_html__('Speichern', 'wordpress-calendar-booking') . '</button></p></form>';
    }

    private function renderFieldsTable(array $items): void {
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Key', 'wordpress-calendar-booking'),
            __('Label', 'wordpress-calendar-booking'),
            __('Typ', 'wordpress-calendar-booking'),
            __('Optionen', 'wordpress-calendar-booking'),
            __('Pflicht', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $i) {
            echo '<tr><td>' . esc_html($i->field_key) . '</td><td>' . esc_html($i->label) . '</td><td>' . esc_html($i->field_type) . '</td><td>' . esc_html(implode(', ', (array)json_decode((string)($i->options_json ?? ''), true))) . '</td><td>' . esc_html($i->is_required ? __('Ja', 'wordpress-calendar-booking') : __('Nein', 'wordpress-calendar-booking')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderFieldForm(): void {
        echo '<h2>' . esc_html__('Neues Formularfeld', 'wordpress-calendar-booking') . '</h2><form method="post">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="save_field"><table class="form-table">';
        $this->row(__('Key', 'wordpress-calendar-booking'), '<input type="text" name="field_key" required>');
        $this->row(__('Label', 'wordpress-calendar-booking'), '<input type="text" name="label" required>');
        $this->row(__('Typ', 'wordpress-calendar-booking'), '<select name="field_type"><option value="text">Text</option><option value="email">' . esc_html__('E-Mail', 'wordpress-calendar-booking') . '</option><option value="textarea">Textarea</option><option value="checkbox">Checkbox</option><option value="select">Select</option><option value="radio">Radio</option></select>');
        $this->row(__('Optionen', 'wordpress-calendar-booking'), '<textarea name="options_raw" rows="5" class="regular-text" placeholder="' . esc_attr__('Eine Option pro Zeile', 'wordpress-calendar-booking') . '"></textarea>');
        $this->row(__('Sortierung', 'wordpress-calendar-booking'), '<input type="number" name="sort_order" value="0">');
        $this->row(__('Pflicht', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="is_required" value="1"> ' . esc_html__('ja', 'wordpress-calendar-booking') . '</label>');
        $this->row(__('Aktiv', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="is_active" value="1" checked> ' . esc_html__('ja', 'wordpress-calendar-booking') . '</label>');
        echo '</table><p><button class="button button-primary">' . esc_html__('Speichern', 'wordpress-calendar-booking') . '</button></p></form>';
    }

    private function renderRulesTable(array $items): void {
        echo '<h2>' . esc_html__('Regeln', 'wordpress-calendar-booking') . '</h2><table class="widefat striped"><thead><tr>';
        foreach ([
            __('Scope', 'wordpress-calendar-booking'),
            __('Wochentag', 'wordpress-calendar-booking'),
            __('Zeit', 'wordpress-calendar-booking'),
            __('Dauer', 'wordpress-calendar-booking'),
            __('Notice', 'wordpress-calendar-booking'),
            __('Horizont', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $i) {
            echo '<tr><td>' . esc_html($i->scope_type . ($i->scope_id ? ' #' . $i->scope_id : '')) . '</td><td>' . (int)$i->weekday . '</td><td>' . esc_html(substr($i->start_time, 0, 5) . ' - ' . substr($i->end_time, 0, 5)) . '</td><td>' . (int)$i->slot_duration_minutes . '</td><td>' . (int)$i->min_notice_minutes . '</td><td>' . (int)$i->max_days_in_advance . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderRuleForm(): void {
        $types = (new BookingTypeRepository())->all(false);
        $resources = (new ResourceRepository())->all(false);
        echo '<h2>' . esc_html__('Neue Regel', 'wordpress-calendar-booking') . '</h2><form method="post">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="save_rule"><table class="form-table">';
        $options = '<option value="global">global</option><option value="booking_type">' . esc_html__('Terminart', 'wordpress-calendar-booking') . '</option><option value="resource">' . esc_html__('Ressource', 'wordpress-calendar-booking') . '</option>';
        $this->row(__('Scope', 'wordpress-calendar-booking'), '<select name="scope_type">' . $options . '</select>');
        $typeOptions = '<option value="0">-</option>';
        foreach ($types as $type) {
            $typeOptions .= '<option value="' . (int)$type->id . '">' . esc_html($type->name) . '</option>';
        }
        $this->row(__('Terminart', 'wordpress-calendar-booking'), '<select name="scope_id">' . $typeOptions . '</select>');
        $resourceOptions = '<option value="0">-</option>';
        foreach ($resources as $resource) {
            $resourceOptions .= '<option value="' . (int)$resource->id . '">' . esc_html($resource->name) . '</option>';
        }
        $this->row(__('Ressource', 'wordpress-calendar-booking'), '<select name="resource_scope_id">' . $resourceOptions . '</select>');
        $this->row(__('Wochentag (1=Mo)', 'wordpress-calendar-booking'), '<input type="number" name="weekday" value="1" min="1" max="7">');
        $this->row(__('Startzeit', 'wordpress-calendar-booking'), '<input type="time" name="start_time" value="09:00">');
        $this->row(__('Endzeit', 'wordpress-calendar-booking'), '<input type="time" name="end_time" value="17:00">');
        $this->row(__('Slot-Dauer', 'wordpress-calendar-booking'), '<input type="number" name="slot_duration_minutes" value="30">');
        $this->row(__('Puffer davor', 'wordpress-calendar-booking'), '<input type="number" name="buffer_before_minutes" value="0">');
        $this->row(__('Puffer danach', 'wordpress-calendar-booking'), '<input type="number" name="buffer_after_minutes" value="15">');
        $this->row(__('Vorlaufzeit (Min.)', 'wordpress-calendar-booking'), '<input type="number" name="min_notice_minutes" value="120">');
        $this->row(__('Max. Tage im Voraus', 'wordpress-calendar-booking'), '<input type="number" name="max_days_in_advance" value="30">');
        $this->row(__('Aktiv', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="is_active" value="1" checked> ' . esc_html__('ja', 'wordpress-calendar-booking') . '</label>');
        echo '</table><p><button class="button button-primary">' . esc_html__('Speichern', 'wordpress-calendar-booking') . '</button></p></form>';
    }

    private function renderExceptionsTable(array $items): void {
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Titel', 'wordpress-calendar-booking'),
            __('Typ', 'wordpress-calendar-booking'),
            __('Von', 'wordpress-calendar-booking'),
            __('Bis', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($items as $i) {
            echo '<tr><td>' . esc_html($i->title) . '</td><td>' . esc_html($i->type) . '</td><td>' . esc_html($i->date_start) . '</td><td>' . esc_html($i->date_end) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderExceptionForm(): void {
        $types = (new BookingTypeRepository())->all(false);
        $resources = (new ResourceRepository())->all(false);
        echo '<h2>' . esc_html__('Neue Ausnahme / Sperre', 'wordpress-calendar-booking') . '</h2><form method="post">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="save_exception"><table class="form-table">';
        $this->row(__('Typ', 'wordpress-calendar-booking'), '<select name="type"><option value="holiday">' . esc_html__('Feiertag', 'wordpress-calendar-booking') . '</option><option value="blocked_day">' . esc_html__('Gesperrter Tag', 'wordpress-calendar-booking') . '</option><option value="blocked_range">' . esc_html__('Gesperrter Zeitraum', 'wordpress-calendar-booking') . '</option><option value="vacation">' . esc_html__('Urlaub', 'wordpress-calendar-booking') . '</option></select>');
        $this->row(__('Titel', 'wordpress-calendar-booking'), '<input type="text" name="title" required>');
        $this->row(__('Von', 'wordpress-calendar-booking'), '<input type="datetime-local" name="date_start" required>');
        $this->row(__('Bis', 'wordpress-calendar-booking'), '<input type="datetime-local" name="date_end" required>');
        $typeOptions = '<option value="0">' . esc_html__('alle Terminarten', 'wordpress-calendar-booking') . '</option>';
        foreach ($types as $type) {
            $typeOptions .= '<option value="' . (int)$type->id . '">' . esc_html($type->name) . '</option>';
        }
        $this->row(__('Terminart', 'wordpress-calendar-booking'), '<select name="booking_type_id">' . $typeOptions . '</select>');
        $resourceOptions = '<option value="0">' . esc_html__('alle Ressourcen', 'wordpress-calendar-booking') . '</option>';
        foreach ($resources as $resource) {
            $resourceOptions .= '<option value="' . (int)$resource->id . '">' . esc_html($resource->name) . '</option>';
        }
        $this->row(__('Ressource', 'wordpress-calendar-booking'), '<select name="resource_id">' . $resourceOptions . '</select>');
        $this->row(__('Ganztägig', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="all_day" value="1" checked> ' . esc_html__('ja', 'wordpress-calendar-booking') . '</label>');
        $this->row(__('Aktiv', 'wordpress-calendar-booking'), '<label><input type="checkbox" name="is_active" value="1" checked> ' . esc_html__('ja', 'wordpress-calendar-booking') . '</label>');
        echo '</table><p><button class="button button-primary">' . esc_html__('Speichern', 'wordpress-calendar-booking') . '</button></p></form>';
    }

    public function schedulerHealth(): void {
        $this->formStart();
        $health = (new SchedulerHealth())->snapshot();
        $mailDiagnostics = new MailDiagnostics();
        $mail = $mailDiagnostics->lastResult();
        echo '<h1>' . esc_html__('Systemstatus', 'wordpress-calendar-booking') . '</h1>';
        if ($health['healthy']) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('WP-Cron und Queue-Verarbeitung wirken gesund.', 'wordpress-calendar-booking') . '</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Scheduler-Warnungen:', 'wordpress-calendar-booking') . '</strong></p><ul>';
            foreach ($health['warnings'] as $warning) {
                echo '<li>' . esc_html($warning) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        $never = __('noch keiner', 'wordpress-calendar-booking');
        $notScheduled = __('nicht geplant', 'wordpress-calendar-booking');
        echo '<tr><th>' . esc_html__('Letzter Queue-Lauf (UTC)', 'wordpress-calendar-booking') . '</th><td>' . esc_html($health['last_queue_run'] ?: $never) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Nächster Queue-Lauf (UTC)', 'wordpress-calendar-booking') . '</th><td>' . esc_html($health['next_queue_run'] ?: $notScheduled) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Letzter Stundenlauf (UTC)', 'wordpress-calendar-booking') . '</th><td>' . esc_html($health['last_reminder_run'] ?: $never) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Nächster Stundenlauf (UTC)', 'wordpress-calendar-booking') . '</th><td>' . esc_html($health['next_reminder_run'] ?: $notScheduled) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Queue pending / running / failed', 'wordpress-calendar-booking') . '</th><td>'
            . (int)$health['counts']['pending'] . ' / '
            . (int)$health['counts']['running'] . ' / '
            . (int)$health['counts']['failed'] . '</td></tr>';
        echo '<tr><th>' . esc_html__('Abgelaufene Leases', 'wordpress-calendar-booking') . '</th><td>' . (int)$health['stale_leases'] . '</td></tr>';
        echo '</tbody></table>';

        echo '<div style="margin-top:16px">';
        echo '<form method="post" style="display:inline-block;margin-right:8px">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="process_sync_queue">';
        echo '<button class="button button-primary">' . esc_html__('Sync-Queue jetzt ausführen', 'wordpress-calendar-booking') . '</button></form>';
        echo '<form method="post" style="display:inline-block">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="run_hourly_tasks">';
        echo '<button class="button">' . esc_html__('Stündliche Aufgaben jetzt ausführen', 'wordpress-calendar-booking') . '</button></form>';
        echo '</div>';
        echo '<p class="description">' . wp_kses_post(__('Für zuverlässige Produktion sollte WP-Cron durch einen echten System-Cron angestoßen werden. Siehe <code>docs/OPERATIONS.md</code>.', 'wordpress-calendar-booking')) . '</p>';

        echo '<hr><h2>' . esc_html__('E-Mail-Zustellung', 'wordpress-calendar-booking') . '</h2>';
        $mailStatus = (string)($mail['status'] ?? 'untested');
        if ($mailStatus === 'accepted') {
            echo '<div class="notice notice-success inline"><p><strong>' . esc_html__('Test-E-Mail an Mail-Transport übergeben.', 'wordpress-calendar-booking') . '</strong> ';
            echo esc_html__('Das bestätigt die Annahme durch WordPress bzw. den konfigurierten Mail-Transport, nicht die tatsächliche Zustellung im Posteingang.', 'wordpress-calendar-booking') . '</p></div>';
        } elseif ($mailStatus === 'failed') {
            echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('Test-E-Mail konnte nicht übergeben werden.', 'wordpress-calendar-booking') . '</strong>';
            if (!empty($mail['error_code'])) {
                echo ' <code>' . esc_html((string)$mail['error_code']) . '</code>';
            }
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Noch kein Testversand durchgeführt.', 'wordpress-calendar-booking') . '</p></div>';
        }

        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><th>' . esc_html__('Letzter Mail-Test (UTC)', 'wordpress-calendar-booking') . '</th><td>'
            . esc_html((string)($mail['tested_at'] ?: __('noch keiner', 'wordpress-calendar-booking'))) . '</td></tr>';
        echo '</tbody></table>';

        $testRecipient = sanitize_email((string)wp_get_current_user()->user_email);
        if (!is_email($testRecipient)) {
            $testRecipient = sanitize_email((string)get_option('admin_email'));
        }
        echo '<form method="post" style="margin-top:12px;max-width:900px">';
        wp_nonce_field('wpcb_admin_action');
        echo '<input type="hidden" name="wpcb_admin_action" value="test_mail_delivery">';
        echo '<label><strong>' . esc_html__('Test-E-Mail an', 'wordpress-calendar-booking') . '</strong><br>';
        echo '<input type="email" name="test_email" class="regular-text" required value="' . esc_attr($testRecipient) . '"></label> ';
        echo '<button class="button button-primary">' . esc_html__('Test-E-Mail senden', 'wordpress-calendar-booking') . '</button>';
        echo '<p class="description">' . esc_html__('Die Empfängeradresse und der Nachrichtentext werden nicht im Diagnose- oder Versandprotokoll gespeichert.', 'wordpress-calendar-booking') . '</p>';
        echo '</form>';
        $this->formEnd();
    }

    public function syncLog(): void {
        $this->formStart();
        echo '<h1>' . esc_html__('Sync-Protokoll', 'wordpress-calendar-booking') . '</h1>';
        $repo = new JobRepository();
        $logs = $repo->recentLogs(100);
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Zeit', 'wordpress-calendar-booking'),
            __('Booking', 'wordpress-calendar-booking'),
            __('Level', 'wordpress-calendar-booking'),
            __('Meldung', 'wordpress-calendar-booking'),
        ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($logs as $log) {
            echo '<tr><td>' . esc_html($log->created_at) . '</td><td>' . (int)$log->booking_id . '</td><td>' . esc_html($log->level) . '</td><td>' . esc_html($log->message) . '</td></tr>';
        }
        echo '</tbody></table>';
        $this->formEnd();
    }

}
