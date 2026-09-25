<?php
if (!defined('ABSPATH')) { exit(1); }

function wpcb_transparency_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$settingsBefore = get_option('wpcb_settings', []);
$typeId = 0;
$resourceId = 0;
$ruleId = 0;
$calendarUrl = 'https://8.8.8.8/transparency-ci.ics';
$httpFilter = null;

try {
    $now = Wpcb\Support\Time::nowUtc();
    $target = $now->modify('+10 days')->setTime(0, 0, 0);
    $targetDate = $target->format('Y-m-d');
    $targetCompact = $target->format('Ymd');
    $second = $target->modify('+7 days');
    $secondCompact = $second->format('Ymd');
    $allDayCompact = $target->modify('+1 day')->format('Ymd');

    $recurringIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WPCB TRANSP CI//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:transparent-series-ci\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:{$targetCompact}T110000Z\r\nDTEND:{$targetCompact}T113000Z\r\n"
        . "RRULE:FREQ=WEEKLY;COUNT=2\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:transparent-series-ci\r\nDTSTAMP:20260101T000000Z\r\n"
        . "RECURRENCE-ID:{$secondCompact}T110000Z\r\nDTSTART:{$secondCompact}T120000Z\r\n"
        . "DTEND:{$secondCompact}T123000Z\r\nTRANSP:OPAQUE\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:transparent-allday-ci\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART;VALUE=DATE:{$allDayCompact}\r\nDTEND;VALUE=DATE:" . $target->modify('+2 days')->format('Ymd') . "\r\n"
        . "TRANSP:TRANSPARENT\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:default-opaque-ci\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:{$targetCompact}T100000Z\r\nDTEND:{$targetCompact}T103000Z\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:cancelled-opaque-ci\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:{$targetCompact}T130000Z\r\nDTEND:{$targetCompact}T133000Z\r\n"
        . "STATUS:CANCELLED\r\nTRANSP:OPAQUE\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    $parsed = (new Wpcb\Calendar\Parser())->parseResult(
        $recurringIcs,
        $target->modify('-1 day')->format('Y-m-d H:i:s'),
        $target->modify('+15 days')->format('Y-m-d H:i:s')
    );
    wpcb_transparency_assert(is_array($parsed), 'iCalendar transparency fixture parses successfully.');

    $series = array_values(array_filter($parsed, static fn($event) => ($event['uid'] ?? '') === 'transparent-series-ci'));
    wpcb_transparency_assert(
        count($series) === 1 && $series[0]['start'] === $second->format('Y-m-d') . ' 12:00:00',
        'Transparent recurring master instances are free while an explicit opaque override remains busy.'
    );
    wpcb_transparency_assert(
        count(array_filter($parsed, static fn($event) => ($event['uid'] ?? '') === 'transparent-allday-ci')) === 0,
        'Transparent all-day events do not become busy intervals.'
    );
    wpcb_transparency_assert(
        count(array_filter($parsed, static fn($event) => ($event['uid'] ?? '') === 'default-opaque-ci')) === 1,
        'VEVENTs without TRANSP retain the RFC default opaque behavior.'
    );
    wpcb_transparency_assert(
        count(array_filter($parsed, static fn($event) => ($event['uid'] ?? '') === 'cancelled-opaque-ci')) === 0,
        'Cancelled events remain non-blocking independently of TRANSP.'
    );

    $feedIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WPCB SLOT TRANSP CI//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:slot-transparent-ci\r\nDTSTART:{$targetCompact}T090000Z\r\nDTEND:{$targetCompact}T093000Z\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:slot-opaque-ci\r\nDTSTART:{$targetCompact}T093000Z\r\nDTEND:{$targetCompact}T100000Z\r\nTRANSP:OPAQUE\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:slot-default-ci\r\nDTSTART:{$targetCompact}T100000Z\r\nDTEND:{$targetCompact}T103000Z\r\nEND:VEVENT\r\n"
        . "END:VCALENDAR\r\n";

    $httpFilter = static function ($pre, $args, $url) use ($calendarUrl, $feedIcs) {
        if ($url !== $calendarUrl) {
            return $pre;
        }
        return [
            'headers' => ['content-type' => 'text/calendar'],
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => $feedIcs,
            'cookies' => [],
            'filename' => null,
        ];
    };
    add_filter('pre_http_request', $httpFilter, 10, 3);

    $settings = Wpcb\Admin\Settings::get();
    $settings['timezone'] = 'UTC';
    $settings['calendar_url'] = $calendarUrl;
    $settings['calendar_urls'] = $calendarUrl;
    $settings['calendar_cache_minutes'] = 1;
    update_option('wpcb_settings', $settings);
    delete_transient('wpcb_ical_' . md5($calendarUrl));

    $createdAt = Wpcb\Support\Time::formatUtc($now);
    $wpdb->insert($wpdb->prefix . 'wpcb_booking_types', [
        'name' => 'TRANSP slot fixture',
        'slug' => 'transp-slot-' . strtolower(wp_generate_password(10, false, false)),
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
        'is_public' => 0,
        'sort_order' => 0,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $typeId = (int)$wpdb->insert_id;
    wpcb_transparency_assert($typeId > 0, 'Transparency slot fixture booking type is created.');

    $resources = new Wpcb\Resources\ResourceRepository();
    $resource = $resources->save([
        'name' => 'TRANSP slot resource',
        'slug' => 'transp-slot-' . strtolower(wp_generate_password(10, false, false)),
        'capacity' => 1,
        'is_active' => 1,
        'is_public' => 0,
    ]);
    $resourceId = is_int($resource) ? $resource : 0;
    wpcb_transparency_assert($resourceId > 0, 'Transparency slot fixture resource is created.');
    $resources->setForBookingType($typeId, [$resourceId]);

    $wpdb->insert($wpdb->prefix . 'wpcb_availability_rules', [
        'scope_type' => 'booking_type',
        'scope_id' => $typeId,
        'weekday' => (int)$target->format('N'),
        'start_time' => '09:00:00',
        'end_time' => '10:30:00',
        'slot_duration_minutes' => 30,
        'min_notice_minutes' => 0,
        'max_days_in_advance' => 30,
        'is_active' => 1,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $ruleId = (int)$wpdb->insert_id;
    wpcb_transparency_assert($ruleId > 0, 'Transparency slot fixture availability rule is created.');

    $slots = (new Wpcb\Availability\SlotService())->getSlotsForResource($typeId, $resourceId, 14);
    $starts = array_column($slots, 'start');
    $transparentStart = $targetDate . ' 09:00:00';
    $opaqueStart = $targetDate . ' 09:30:00';
    $defaultStart = $targetDate . ' 10:00:00';
    wpcb_transparency_assert(
        in_array($transparentStart, $starts, true),
        'Final slot generation keeps a slot free when the matching ICS event is TRANSPARENT.'
    );
    wpcb_transparency_assert(
        !in_array($opaqueStart, $starts, true) && !in_array($defaultStart, $starts, true),
        'Final slot generation blocks explicit and default opaque ICS events.'
    );
} finally {
    if ($httpFilter !== null) {
        remove_filter('pre_http_request', $httpFilter, 10);
    }
    delete_transient('wpcb_ical_' . md5($calendarUrl));
    update_option('wpcb_settings', $settingsBefore);
    if ($ruleId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_availability_rules', ['id' => $ruleId]);
    }
    if ($typeId > 0 && $resourceId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_type_resources', [
            'booking_type_id' => $typeId,
            'resource_id' => $resourceId,
        ]);
    }
    if ($resourceId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_resource_calendar_connections', ['resource_id' => $resourceId]);
        $wpdb->delete($wpdb->prefix . 'wpcb_resources', ['id' => $resourceId]);
    }
    if ($typeId > 0) {
        $wpdb->delete($wpdb->prefix . 'wpcb_booking_types', ['id' => $typeId]);
    }
}

WP_CLI::success('iCalendar TRANSP busy/slot regression passed.');
