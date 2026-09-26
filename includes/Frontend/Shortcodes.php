<?php
namespace Wpcb\Frontend;

use Wpcb\Forms\FormRecovery;

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
        ob_start();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpcb-waiting-list-form">';
        echo '<input type="hidden" name="action" value="wpcb_waitlist_join">';
        echo '<input type="hidden" name="booking_type_id" value="' . (int)$typeId . '">';
        echo '<input type="hidden" name="resource_id" value="' . (int)$resourceId . '">';
        echo '<input type="hidden" name="slot_start" value="' . esc_attr($start) . '">';
        echo '<input type="hidden" name="slot_end" value="' . esc_attr($end) . '">';
        echo '<input type="hidden" name="party_size" value="' . max(1, absint($atts['party_size'])) . '">';
        wp_nonce_field('wpcb_waitlist_join', 'wpcb_waitlist_nonce');
        $recovery = (new FormRecovery())->current('waiting_list');
        $recoveryState = is_array($recovery) ? (array)($recovery['state'] ?? []) : [];
        $recoveryFields = isset($recoveryState['fields']) && is_array($recoveryState['fields'])
            ? $recoveryState['fields']
            : [];
        if (is_array($recovery) && !empty($recovery['message'])) {
            echo '<p class="wpcb-form-error uk-alert-danger" role="alert">' . esc_html((string)$recovery['message']) . '</p>';
        }
        echo $this->renderer->formFieldsMarkup($recoveryFields, '_waitlist');
        echo '<button type="submit">' . esc_html__('Join waiting list', 'wordpress-calendar-booking') . '</button>';
        echo '</form>';
        return (string)ob_get_clean();
    }
}

