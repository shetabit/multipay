<?php

namespace Shetabit\Multipay\Constants;

class Currency
{
    public const TOMAN = 'T';

    public const RIAL = 'R';

    public const RATIO = [
        self::TOMAN => 10,
        self::RIAL => 1
    ];
}
