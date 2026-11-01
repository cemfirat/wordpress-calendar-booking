<?php
namespace Wpcb\Payments;

interface CheckoutExpiryInterface {
    /**
     * Ask the provider to close a still-open checkout attempt.
     *
     * @return true|\WP_Error An error does not prove the attempt stays payable;
     *                        a later verified payment is reconciled separately.
     */
    public function expireCheckout(string $providerReference);
}
