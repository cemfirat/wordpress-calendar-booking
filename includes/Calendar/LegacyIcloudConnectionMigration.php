<?php
namespace Cemb\Calendar;

use Cemb\Admin\Settings;
use Cemb\Booking\BookingTypeRepository;

/**
 * Moves the imported 1.x iCloud write-back settings into the provider-neutral
 * CalDAV connection store once. Public ICS feed settings are unrelated and are
 * intentionally left untouched.
 */
final class LegacyIcloudConnectionMigration {
    private const OPTION = 'cemb_legacy_icloud_connection_version';
    private const VERSION = 1;
    private const RECONNECT_OPTION = 'cemb_legacy_icloud_reconnect_required';

    public static function maybeRun(): void {
        if ((int)get_option(self::OPTION, 0) >= self::VERSION) {
            return;
        }

        $settings = Settings::get();
        if (empty($settings['icloud_sync_enabled'])) {
            update_option(self::OPTION, self::VERSION, false);
            return;
        }

        $username = trim((string)($settings['icloud_sync_apple_id'] ?? ''));
        $password = Settings::getIcloudSyncPassword();
        $calendarUrl = Settings::normalizeCalendarUrl(
            (string)($settings['icloud_sync_target_calendar_url'] ?? '')
        );

        if ($username === '' || $password === '' || $calendarUrl === '') {
            self::disableLegacySync($settings);
            update_option(self::RECONNECT_OPTION, 1, false);
            update_option(self::OPTION, self::VERSION, false);
            return;
        }

        $connections = new CalendarConnectionRepository();
        $connectionId = $connections->create(
            [
                'provider' => 'caldav',
                'name' => 'iCloud (migrated)',
                'remote_calendar_id' => $calendarUrl,
                'blocks_availability' => 0,
                'receives_bookings' => 1,
                'config' => [
                    'endpoint' => 'https://caldav.icloud.com/',
                    'preset' => 'icloud',
                    'migrated_from' => '1.x',
                ],
            ],
            [
                'username' => $username,
                'password' => $password,
            ]
        );

        if (is_wp_error($connectionId)) {
            update_option(self::RECONNECT_OPTION, 1, false);
            return;
        }

        foreach ((new BookingTypeRepository())->all(false) as $type) {
            $existing = $connections->forBookingType((int)$type->id, false);
            $selection = [];
            foreach ($existing as $row) {
                $selection[] = [
                    'connection_id' => $row['connection']->id,
                    'blocks_availability' => !empty($row['blocks_availability']),
                    'receives_bookings' => !empty($row['receives_bookings']),
                ];
            }
            $selection[] = [
                'connection_id' => (int)$connectionId,
                'blocks_availability' => 0,
                'receives_bookings' => 1,
            ];
            $connections->setForBookingType((int)$type->id, $selection);
        }

        self::disableLegacySync($settings);
        delete_option(self::RECONNECT_OPTION);
        update_option(self::OPTION, self::VERSION, false);
    }

    public static function reconnectRequired(): bool {
        return (bool)get_option(self::RECONNECT_OPTION, false);
    }

    private static function disableLegacySync(array $settings): void {
        $settings['icloud_sync_enabled'] = 0;
        $settings['icloud_sync_updates'] = 0;
        $settings['icloud_sync_cancellations'] = 0;
        $settings['icloud_sync_password_enc'] = '';
        update_option('cemb_settings', $settings, false);
    }
}
