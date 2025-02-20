<?php

namespace Shetabit\Multipay\Contracts;

use Carbon\Carbon;

interface ReceiptInterface
{
    /**
     * Retrieve driver's name
     */
    public function getDriver() : string;

    /**
     * Retrieve payment reference code.
     */
    public function getReferenceId() : string;

    /**
     * Retrieve payment date
     */
    public function getDate() : Carbon;

    /**
     * Retrieve detail using its name
     *
     * @param $name
     * @return string|null
     */
    public function getDetail($name);

    /**
     * Get the value of details
     */
    public function getDetails() : array;
}
