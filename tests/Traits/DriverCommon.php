<?php


namespace Shetabit\Multipay\Tests\Traits;

use Carbon\Carbon;
use Shetabit\Multipay\RedirectionForm;

trait DriverCommon
{
    protected $driverInstance;
    protected $transactionId;

    public function setUp()
    {
        $this->driverInstance = $this->getDriverInstance();
        $this->transactionId = 'biSBUv86G';
    }

    /**
     * Test Purchase method.
     *
     * @throws \Exception
     */
    public function testPurchase()
    {
        $amount = 10000;
        $detailKey = 'foo';
        $detailValue = 'bar';

        $this
            ->driverInstance
            ->detail($detailKey, $detailValue)
            ->purchase($amount, function ($driver, $transactionId) {
                $this->assertEquals($this->amount, $driver->getInvoice()->getAmount());
                $this->assertEquals([$this->detailKey => $this->detailValue], $driver->getInvoice()->getDetails());
                $this->assertEquals($this->transactionId, $transactionId);
            });
    }

    /**
     * Test pay method.
     *
     * @throws \Exception
     */
    public function testPay()
    {
        $amount = 1000;

        $this
            ->driverInstance
            ->amount($amount)
            ->purchase();

        $this->assertInstanceOf(RedirectionForm::class, $this->driverInstance);
    }

    /**
     * Test Verify method
     *
     * @throws \Shetabit\Multipay\Exceptions\InvoiceNotFoundException
     */
    public function testVerify()
    {
        $amount = 1000;

        $receipt = $this
            ->driverInstance
            ->amount($amount)
            ->transactionId($this->transactionId)
            ->verify();

        $this->assertInstanceOf(Carbon::class, $receipt->getDate());
        $this->assertEquals("test", $receipt->getDriver());
        $this->assertEquals("122156415036", $receipt->getReferenceId());
    }
}
