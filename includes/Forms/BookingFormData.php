<?php
namespace Wpcb\Forms;

use Wpcb\Admin\Settings;
use Wpcb\Support\BookingFormatter;

final class BookingFormData {
    private BookingFormatter $formatter;

    public function __construct(?BookingFormatter $formatter = null) {
        $this->formatter = $formatter ?: new BookingFormatter();
    }

    /** @return array{customer:array{full_name:string,email:string,phone:string},meta:array<string,string>}|\WP_Error */
    public function prepare(int $bookingTypeId, array $meta) {
        $email = (string)($meta['email'] ?? '');
        if (!is_email($email)) {
            return new \WP_Error(
                'wpcb_field_email',
                __('Bitte eine gültige E-Mail-Adresse eingeben.', 'wordpress-calendar-booking'),
                ['field' => 'email']
            );
        }

        $settings = Settings::get();
        $meta['computed_location'] = $this->formatter->location(
            ['booking_type_id' => $bookingTypeId],
            $meta,
            $settings
        );
        $fullName = $this->formatter->displayName([], $meta);
        if (FieldContract::length($fullName) > 190) {
            return new \WP_Error(
                'wpcb_field_too_long',
                __('Der Name ist zu lang.', 'wordpress-calendar-booking')
            );
        }

        $phone = (string)($meta['phone'] ?? '');
        if ($phone === '' && !empty($meta['who_calls']) && $meta['who_calls'] === 'Ich rufe an') {
            $phone = (string)($settings['own_phone'] ?? '');
        }
        if (FieldContract::length($phone) > 100) {
            return new \WP_Error(
                'wpcb_field_too_long',
                __('Die Telefonnummer ist zu lang.', 'wordpress-calendar-booking'),
                ['field' => 'phone']
            );
        }

        return [
            'customer' => [
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
            ],
            'meta' => $meta,
        ];
    }
}
