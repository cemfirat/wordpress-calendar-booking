<?php
namespace Wpcb\Sync;

use Wpcb\Admin\Settings;
use Wpcb\Booking\BookingRepository;
use Wpcb\Support\BookingFormatter;
use Wpcb\ICS\IcsGenerator;
use Wpcb\Support\Time;

class IcloudSyncService {
    private BookingRepository $bookings;
    private CalDavClient $client;
    private BookingFormatter $formatter;

    public function __construct() {
        $this->bookings = new BookingRepository();
        $this->client = new CalDavClient();
        $this->formatter = new BookingFormatter();
    }

    public function enabled(): bool {
        $settings = Settings::get();
        return !empty($settings['icloud_sync_enabled']) && $this->client->configured();
    }

    public function testConnection(): array {
        $settings = Settings::get();
        return $this->client->testConnection((string)($settings['icloud_sync_target_calendar_url'] ?? ''));
    }

    public function syncBooking(int $bookingId): array {
        if (!$this->enabled()) {
            return ['ok' => false, 'message' => 'iCloud-Sync ist deaktiviert oder unvollständig konfiguriert.'];
        }
        $booking = (array)$this->bookings->find($bookingId);
        if (!$booking) {
            return ['ok' => false, 'message' => 'Buchung nicht gefunden.'];
        }
        $meta = $this->bookings->getMeta($bookingId);
        $settings = Settings::get();
        $calendarUrl = $this->client->resolveTargetCalendarUrl((string)($settings['icloud_sync_target_calendar_url'] ?? ''), (string)($settings['icloud_sync_target_calendar_name'] ?? ''));
        if (!$calendarUrl) {
            $this->storeSyncMeta($bookingId, ['sync_status' => 'error', 'sync_error' => 'Zielkalender konnte nicht ermittelt werden.']);
            return ['ok' => false, 'message' => 'Zielkalender konnte nicht ermittelt werden.'];
        }

        $uid = (string)($meta['icloud_uid'] ?? ('wpcb-' . ($booking['booking_uuid'] ?? wp_generate_uuid4()) . '@' . wp_parse_url(home_url(), PHP_URL_HOST)));
        $eventFile = (string)($meta['icloud_event_file'] ?? ($uid . '.ics'));
        $summary = $this->formatter->summary($booking, $meta);
        $location = $this->formatter->location($booking, $meta, $settings);
        $ics = (new IcsGenerator())->generate($booking, $meta, $summary, $location, $uid);
        $result = $this->client->upsertEvent($calendarUrl, $eventFile, $ics);

        $payload = [
            'icloud_uid' => $uid,
            'icloud_event_file' => $eventFile,
            'icloud_event_url' => $result['url'] ?? (untrailingslashit($calendarUrl) . '/' . $eventFile),
            'icloud_calendar_url' => $calendarUrl,
            'sync_status' => !empty($result['ok']) ? 'synced' : 'error',
            'sync_error' => !empty($result['ok']) ? '' : (string)($result['message'] ?? 'Sync-Fehler'),
            'last_synced_at' => Time::formatUtc(Time::nowUtc()),
            'icloud_etag' => (string)($result['etag'] ?? ''),
        ];
        $this->storeSyncMeta($bookingId, $payload);
        return $result;
    }

    public function cancelBooking(int $bookingId): array {
        if (!$this->enabled()) {
            return ['ok' => false, 'message' => 'iCloud-Sync ist deaktiviert oder unvollständig konfiguriert.'];
        }
        $meta = $this->bookings->getMeta($bookingId);
        if (empty($meta['icloud_event_url'])) {
            return ['ok' => true, 'message' => 'Kein iCloud-Ereignis vorhanden.'];
        }
        $result = $this->client->deleteEvent((string)$meta['icloud_event_url']);
        $this->storeSyncMeta($bookingId, [
            'sync_status' => !empty($result['ok']) ? 'cancelled' : 'error',
            'sync_error' => !empty($result['ok']) ? '' : (string)($result['message'] ?? 'Storno fehlgeschlagen'),
            'last_synced_at' => Time::formatUtc(Time::nowUtc()),
        ]);
        return $result;
    }

    private function storeSyncMeta(int $bookingId, array $meta): void {
        foreach ($meta as $key => $value) {
            $this->bookings->updateMeta($bookingId, (string)$key, (string)$value);
        }
    }
}
