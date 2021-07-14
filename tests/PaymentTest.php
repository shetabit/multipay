<?php


namespace Shetabit\Multipay\Tests;

use Carbon\Carbon;
use Shetabit\Multipay\Payment;
use Shetabit\Multipay\RedirectionForm;

class PaymentTest extends TestCase
{
    /**
     * test Purchase method
     * @throws \Exception
     */
    public function testPurchase()
    {
        $payment=$this->payment->amount(10000)->detail('foo', 'bar');
        $payment->purchase(null, function ($driver, $transactionId) {
            $this->assertEquals(10000, $driver->getInvoice()->getAmount());
            $this->assertEquals(['foo'=>'bar'], $driver->getInvoice()->getDetails());
            $this->assertEquals('biSBUv86G', $transactionId);
        });
    }

    /**
     * test pay method
     * @throws \Exception
     */
    public function testPay()
    {
        $payment=$this->payment->amount(10000)->purchase()->pay();
        $this->assertTrue($payment instanceof RedirectionForm);
    }

    /**
     * test Verify method
     * @throws \Shetabit\Multipay\Exceptions\InvoiceNotFoundException
     */
    public function testVerify()
    {
        $payment=$this->payment->amount(10000)->transactionId("biSBUv86G")->verify();
        $this->assertEquals(Carbon::now()->format("Y-m-d h:t:s"), $payment->getDate()->format("Y-m-d h:t:s"));
        $this->assertEquals("test", $payment->getDriver());
        $this->assertEquals("122156415036", $payment->getReferenceId());
    }
}
