<?php

namespace Shetabit\Multipay\Contracts;

/**
 * A driver whose gateway can reverse a payment as a whole.
 */
interface Reversible
{
    /**
     * Reverse the payment.
     *
     * @return array the answer of the gateway
     */
    public function reverse() : array;
}
