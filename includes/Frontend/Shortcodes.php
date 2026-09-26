<?php
namespace Wpcb\Frontend;

use Wpcb\WaitingList\WaitingListFormRenderer;

final class Shortcodes {
    private ComponentRenderer $renderer;
    private AssetManager $assets;
    private WaitingListFormRenderer $waitingListRenderer;

    public function __construct(
        ?ComponentRenderer $renderer = null,
        ?AssetManager $assets = null,
        ?WaitingListFormRenderer $waitingListRenderer = null
    ) {
        $this->assets = $assets ?: new AssetManager();
        $this->renderer = $renderer ?: new ComponentRenderer($this->assets);
        $this->waitingListRenderer = $waitingListRenderer ?: new WaitingListFormRenderer();
    }

    public function boot(): void {
        add_shortcode('wpcb_booking_form', [$this, 'bookingForm']);
        add_shortcode('wpcb_calendar', [$this, 'calendarList']);
        add_shortcode('wpcb_booking_calendar', [$this, 'bookingCalendar']);
        add_shortcode('wpcb_waiting_list', [$this, 'waitingList']);
        add_action('wp_enqueue_scripts', [$this->assets, 'register']);
    }

    public function bookingForm(array $atts = []): string {
        return $this->renderer->bookingForm();
    }

    public function calendarList(array $atts = []): string {
        return $this->renderer->calendarList();
    }

    public function bookingCalendar(array $atts = []): string {
        return $this->renderer->bookingCalendar($atts);
    }

    public function waitingList(array $atts = []): string {
        $atts = shortcode_atts([
            'booking_type_id' => 0,
            'resource_id' => 0,
            'slot_start' => '',
            'slot_end' => '',
            'party_size' => 1,
        ], $atts, 'wpcb_waiting_list');
        $typeId = absint($atts['booking_type_id']);
        $resourceId = absint($atts['resource_id']);
        $start = sanitize_text_field((string)$atts['slot_start']);
        $end = sanitize_text_field((string)$atts['slot_end']);
        if ($typeId < 1 || $resourceId < 1 || !$start || !$end) {
            return '<p>' . esc_html__('Waiting-list form is not configured for a specific slot.', 'wordpress-calendar-booking') . '</p>';
        }
        return $this->waitingListRenderer->joinForm([
            'booking_type_id' => $typeId,
            'resource_id' => $resourceId,
            'slot_start' => $start,
            'slot_end' => $end,
            'party_size' => max(1, absint($atts['party_size'])),
        ]);
    }
}

