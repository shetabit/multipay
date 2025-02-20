<?php

namespace Shetabit\Multipay\Tests;

use Carbon\Carbon;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Tests\Drivers\BarDriver;
use Shetabit\Multipay\Tests\Mocks\MockPaymentManager;

class PaymentTest extends TestCase
{
    public function testItHasDefaultDriver(): void
    {
        $config = $this->config();
        $manager = $this->getManagerFreshInstance();

        $this->assertEquals($config['default'], $manager->getDriver());
    }

    public function testItWontAcceptInvalidDriver(): void
    {
        $this->expectException(\Exception::class);

        $manager = $this->getManagerFreshInstance();
        $manager->via('none_existance_driver_name');
    }

    public function testConfigCanBeModified(): void
    {
        $manager = $this->getManagerFreshInstance();

        $manager->config('foo', 'bar');

        $config = $manager->getCurrentDriverSetting();

        $this->assertArrayHasKey('foo', $config);
        $this->assertSame('bar', $config['foo']);
    }

    public function testCallbackUrlCanBeModified(): void
    {
        $manager = $this->getManagerFreshInstance();
        $manager->callbackUrl('/random_url');

        $this->assertEquals('/random_url', $manager->getCallbackUrl());
    }

    public function testAmountCanBeSetted(): void
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();
        $manager->amount($amount);

        $this->assertSame($amount, $manager->getInvoice()->getAmount());
    }

    public function testDeteilCanBeSetted(): void
    {
        $manager = $this->getManagerFreshInstance();

        // array style
        $manager->detail(['foo' => 'bar']);

        // normal style
        $manager->detail('john', 'doe');

        $invoice = $manager->getInvoice();

        $this->assertEquals('bar', $invoice->getDetail('foo'));
        $this->assertEquals('doe', $invoice->getDetail('john'));
    }

    public function testDriverCanBeChanged(): void
    {
        $driverName = 'bar';
        $manager = $this->getManagerFreshInstance();
        $manager->via($driverName);

        $this->assertEquals($driverName, $manager->getDriver());
    }

    public function testPurchase(): void
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();

        $manager
            ->via('bar')
            ->amount($amount)
            ->purchase(null, function ($driver, $transactionId) use ($amount): void {
                $this->assertEquals(BarDriver::TRANSACTION_ID, $transactionId);
                $this->assertSame($amount, $driver->getInvoice()->getAmount());
            });
    }

    public function testCustomInvoiceCanBeUsedInPurchase(): void
    {
        $manager = $this->getManagerFreshInstance();

        $invoice = new Invoice;
        $invoice->amount(10000);

        $manager
            ->via('bar')
            ->purchase($invoice, function ($driver, $transactionId) use ($invoice): void {
                $this->assertEquals(BarDriver::TRANSACTION_ID, $transactionId);
                $this->assertSame($invoice->getAmount(), $driver->getInvoice()->getAmount());
            });
    }

    public function testPay(): void
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();

        $redirectionForm = $manager->amount($amount)->via('bar')->purchase()->pay();
        $inputs = $redirectionForm->getInputs();

        $this->assertInstanceOf(RedirectionForm::class, $redirectionForm);
        $this->assertEquals($inputs['amount'], $amount);
    }

    public function testVerify(): void
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();

        $receipt = $manager->amount($amount)->via('bar')->transactionId(BarDriver::TRANSACTION_ID)->verify();

        $this->assertSame(BarDriver::REFERENCE_ID, $receipt->getReferenceId());
        $this->assertInstanceOf(Carbon::class, $receipt->getDate());
    }

    protected function getManagerFreshInstance(): \Shetabit\Multipay\Tests\Mocks\MockPaymentManager
    {
        return new MockPaymentManager($this->config());
    }
}
