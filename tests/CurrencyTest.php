<?php

namespace Shetabit\Multipay\Tests;

use Shetabit\Multipay\Constants\IranCurrency;

class CurrencyTest extends TestCase
{
    public function testItDefinesCurrencyConstants(): void
    {
        $this->assertSame('T', IranCurrency::TOMAN);
        $this->assertSame('R', IranCurrency::RIAL);
    }

    public function testItDefinesExpectedRatioForCurrencies(): void
    {
        $this->assertSame(10, IranCurrency::RATIO[IranCurrency::TOMAN]);
        $this->assertSame(1, IranCurrency::RATIO[IranCurrency::RIAL]);
        $this->assertArrayHasKey(IranCurrency::TOMAN, IranCurrency::RATIO);
        $this->assertArrayHasKey(IranCurrency::RIAL, IranCurrency::RATIO);
    }
}
