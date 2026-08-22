<?php

namespace Shetabit\Multipay\Constants;

class Currency
{
    public const string TOMAN = 'T';

    public const string RIAL = 'R';

    public const array RATIO = [
        self::TOMAN => 10,
        self::RIAL => 1
    ];
}
