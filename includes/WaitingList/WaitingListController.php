<?php
namespace Wpcb\WaitingList;

final class WaitingListController {
    public function boot(): void {
        add_action('admin_post_nopriv_wpcb_waitlist_join', [$this, 'join']);
        add_action('admin_post_wpcb_waitlist_join', [$this, 'join']);
        add_action('template_redirect', [$this, 'accept'], 0);
        add_action('wpcb_waitlist_send_offer', [$this, 'sendOffer']);
        add_action('wpcb_hourly_reminders', [$this, 'expireOffers'], 7);
        add_action('wpcb_booking_transitioned', [$this, 'onBookingTransition'], 30, 2);
        add_action('wpcb_booking_event_recorded', [$this, 'onBookingEvent'], 30, 2);
    }

    public function join(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            wp_die('Method not allowed.', 'Method not allowed', ['response' => 405]);
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
            wp_die(esc_html($result->get_error_message()), 'Waiting list', ['response' => 400]);
        }
        wp_safe_redirect(add_query_arg('wpcb_notice', rawurlencode('Du stehst auf der Warteliste.'), wp_get_referer() ?: home_url('/')));
        exit;
    }

    public function accept(): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }
        if (sanitize_key(wp_unslash($_GET['wpcb_waitlist_action'] ?? '')) !== 'accept') {
            return;
        }
        $id = absint($_GET['wpcb_waitlist_id'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_GET['wpcb_waitlist_token'] ?? ''));
        $result = (new WaitingListService())->accept($id, $token);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), 'Waiting list', ['response' => 400]);
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
        $this->promoteFromBooking($booking);
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
