<?php
namespace Wpcb\Admin;

use Wpcb\Database\SchemaMigration;
use Wpcb\Mail\MailDiagnostics;
use Wpcb\Reliability\SchedulerHealth;
use Wpcb\Resources\ResourceMigration;

final class SiteHealth {
    public function boot(): void {
        add_filter('site_status_tests', [$this, 'tests']);
        add_filter('debug_information', [$this, 'debugInformation']);
    }

    public function tests(array $tests): array {
        $tests['direct']['wpcb_core_readiness'] = [
            'label' => __('WordPress Calendar Booking: Einrichtung', 'wordpress-calendar-booking'),
            'test' => [$this, 'coreReadinessTest'],
        ];
        $tests['direct']['wpcb_scheduler_health'] = [
            'label' => __('WordPress Calendar Booking: Scheduler', 'wordpress-calendar-booking'),
            'test' => [$this, 'schedulerTest'],
        ];
        $tests['direct']['wpcb_mail_health'] = [
            'label' => __('WordPress Calendar Booking: E-Mail-Transport', 'wordpress-calendar-booking'),
            'test' => [$this, 'mailTest'],
        ];
        return $tests;
    }

    public function coreReadinessTest(): array {
        $snapshot = (new SetupReadiness())->snapshot();
        if (!empty($snapshot['ready'])) {
            return $this->result(
                'good',
                __('WordPress Calendar Booking ist buchungsbereit', 'wordpress-calendar-booking'),
                __('Die Kernkonfiguration für Terminarten, Ressourcen, Verfügbarkeit, Grundeinstellungen, Scheduler und öffentliche Buchungsoberfläche ist vollständig.', 'wordpress-calendar-booking')
            );
        }

        $missing = array_values(array_filter(
            (array)($snapshot['items'] ?? []),
            static fn($item) => empty($item['ready'])
        ));
        $labels = array_map(static fn($item) => (string)($item['label'] ?? ''), $missing);
        $detail = $labels
            ? sprintf(
                /* translators: %s: comma-separated readiness items */
                __('Noch nicht bereit: %s.', 'wordpress-calendar-booking'),
                implode(', ', $labels)
            )
            : __('Die Kernkonfiguration ist noch nicht vollständig.', 'wordpress-calendar-booking');

        return $this->result(
            'recommended',
            __('WordPress Calendar Booking ist noch nicht vollständig eingerichtet', 'wordpress-calendar-booking'),
            $detail,
            admin_url('admin.php?page=wpcb_dashboard')
        );
    }

    public function schedulerTest(): array {
        $health = (new SchedulerHealth())->snapshot();
        if (!empty($health['healthy'])) {
            return $this->result(
                'good',
                __('WordPress Calendar Booking Scheduler ist gesund', 'wordpress-calendar-booking'),
                __('WP-Cron, Queue und wiederkehrende Wartungsaufgaben sind eingeplant und zeigen keine bekannten Betriebswarnungen.', 'wordpress-calendar-booking')
            );
        }

        $warnings = array_values(array_filter(array_map('strval', (array)($health['warnings'] ?? []))));
        return $this->result(
            'critical',
            __('WordPress Calendar Booking Scheduler benötigt Aufmerksamkeit', 'wordpress-calendar-booking'),
            $warnings ? implode(' ', $warnings) : __('Der Scheduler meldet einen nicht gesunden Zustand.', 'wordpress-calendar-booking'),
            admin_url('admin.php?page=wpcb_system_health')
        );
    }

    public function mailTest(): array {
        $mail = (new MailDiagnostics())->lastResult();
        $status = (string)($mail['status'] ?? 'untested');

        if ($status === 'accepted') {
            return $this->result(
                'good',
                __('WordPress Calendar Booking E-Mail-Transport wurde erfolgreich getestet', 'wordpress-calendar-booking'),
                __('WordPress hat die neutrale Diagnose-E-Mail an den konfigurierten Transport übergeben. Das bestätigt nicht die endgültige Zustellung im Posteingang.', 'wordpress-calendar-booking')
            );
        }

        if ($status === 'failed') {
            return $this->result(
                'critical',
                __('WordPress Calendar Booking E-Mail-Transport ist fehlgeschlagen', 'wordpress-calendar-booking'),
                __('Der letzte geschützte E-Mail-Test wurde vom konfigurierten Transport nicht akzeptiert. Prüfe SMTP-/Mail-Konfiguration und Absenderidentität vor produktiven Buchungen.', 'wordpress-calendar-booking'),
                admin_url('admin.php?page=wpcb_system_health')
            );
        }

        return $this->result(
            'recommended',
            __('WordPress Calendar Booking E-Mail-Transport wurde noch nicht getestet', 'wordpress-calendar-booking'),
            __('Sende vor dem ersten produktiven Einsatz eine neutrale Test-E-Mail über den Systemstatus.', 'wordpress-calendar-booking'),
            admin_url('admin.php?page=wpcb_system_health')
        );
    }

    public function debugInformation(array $info): array {
        $readiness = (new SetupReadiness())->snapshot();
        $scheduler = (new SchedulerHealth())->snapshot();
        $mail = (new MailDiagnostics())->lastResult();

        $info['wordpress-calendar-booking'] = [
            'label' => __('WordPress Calendar Booking', 'wordpress-calendar-booking'),
            'description' => __('Nicht-sensitive Betriebsmetadaten für Diagnosezwecke. Kunden-, Zahlungs-, Kalender- und Zugangsdaten werden nicht ausgegeben.', 'wordpress-calendar-booking'),
            'fields' => [
                'version' => $this->field(__('Plugin-Version', 'wordpress-calendar-booking'), defined('WPCB_VERSION') ? WPCB_VERSION : ''),
                'schema_version' => $this->field(__('Datenbankschema (gespeichert / erwartet)', 'wordpress-calendar-booking'), SchemaMigration::recordedVersion() . ' / ' . SchemaMigration::currentVersion()),
                'schema_verified' => $this->field(__('Datenbankschema verifiziert', 'wordpress-calendar-booking'), SchemaMigration::isReady() ? 'yes' : 'no'),
                'resource_model_version' => $this->field(__('Ressourcenmodell', 'wordpress-calendar-booking'), (string)ResourceMigration::currentVersion()),
                'core_ready' => $this->field(__('Kern-Bereitschaft', 'wordpress-calendar-booking'), !empty($readiness['ready']) ? 'yes' : 'no'),
                'scheduler_healthy' => $this->field(__('Scheduler gesund', 'wordpress-calendar-booking'), !empty($scheduler['healthy']) ? 'yes' : 'no'),
                'queue_failed' => $this->field(__('Fehlgeschlagene Queue-Jobs', 'wordpress-calendar-booking'), (string)((int)($scheduler['counts']['failed'] ?? 0))),
                'stale_leases' => $this->field(__('Abgelaufene Queue-Leases', 'wordpress-calendar-booking'), (string)((int)($scheduler['stale_leases'] ?? 0))),
                'next_queue_run' => $this->field(__('Nächster Queue-Lauf (UTC)', 'wordpress-calendar-booking'), (string)($scheduler['next_queue_run'] ?? '')),
                'next_reminder_run' => $this->field(__('Nächster Wartungs-/Erinnerungslauf (UTC)', 'wordpress-calendar-booking'), (string)($scheduler['next_reminder_run'] ?? '')),
                'mail_status' => $this->field(__('E-Mail-Diagnosestatus', 'wordpress-calendar-booking'), sanitize_key((string)($mail['status'] ?? 'untested'))),
                'mail_tested_at' => $this->field(__('Letzter E-Mail-Test (UTC)', 'wordpress-calendar-booking'), (string)($mail['tested_at'] ?? '')),
            ],
        ];
        return $info;
    }

    private function result(string $status, string $label, string $description, string $url = ''): array {
        $actions = '';
        if ($url !== '') {
            $actions = '<p><a href="' . esc_url($url) . '">' . esc_html__('WordPress Calendar Booking öffnen', 'wordpress-calendar-booking') . '</a></p>';
        }
        return [
            'label' => $label,
            'status' => $status,
            'badge' => [
                'label' => __('WordPress Calendar Booking', 'wordpress-calendar-booking'),
                'color' => 'blue',
            ],
            'description' => '<p>' . esc_html($description) . '</p>',
            'actions' => $actions,
            'test' => 'wpcb_site_health',
        ];
    }

    private function field(string $label, string $value): array {
        return [
            'label' => $label,
            'value' => $value,
            'debug' => $value,
            'private' => false,
        ];
    }
}
