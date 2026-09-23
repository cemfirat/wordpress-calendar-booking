<?php
namespace Wpcb\Payments;

interface PaymentAdapterInterface {
    public function code(): string;

    /**
     * @return array|\WP_Error Must return provider_reference on success.
     */
    public function createPayment(array $context);

    /**
     * @return array|\WP_Error Must return provider_event_id on success.
     */
    public function refund(array $context);
}
