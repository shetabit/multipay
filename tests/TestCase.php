<?php

namespace Shetabit\Multipay\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Shetabit\Multipay\Tests\Drivers\BarDriver;

class TestCase extends BaseTestCase
{
    private $config = [];

    protected function setUp() : void
    {
        $this->environmentSetUp();
    }

    protected function config() : array
    {
        return $this->config;
    }

    private function environmentSetUp()
    {
        $this->config = $this->loadConfig();

        $this->config['map']['bar'] = BarDriver::class;
        $this->config['drivers']['bar'] = [
            'callback' => '/callback'
        ];
    }

    private function loadConfig() : array
    {
        return require(__DIR__.'/../config/payment.php');
    }
}
