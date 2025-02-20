<?php

namespace Shetabit\Multipay\Abstracts;

use Carbon\Carbon;
use Shetabit\Multipay\Contracts\ReceiptInterface;

abstract class Receipt implements ReceiptInterface
{
    /**
     * payment date
     */
    protected \Carbon\Carbon $date;

    /**
     * Receipt constructor.
     *
     * @param $driver
     * @param $referenceId
     * @param string $referenceId
     * @param string $driver
     */
    public function __construct(/**
         * payment driver's name.
         */
        protected $driver, /**
         * A unique ID which is given to the customer whenever the payment is done successfully.
         * This ID can be used for financial follow up.
         */
        protected $referenceId
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
