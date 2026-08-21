<?php

namespace Shetabit\Multipay\Abstracts;

use Carbon\Carbon;
use Shetabit\Multipay\Contracts\ReceiptInterface;

abstract class Receipt implements ReceiptInterface
{
    /**
     * The moment the payment was received.
     */
    protected readonly Carbon $date;

    /**
     * Receipt constructor.
     *
     * @param string     $driver      name of the payment driver
     * @param string|int $referenceId a unique id which is given to the customer whenever the
     *                                payment is done successfully, and which can be used for
     *                                financial follow up
     */
    public function __construct(
        protected readonly string $driver,
        protected readonly string|int $referenceId,
    ) {
        $this->date = Carbon::now();
    }

    /**
     * Retrieve driver's name
     */
    public function getDriver() : string
    {
        return $this->driver;
    }

    /**
     * Retrieve payment reference code.
     */
    public function getReferenceId() : string
    {
        return (string) $this->referenceId;
    }

    /**
     * Retrieve payment date
     */
    public function getDate() : Carbon
    {
        return $this->date;
    }
}
