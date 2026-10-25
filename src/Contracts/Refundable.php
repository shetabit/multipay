<?php

namespace Shetabit\Multipay\Contracts;

/**
 * A driver whose gateway can give the money of a verified payment back.
 */
interface Refundable
{
    /**
     * Ask the gateway whether refunds are enabled for the merchant.
     */
    public function isRefundEnabled() : bool;

    /**
     * Refund the payment, or a part of it.
     *
     * @param int|null    $amount  in the currency of the invoice; the whole invoice when null
     * @param string|null $trackId the merchant's id of this refund, kept to follow it up and never to be renewed for it
     *
     * @return array the answer of the gateway
     */
    public function refund(?int $amount = null, ?string $trackId = null) : array;

    /**
     * Ask the gateway how a refund is going.
     *
     * @param string|null $trackId the merchant's id of the refund, as given to refund()
     *
     * @return array the answer of the gateway
     */
    public function refundInquiry(?string $trackId = null) : array;
}
