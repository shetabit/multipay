<?php

namespace Shetabit\Multipay\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Shetabit\Multipay\Tests\Mocks\Drivers\BarDriver;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return ['Shetabit\Multipay\Provider\PaymentServiceProvider'];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Payment' => 'Shetabit\Multipay\Payment',
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $settings = require __DIR__.'/../src/Config/payment.php';
        $settings['drivers']['bar'] = ['key' => 'foo'];
        $settings['map']['bar'] = BarDriver::class;

        $app['config']->set('payment', $settings);
    }
}
