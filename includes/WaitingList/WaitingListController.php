<?php
namespace Wpcb\WaitingList;

use Wpcb\Payments\CheckoutHandoffService;
use Wpcb\Payments\PaymentService;

final class WaitingListController {
    public function boot(): void {
        add_action('admin_post_nopriv_wpcb_waitlist_join', [$this, 'join']);
        add_action('admin_post_wpcb_waitlist_join', [$this, 'join']);
        add_action('template_redirect', [$this, 'renderOffer'], 0);
        add_action('admin_post_nopriv_wpcb_waitlist_accept', [$this, 'accept']);
        add_action('admin_post_wpcb_waitlist_accept', [$this, 'accept']);
        add_action('wpcb_waitlist_send_offer', [$this, 'sendOffer']);
        add_action('wpcb_hourly_reminders', [$this, 'expireOffers'], 7);
        add_action('wpcb_booking_transitioned', [$this, 'onBookingTransition'], 30, 2);
        add_action('wpcb_booking_event_recorded', [$this, 'onBookingEvent'], 30, 2);
        add_action('wpcb_capacity_changed', [$this, 'expireOffers']);
    }

    public function join(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            wp_die(esc_html__('Method not allowed.', 'wordpress-calendar-booking'), esc_html__('Method not allowed', 'wordpress-calendar-booking'), ['response' => 405]);
        }
        check_admin_referer('wpcb_waitlist_join', 'wpcb_waitlist_nonce');
        $result = (new WaitingListService())->join([
            'booking_type_id' => absint($_POST['booking_type_id'] ?? 0),
            'resource_id' => absint($_POST['resource_id'] ?? 0),
            'slot_start' => sanitize_text_field(wp_unslash($_POST['slot_start'] ?? '')),
            'slot_end' => sanitize_text_field(wp_unslash($_POST['slot_end'] ?? '')),
            'party_size' => absint($_POST['party_size'] ?? 1),
            'full_name' => sanitize_text_field(wp_unslash($_POST['full_name'] ?? '')),
            'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
        ]);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), esc_html__('Waiting list', 'wordpress-calendar-booking'), ['response' => 400]);
        }
        wp_safe_redirect(add_query_arg('wpcb_notice', rawurlencode(__('Du stehst auf der Warteliste.', 'wordpress-calendar-booking')), wp_get_referer() ?: home_url('/')));
        exit;
    }

    public function renderOffer(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') return;
        if (sanitize_key(wp_unslash($_GET['wpcb_waitlist_action'] ?? '')) !== 'accept') return;
        $id = absint($_GET['wpcb_waitlist_id'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['wpcb_waitlist_token'] ?? ''));
        if ($id < 1 || $token === '') return;
        echo '<!doctype html><html><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '"><meta name="robots" content="noindex,nofollow"><title>' . esc_html__('Waiting-list offer', 'wordpress-calendar-booking') . '</title></head><body>';
        echo '<main><h1>' . esc_html__('A booking slot is available', 'wordpress-calendar-booking') . '</h1><p>' . esc_html__('Confirm explicitly to reserve the offered slot. Opening this page alone changes nothing.', 'wordpress-calendar-booking') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wpcb_waitlist_accept">';
        echo '<input type="hidden" name="wpcb_waitlist_id" value="' . (int)$id . '">';
        echo '<input type="hidden" name="wpcb_waitlist_token" value="' . esc_attr($token) . '">';
        wp_nonce_field('wpcb_waitlist_accept|' . hash('sha256', $token), 'wpcb_waitlist_nonce');
        echo '<button type="submit">' . esc_html__('Reserve this slot', 'wordpress-calendar-booking') . '</button></form></main></body></html>';
        exit;
    }

    public function accept(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            wp_die(esc_html__('Method not allowed.', 'wordpress-calendar-booking'), esc_html__('Method not allowed', 'wordpress-calendar-booking'), ['response' => 405]);
        }
        $id = absint($_POST['wpcb_waitlist_id'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_POST['wpcb_waitlist_token'] ?? ''));
        $nonce = sanitize_text_field(wp_unslash($_POST['wpcb_waitlist_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'wpcb_waitlist_accept|' . hash('sha256', $token))) {
            wp_die(esc_html__('Security check failed.', 'wordpress-calendar-booking'), esc_html__('Security check failed', 'wordpress-calendar-booking'), ['response' => 403]);
        }
        $result = (new WaitingListService())->accept($id, $token);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), esc_html__('Waiting list', 'wordpress-calendar-booking'), ['response' => 400]);
        }
        $handoff = (new CheckoutHandoffService())->beginForBooking((int)$result);
        if (is_wp_error($handoff)) {
            $payment = (new PaymentService())->paymentForBooking((int)$result);
            if ($payment) {
                wp_die(
                    esc_html__('The slot is reserved and the confirmation email has been scheduled. Payment could not be opened automatically; after confirming your email you can resume the pending payment from the customer portal.', 'wordpress-calendar-booking'),
                    esc_html__('Waiting-list offer accepted', 'wordpress-calendar-booking'),
                    ['response' => 200]
                );
            }
            wp_die(
                esc_html($handoff->get_error_message()),
                esc_html__('Waiting list', 'wordpress-calendar-booking'),
                ['response' => 500]
            );
        }
        if (!empty($handoff['required']) && !empty($handoff['checkout_url'])) {
            wp_redirect((string)$handoff['checkout_url'], 303);
            exit;
        }

        wp_die(
            esc_html__('The slot has been reserved for you. Please check your email to confirm the booking.', 'wordpress-calendar-booking'),
            esc_html__('Waiting-list offer accepted', 'wordpress-calendar-booking'),
            ['response' => 200]
        );
    }

    public function sendOffer(int $entryId): void {
        (new WaitingListService())->sendOffer($entryId);
    }

    public function expireOffers(): void {
        (new WaitingListService())->expireAndRepromote();
    }

    public function onBookingTransition(array $transition, $booking): void {
        if (!$booking || empty($transition['changed'])) return;
        if (!in_array((string)$transition['to'], ['cancelled','rejected','expired'], true)) return;
        $this->promoteFromBooking($booking);
    }

    public function onBookingEvent(array $event, $booking): void {
        if (!$booking || empty($event['changed']) || ($event['event'] ?? '') !== 'rescheduled') return;
        if (!empty($event['previous_resource_id']) && !empty($event['previous_slot_start']) && !empty($event['previous_slot_end'])) {
            (new WaitingListService())->promoteSlot(
                (int)$booking->booking_type_id,
                (int)$event['previous_resource_id'],
                (string)$event['previous_slot_start'],
                (string)$event['previous_slot_end']
            );
        }
    }

    private function promoteFromBooking(object $booking): void {
        if (empty($booking->resource_id)) return;
        (new WaitingListService())->promoteSlot(
            (int)$booking->booking_type_id,
            (int)$booking->resource_id,
            (string)$booking->slot_start,
            (string)$booking->slot_end
        );
    }
}
