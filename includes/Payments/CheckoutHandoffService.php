<?php
namespace Wpcb\Payments;

use Wpcb\Booking\BookingRepository;
use Wpcb\Booking\BookingTypeRepository;

/**
 * Shared secure hand-off to the currently supported hosted payment provider.
 */
final class CheckoutHandoffService {
    private BookingRepository $bookings;
    private BookingTypeRepository $types;
    private PaymentService $payments;
    private StripeConfig $stripe;

    public function __construct(
        ?BookingRepository $bookings = null,
        ?BookingTypeRepository $types = null,
        ?PaymentService $payments = null,
        ?StripeConfig $stripe = null
    ) {
        $this->bookings = $bookings ?: new BookingRepository();
        $this->types = $types ?: new BookingTypeRepository();
        $this->payments = $payments ?: new PaymentService();
        $this->stripe = $stripe ?: new StripeConfig();
    }

    /** @return array{required:bool,provider:string}|\WP_Error */
    public function preflightType(int $typeId) {
        $type = $this->types->find($typeId);
        if (!$type) {
            return new \WP_Error('wpcb_payment_type_missing', __('Booking type not found.', 'wordpress-calendar-booking'));
        }
        if ((string)($type->payment_mode ?? 'free') !== 'required') {
            return ['required' => false, 'provider' => ''];
        }
        if (!$this->stripe->ready()) {
            return new \WP_Error(
                'wpcb_payment_unavailable',
                __('Für diese Terminart ist eine Zahlung erforderlich, der Zahlungsanbieter ist derzeit aber nicht verfügbar.', 'wordpress-calendar-booking')
            );
        }
        return ['required' => true, 'provider' => 'stripe'];
    }

    /** @return array{required:bool,provider:string,checkout_url:string}|\WP_Error */
    public function beginForBooking(int $bookingId) {
        $booking = $this->bookings->find($bookingId);
        if (!$booking) {
            return new \WP_Error('wpcb_payment_booking_missing', __('Booking not found.', 'wordpress-calendar-booking'));
        }

        $preflight = $this->preflightType((int)$booking->booking_type_id);
        if (is_wp_error($preflight)) {
            return $preflight;
        }
        if (empty($preflight['required'])) {
            return ['required' => false, 'provider' => '', 'checkout_url' => ''];
        }

        $started = $this->payments->begin($bookingId, new StripeAdapter($this->stripe));
        if (is_wp_error($started)) {
            return $started;
        }
        $url = $this->trustedStripeUrl((string)($started->checkout_url ?? ''));
        if ($url === '') {
            return new \WP_Error(
                'wpcb_payment_checkout_invalid',
                __('Die Zahlung konnte nicht sicher gestartet werden.', 'wordpress-calendar-booking')
            );
        }

        return ['required' => true, 'provider' => 'stripe', 'checkout_url' => $url];
    }

    private function trustedStripeUrl(string $value): string {
        $url = esc_url_raw($value);
        $host = strtolower((string)wp_parse_url($url, PHP_URL_HOST));
        return $url !== '' && ($host === 'checkout.stripe.com' || str_ends_with($host, '.stripe.com'))
            ? $url
            : '';
    }
}
