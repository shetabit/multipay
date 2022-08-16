<?php

namespace Shetabit\Multipay\Contracts;

use Carbon\Carbon;

interface ReceiptInterface
{
    /**
     * Retrieve driver's name
     *
     * @return string
     */
    public function getDriver() : string;

    /**
     * Retrieve payment reference code.
     *
     * @return string
     */
    public function getReferenceId() : string;

    /**
     * Retrieve payment date
     *
     * @return Carbon|\Illuminate\Support\Carbon
     */
    public function getDate() : Carbon;

    /**
     * Retrieve detail using its name
     *
     * @param $name
     * @return string|null
     */
    public function getDetail() : array;

    /**
     * Get the value of details
     * @return array
     */
    public function getDetails() : array;
}
