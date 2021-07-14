<?php

namespace Shetabit\Multipay\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Shetabit\Multipay\Payment;

class TestCase extends BaseTestCase
{
    protected $payment;

    /**
     * @throws \Exception
     */
    protected function setUp(): void
    {
        $this->payment=new Payment($this->config());
    }
    protected function config()
    {
        return require(__DIR__.'/helpers/config.php');
    }
}
