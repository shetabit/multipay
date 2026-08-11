<?php

namespace Shetabit\Multipay\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Shetabit\Multipay\Payment;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use Shetabit\Multipay\Tests\Drivers\BarDriver;

class TestCase extends BaseTestCase
{
    private array $config = [];

    protected function setUp() : void
    {
        parent::setUp();

        $this->environmentSetUp();
    }

    protected function tearDown() : void
    {
        $this->resetSharedState();

        parent::tearDown();
    }

    protected function config() : array
    {
        return $this->config;
    }

    /**
     * Reset the static state that is shared between tests.
     *
     * Several parts of the package keep their state in static properties
     * (redirection form's view, overwritten request methods, event listeners).
     * Without resetting them, a test would leak its state into the next ones.
     */
    protected function resetSharedState() : void
    {
        $this->setStaticProperty(RedirectionForm::class, 'viewPath', null);
        $this->setStaticProperty(RedirectionForm::class, 'viewRenderer', null);
        $this->setStaticProperty(Request::class, 'overwrittenMethods', []);
        $this->setStaticProperty(Payment::class, 'eventEmitter', null);
    }

    /**
     * Overwrite the value of a (protected) static property.
     *
     * @param mixed $value
     */
    protected function setStaticProperty(string $class, string $property, $value) : void
    {
        (new \ReflectionProperty($class, $property))->setValue(null, $value);
    }

    private function environmentSetUp(): void
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
