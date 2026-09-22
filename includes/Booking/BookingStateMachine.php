<?php
namespace Cemb\Booking;

/**
 * Pure booking lifecycle rules.
 *
 * Callers emit semantic events; they never write status strings directly.
 */
final class BookingStateMachine {
    public const EMAIL_CONFIRMED_APPROVAL = 'email_confirmed_approval';
    public const EMAIL_CONFIRMED_AUTOMATIC = 'email_confirmed_automatic';
    public const ADMIN_APPROVED = 'admin_approved';
    public const ADMIN_REJECTED = 'admin_rejected';
    public const USER_CANCELLED = 'user_cancelled';
    public const ADMIN_CANCELLED = 'admin_cancelled';
    public const RESERVATION_EXPIRED = 'reservation_expired';

    private const TRANSITIONS = [
        self::EMAIL_CONFIRMED_APPROVAL => [
            'from' => [BookingStatus::RESERVED_UNCONFIRMED],
            'to' => BookingStatus::PENDING_APPROVAL,
        ],
        self::EMAIL_CONFIRMED_AUTOMATIC => [
            'from' => [BookingStatus::RESERVED_UNCONFIRMED],
            'to' => BookingStatus::CONFIRMED,
        ],
        self::ADMIN_APPROVED => [
            'from' => [BookingStatus::PENDING_APPROVAL],
            'to' => BookingStatus::CONFIRMED,
        ],
        self::ADMIN_REJECTED => [
            'from' => [BookingStatus::PENDING_APPROVAL],
            'to' => BookingStatus::REJECTED,
        ],
        self::USER_CANCELLED => [
            'from' => [BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED],
            'to' => BookingStatus::CANCELLED,
        ],
        self::ADMIN_CANCELLED => [
            'from' => [BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED],
            'to' => BookingStatus::CANCELLED,
        ],
        self::RESERVATION_EXPIRED => [
            'from' => [BookingStatus::RESERVED_UNCONFIRMED],
            'to' => BookingStatus::EXPIRED,
        ],
    ];

    public function targetForEvent(string $event): ?string {
        return self::TRANSITIONS[$event]['to'] ?? null;
    }

    public function canApply(string $currentStatus, string $event): bool {
        $definition = self::TRANSITIONS[$event] ?? null;
        if (!$definition) {
            return false;
        }

        if ($currentStatus === $definition['to']) {
            return true; // idempotent retry.
        }

        return in_array($currentStatus, $definition['from'], true);
    }

    public function isIdempotent(string $currentStatus, string $event): bool {
        $target = $this->targetForEvent($event);
        return $target !== null && $currentStatus === $target;
    }

    public function adminEventsFor(string $status): array {
        $events = [];
        foreach ([
            self::ADMIN_APPROVED => __('Approve', 'cemb'),
            self::ADMIN_REJECTED => __('Reject', 'cemb'),
            self::ADMIN_CANCELLED => __('Cancel', 'cemb'),
        ] as $event => $label) {
            if ($this->canApply($status, $event) && !$this->isIdempotent($status, $event)) {
                $events[$event] = $label;
            }
        }
        return $events;
    }

    public static function knownEvents(): array {
        return array_keys(self::TRANSITIONS);
    }
}
