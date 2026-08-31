<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Payfa\Payfa;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class PayfaTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'payfa';
    }

    protected function driverClass() : string
    {
        return Payfa::class;
    }

    public function testPurchaseReturnsThePaymentIdOfTheGateway(): void
    {
        $driver = $this->driver(['apiKey' => 'payfa-api-key']);
        $this->fakeHttp($driver, [$this->jsonResponse(['paymentId' => 'payment-1'])]);

        $this->assertSame('payment-1', $driver->amount(1000)->purchase());
        $this->assertSame('payment-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('payfa-api-key', $this->request()->getHeaderLine('X-API-Key'));
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['paymentId' => 'payment-1'])]);

        $driver->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame(10000, $body['amount']);
        $this->assertSame($this->settings()['callbackUrl'], $body['callbackUrl']);
        $this->assertSame($driver->getInvoice()->getUuid(), $body['invoiceId']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->jsonResponse(['paymentId' => 'payment-1'])]);

        $driver->detail([
            'mobile' => '09120000000',
            'cardNumber' => '6037********1234',
        ])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame(1000, $body['amount']);
        $this->assertSame('09120000000', $body['mobileNumber']);
        $this->assertSame('6037********1234', $body['cardNumber']);
    }

    public function testPurchaseFailsWithTheTitleOfTheGatewayError(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['title' => 'the api key is not valid'], 401)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the api key is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithThePaymentId(): void
    {
        $invoice = (new Invoice)->transactionId('payment-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'payment-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceiptWithTheTransactionId(): void
    {
        $invoice = (new Invoice)->transactionId('payment-1');
        $driver = $this->driver(['apiKey' => 'payfa-api-key'], $invoice);
        $this->fakeHttp($driver, [$this->jsonResponse(['transactionId' => 'transaction-1'])]);

        $receipt = $driver->verify();

        $this->assertSame('payfa', $receipt->getDriver());
        $this->assertSame('transaction-1', $receipt->getReferenceId());
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl'].'payment-1');
        $this->assertSame('payfa-api-key', $this->request()->getHeaderLine('X-API-Key'));
    }

    public function testVerifyFallsBackToThePaymentIdOfTheCallback(): void
    {
        $this->fakeRequest(['paymentId' => 'payment-from-callback']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['transactionId' => 'transaction-1'])]);

        $driver->verify();

        $this->assertRequestedUrl($this->settings()['apiVerificationUrl'].'payment-from-callback');
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('payment-1'));
        $this->fakeHttp($driver, [$this->jsonResponse(['message' => 'the payment was not completed'], 400)]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('the payment was not completed');

        $driver->verify();
    }
}
