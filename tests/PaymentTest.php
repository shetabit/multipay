<?php

namespace Shetabit\Multipay\Tests;

use Carbon\Carbon;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Tests\Drivers\BarDriver;
use Shetabit\Multipay\Tests\Mocks\MockPaymentManager;

class PaymentTest extends TestCase
{
    public function testItHasDefaultDriver()
    {
        $config = $this->config();
        $manager = $this->getManagerFreshInstance();

        $this->assertEquals($config['default'], $manager->getDriver());
    }

    public function testItWontAcceptInvalidDriver()
    {
        $this->expectException(\Exception::class);

        $manager = $this->getManagerFreshInstance();
        $manager->via('none_existance_driver_name');
    }

    public function testConfigCanBeModified()
    {
        $manager = $this->getManagerFreshInstance();

        $manager->config('foo', 'bar');

        $config = $manager->getCurrentDriverSetting();

        $this->assertArrayHasKey('foo', $config);
        $this->assertSame('bar', $config['foo']);
    }

    public function testCallbackUrlCanBeModified()
    {
        $manager = $this->getManagerFreshInstance();
        $manager->callbackUrl('/random_url');

        $this->assertEquals('/random_url', $manager->getCallbackUrl());
    }

    public function testAmountCanBeSetted()
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();
        $manager->amount($amount);

        $this->assertSame($amount, $manager->getInvoice()->getAmount());
    }

    public function testDeteilCanBeSetted()
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

    public function testDriverCanBeChanged()
    {
        $driverName = 'bar';
        $manager = $this->getManagerFreshInstance();
        $manager->via($driverName);

        $this->assertEquals($driverName, $manager->getDriver());
    }

    public function testPurchase()
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();

        $manager
            ->via('bar')
            ->amount($amount)
            ->purchase(null, function ($driver, $transactionId) use ($amount) {
                $this->assertEquals(BarDriver::TRANSACTION_ID, $transactionId);
                $this->assertSame($amount, $driver->getInvoice()->getAmount());
            });
    }

    public function testCustomInvoiceCanBeUsedInPurchase()
    {
        $manager = $this->getManagerFreshInstance();

        $invoice = new Invoice;
        $invoice->amount(10000);

        $manager
            ->via('bar')
            ->purchase($invoice, function ($driver, $transactionId) use ($invoice) {
                $this->assertEquals(BarDriver::TRANSACTION_ID, $transactionId);
                $this->assertSame($invoice->getAmount(), $driver->getInvoice()->getAmount());
            });
    }

    public function testPay()
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();

        $redirectionForm = $manager->amount($amount)->via('bar')->purchase()->pay();
        $inputs = $redirectionForm->getInputs();

        $this->assertInstanceOf(RedirectionForm::class, $redirectionForm);
        $this->assertEquals($inputs['amount'], $amount);
    }

    public function testVerify()
    {
        $amount = 10000;
        $manager = $this->getManagerFreshInstance();

        $receipt = $manager->amount($amount)->via('bar')->transactionId(BarDriver::TRANSACTION_ID)->verify();

        $this->assertSame(BarDriver::REFERENCE_ID, $receipt->getReferenceId());
        $this->assertInstanceOf(Carbon::class, $receipt->getDate());
    }

    protected function getManagerFreshInstance()
    {
        return new MockPaymentManager($this->config());
    }
}
