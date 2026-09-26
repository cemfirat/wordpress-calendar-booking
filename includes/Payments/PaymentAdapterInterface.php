<?php
namespace Wpcb\Payments;

interface PaymentAdapterInterface {
    public function code(): string;

    /**
     * @return array|\WP_Error Must return provider_reference on success.
     */
    public function createPayment(array $context);

    /**
     * Submit or re-check one durable refund attempt.
     *
     * Successful responses must include provider_refund_id, provider_status,
     * amount_minor and currency. A WP_Error may carry
     * ['refund_outcome' => 'uncertain'] when a side-effecting request might
     * have reached the provider; callers must not create a second attempt.
     *
     * @return array|\WP_Error
     */
    public function refund(array $context);
}
