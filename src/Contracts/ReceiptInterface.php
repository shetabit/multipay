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
     */
    public function getDetail(string $name) : mixed;

    /**
     * Get the value of details
     *
     * @return array<string, mixed>
     */
    public function getDetails() : array;
}
