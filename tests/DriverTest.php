<?php

namespace Shetabit\Multipay\Tests;

use Shetabit\Multipay\Constants\IranCurrency;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Tests\Drivers\BarDriver;
use Exception;

class DriverTest extends TestCase
{
    public function testItExposesTheGivenInvoice(): void
    {
        $invoice = new Invoice;
        $driver = new BarDriver($invoice, []);

        $this->assertSame($invoice, $driver->getInvoice());
    }

    public function testTheInvoiceCanBeReplaced(): void
    {
        $driver = new BarDriver(new Invoice, []);
        $invoice = new Invoice;

        $this->assertSame($driver, $driver->invoice($invoice));
        $this->assertSame($invoice, $driver->getInvoice());
    }

    public function testTheAmountIsSetOnTheInvoice(): void
    {
        $driver = new BarDriver(new Invoice, []);

        $this->assertSame($driver, $driver->amount(10000));
        $this->assertSame(10000, $driver->getInvoice()->getAmount());
    }

    public function testAnInvalidAmountIsRejected(): void
    {
        $driver = new BarDriver(new Invoice, []);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Amount value should be a number (integer or float).');

        $driver->amount('not-a-number');
    }

    public function testADetailIsSetOnTheInvoice(): void
    {
        $driver = new BarDriver(new Invoice, []);

        $this->assertSame($driver, $driver->detail('foo', 'bar'));
        $this->assertSame(['foo' => 'bar'], $driver->getInvoice()->getDetails());
    }

    public function testMultipleDetailsCanBeSetUsingAnArray(): void
    {
        $driver = new BarDriver(new Invoice, []);
        $driver->detail(['foo' => 'bar', 'john' => 'doe']);

        $this->assertSame(['foo' => 'bar', 'john' => 'doe'], $driver->getInvoice()->getDetails());
    }

    public function testItBuildsARedirectionForm(): void
    {
        $driver = new BarDriver(new Invoice, []);

        $form = $driver->redirectWithForm('https://example.com/pay', ['amount' => 10000], 'GET');

        $this->assertInstanceOf(RedirectionForm::class, $form);
        $this->assertSame('https://example.com/pay', $form->getAction());
        $this->assertSame(['amount' => 10000], $form->getInputs());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testARedirectionFormIsSubmittedUsingPostByDefault(): void
    {
        $driver = new BarDriver(new Invoice, []);

        $form = $driver->redirectWithForm('https://example.com/pay');

        $this->assertSame([], $form->getInputs());
        $this->assertSame('POST', $form->getMethod());
    }

    public function testItNormalizesPriceWithTomanCurrency(): void
    {
        $driver = new BarDriver(new Invoice, ['currency' => IranCurrency::TOMAN]);

        $this->assertSame(10000, $driver->normalizePrice(1000));
        $this->assertSame(1050.0, $driver->normalizePrice(105.0));
    }

    public function testItNormalizesPriceWithRialCurrency(): void
    {
        $driver = new BarDriver(new Invoice, ['currency' => IranCurrency::RIAL]);

        $this->assertSame(1000, $driver->normalizePrice(1000));
        $this->assertSame(105.5, $driver->normalizePrice(105.5));
    }
}
