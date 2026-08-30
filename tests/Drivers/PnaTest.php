<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Pna\Pna;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class PnaTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'pna';
    }

    protected function driverClass() : string
    {
        return Pna::class;
    }

    public function testPurchaseReturnsTheTokenOfTheGateway(): void
    {
        $driver = $this->driver(['CorporationPin' => 'corporation-pin']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 0, 'token' => 'token-1'])]);

        $this->assertSame('token-1', $driver->amount(1000)->purchase());
        $this->assertSame('token-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiNormalSale']);

        $body = $this->requestJson();

        $this->assertSame('corporation-pin', $body['CorporationPin']);
        $this->assertSame($this->settings()['callbackUrl'], $body['CallBackUrl']);
        $this->assertSame($this->settings()['description'], $body['AdditionalData']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 0, 'token' => 'token-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertSame(10000, $this->requestJson()['Amount']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 0, 'token' => 'token-1'])]);

        $driver->detail(['mobile' => '09120000000', 'description' => 'a description'])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame(1000, $body['Amount']);
        $this->assertSame('09120000000', $body['Originator']);
        $this->assertSame('a description', $body['AdditionalData']);
    }

    public function testPurchaseOmitsTheOriginatorWhenTheInvoiceHasNoMobile(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 0, 'token' => 'token-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertArrayNotHasKey('Originator', $this->requestJson());
    }

    public function testPurchaseFailsWhenTheRequestIsRejected(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'errors' => ['Amount' => ['is required']],
                'title' => 'invalid request',
                'status' => 400,
            ]),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('invalid request');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => -1, 'message' => 'the pin is not valid'])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the pin is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheToken(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'token-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');
        $driver = $this->driver(['CorporationPin' => 'corporation-pin'], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'status' => 0,
                'rrn' => 'rrn-1',
                'cardNumberMasked' => '6037****1234',
                'token' => 'token-1',
            ]),
        ]);

        $receipt = $driver->verify();

        $this->assertSame('pna', $receipt->getDriver());
        $this->assertSame('rrn-1', $receipt->getReferenceId());
        $this->assertSame('6037****1234', $receipt->getDetail('cardNumberMasked'));
        $this->assertRequestedUrl($this->settings()['apiConfirmationUrl']);

        $body = $this->requestJson();

        $this->assertSame('token-1', $body['Token']);
        $this->assertSame('corporation-pin', $body['CorporationPin']);
    }

    public function testVerifyAcceptsAnAlreadyConfirmedTransaction(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('token-1'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 2, 'rrn' => 'rrn-1', 'cardNumberMasked' => '', 'token' => 'token-1']),
        ]);

        $this->assertSame('rrn-1', $driver->verify()->getReferenceId());
    }

    public function testVerifyFallsBackToTheTokenOfTheCallback(): void
    {
        $this->fakeRequest(['Token' => 'token-from-callback']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 0, 'rrn' => 'rrn-1', 'cardNumberMasked' => '', 'token' => 'token-1']),
        ]);

        $driver->verify();

        $this->assertSame('token-from-callback', $this->requestJson()['Token']);
    }

    public function testVerifyFailsWhenTheGatewayReportsAnError(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('token-1'));
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => -1])]);

        $this->expectException(InvalidPaymentException::class);

        $driver->verify();
    }

    public function testVerifyFailsWhenTheTransactionHasNoReferenceNumber(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('token-1'));
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 0, 'rrn' => '0'])]);

        $this->expectException(InvalidPaymentException::class);

        $driver->verify();
    }
}
