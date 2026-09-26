<?php
namespace Wpcb\Frontend;

use Wpcb\Availability\SlotService;
use Wpcb\Booking\BookingTypeRepository;
use Wpcb\Forms\FieldRepository;
use Wpcb\Forms\FieldValidator;
use Wpcb\Forms\FormRecovery;
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
        $settings = Settings::get();
        $recovery = (new FormRecovery())->current('booking');
        $state = is_array($recovery) ? (array)($recovery['state'] ?? []) : [];
        $fieldValues = isset($state['fields']) && is_array($state['fields']) ? $state['fields'] : [];
        $recoveredTypeId = absint($state['booking_type_id'] ?? 0);
        $recoveredPartySize = max(1, absint($state['party_size'] ?? 1));
        $recoveredRecurrenceCount = max(1, absint($state['recurrence_count'] ?? 1));
        $recoveredRecurrenceInterval = max(1, absint($state['recurrence_interval'] ?? 1));
        $recoveredSlot = sanitize_text_field((string)($state['slot_token'] ?? ''));
        ob_start(); ?>
        <?php
        $formClasses = ['wpcb-booking-form', 'uk-form-stacked'];
        if ($isModal) $formClasses[] = 'wpcb-booking-form-modal';
        $formClasses = (array)apply_filters('wpcb_booking_form_classes', $formClasses, $isModal);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="<?php echo esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', $formClasses)))); ?>" data-wpcb-booking-form<?php echo $recoveredSlot !== '' ? ' data-wpcb-recover-slot="' . esc_attr($recoveredSlot) . '"' : ''; ?>>
            <?php wp_nonce_field('wpcb_booking', 'wpcb_nonce'); ?>
            <input type="hidden" name="action" value="wpcb_submit_booking">
            <input type="hidden" name="wpcb_form_ts" value="<?php echo esc_attr(current_time('timestamp')); ?>">
            <input type="hidden" name="visit_address_value" value="<?php echo esc_attr($settings['visit_address']); ?>">
            <input type="hidden" name="own_phone_value" value="<?php echo esc_attr($settings['own_phone']); ?>">
            <div style="display:none"><input type="text" name="website" value=""></div>
            <?php if (is_array($recovery) && !empty($recovery['message'])): ?>
                <p class="wpcb-form-error uk-alert-danger" role="alert"><?php echo esc_html((string)$recovery['message']); ?></p>
            <?php endif; ?>

            <div class="wpcb-field uk-margin">
                <label class="uk-form-label" for="wpcb_booking_type_id<?php echo $isModal ? '_modal' : ''; ?>"><?php esc_html_e('Terminart', 'wordpress-calendar-booking'); ?></label>
                <div class="uk-form-controls">
                    <select class="uk-select" id="wpcb_booking_type_id<?php echo $isModal ? '_modal' : ''; ?>" name="booking_type_id" required data-wpcb-type-select>
                        <option value=""><?php esc_html_e('Bitte wählen', 'wordpress-calendar-booking'); ?></option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo esc_attr($type->id); ?>" <?php echo selected($recoveredTypeId, (int)$type->id, false); ?> data-capacity="<?php echo esc_attr(max(1, (int)($type->capacity ?? 1))); ?>" data-payment-mode="<?php echo esc_attr((string)($type->payment_mode ?? 'free')); ?>"><?php echo esc_html($type->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php
            $maxPartySize = 1;
            foreach ($types as $type) {
                $maxPartySize = max($maxPartySize, max(1, (int)($type->capacity ?? 1)));
            }
            $recoveredPartySize = min($maxPartySize, $recoveredPartySize);
            ?>
            <?php if ($maxPartySize > 1): ?>
                <div class="wpcb-field uk-margin">
                    <label class="uk-form-label" for="wpcb_party_size<?php echo $isModal ? '_modal' : ''; ?>"><?php esc_html_e('Teilnehmer', 'wordpress-calendar-booking'); ?></label>
                    <div class="uk-form-controls">
                        <input class="uk-input" type="number" id="wpcb_party_size<?php echo $isModal ? '_modal' : ''; ?>" name="party_size" value="<?php echo (int)$recoveredPartySize; ?>" min="1" max="<?php echo (int)$maxPartySize; ?>" required data-wpcb-party-size>
                    </div>
                </div>
            <?php else: ?>
                <input type="hidden" name="party_size" value="1">
            <?php endif; ?>

            <div class="wpcb-field uk-margin">
                <label class="uk-form-label" for="wpcb_recurrence_count<?php echo $isModal ? '_modal' : ''; ?>"><?php esc_html_e('Wiederholung', 'wordpress-calendar-booking'); ?></label>
                <div class="uk-form-controls">
                    <select class="uk-select" id="wpcb_recurrence_count<?php echo $isModal ? '_modal' : ''; ?>" name="recurrence_count">
                        <?php foreach ([1 => __('Einmaliger Termin', 'wordpress-calendar-booking'), 2 => __('2 Termine', 'wordpress-calendar-booking'), 4 => __('4 Termine', 'wordpress-calendar-booking'), 6 => __('6 Termine', 'wordpress-calendar-booking'), 8 => __('8 Termine', 'wordpress-calendar-booking'), 12 => __('12 Termine', 'wordpress-calendar-booking')] as $count => $label): ?>
                            <option value="<?php echo (int)$count; ?>" <?php echo selected($recoveredRecurrenceCount, (int)$count, false); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="wpcb-field uk-margin">
                <label class="uk-form-label" for="wpcb_recurrence_interval<?php echo $isModal ? '_modal' : ''; ?>"><?php esc_html_e('Serienabstand', 'wordpress-calendar-booking'); ?></label>
                <div class="uk-form-controls">
                    <select class="uk-select" id="wpcb_recurrence_interval<?php echo $isModal ? '_modal' : ''; ?>" name="recurrence_interval">
                        <?php foreach ([1 => __('Wöchentlich', 'wordpress-calendar-booking'), 2 => __('Alle 2 Wochen', 'wordpress-calendar-booking'), 3 => __('Alle 3 Wochen', 'wordpress-calendar-booking'), 4 => __('Alle 4 Wochen', 'wordpress-calendar-booking')] as $interval => $label): ?>
                            <option value="<?php echo (int)$interval; ?>" <?php echo selected($recoveredRecurrenceInterval, (int)$interval, false); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
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

            <?php echo $this->formFieldsMarkup($fieldValues, $isModal ? '_modal' : ''); ?>
            <?php $buttonClasses = (array)apply_filters('wpcb_booking_button_classes', ['uk-button', 'uk-button-primary'], 'submit'); ?>
            <button type="submit" class="<?php echo esc_attr(implode(' ', array_filter(array_map('sanitize_html_class', $buttonClasses)))); ?>"><?php esc_html_e('Termin buchen', 'wordpress-calendar-booking'); ?></button>
        </form>
        <?php
        $html = (string)ob_get_clean();
        return (string)apply_filters('wpcb_render_booking_form_markup', $html, $isModal);
    }

    public function formFieldsMarkup(array $values = [], string $idSuffix = ''): string {
        $fields = (new FieldRepository())->active();
        ob_start();
        foreach ($fields as $field) {
            $key = sanitize_key((string)$field->field_key);
            $value = isset($values[$key]) && is_scalar($values[$key]) ? (string)$values[$key] : '';
            $constraints = (new FieldValidator())->constraints($field);
            $required = !empty($field->is_required)
                || (!is_wp_error($constraints) && !empty($constraints['must_be_checked']));
            ?>
            <div class="wpcb-field uk-margin" data-wpcb-field="<?php echo esc_attr($key); ?>">
                <label class="uk-form-label" for="wpcb_<?php echo esc_attr($key . $idSuffix); ?>"><?php echo esc_html($field->label); ?><?php echo $required ? ' *' : ''; ?></label>
                <div class="uk-form-controls">
                    <?php
                    if (is_wp_error($constraints)) {
                        echo '<p class="uk-text-danger">' . esc_html__('Dieses Feld ist ungültig konfiguriert.', 'wordpress-calendar-booking') . '</p>';
                    } else {
                        $this->renderField($field, $value, $idSuffix, $constraints);
                    }
                    ?>
                </div>
            </div>
            <?php
        }
        return (string)ob_get_clean();
    }

    private function renderField(object $field, string $value, string $idSuffix, array $constraints): void {
        $name = sanitize_key((string)$field->field_key);
        $required = (!empty($field->is_required) || !empty($constraints['must_be_checked'])) ? ' required' : '';
        $id = 'wpcb_' . $name . $idSuffix;
        $min = (int)($constraints['min_length'] ?? 0);
        $max = (int)($constraints['max_length'] ?? 0);
        $lengthAttrs = ($min > 0 ? ' minlength="' . $min . '"' : '')
            . ($max > 0 ? ' maxlength="' . $max . '"' : '');
        $options = (array)($constraints['options'] ?? []);

        switch ((string)$field->field_type) {
            case 'textarea':
                echo '<textarea class="uk-textarea" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $required . $lengthAttrs . '>' . esc_textarea($value) . '</textarea>';
                break;
            case 'checkbox':
                echo '<label><input class="uk-checkbox" type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"' . checked($value, '1', false) . $required . '> </label>';
                break;
            case 'email':
                echo '<input class="uk-input" type="email" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $required . $lengthAttrs . '>';
                break;
            case 'select':
                echo '<select class="uk-select" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $required . '><option value="">' . esc_html__('Bitte wählen', 'wordpress-calendar-booking') . '</option>';
                foreach ($options as $option) {
                    echo '<option value="' . esc_attr($option) . '"' . selected($value, (string)$option, false) . '>' . esc_html($option) . '</option>';
                }
                echo '</select>';
                break;
            case 'radio':
                foreach ($options as $idx => $option) {
                    echo '<label class="uk-margin-small-right"><input class="uk-radio" type="radio" id="' . esc_attr($id . '_' . $idx) . '" name="' . esc_attr($name) . '" value="' . esc_attr($option) . '"' . checked($value, (string)$option, false) . $required . '> ' . esc_html($option) . '</label>';
                }
                break;
            default:
                echo '<input class="uk-input" type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $required . $lengthAttrs . '>';
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
            <?php if (empty($display['availability_complete'])): ?>
                <p class="wpcb-calendar-status uk-text-warning"><?php echo esc_html__('Kalenderdaten konnten nicht vollständig geladen werden.', 'wordpress-calendar-booking'); ?></p>
            <?php endif; ?>
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
                        $classes[] = !empty($day['is_working_day']) ? 'is-working' : 'is-nonworking';
                        if ($day['date'] === Time::nowLocal()->format('Y-m-d')) $classes[] = 'is-today';
                    ?>
                        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>">
                            <div class="wpcb-day-head"><span class="wpcb-day-number"><?php echo esc_html(wp_date('j', strtotime($day['date']))); ?></span></div>
                            <div class="wpcb-day-body">
                                <?php foreach ($day['items'] as $item): ?>
                                    <div class="wpcb-event-pill <?php echo esc_attr($item['class']); ?>" title="<?php echo esc_attr($item['title']); ?>">
                                        <?php echo esc_html($item['label']); ?>
                                    </div>
                                <?php endforeach; ?>
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
