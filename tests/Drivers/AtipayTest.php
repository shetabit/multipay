<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Atipay\Atipay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Invoice;
use ReflectionProperty;

/**
 * The gateway calls of this driver live in the global functions of
 * `src/Drivers/Atipay/Core/fn.atipay.php`, which talk to the url of the
 * `ATIPAY_URL` constant with cURL. Neither the url nor the transport can be
 * replaced from the outside, so the purchase and the successful verification can
 * not be covered here. What is left of the driver is.
 */
class AtipayTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'atipay';
    }

    protected function driverClass() : string
    {
        return Atipay::class;
    }

    protected function tearDown() : void
    {
        $_POST = [];

        parent::tearDown();
    }

    public function testPayPostsTheTokenOfThePurchaseToTheGateway(): void
    {
        $driver = $this->driver();

        // The token is the one the purchase received from the gateway.
        new ReflectionProperty($driver, 'tokenId')->setValue($driver, 'token-1');

        $form = $driver->pay();

        $this->assertSame($this->settings()['atipayRedirectGatewayUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['token' => 'token-1'], $form->getInputs());
    }

    public function testVerifyFailsWhenTheGatewayReportsACanceledPayment(): void
    {
        $_POST = [
            'state' => 'CanceledByUser',
            'status' => '2',
            'reservationNumber' => 'reservation-1',
            'referenceNumber' => 'reference-1',
            'terminalId' => 'terminal-1',
            'traceNumber' => 'trace-1',
        ];

        $this->expectException(InvalidPaymentException::class);

        $this->driver()->verify();
    }

    public function testVerifyFailsWhenTheCallbackHasNoState(): void
    {
        $_POST = [];

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage(
            'خطای نامشخص در پرداخت. در صورتیکه مبلغی از شما کسر شده باشد، برگشت داده می شود.'
        );

        $this->driver()->verify();
    }

    public function testItIsAWellFormedDriver(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('transaction-1'));

        $this->assertSame('transaction-1', $driver->getInvoice()->getTransactionId());
        $this->assertTrue(function_exists('fn_atipay_get_token'));
        $this->assertTrue(function_exists('fn_atipay_verify_payment'));
    }
}
