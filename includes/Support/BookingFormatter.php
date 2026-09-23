<?php
namespace Wpcb\Support;

use Wpcb\Booking\BookingTypeRepository;

class BookingFormatter {
    public function summary(array $booking, array $meta): string {
        $type = (new BookingTypeRepository())->find((int)($booking['booking_type_id'] ?? 0));
        $typeName = trim((string)($type->name ?? 'Termin'));
        $gender = trim((string)($meta['gender'] ?? ''));
        $lastName = trim((string)($meta['last_name'] ?? ''));
        $subject = trim((string)($meta['subject'] ?? ''));

        $namePart = trim($gender . ' ' . $lastName);
        $title = $typeName;
        if ($namePart !== '') {
            $title .= ' mit ' . $namePart;
        }
        if ($subject !== '') {
            $title .= ' (' . $subject . ')';
        }
        return trim($title);
    }

    public function location(array $booking, array $meta, array $settings): string {
        if (!empty($meta['computed_location'])) {
            return (string)$meta['computed_location'];
        }
        $type = (new BookingTypeRepository())->find((int)($booking['booking_type_id'] ?? 0));
        $typeName = strtolower((string)($type->name ?? ''));
        if (strpos($typeName, 'telefon') !== false) {
            $whoCalls = (string)($meta['who_calls'] ?? '');
            if ($whoCalls === 'Ich rufe an') {
                return (string)($settings['own_phone'] ?? '');
            }
            if ($whoCalls === 'Die Kundin ruft an') {
                return (string)($meta['phone'] ?? '');
            }
        }
        if (strpos($typeName, 'besuch') !== false || strpos($typeName, 'vor ort') !== false) {
            return (string)($settings['visit_address'] ?? '');
        }
        return (string)($meta['location'] ?? '');
    }

    public function displayName(array $booking, array $meta): string {
        $parts = [];
        if (!empty($meta['first_name'])) $parts[] = (string)$meta['first_name'];
        if (!empty($meta['last_name'])) $parts[] = (string)$meta['last_name'];
        if ($parts) return trim(implode(' ', $parts));
        return trim((string)($booking['full_name'] ?? ''));
    }

    public function typeColorClass(int $typeId): string {
        $palette = [
            'wpcb-type-color-1',
            'wpcb-type-color-2',
            'wpcb-type-color-3',
            'wpcb-type-color-4',
            'wpcb-type-color-5',
            'wpcb-type-color-6',
        ];
        return $palette[max(0, ($typeId - 1) % count($palette))];
    }
}
