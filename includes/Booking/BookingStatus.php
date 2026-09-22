<?php
namespace Cemb\Booking;

final class BookingStatus {
    public const RESERVED_UNCONFIRMED = 'reserved_unconfirmed';
    public const PENDING_APPROVAL = 'pending_approval';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    public static function all(): array {
        return [
            self::RESERVED_UNCONFIRMED,
            self::PENDING_APPROVAL,
            self::CONFIRMED,
            self::REJECTED,
            self::CANCELLED,
            self::EXPIRED,
        ];
    }

    public static function activeBlockingStatuses(): array {
        return [self::RESERVED_UNCONFIRMED, self::PENDING_APPROVAL, self::CONFIRMED];
    }

    public static function displayableCalendarStatuses(): array {
        return [self::PENDING_APPROVAL, self::CONFIRMED];
    }

    public static function terminalStatuses(): array {
        return [self::REJECTED, self::CANCELLED, self::EXPIRED];
    }
}
