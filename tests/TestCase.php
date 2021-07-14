<?php

namespace Shetabit\Multipay\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Payment;
use Shetabit\Multipay\Tests\helpers\TestDriverMock;

class TestCase extends BaseTestCase
{
    /**
     * @var Payment
     */
    protected $payment;
    /**
     * @throws \Exception
     */
    protected function setUp(): void
    {
        $this->payment=new Payment($this->config());
    }

    /**
     * return config
     * @return mixed
     */
    protected function config()
    {
        return require(__DIR__.'/helpers/config.php');
    }
}
