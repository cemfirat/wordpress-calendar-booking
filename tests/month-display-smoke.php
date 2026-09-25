<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_month_display_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$settingsBefore = get_option('wpcb_settings', []);
$publicTypeId = 0;
$privateTypeId = 0;
$resourceId = 0;
$connectionId = 0;
$bookingIds = [];
$ruleId = 0;
$calendarUrl = 'https://8.8.8.8/month-display-ci.ics';
$httpFilter = null;

try {
    $settings = Wpcb\Admin\Settings::get();
    $settings['timezone'] = 'Europe/Vienna';
    $settings['calendar_url'] = $calendarUrl;
    $settings['calendar_urls'] = $calendarUrl;
    $settings['calendar_cache_minutes'] = 1;
    update_option('wpcb_settings', $settings);
    delete_transient('wpcb_ical_' . md5($calendarUrl));

    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WPCB MONTH DISPLAY CI//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:all-day-month-ci\r\nDTSTART;VALUE=DATE:20261030\r\n"
        . "DTEND;VALUE=DATE:20261101\r\nSUMMARY:PRIVATE EXTERNAL NAME\r\nEND:VEVENT\r\n"
        . "END:VCALENDAR\r\n";

    $googleCalls = 0;
    $httpFilter = static function ($pre, $args, $url) use ($calendarUrl, $ics, &$googleCalls) {
        if ($url === $calendarUrl) {
            return [
                'headers' => ['content-type' => 'text/calendar'],
                'response' => ['code' => 200, 'message' => 'OK'],
                'body' => $ics,
                'cookies' => [],
                'filename' => null,
            ];
        }
        if ($url === 'https://www.googleapis.com/calendar/v3/freeBusy') {
            $googleCalls++;
            return [
                'headers' => ['content-type' => 'application/json'],
                'response' => ['code' => 200, 'message' => 'OK'],
                'body' => wp_json_encode([
                    'calendars' => [
                        'primary' => [
                            'busy' => [[
                                'start' => '2026-11-01T09:00:00Z',
                                'end' => '2026-11-01T10:00:00Z',
                            ]],
                        ],
                    ],
                ]),
                'cookies' => [],
                'filename' => null,
            ];
        }
        return $pre;
    };
    add_filter('pre_http_request', $httpFilter, 10, 3);

    $now = Wpcb\Support\Time::formatUtc(Wpcb\Support\Time::nowUtc());
    $typeTable = $wpdb->prefix . 'wpcb_booking_types';
    $makeType = static function (string $name, bool $public) use ($wpdb, $typeTable, $now): int {
        $wpdb->insert($typeTable, [
            'name' => $name,
            'slug' => sanitize_title($name . '-' . wp_generate_password(8, false, false)),
            'description' => '',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'capacity' => 1,
            'show_remaining_capacity' => 0,
            'payment_mode' => 'free',
            'price_minor' => 0,
            'currency' => 'EUR',
            'is_active' => 1,
            'is_public' => $public ? 1 : 0,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int)$wpdb->insert_id;
    };

    $publicTypeId = $makeType('Month public CI', true);
    $privateTypeId = $makeType('Month private CI', false);
    wpcb_month_display_assert($publicTypeId > 0 && $privateTypeId > 0, 'Month display booking types are created.');

    $resources = new Wpcb\Resources\ResourceRepository();
    $resource = $resources->save([
        'name' => 'Month display resource',
        'slug' => 'month-display-' . strtolower(wp_generate_password(8, false, false)),
        'capacity' => 1,
        'is_active' => 1,
        'is_public' => 0,
    ]);
    $resourceId = is_int($resource) ? $resource : 0;
    wpcb_month_display_assert($resourceId > 0, 'Month display resource is created.');
    $resources->setForBookingType($publicTypeId, [$resourceId]);
    $resources->setForBookingType($privateTypeId, [$resourceId]);

    $wpdb->insert($wpdb->prefix . 'wpcb_availability_rules', [
        'scope_type' => 'booking_type',
        'scope_id' => $publicTypeId,
        'weekday' => 6,
        'start_time' => '08:00:00',
        'end_time' => '18:00:00',
        'slot_duration_minutes' => 30,
        'min_notice_minutes' => 0,
        'max_days_in_advance' => 365,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $ruleId = (int)$wpdb->insert_id;
    wpcb_month_display_assert($ruleId > 0, 'Saturday is configured as a real working day for the public type.');

    $connections = new Wpcb\Calendar\CalendarConnectionRepository();
    $createdConnection = $connections->create([
        'provider' => 'google',
        'name' => 'Month routed Google CI',
        'remote_calendar_id' => 'primary',
        'blocks_availability' => 1,
        'receives_bookings' => 0,
    ], [
        'access_token' => 'CI-MONTH-GOOGLE',
        'expires_at' => time() + 3600,
        'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
        'token_type' => 'Bearer',
    ]);
    $connectionId = is_int($createdConnection) ? $createdConnection : 0;
    wpcb_month_display_assert($connectionId > 0, 'Routed blocking calendar connection is created.');
    $connections->setForResource($resourceId, [[
        'connection_id' => $connectionId,
        'blocks_availability' => 1,
        'receives_bookings' => 0,
    ]]);

    $bookingTable = $wpdb->prefix . 'wpcb_bookings';
    $insertBooking = static function (
        int $typeId,
        int $resourceId,
        string $start,
        string $end,
        string $name
    ) use ($wpdb, $bookingTable, $now, &$bookingIds): int {
        $wpdb->insert($bookingTable, [
            'booking_uuid' => wp_generate_uuid4(),
            'booking_type_id' => $typeId,
            'resource_id' => $resourceId,
            'slot_start' => $start,
            'slot_end' => $end,
            'party_size' => 1,
            'status' => Wpcb\Booking\BookingStatus::CONFIRMED,
            'full_name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)) . '@example.invalid',
            'source' => 'ci-month-display',
            'lang' => 'de',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int)$wpdb->insert_id;
        if ($id > 0) {
            $bookingIds[] = $id;
        }
        return $id;
    };

    $multiStart = Wpcb\Support\Time::localToUtc('2026-10-30 23:30:00');
    $multiEnd = Wpcb\Support\Time::localToUtc('2026-10-31 00:30:00');
    wpcb_month_display_assert(
        is_string($multiStart) && is_string($multiEnd)
        && $insertBooking($publicTypeId, $resourceId, $multiStart, $multiEnd, 'PRIVATE MULTIDAY CUSTOMER') > 0,
        'Public multi-day booking fixture is created across a local midnight.'
    );

    $privateStart = Wpcb\Support\Time::localToUtc('2026-10-29 10:00:00');
    $privateEnd = Wpcb\Support\Time::localToUtc('2026-10-29 10:30:00');
    wpcb_month_display_assert(
        is_string($privateStart) && is_string($privateEnd)
        && $insertBooking($privateTypeId, $resourceId, $privateStart, $privateEnd, 'PRIVATE HIDDEN CUSTOMER') > 0,
        'Private booking-type fixture is created for scope verification.'
    );

    $display = (new Wpcb\Availability\SlotService())->getMonthDisplay('2026-10');
    $days = [];
    foreach ($display['days'] as $day) {
        $days[$day['date']] = $day;
    }

    wpcb_month_display_assert(!empty($display['availability_complete']), 'Month model reports complete provider availability.');
    wpcb_month_display_assert($googleCalls > 0, 'Month model reads the routed blocking provider used by public booking availability.');
    wpcb_month_display_assert(
        !empty($days['2026-10-31']['is_weekend']) && !empty($days['2026-10-31']['is_working_day']),
        'A configured Saturday remains a weekend date but is also represented as a working day.'
    );
    wpcb_month_display_assert(
        !empty($days['2026-11-01']['is_weekend']) && empty($days['2026-11-01']['is_working_day']),
        'An unconfigured Sunday remains non-working without suppressing its busy data.'
    );
    wpcb_month_display_assert(
        count($days['2026-10-30']['items'] ?? []) >= 2
        && count($days['2026-10-31']['items'] ?? []) >= 2,
        'Overnight booking and multi-day all-day ICS intervals are projected onto every affected local day.'
    );
    wpcb_month_display_assert(
        count($days['2026-11-01']['items'] ?? []) === 1,
        'All-day exclusive DTEND does not spill into November 1, while routed Sunday busy time remains visible.'
    );
    wpcb_month_display_assert(
        count($days['2026-10-29']['items'] ?? []) === 0,
        'Bookings from non-public booking types are outside the public month-calendar scope.'
    );

    $publicJson = wp_json_encode($display);
    wpcb_month_display_assert(
        strpos($publicJson, 'PRIVATE MULTIDAY CUSTOMER') === false
        && strpos($publicJson, 'PRIVATE HIDDEN CUSTOMER') === false
        && strpos($publicJson, 'PRIVATE EXTERNAL NAME') === false
        && strpos($publicJson, 'Month routed Google CI') === false,
        'Public month data stays busy-only and does not expose customer, event, resource, or provider labels.'
    );

    $renderer = new Wpcb\Frontend\ComponentRenderer();
    $html = $renderer->bookingCalendar(['month' => '2026-10']);
    wpcb_month_display_assert(
        strpos($html, 'is-weekend is-working') !== false
        && substr_count($html, 'wpcb-event-pill') >= 5,
        'Shared booking-calendar renderer displays busy pills on configured weekend working days.'
    );
    wpcb_month_display_assert(
        strpos($html, 'PRIVATE MULTIDAY CUSTOMER') === false
        && strpos($html, 'PRIVATE HIDDEN CUSTOMER') === false
        && strpos($html, 'PRIVATE EXTERNAL NAME') === false,
        'Shared renderer preserves the busy-only privacy projection.'
    );
} finally {
    if ($httpFilter !== null) {
        remove_filter('pre_http_request', $httpFilter, 10);
    }
    delete_transient('wpcb_ical_' . md5($calendarUrl));
    update_option('wpcb_settings', $settingsBefore);

    foreach ($bookingIds as $bookingId) {
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_status_log', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_meta', ['booking_id' => $bookingId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_bookings', ['id' => $bookingId]);
    }
    if ($connectionId > 0) {
        (new Wpcb\Calendar\CalendarConnectionRepository())->delete($connectionId);
    }
    if ($ruleId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_availability_rules', ['id' => $ruleId]);
    }
    foreach ([$publicTypeId, $privateTypeId] as $typeId) {
        if ($typeId > 0) {
            $wpdb->delete($wpdb->prefix . 'wpcb_booking_type_resources', ['booking_type_id' => $typeId]);
            $wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $typeId]);
        }
    }
    if ($resourceId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_resource_calendar_connections', ['resource_id' => $resourceId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_resources', ['id' => $resourceId]);
    }
}

WP_CLI::success('Month display busy-source and multi-day regression passed.');
