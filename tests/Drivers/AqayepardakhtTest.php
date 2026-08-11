<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Aqayepardakht\Aqayepardakht;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class AqayepardakhtTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'aqayepardakht';
    }

    protected function driverClass() : string
    {
        return Aqayepardakht::class;
    }

    public function testPurchaseReturnsTheTransactionIdOfTheGateway(): void
    {
        $driver = $this->driver(['pin' => 'aqayepardakht-pin']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 'success', 'transid' => 'transaction-1'])]);

        $this->assertSame('transaction-1', $driver->amount(1000)->purchase());
        $this->assertSame('transaction-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('aqayepardakht-pin', $this->requestJson()['pin']);
    }

    public function testPurchaseSendsTheAmountInToman(): void
    {
        $driver = $this->driver(['currency' => 'R']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 'success', 'transid' => 'transaction-1'])]);

        $driver->amount(10000)->purchase();

        $this->assertSame(1000, $this->requestJson()['amount']);
    }

    public function testPurchaseUsesTheSandboxPinInSandboxMode(): void
    {
        $driver = $this->driver(['mode' => 'sandbox', 'pin' => 'aqayepardakht-pin']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 'success', 'transid' => 'transaction-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertSame('sandbox', $this->requestJson()['pin']);
    }

    public function testPurchaseFailsWithTheTranslatedErrorOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 'error', 'code' => -1])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('مبلغ نباید خالی باشد.');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheTransactionId(): void
    {
        $invoice = (new Invoice)->transactionId('transaction-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'transaction-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testPayRedirectsToTheSandboxGatewayInSandboxMode(): void
    {
        $invoice = (new Invoice)->transactionId('transaction-1');
        $form = $this->driver(['mode' => 'sandbox'], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrlSandbox'].'transaction-1', $form->getAction());
    }

    public function testVerifyReturnsAReceiptWithTheTrackingNumber(): void
    {
        $this->fakeRequest(['tracking_number' => 'tracking-1', 'transid' => 'transaction-1']);

        $driver = $this->driver(['pin' => 'aqayepardakht-pin']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 'success'])]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('aqayepardakht', $receipt->getDriver());
        $this->assertSame('tracking-1', $receipt->getReferenceId());
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $body = $this->requestJson();

        $this->assertSame('transaction-1', $body['transid']);
        $this->assertSame('aqayepardakht-pin', $body['pin']);
    }

    public function testVerifyFailsWhenTheCallbackIsIncomplete(): void
    {
        $this->fakeRequest(['tracking_number' => null, 'transid' => null]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('پرداخت ناموفق.');

        $this->driver()->amount(1000)->verify();
    }

    public function testVerifyFailsWithTheTranslatedErrorOfTheGateway(): void
    {
        $this->fakeRequest(['tracking_number' => 'tracking-1', 'transid' => 'transaction-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 'error', 'code' => -1])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('مبلغ نباید خالی باشد.');

        $driver->amount(1000)->verify();
    }
}
