<?php

namespace Shetabit\Multipay\Traits;

use Shetabit\Multipay\Constants\IranCurrency;

trait HasIranCurrency
{
    /**
     *  Normalize the price based on the selected currency ratio on config.
     *
     * @param int|float $price
     * @return int|float
     */
    protected function normalizeByCurrency(int|float $price): int|float
    {
        return $price * IranCurrency::RATIO[$this->settings->currency];
    }
}
