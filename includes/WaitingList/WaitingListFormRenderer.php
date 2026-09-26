<?php
namespace Wpcb\WaitingList;

use Wpcb\Forms\FieldInputRenderer;
use Wpcb\Forms\FieldRepository;

final class WaitingListFormRenderer {
    private FieldRepository $fields;
    private FieldInputRenderer $inputs;

    public function __construct(?FieldRepository $fields = null, ?FieldInputRenderer $inputs = null) {
        $this->fields = $fields ?: new FieldRepository();
        $this->inputs = $inputs ?: new FieldInputRenderer();
    }

    public function joinForm(array $context, array $values = [], string $error = ''): string {
        $prefix = wp_unique_id('wpcb_waitlist_join_');
        ob_start();
        if ($error !== '') {
            echo '<p class="wpcb-form-error" role="alert">' . esc_html($error) . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpcb-waiting-list-form">';
        echo '<input type="hidden" name="action" value="wpcb_waitlist_join">';
        foreach (['booking_type_id', 'resource_id', 'slot_start', 'slot_end', 'party_size'] as $key) {
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string)($context[$key] ?? '')) . '">';
        }
        wp_nonce_field('wpcb_waitlist_join', 'wpcb_waitlist_nonce');
        $this->fields($prefix, $values);
        echo '<button type="submit">' . esc_html__('Join waiting list', 'wordpress-calendar-booking') . '</button>';
        echo '</form>';
        return (string)ob_get_clean();
    }

    public function offerForm(int $entryId, string $token, array $values = [], string $error = ''): string {
        $prefix = wp_unique_id('wpcb_waitlist_offer_');
        ob_start();
        if ($error !== '') {
            echo '<p class="wpcb-form-error" role="alert">' . esc_html($error) . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpcb-waiting-list-offer-form">';
        echo '<input type="hidden" name="action" value="wpcb_waitlist_accept">';
        echo '<input type="hidden" name="wpcb_waitlist_id" value="' . (int)$entryId . '">';
        echo '<input type="hidden" name="wpcb_waitlist_token" value="' . esc_attr($token) . '">';
        wp_nonce_field('wpcb_waitlist_accept|' . hash('sha256', $token), 'wpcb_waitlist_nonce');
        $this->fields($prefix, $values);
        echo '<button type="submit">' . esc_html__('Reserve this slot', 'wordpress-calendar-booking') . '</button>';
        echo '</form>';
        return (string)ob_get_clean();
    }

    private function fields(string $prefix, array $values): void {
        foreach ($this->fields->active() as $field) {
            $key = (string)$field->field_key;
            $raw = $values[$key] ?? '';
            $value = is_scalar($raw) ? (string)$raw : '';
            $id = $prefix . $key;
            echo '<p class="wpcb-field" data-wpcb-field="' . esc_attr($key) . '">';
            echo '<label for="' . esc_attr($id) . '">' . esc_html((string)$field->label);
            if (!empty($field->is_required)) {
                echo ' *';
            }
            echo '</label><br>';
            echo $this->inputs->render($field, $id, $value);
            echo '</p>';
        }
    }
}
