<?php

namespace Shetabit\Multipay\Tests;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\DriverInterface;
use Shetabit\Multipay\Payment;
use Shetabit\Multipay\Tests\Mocks\MockPaymentManager;
use ReflectionClass;

class ConfigTest extends TestCase
{
    public function testTheDefaultConfigFileExists(): void
    {
        $this->assertFileExists(Payment::getDefaultConfigPath());
    }

    public function testTheConfigFileHasTheExpectedSections(): void
    {
        $config = $this->config();

        $this->assertArrayHasKey('default', $config);
        $this->assertArrayHasKey('drivers', $config);
        $this->assertArrayHasKey('map', $config);
    }

    public function testTheDefaultDriverIsConfigured(): void
    {
        $config = $this->config();

        $this->assertArrayHasKey($config['default'], $config['drivers']);
        $this->assertArrayHasKey($config['default'], $config['map']);
    }

    public function testEveryMappedDriverHasSettings(): void
    {
        $config = $this->config();

        foreach (array_keys($config['map']) as $name) {
            $this->assertArrayHasKey($name, $config['drivers'], "Driver [$name] is mapped but has no settings.");
            $this->assertIsArray($config['drivers'][$name], "Settings of driver [$name] should be an array.");
        }
    }

    public function testEveryMappedDriverIsAUsableDriver(): void
    {
        foreach ($this->config()['map'] as $name => $class) {
            $this->assertTrue(class_exists($class), "Class of driver [$name] does not exist: $class");

            $reflection = new ReflectionClass($class);

            $this->assertFalse($reflection->isAbstract(), "Driver [$name] should not be abstract.");
            $this->assertTrue(
                $reflection->implementsInterface(DriverInterface::class),
                "Driver [$name] should implement ".DriverInterface::class
            );
            $this->assertTrue(
                $reflection->isSubclassOf(Driver::class),
                "Driver [$name] should extend ".Driver::class
            );
        }
    }

    public function testEveryDriverCanBeSelected(): void
    {
        $config = $this->config();
        $manager = new MockPaymentManager($config);

        foreach (array_keys($config['map']) as $name) {
            $manager->via($name);

            $this->assertSame($name, $manager->getDriver());
            $this->assertNotSame([], $manager->getCurrentDriverSetting(), "Driver [$name] has empty settings.");
        }
    }
}
