<?php
namespace Wpcb\Privacy;

use Wpcb\Admin\Settings;
use Wpcb\Booking\BookingStatus;
use Wpcb\Support\Time;

final class PrivacyService {
    private const PAGE_SIZE = 50;
    private const RETAIN_META_KEY = 'privacy_retain';

    public function boot(): void {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
        add_action('admin_init', [$this, 'addPolicyText']);
        add_action('wpcb_privacy_retention', [$this, 'runRetention']);

        if (!wp_next_scheduled('wpcb_privacy_retention')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpcb_privacy_retention');
        }
    }

    public function registerExporter(array $exporters): array {
        $exporters['wpcb-bookings'] = [
            'exporter_friendly_name' => __('Calendar Booking appointments', 'wordpress-calendar-booking'),
            'callback' => [$this, 'exporter'],
        ];
        return $exporters;
    }

    public function registerEraser(array $erasers): array {
        $erasers['wpcb-bookings'] = [
            'eraser_friendly_name' => __('Calendar Booking appointments', 'wordpress-calendar-booking'),
            'callback' => [$this, 'eraser'],
        ];
        return $erasers;
    }

    public function exporter(string $emailAddress, int $page = 1): array {
        global $wpdb;
        $emailAddress = sanitize_email($emailAddress);
        $page = max(1, $page);
        if ($emailAddress === '') {
            return ['data' => [], 'done' => true];
        }

        $table = $wpdb->prefix . 'wpcb_bookings';
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
                $emailAddress,
                self::PAGE_SIZE,
                $offset
            )
        );

        $data = [];
        foreach ($rows as $booking) {
            $meta = $this->personalMeta((int)$booking->id);
            $items = [
                ['name' => __('Booking ID', 'wordpress-calendar-booking'), 'value' => (string)$booking->id],
                ['name' => __('Status', 'wordpress-calendar-booking'), 'value' => (string)$booking->status],
                ['name' => __('Appointment start (UTC)', 'wordpress-calendar-booking'), 'value' => (string)$booking->slot_start],
                ['name' => __('Appointment end (UTC)', 'wordpress-calendar-booking'), 'value' => (string)$booking->slot_end],
                ['name' => __('Name', 'wordpress-calendar-booking'), 'value' => (string)$booking->full_name],
                ['name' => __('Email', 'wordpress-calendar-booking'), 'value' => (string)$booking->email],
                ['name' => __('Phone', 'wordpress-calendar-booking'), 'value' => (string)$booking->phone],
                ['name' => __('Notes', 'wordpress-calendar-booking'), 'value' => (string)$booking->notes],
                ['name' => __('Admin notes', 'wordpress-calendar-booking'), 'value' => (string)$booking->admin_notes],
            ];

            foreach ($meta as $key => $value) {
                $items[] = [
                    'name' => sprintf(__('Form field: %s', 'wordpress-calendar-booking'), $key),
                    'value' => is_scalar($value) ? (string)$value : wp_json_encode($value),
                ];
            }

            $data[] = [
                'group_id' => 'wpcb-bookings',
                'group_label' => __('Calendar Booking appointments', 'wordpress-calendar-booking'),
                'item_id' => 'booking-' . (int)$booking->id,
                'data' => $items,
            ];
        }

        return [
            'data' => $data,
            'done' => count($rows) < self::PAGE_SIZE,
        ];
    }

    public function eraser(string $emailAddress, int $page = 1): array {
        global $wpdb;
        $emailAddress = sanitize_email($emailAddress);
        $page = max(1, $page);
        if ($emailAddress === '') {
            return [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [],
                'done' => true,
            ];
        }

        $table = $wpdb->prefix . 'wpcb_bookings';
        // Always process the first matching batch. Successful anonymization
        // removes rows from this email lookup, so offset pagination would skip
        // records on subsequent WordPress eraser calls.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d",
                $emailAddress,
                self::PAGE_SIZE
            )
        );

        $removed = false;
        $retained = false;
        $messages = [];

        foreach ($rows as $row) {
            $bookingId = (int)$row->id;
            if ($this->isRetained($bookingId)) {
                $retained = true;
                $messages[] = sprintf(
                    __('Booking #%d was retained because an administrator marked it for retention.', 'wordpress-calendar-booking'),
                    $bookingId
                );
                continue;
            }

            if ($this->anonymizeBooking($bookingId)) {
                $removed = true;
            }
        }

        $remainingErasable = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} b
                 WHERE b.email = %s
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}wpcb_booking_meta m
                       WHERE m.booking_id = b.id
                         AND m.meta_key = %s
                         AND m.meta_value = '1'
                   )",
                $emailAddress,
                self::RETAIN_META_KEY
            )
        );

        return [
            'items_removed' => $removed,
            'items_retained' => $retained,
            'messages' => array_values(array_unique($messages)),
            'done' => $remainingErasable === 0,
        ];
    }

    public function runRetention(): int {
        $settings = Settings::get();
        if (empty($settings['retention_enabled'])) {
            return 0;
        }

        global $wpdb;
        $days = max(1, (int)($settings['retention_days'] ?? 365));
        $cutoff = Time::formatUtc(Time::nowUtc()->modify('-' . $days . ' days'));
        $bookings = $wpdb->prefix . 'wpcb_bookings';
        $meta = $wpdb->prefix . 'wpcb_booking_meta';

        $statuses = [
            BookingStatus::CONFIRMED,
            BookingStatus::CANCELLED,
            BookingStatus::REJECTED,
            BookingStatus::EXPIRED,
        ];
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT b.id
                FROM {$bookings} b
                WHERE b.status IN ({$placeholders})
                  AND b.slot_end < %s
                  AND NOT EXISTS (
                    SELECT 1 FROM {$meta} m
                    WHERE m.booking_id = b.id
                      AND m.meta_key = %s
                      AND m.meta_value = '1'
                  )
                ORDER BY b.id ASC
                LIMIT 200";
        $params = array_merge($statuses, [$cutoff, self::RETAIN_META_KEY]);
        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$params));

        $count = 0;
        foreach ($ids as $bookingId) {
            if ($this->anonymizeBooking((int)$bookingId)) {
                ++$count;
            }
        }
        return $count;
    }

    public function setRetention(int $bookingId, bool $retain): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_booking_meta';
        $wpdb->delete($table, [
            'booking_id' => $bookingId,
            'meta_key' => self::RETAIN_META_KEY,
        ]);
        if ($retain) {
            $wpdb->insert($table, [
                'booking_id' => $bookingId,
                'meta_key' => self::RETAIN_META_KEY,
                'meta_value' => '1',
            ]);
        }
    }

    public function isRetained(int $bookingId): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_booking_meta';
        return '1' === (string)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$table} WHERE booking_id = %d AND meta_key = %s LIMIT 1",
                $bookingId,
                self::RETAIN_META_KEY
            )
        );
    }

    public function addPolicyText(): void {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = __(
            'When visitors book appointments, this site may store their name, email address, phone number, form responses, appointment time, booking status and administrator notes. The data is used to process the appointment, send Double Opt-In and appointment messages, prevent scheduling conflicts and maintain an audit trail. If calendar synchronization is enabled, appointment data required to create or update the event may be transferred to the configured calendar provider. Calendar account credentials are stored separately in the site settings and are never included in WordPress personal-data exports. Site administrators can configure automatic anonymization of older completed/terminal bookings and can mark individual bookings for retention when they must be kept.',
            'wordpress-calendar-booking'
        );
        wp_add_privacy_policy_content('WordPress Calendar Booking', wpautop($content));
    }

    private function anonymizeBooking(int $bookingId): bool {
        global $wpdb;
        $bookings = $wpdb->prefix . 'wpcb_bookings';
        $meta = $wpdb->prefix . 'wpcb_booking_meta';
        $tokens = $wpdb->prefix . 'wpcb_tokens';

        $booking = $wpdb->get_row(
            $wpdb->prepare("SELECT id, email FROM {$bookings} WHERE id = %d LIMIT 1", $bookingId)
        );
        if (!$booking || strpos((string)$booking->email, '@example.invalid') !== false) {
            return false;
        }

        $updated = $wpdb->update(
            $bookings,
            [
                'full_name' => '',
                'email' => 'anonymized-' . $bookingId . '@example.invalid',
                'phone' => '',
                'notes' => '',
                'admin_notes' => '',
                'updated_at' => Time::formatUtc(Time::nowUtc()),
            ],
            ['id' => $bookingId]
        );
        if ($updated === false) {
            return false;
        }

        $technicalKeys = [
            self::RETAIN_META_KEY,
            'icloud_uid',
            'icloud_event_file',
            'icloud_event_url',
            'icloud_calendar_url',
            'icloud_etag',
            'sync_status',
            'sync_error',
            'last_synced_at',
        ];
        $placeholders = implode(',', array_fill(0, count($technicalKeys), '%s'));
        $params = array_merge([$bookingId], $technicalKeys);
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$meta} WHERE booking_id = %d AND meta_key NOT IN ({$placeholders})",
                ...$params
            )
        );

        // Guest action links are personal access tokens; invalidate them on erase.
        $wpdb->delete($tokens, ['booking_id' => $bookingId]);
        return true;
    }

    private function personalMeta(int $bookingId): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wpcb_booking_meta';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$table} WHERE booking_id = %d ORDER BY id ASC",
                $bookingId
            )
        );

        $technical = [
            self::RETAIN_META_KEY,
            'icloud_uid',
            'icloud_event_file',
            'icloud_event_url',
            'icloud_calendar_url',
            'icloud_etag',
            'sync_status',
            'sync_error',
            'last_synced_at',
        ];

        $out = [];
        foreach ($rows as $row) {
            if (in_array((string)$row->meta_key, $technical, true)) {
                continue;
            }
            $value = maybe_unserialize($row->meta_value);
            $out[(string)$row->meta_key] = $value;
        }
        return $out;
    }
}
