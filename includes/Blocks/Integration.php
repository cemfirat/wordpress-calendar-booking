<?php
namespace Cemb\Blocks;

use Cemb\Frontend\ComponentRenderer;

final class Integration {
    private ComponentRenderer $renderer;

    public function __construct(?ComponentRenderer $renderer = null) {
        $this->renderer = $renderer ?: new ComponentRenderer();
    }

    public function boot(): void {
        add_action('init', [$this, 'register']);
    }

    public function register(): void {
        wp_register_script(
            'cemb-booking-blocks',
            CEMB_URL . 'assets/js/blocks.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-i18n'],
            CEMB_VERSION,
            true
        );

        register_block_type(
            CEMB_DIR . 'blocks/booking-form',
            ['render_callback' => [$this, 'renderBookingForm']]
        );
        register_block_type(
            CEMB_DIR . 'blocks/availability-calendar',
            ['render_callback' => [$this, 'renderAvailabilityCalendar']]
        );
    }

    public function renderBookingForm(array $attributes = []): string {
        return $this->renderer->bookingForm();
    }

    public function renderAvailabilityCalendar(array $attributes = []): string {
        $args = [];
        $month = isset($attributes['month']) ? sanitize_text_field((string)$attributes['month']) : '';
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $args['month'] = $month;
        }
        return $this->renderer->bookingCalendar($args);
    }
}
