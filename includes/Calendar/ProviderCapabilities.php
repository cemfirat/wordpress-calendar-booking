<?php
namespace Cemb\Calendar;

final class ProviderCapabilities {
    public const BUSY_READ = 'busy_read';
    public const EVENT_CREATE = 'event_create';
    public const EVENT_UPDATE = 'event_update';
    public const EVENT_CANCEL = 'event_cancel';
    public const CALENDAR_DISCOVERY = 'calendar_discovery';

    public static function all(): array {
        return [
            self::BUSY_READ,
            self::EVENT_CREATE,
            self::EVENT_UPDATE,
            self::EVENT_CANCEL,
            self::CALENDAR_DISCOVERY,
        ];
    }

    public static function normalize(array $capabilities): array {
        return array_values(array_unique(array_intersect(
            self::all(),
            array_map('strval', $capabilities)
        )));
    }
}
