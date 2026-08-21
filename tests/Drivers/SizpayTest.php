<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Sizpay\Sizpay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Invoice;

/**
 * The purchase and the successful verification of this driver talk SOAP to the
 * gateway (`new SoapClient($wsdl)`), which can neither be replaced nor reached from
 * the tests. Everything around it is covered here.
 */
class SizpayTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'sizpay';
    }

    protected function driverClass() : string
    {
        return Sizpay::class;
    }

    public function testPayPostsTheTokenAndTheCredentialsToTheGateway(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');
        $driver = $this->driver([
            'merchantId' => 'sizpay-merchant',
            'terminal' => 'terminal-1',
            'SignData' => 'sign-data',
        ], $invoice);

        $form = $driver->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(
            [
                'MerchantID' => 'sizpay-merchant',
                'TerminalID' => 'terminal-1',
                'Token' => 'token-1',
                'SignData' => 'sign-data',
            ],
            $form->getInputs()
        );
    }

    public function testVerifyFailsWhenTheUserCanceledThePayment(): void
    {
        $this->fakeRequest(['ResCod' => '-1']);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('پرداخت توسط کاربر لغو شد');

        $this->driver()->verify();
    }

    public function testVerifyFailsWhenTheCallbackHasNoResultCode(): void
    {
        $this->fakeRequest([]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('پرداخت توسط کاربر لغو شد');

        $this->driver()->verify();
    }
}
