<?php
namespace Wpcb\Frontend;

use Wpcb\Availability\SlotService;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Forms\FieldRepository;
use Wpcb\Calendar\IcloudProvider;
use Wpcb\Calendar\PublicBusyPresenter;
use Wpcb\Admin\Settings;
use Wpcb\Support\Time;

class ComponentRenderer {
    private AssetManager $assets;

    public function __construct(?AssetManager $assets = null) {
        $this->assets = $assets ?: new AssetManager();
    }
    public function bookingForm(): string {
        $this->assets->enqueue(true);
        do_action('wpcb_before_component', 'booking_form');
        $classes = apply_filters('wpcb_booking_wrapper_classes', ['wpcb-booking-form-wrap'], 'booking_form');
        $html = '<div class="' . esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', (array)$classes)))) . '">' . $this->renderBookingFormMarkup(false) . '</div>';
        $html = (string)apply_filters('wpcb_render_booking_form', $html);
        do_action('wpcb_after_component', 'booking_form', $html);
        return $html;
    }

    private function renderBookingFormMarkup(bool $isModal = false): string {
        $types = (new BookingTypeRepository())->all(true);
        $fields = (new FieldRepository())->active();
        $settings = Settings::get();
        ob_start(); ?>
        <?php
        $formClasses = ['wpcb-booking-form', 'uk-form-stacked'];
        if ($isModal) $formClasses[] = 'wpcb-booking-form-modal';
        $formClasses = (array)apply_filters('wpcb_booking_form_classes', $formClasses, $isModal);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="<?php echo esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', $formClasses)))); ?>" data-wpcb-booking-form>
            <?php wp_nonce_field('wpcb_booking', 'wpcb_nonce'); ?>
            <input type="hidden" name="action" value="wpcb_submit_booking">
            <input type="hidden" name="wpcb_form_ts" value="<?php echo esc_attr(current_time('timestamp')); ?>">
            <input type="hidden" name="visit_address_value" value="<?php echo esc_attr($settings['visit_address']); ?>">
            <input type="hidden" name="own_phone_value" value="<?php echo esc_attr($settings['own_phone']); ?>">
            <div style="display:none"><input type="text" name="website" value=""></div>

            <div class="wpcb-field uk-margin">
                <label class="uk-form-label" for="wpcb_booking_type_id<?php echo $isModal ? '_modal' : ''; ?>"><?php esc_html_e('Terminart', 'wordpress-calendar-booking'); ?></label>
                <div class="uk-form-controls">
                    <select class="uk-select" id="wpcb_booking_type_id<?php echo $isModal ? '_modal' : ''; ?>" name="booking_type_id" required data-wpcb-type-select>
                        <option value=""><?php esc_html_e('Bitte wählen', 'wordpress-calendar-booking'); ?></option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo esc_attr($type->id); ?>" data-capacity="<?php echo esc_attr(max(1, (int)($type->capacity ?? 1))); ?>" data-payment-mode="<?php echo esc_attr((string)($type->payment_mode ?? 'free')); ?>"><?php echo esc_html($type->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php
            $maxPartySize = 1;
            foreach ($types as $type) {
                $maxPartySize = max($maxPartySize, max(1, (int)($type->capacity ?? 1)));
            }
            ?>
            <?php if ($maxPartySize > 1): ?>
                <div class="wpcb-field uk-margin">
                    <label class="uk-form-label" for="wpcb_party_size<?php echo $isModal ? '_modal' : ''; ?>"><?php esc_html_e('Teilnehmer', 'wordpress-calendar-booking'); ?></label>
                    <div class="uk-form-controls">
                        <input class="uk-input" type="number" id="wpcb_party_size<?php echo $isModal ? '_modal' : ''; ?>" name="party_size" value="1" min="1" max="<?php echo (int)$maxPartySize; ?>" required data-wpcb-party-size>
                    </div>
                </div>
            <?php else: ?>
                <input type="hidden" name="party_size" value="1">
            <?php endif; ?>

            <div class="wpcb-field uk-margin">
                <label class="uk-form-label" for="wpcb_recurrence_count<?php echo $isModal ? '_modal' : ''; ?>"><?php esc_html_e('Wiederholung', 'wordpress-calendar-booking'); ?></label>
                <div class="uk-form-controls">
                    <select class="uk-select" id="wpcb_recurrence_count<?php echo $isModal ? '_modal' : ''; ?>" name="recurrence_count">
                        <option value="1"><?php esc_html_e('Einmaliger Termin', 'wordpress-calendar-booking'); ?></option>
                        <option value="2"><?php esc_html_e('2 Termine', 'wordpress-calendar-booking'); ?></option>
                        <option value="4"><?php esc_html_e('4 Termine', 'wordpress-calendar-booking'); ?></option>
                        <option value="6"><?php esc_html_e('6 Termine', 'wordpress-calendar-booking'); ?></option>
                        <option value="8"><?php esc_html_e('8 Termine', 'wordpress-calendar-booking'); ?></option>
                        <option value="12"><?php esc_html_e('12 Termine', 'wordpress-calendar-booking'); ?></option>
                    </select>
                </div>
            </div>
            <div class="wpcb-field uk-margin">
                <label class="uk-form-label" for="wpcb_recurrence_interval<?php echo $isModal ? '_modal' : ''; ?>"><?php esc_html_e('Serienabstand', 'wordpress-calendar-booking'); ?></label>
                <div class="uk-form-controls">
                    <select class="uk-select" id="wpcb_recurrence_interval<?php echo $isModal ? '_modal' : ''; ?>" name="recurrence_interval">
                        <option value="1"><?php esc_html_e('Wöchentlich', 'wordpress-calendar-booking'); ?></option>
                        <option value="2"><?php esc_html_e('Alle 2 Wochen', 'wordpress-calendar-booking'); ?></option>
                        <option value="3"><?php esc_html_e('Alle 3 Wochen', 'wordpress-calendar-booking'); ?></option>
                        <option value="4"><?php esc_html_e('Alle 4 Wochen', 'wordpress-calendar-booking'); ?></option>
                    </select>
                </div>
                <p class="uk-text-meta"><?php esc_html_e('Serien sind derzeit für Terminarten ohne Zahlung verfügbar.', 'wordpress-calendar-booking'); ?></p>
            </div>

            <div class="wpcb-field uk-margin">
                <label class="uk-form-label" for="wpcb_slot_start<?php echo $isModal ? '_modal' : ''; ?>"><?php esc_html_e('Startzeit', 'wordpress-calendar-booking'); ?></label>
                <div class="uk-form-controls">
                    <select class="uk-select" id="wpcb_slot_start<?php echo $isModal ? '_modal' : ''; ?>" name="slot_token" required data-wpcb-slot-select>
                        <option value=""><?php esc_html_e('Bitte zuerst Terminart wählen', 'wordpress-calendar-booking'); ?></option>
                    </select>
                </div>
            </div>

            <?php foreach ($fields as $field): ?>
                <div class="wpcb-field uk-margin" data-wpcb-field="<?php echo esc_attr($field->field_key); ?>">
                    <label class="uk-form-label" for="wpcb_<?php echo esc_attr($field->field_key . ($isModal ? '_modal' : '')); ?>"><?php echo esc_html($field->label); ?><?php echo $field->is_required ? ' *' : ''; ?></label>
                    <div class="uk-form-controls"><?php $this->renderField($field, $isModal); ?></div>
                </div>
            <?php endforeach; ?>
            <?php $buttonClasses = (array)apply_filters('wpcb_booking_button_classes', ['uk-button', 'uk-button-primary'], 'submit'); ?>
            <button type="submit" class="<?php echo esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', $buttonClasses)))); ?>"><?php esc_html_e('Termin buchen', 'wordpress-calendar-booking'); ?></button>
        </form>
        <?php
        $html = (string)ob_get_clean();
        return (string)apply_filters('wpcb_render_booking_form_markup', $html, $isModal);
    }

    private function fieldOptions(object $field): array {
        if (empty($field->options_json)) return [];
        $decoded = json_decode((string)$field->options_json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function renderField(object $field, bool $isModal = false): void {
        $name = esc_attr($field->field_key);
        $required = $field->is_required ? 'required' : '';
        $id = 'wpcb_' . $name . ($isModal ? '_modal' : '');
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
                echo '<select class="uk-select" id="' . esc_attr($id) . '" name="' . $name . '" ' . $required . '><option value="">' . esc_html__('Bitte wählen', 'wordpress-calendar-booking') . '</option>';
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
        $this->assets->enqueue(false);
        do_action('wpcb_before_component', 'calendar_list');
        $settings = Settings::get();
        $provider = new IcloudProvider();
        $from = Time::formatUtc(Time::nowUtc());
        $to = Time::formatUtc(Time::nowUtc()->modify('+60 days'));
        $events = $provider->events($from, $to);
        usort($events, static fn($a, $b) => strcmp($a['start'], $b['start']));
        $events = array_slice($events, 0, (int)$settings['show_calendar_limit']);
        $presenter = new PublicBusyPresenter();
        ob_start();
        $wrapperClasses = (array)apply_filters('wpcb_calendar_wrapper_classes', ['wpcb-calendar-list', 'uk-grid', 'uk-child-width-1-1'], 'calendar_list');
        echo '<div class="' . esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', $wrapperClasses)))) . '" uk-grid>';
        if (!$events) {
            echo '<p>' . esc_html__('Keine Termine vorhanden.', 'wordpress-calendar-booking') . '</p>';
        } else {
            foreach ($events as $event) {
                $busy = $presenter->externalEvent($event);
                echo '<div class="wpcb-calendar-item uk-card uk-card-default uk-card-body">';
                echo '<strong>' . esc_html($busy['title']) . '</strong><br>';
                echo esc_html(Time::display((string)$busy['start'], $settings['date_format'] . ' ' . $settings['time_format'])) . ' - ' . esc_html(Time::display((string)$busy['end'], $settings['time_format']));
                echo '</div>';
            }
        }
        echo '</div>';
        $html = (string)ob_get_clean();
        $html = (string)apply_filters('wpcb_render_calendar_list', $html);
        do_action('wpcb_after_component', 'calendar_list', $html);
        return $html;
    }

    public function bookingCalendar(array $atts = []): string {
        $this->assets->enqueue(true);
        do_action('wpcb_before_component', 'booking_calendar');
        $atts = shortcode_atts(['month' => Time::nowLocal()->format('Y-m')], $atts, 'wpcb_booking_calendar');
        $requestedMonth = isset($_GET['wpcb_month']) ? sanitize_text_field(wp_unslash($_GET['wpcb_month'])) : (string)$atts['month'];
        $month = preg_match('/^\d{4}-\d{2}$/', $requestedMonth) ? $requestedMonth : Time::nowLocal()->format('Y-m');
        $slotService = new SlotService();
        $display = $slotService->getMonthDisplay($month);
        $weeks = array_chunk($display['days'], 7);
        ob_start(); ?>
        <?php $calendarClasses = (array)apply_filters('wpcb_calendar_wrapper_classes', ['wpcb-booking-calendar-wrap'], 'booking_calendar'); ?>
        <div class="<?php echo esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', $calendarClasses)))); ?>" data-wpcb-booking-calendar>
            <div class="wpcb-calendar-toolbar uk-flex uk-flex-between uk-flex-middle uk-flex-wrap gap-1">
                <div class="uk-flex uk-flex-middle uk-flex-wrap gap-1">
                    <a class="uk-button uk-button-default" href="<?php echo esc_url(add_query_arg(['wpcb_month' => $display['prev']])); ?>">&lsaquo;</a>
                    <strong class="uk-text-large"><?php echo esc_html($display['title']); ?></strong>
                    <a class="uk-button uk-button-default" href="<?php echo esc_url(add_query_arg(['wpcb_month' => $display['next']])); ?>">&rsaquo;</a>
                </div>
                <a href="#wpcb-booking-modal" class="uk-button uk-button-primary" data-wpcb-open-toolbar-modal aria-haspopup="dialog" aria-label="<?php echo esc_attr__('Book an appointment', 'wordpress-calendar-booking'); ?>">+</a>
            </div>
            <div class="wpcb-apple-calendar">
                <div class="wpcb-week-header uk-text-muted">KW</div>
                <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $weekday): ?><div class="wpcb-week-header"><?php echo esc_html($weekday); ?></div><?php endforeach; ?>
                <?php foreach ($weeks as $week): ?>
                    <div class="wpcb-weeknumber uk-text-muted"><?php echo esc_html(wp_date('W', strtotime($week[0]['date']))); ?></div>
                    <?php foreach ($week as $day):
                        $classes = ['wpcb-day'];
                        if (!$day['in_month']) $classes[] = 'is-outside';
                        if ($day['is_past']) $classes[] = 'is-past';
                        if ($day['is_weekend']) $classes[] = 'is-weekend';
                        if ($day['date'] === Time::nowLocal()->format('Y-m-d')) $classes[] = 'is-today';
                    ?>
                        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>">
                            <div class="wpcb-day-head"><span class="wpcb-day-number"><?php echo esc_html(wp_date('j', strtotime($day['date']))); ?></span></div>
                            <div class="wpcb-day-body">
                                <?php if (!$day['is_weekend']): ?>
                                    <?php foreach ($day['items'] as $item): ?>
                                        <div class="wpcb-event-pill <?php echo esc_attr($item['class']); ?>" title="<?php echo esc_attr($item['title']); ?>">
                                            <?php echo esc_html($item['label']); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <div id="wpcb-booking-modal" class="wpcb-booking-modal" hidden data-wpcb-modal role="dialog" aria-modal="true" aria-labelledby="wpcb-booking-modal-title">
                <div class="wpcb-modal-panel uk-card uk-card-default uk-card-body" tabindex="-1" data-wpcb-modal-panel>
                    <button type="button" class="wpcb-modal-close" data-wpcb-close-modal aria-label="<?php echo esc_attr__('Close booking dialog', 'wordpress-calendar-booking'); ?>">&times;</button>
                    <h3 id="wpcb-booking-modal-title" class="uk-margin-small-bottom"><?php esc_html_e('Termin buchen', 'wordpress-calendar-booking'); ?></h3>
                    <?php echo $this->renderBookingFormMarkup(true); ?>
                </div>
            </div>
        </div>
        <?php
        $html = (string)ob_get_clean();
        $html = (string)apply_filters('wpcb_render_booking_calendar', $html, $display);
        do_action('wpcb_after_component', 'booking_calendar', $html);
        return $html;
    }
}
