<?php
namespace Cemb\Frontend;

use Cemb\Availability\SlotService;
use Cemb\Booking\BookingTypeRepository;
use Cemb\Forms\FieldRepository;
use Cemb\Calendar\IcloudProvider;
use Cemb\Admin\Settings;

class Shortcodes {
    public function boot(): void {
        add_shortcode('cemb_booking_form', [$this, 'bookingForm']);
        add_shortcode('cemb_calendar', [$this, 'calendarList']);
        add_shortcode('cemb_booking_calendar', [$this, 'bookingCalendar']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void {
        wp_register_style('cemb-frontend', CEMB_URL . 'assets/css/frontend.css', [], CEMB_VERSION);
        wp_register_script('cemb-frontend', CEMB_URL . 'assets/js/frontend.js', [], CEMB_VERSION, true);
        wp_localize_script('cemb-frontend', 'cembFrontend', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cemb_frontend'),
        ]);
    }

    public function bookingForm(): string {
        wp_enqueue_style('cemb-frontend');
        wp_enqueue_script('cemb-frontend');
        return '<div class="cemb-booking-form-wrap">' . $this->renderBookingFormMarkup(false) . '</div>';
    }

    private function renderBookingFormMarkup(bool $isModal = false): string {
        $types = (new BookingTypeRepository())->all(true);
        $fields = (new FieldRepository())->active();
        $settings = Settings::get();
        ob_start(); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cemb-booking-form<?php echo $isModal ? ' cemb-booking-form-modal' : ''; ?> uk-form-stacked" data-cemb-booking-form>
            <?php wp_nonce_field('cemb_booking', 'cemb_nonce'); ?>
            <input type="hidden" name="action" value="cemb_submit_booking">
            <input type="hidden" name="cemb_form_ts" value="<?php echo esc_attr(current_time('timestamp')); ?>">
            <input type="hidden" name="visit_address_value" value="<?php echo esc_attr($settings['visit_address']); ?>">
            <input type="hidden" name="own_phone_value" value="<?php echo esc_attr($settings['own_phone']); ?>">
            <div style="display:none"><input type="text" name="website" value=""></div>

            <div class="cemb-field uk-margin">
                <label class="uk-form-label" for="cemb_booking_type_id<?php echo $isModal ? '_modal' : ''; ?>">Terminart</label>
                <div class="uk-form-controls">
                    <select class="uk-select" id="cemb_booking_type_id<?php echo $isModal ? '_modal' : ''; ?>" name="booking_type_id" required data-cemb-type-select>
                        <option value="">Bitte wählen</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo esc_attr($type->id); ?>"><?php echo esc_html($type->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="cemb-field uk-margin">
                <label class="uk-form-label" for="cemb_slot_start<?php echo $isModal ? '_modal' : ''; ?>">Startzeit</label>
                <div class="uk-form-controls">
                    <select class="uk-select" id="cemb_slot_start<?php echo $isModal ? '_modal' : ''; ?>" name="slot_start" required data-cemb-slot-select>
                        <option value="">Bitte zuerst Terminart wählen</option>
                    </select>
                </div>
            </div>

            <?php foreach ($fields as $field): ?>
                <div class="cemb-field uk-margin" data-cemb-field="<?php echo esc_attr($field->field_key); ?>">
                    <label class="uk-form-label" for="cemb_<?php echo esc_attr($field->field_key . ($isModal ? '_modal' : '')); ?>"><?php echo esc_html($field->label); ?><?php echo $field->is_required ? ' *' : ''; ?></label>
                    <div class="uk-form-controls"><?php $this->renderField($field, $isModal); ?></div>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="uk-button uk-button-primary">Termin buchen</button>
        </form>
        <?php
        return (string)ob_get_clean();
    }

    private function fieldOptions(object $field): array {
        if (empty($field->options_json)) return [];
        $decoded = json_decode((string)$field->options_json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function renderField(object $field, bool $isModal = false): void {
        $name = esc_attr($field->field_key);
        $required = $field->is_required ? 'required' : '';
        $id = 'cemb_' . $name . ($isModal ? '_modal' : '');
        $options = $this->fieldOptions($field);
        switch ($field->field_type) {
            case 'textarea':
                echo '<textarea class="uk-textarea" id="' . esc_attr($id) . '" name="' . $name . '" ' . $required . '></textarea>';
                break;
            case 'checkbox':
                echo '<label><input class="uk-checkbox" type="checkbox" id="' . esc_attr($id) . '" name="' . $name . '" value="1" ' . $required . '> </label>';
                break;
            case 'email':
                echo '<input class="uk-input" type="email" id="' . esc_attr($id) . '" name="' . $name . '" ' . $required . '>';
                break;
            case 'select':
                echo '<select class="uk-select" id="' . esc_attr($id) . '" name="' . $name . '" ' . $required . '><option value="">Bitte wählen</option>';
                foreach ($options as $option) echo '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
                echo '</select>';
                break;
            case 'radio':
                foreach ($options as $idx => $option) echo '<label class="uk-margin-small-right"><input class="uk-radio" type="radio" id="' . esc_attr($id . '_' . $idx) . '" name="' . $name . '" value="' . esc_attr($option) . '" ' . $required . '> ' . esc_html($option) . '</label>';
                break;
            default:
                echo '<input class="uk-input" type="text" id="' . esc_attr($id) . '" name="' . $name . '" ' . $required . '>';
        }
    }

    public function calendarList(): string {
        wp_enqueue_style('cemb-frontend');
        $settings = Settings::get();
        $provider = new IcloudProvider();
        $from = current_time('Y-m-d H:i:s');
        $to = date_i18n('Y-m-d H:i:s', strtotime('+60 days', current_time('timestamp')));
        $events = $provider->events($from, $to);
        usort($events, static fn($a, $b) => strcmp($a['start'], $b['start']));
        $events = array_slice($events, 0, (int)$settings['show_calendar_limit']);
        ob_start();
        echo '<div class="cemb-calendar-list uk-grid uk-child-width-1-1" uk-grid>';
        if (!$events) {
            echo '<p>Keine Termine vorhanden.</p>';
        } else {
            foreach ($events as $event) {
                echo '<div class="cemb-calendar-item uk-card uk-card-default uk-card-body">';
                echo '<strong>' . esc_html($event['summary'] ?: 'Termin') . '</strong><br>';
                echo esc_html(wp_date($settings['date_format'] . ' ' . $settings['time_format'], strtotime($event['start']))) . ' - ' . esc_html(wp_date($settings['time_format'], strtotime($event['end'])));
                if (!empty($event['location'])) echo '<br>' . esc_html($event['location']);
                echo '</div>';
            }
        }
        echo '</div>';
        return (string)ob_get_clean();
    }

    public function bookingCalendar(array $atts = []): string {
        wp_enqueue_style('cemb-frontend');
        wp_enqueue_script('cemb-frontend');
        $atts = shortcode_atts(['month' => current_time('Y-m')], $atts, 'cemb_booking_calendar');
        $requestedMonth = isset($_GET['cemb_month']) ? sanitize_text_field(wp_unslash($_GET['cemb_month'])) : (string)$atts['month'];
        $month = preg_match('/^\d{4}-\d{2}$/', $requestedMonth) ? $requestedMonth : current_time('Y-m');
        $slotService = new SlotService();
        $display = $slotService->getMonthDisplay($month);
        $weeks = array_chunk($display['days'], 7);
        ob_start(); ?>
        <div class="cemb-booking-calendar-wrap" data-cemb-booking-calendar>
            <div class="cemb-calendar-toolbar uk-flex uk-flex-between uk-flex-middle uk-flex-wrap gap-1">
                <div class="uk-flex uk-flex-middle uk-flex-wrap gap-1">
                    <a class="uk-button uk-button-default" href="<?php echo esc_url(add_query_arg(['cemb_month' => $display['prev']])); ?>">&lsaquo;</a>
                    <strong class="uk-text-large"><?php echo esc_html($display['title']); ?></strong>
                    <a class="uk-button uk-button-default" href="<?php echo esc_url(add_query_arg(['cemb_month' => $display['next']])); ?>">&rsaquo;</a>
                </div>
                <a href="#cemb-booking-modal" class="uk-button uk-button-primary" data-cemb-open-toolbar-modal>+</a>
            </div>
            <div class="cemb-apple-calendar">
                <div class="cemb-week-header uk-text-muted">KW</div>
                <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $weekday): ?><div class="cemb-week-header"><?php echo esc_html($weekday); ?></div><?php endforeach; ?>
                <?php foreach ($weeks as $week): ?>
                    <div class="cemb-weeknumber uk-text-muted"><?php echo esc_html(wp_date('W', strtotime($week[0]['date']))); ?></div>
                    <?php foreach ($week as $day):
                        $classes = ['cemb-day'];
                        if (!$day['in_month']) $classes[] = 'is-outside';
                        if ($day['is_past']) $classes[] = 'is-past';
                        if ($day['is_weekend']) $classes[] = 'is-weekend';
                        if ($day['date'] === current_time('Y-m-d')) $classes[] = 'is-today';
                    ?>
                        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>">
                            <div class="cemb-day-head"><span class="cemb-day-number"><?php echo esc_html(wp_date('j', strtotime($day['date']))); ?></span></div>
                            <div class="cemb-day-body">
                                <?php if (!$day['is_weekend']): ?>
                                    <?php foreach ($day['items'] as $item): ?>
                                        <div class="cemb-event-pill <?php echo esc_attr($item['class']); ?>" title="<?php echo esc_attr($item['title']); ?>">
                                            <?php echo esc_html($item['label']); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <div id="cemb-booking-modal" class="cemb-booking-modal" hidden data-cemb-modal>
                <div class="cemb-modal-panel uk-card uk-card-default uk-card-body">
                    <button type="button" class="cemb-modal-close" data-cemb-close-modal aria-label="Schließen">&times;</button>
                    <h3 class="uk-margin-small-bottom">Termin buchen</h3>
                    <?php echo $this->renderBookingFormMarkup(true); ?>
                </div>
            </div>
        </div>
        <?php return (string)ob_get_clean();
    }
}
