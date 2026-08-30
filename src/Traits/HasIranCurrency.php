<?php

namespace Shetabit\Multipay\Traits;

trait HasIranCurrency
{
    /**
     *  Convert given amount to rial
     */
    protected function convertAmountToRial(int|float $price): int|float
    {
        return $price * $this->settings->currency->ratio();
    }

    /**
     *  Convert given amount to toman
     */
    protected function convertAmountToToman(int|float $price): int|float
    {
        return $this->convertAmountToRial($price) / 10;
    }
}
