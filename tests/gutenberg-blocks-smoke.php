<?php
if (!defined('ABSPATH')) { exit; }

function cemb_blocks_assert($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$integration = new Cemb\Blocks\Integration();
$integration->register();
$registry = WP_Block_Type_Registry::get_instance();

cemb_blocks_assert($registry->is_registered('cemb/booking-form'), 'Booking Form block is registered.');
cemb_blocks_assert($registry->is_registered('cemb/availability-calendar'), 'Availability Calendar block is registered.');

$formBlock = $registry->get_registered('cemb/booking-form');
$calendarBlock = $registry->get_registered('cemb/availability-calendar');
cemb_blocks_assert(is_callable($formBlock->render_callback), 'Booking Form block is server-rendered.');
cemb_blocks_assert(is_callable($calendarBlock->render_callback), 'Availability Calendar block is server-rendered.');
cemb_blocks_assert(isset($calendarBlock->attributes['month']), 'Availability Calendar exposes only the shared renderer month argument.');

$formHtml = do_blocks('<!-- wp:cemb/booking-form /-->');
cemb_blocks_assert(strpos($formHtml, 'data-cemb-booking-form') !== false, 'Booking Form block uses shared booking-form markup.');

$shortcodeHtml = do_shortcode('[cemb_booking_form]');
cemb_blocks_assert(strpos($shortcodeHtml, 'data-cemb-booking-form') !== false, 'Shortcode and block share booking-form semantics.');

$calendarHtml = do_blocks('<!-- wp:cemb/availability-calendar {"month":"2026-09"} /-->');
cemb_blocks_assert(strpos($calendarHtml, 'data-cemb-booking-calendar') !== false, 'Availability Calendar block uses shared calendar markup.');
cemb_blocks_assert(strpos($calendarHtml, 'role="dialog"') !== false, 'Block output preserves accessible dialog semantics.');
cemb_blocks_assert(strpos($calendarHtml, 'aria-modal="true"') !== false, 'Block output preserves modal accessibility state.');

$editor = file_get_contents(CEMB_DIR . 'assets/js/blocks.js');
cemb_blocks_assert(strpos($editor, 'Placeholder') !== false, 'Editor uses a read-only placeholder preview.');
cemb_blocks_assert(strpos($editor, 'ServerSideRender') === false, 'Editor does not fetch live/private calendar data for preview.');
cemb_blocks_assert(strpos($editor, 'admin-post.php') === false, 'Editor preview cannot submit booking actions.');

foreach (['blocks/booking-form/block.json', 'blocks/availability-calendar/block.json'] as $file) {
    $metadata = json_decode((string)file_get_contents(CEMB_DIR . $file), true);
    cemb_blocks_assert(is_array($metadata) && !empty($metadata['editorScript']), basename(dirname($file)) . ' ships WordPress block metadata.');
    cemb_blocks_assert(($metadata['supports']['html'] ?? true) === false, basename(dirname($file)) . ' disables arbitrary block HTML editing.');
}

echo "PASS: Gutenberg blocks smoke test complete.\n";
