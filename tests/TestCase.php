<?php

namespace Shetabit\Multipay\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;
use Shetabit\Multipay\Tests\Drivers\BarDriver;

class TestCase extends BaseTestCase
{
    private $config = [];

    protected function setUp(): void
    {
        $this->environmentSetUp();
    }

    protected function config(): array
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

    private function loadConfig(): array
    {
        return require(__DIR__ . '/../config/payment.php');
    }

    /**
     * change capulated to accessible properties
     * @param string $class
     * @return array
     */
    public function deCapsulationProperties(string $class): array
    {
        $decapsulated = [];

        $reflection = new ReflectionClass($class);

        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $propertyName = $property->name;
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $decapsulated[$propertyName] = $property;
        }

        return $decapsulated;
    }
}
