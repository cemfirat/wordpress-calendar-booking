<?php
namespace Wpcb\Payments;

/** Reads an existing provider refund; implementations must never create one. */
interface RefundStatusProviderInterface {
    public function code(): string;

    /**
     * @return array|\WP_Error Current provider_refund_id, provider_status,
     * amount_minor and currency, or an error without side effects.
     */
    public function refundStatus(string $providerRefundId, int $amountMinor, string $currency);
}
