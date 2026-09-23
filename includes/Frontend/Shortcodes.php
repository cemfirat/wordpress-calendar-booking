<?php
namespace Wpcb\Frontend;

final class Shortcodes {
    private ComponentRenderer $renderer;
    private AssetManager $assets;

    public function __construct(?ComponentRenderer $renderer = null, ?AssetManager $assets = null) {
        $this->assets = $assets ?: new AssetManager();
        $this->renderer = $renderer ?: new ComponentRenderer($this->assets);
    }

    public function boot(): void {
        add_shortcode('wpcb_booking_form', [$this, 'bookingForm']);
        add_shortcode('wpcb_calendar', [$this, 'calendarList']);
        add_shortcode('wpcb_booking_calendar', [$this, 'bookingCalendar']);
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
}
