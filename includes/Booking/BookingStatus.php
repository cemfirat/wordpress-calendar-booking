<?php
namespace Cemb\Booking;

class BookingStatus {
    public const EMAIL_UNCONFIRMED = 'email_unconfirmed';
    public const PENDING_ADMIN_APPROVAL = 'pending_admin_approval';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const UPDATED = 'updated';
    public const SPAM_BLOCKED = 'spam_blocked';

    public static function activeBlockingStatuses(): array {
        return [self::EMAIL_UNCONFIRMED, self::PENDING_ADMIN_APPROVAL, self::CONFIRMED, self::UPDATED];
    }

    public static function displayableCalendarStatuses(): array {
        return [self::PENDING_ADMIN_APPROVAL, self::CONFIRMED, self::UPDATED];
    }
}
