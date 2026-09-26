<?php
namespace Wpcb\Payments;

final class PaymentRefundStatus {
    public const SUBMITTING = 'submitting';
    public const UNCERTAIN = 'uncertain';
    public const PENDING = 'pending';
    public const REQUIRES_ACTION = 'requires_action';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';

    public static function all(): array {
        return [
            self::SUBMITTING,
            self::UNCERTAIN,
            self::PENDING,
            self::REQUIRES_ACTION,
            self::SUCCEEDED,
            self::FAILED,
            self::CANCELED,
        ];
    }

    public static function providerStatuses(): array {
        return [
            self::PENDING,
            self::REQUIRES_ACTION,
            self::SUCCEEDED,
            self::FAILED,
            self::CANCELED,
        ];
    }

    public static function activeStatuses(): array {
        return [
            self::SUBMITTING,
            self::UNCERTAIN,
            self::PENDING,
            self::REQUIRES_ACTION,
        ];
    }

    public static function terminalStatuses(): array {
        return [self::SUCCEEDED, self::FAILED, self::CANCELED];
    }

    public static function customerLabel(string $status): string {
        return [
            self::SUBMITTING => __('Erstattung wird an den Zahlungsanbieter übermittelt', 'wordpress-calendar-booking'),
            self::UNCERTAIN => __('Erstattungsstatus wird geprüft', 'wordpress-calendar-booking'),
            self::PENDING => __('Erstattung wird beim Zahlungsanbieter verarbeitet', 'wordpress-calendar-booking'),
            self::REQUIRES_ACTION => __('Erstattung benötigt eine Aktion beim Zahlungsanbieter', 'wordpress-calendar-booking'),
            self::SUCCEEDED => __('Erstattung bestätigt', 'wordpress-calendar-booking'),
            self::FAILED => __('Erstattung fehlgeschlagen', 'wordpress-calendar-booking'),
            self::CANCELED => __('Erstattung abgebrochen', 'wordpress-calendar-booking'),
        ][$status] ?? __('Erstattungsstatus unbekannt', 'wordpress-calendar-booking');
    }
}
