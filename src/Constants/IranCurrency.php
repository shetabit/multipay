<?php

namespace Shetabit\Multipay\Constants;

enum IranCurrency
{
    case TOMAN;
    case RIAL;

    public function ratio(): int
    {
        return match ($this) {
            self::TOMAN => 10,
            self::RIAL => 1,
        };
    }
}
