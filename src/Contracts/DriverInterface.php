<?php

namespace Shetabit\Multipay\Contracts;

use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;

interface DriverInterface
{
    /**
     * Set payment amount.
     *
     * @throws \Exception
     */
    public function amount(mixed $amount) : static;

    /**
     * Set a piece of data to the details.
     *
     * @param array|string $key   a single detail's name, or a set of details
     * @param mixed        $value the value of a single detail
     */
    public function detail(array|string $key, mixed $value = null) : static;

    /**
     * Retrieve the invoice the driver is working on.
     */
    public function getInvoice() : Invoice;

    /**
     * Create new purchase
     *
     * @return string|int the transaction's id
     */
    public function purchase() : string|int|null;

    /**
     * Pay the purchase
     */
    public function pay() : RedirectionForm;

    /**
     * verify the payment
     */
    public function verify() : ReceiptInterface;
}
