<?php
namespace Wpcb\Payments;

final class PaymentStatus {
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';
    public const REFUND_PENDING = 'refund_pending';
    public const REFUNDED = 'refunded';

    public static function all(): array {
        return [
            self::PENDING,
            self::PAID,
            self::FAILED,
            self::EXPIRED,
            self::REFUND_PENDING,
            self::REFUNDED,
        ];
    }
}
