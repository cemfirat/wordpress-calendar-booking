<?php
namespace Cemb\Reliability;

use Cemb\Support\Time;
use Cemb\Sync\JobRepository;

class SchedulerHealth {
    public const QUEUE_LAST_RUN_OPTION = 'cemb_sync_queue_last_run';
    public const REMINDER_LAST_RUN_OPTION = 'cemb_hourly_reminders_last_run';

    public function snapshot(): array {
        $jobs = new JobRepository();
        $counts = $jobs->statusCounts();
        $staleLeases = $jobs->staleLeaseCount();
        $lastQueue = (string)get_option(self::QUEUE_LAST_RUN_OPTION, '');
        $lastReminder = (string)get_option(self::REMINDER_LAST_RUN_OPTION, '');
        $nextQueue = wp_next_scheduled('cemb_sync_queue');
        $nextReminder = wp_next_scheduled('cemb_hourly_reminders');
        $warnings = [];

        if (!$nextQueue) {
            $warnings[] = 'Die Sync-Queue ist nicht in WP-Cron eingeplant.';
        }
        if (!$nextReminder) {
            $warnings[] = 'Die stündlichen Wartungs-/Erinnerungsaufgaben sind nicht in WP-Cron eingeplant.';
        }
        if ($lastQueue === '') {
            $warnings[] = 'Es wurde noch kein Sync-Queue-Lauf aufgezeichnet.';
        } elseif ($this->isOlderThan($lastQueue, 15 * MINUTE_IN_SECONDS)) {
            $warnings[] = 'Der letzte Sync-Queue-Lauf ist älter als 15 Minuten.';
        }
        if ($lastReminder === '') {
            $warnings[] = 'Es wurde noch kein stündlicher Wartungs-/Erinnerungslauf aufgezeichnet.';
        } elseif ($this->isOlderThan($lastReminder, 2 * HOUR_IN_SECONDS)) {
            $warnings[] = 'Der letzte stündliche Wartungs-/Erinnerungslauf ist älter als 2 Stunden.';
        }
        if (($counts['failed'] ?? 0) > 0) {
            $warnings[] = 'Es gibt fehlgeschlagene Queue-Jobs.';
        }
        if ($staleLeases > 0) {
            $warnings[] = 'Es gibt laufende Queue-Jobs mit abgelaufener Lease.';
        }

        return [
            'last_queue_run' => $lastQueue,
            'last_reminder_run' => $lastReminder,
            'next_queue_run' => $nextQueue ? gmdate('Y-m-d H:i:s', (int)$nextQueue) : '',
            'next_reminder_run' => $nextReminder ? gmdate('Y-m-d H:i:s', (int)$nextReminder) : '',
            'counts' => $counts,
            'stale_leases' => $staleLeases,
            'warnings' => $warnings,
            'healthy' => $warnings === [],
        ];
    }

    private function isOlderThan(string $utc, int $seconds): bool {
        $timestamp = strtotime($utc . ' UTC');
        if ($timestamp === false) {
            return true;
        }
        return (Time::nowUtc()->getTimestamp() - $timestamp) > $seconds;
    }
}
