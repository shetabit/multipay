<?php

namespace Shetabit\Multipay\Tests;

use Shetabit\Multipay\Constants\IranCurrency;

class CurrencyTest extends TestCase
{
    public function testItDefinesCurrencyCases(): void
    {
        $this->assertCount(2, IranCurrency::cases());
    }

    public function testItDefinesExpectedRatioForCurrencies(): void
    {
        $this->assertSame(10, IranCurrency::TOMAN->ratio());
        $this->assertSame(1, IranCurrency::RIAL->ratio());
    }
}
