<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Paypal\Paypal;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class PaypalTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'paypal';
    }

    protected function driverClass() : string
    {
        return Paypal::class;
    }

    public function testPurchaseReturnsTheOrderIdOfPaypal(): void
    {
        $driver = $this->driver(['clientId' => 'the-client', 'clientSecret' => 'the-secret']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['access_token' => 'access-token']),
            $this->jsonResponse(['id' => 'order-1'], 201),
        ]);

        $this->assertSame('order-1', $driver->amount(10)->purchase());
        $this->assertSame('order-1', $driver->getInvoice()->getTransactionId());

        $this->assertRequestedUrl($this->settings()['accessTokenUrl'], 0);
        $this->assertSame(
            'Basic '.base64_encode('the-client:the-secret'),
            $this->request(0)->getHeaderLine('Authorization')
        );

        $this->assertRequestedUrl($this->settings()['purchaseUrl'], 1);
        $this->assertSame('Bearer access-token', $this->request(1)->getHeaderLine('Authorization'));
    }

    public function testPurchaseSendsTheAmountAndTheCurrencyOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => 'EUR']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['access_token' => 'access-token']),
            $this->jsonResponse(['id' => 'order-1'], 201),
        ]);

        $driver->amount(10)->purchase();

        $body = $this->requestJson(1);

        $this->assertSame('CAPTURE', $body['intent']);
        $this->assertSame('EUR', $body['purchase_units'][0]['amount']['currency_code']);
        $this->assertSame(10, $body['purchase_units'][0]['amount']['value']);
        $this->assertSame($this->settings()['callbackUrl'], $body['application_context']['return_url']);
    }

    public function testPurchaseUsesTheSandboxInSandboxMode(): void
    {
        $driver = $this->driver(['mode' => 'sandbox']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['access_token' => 'access-token']),
            $this->jsonResponse(['id' => 'order-1'], 201),
        ]);

        $driver->amount(10)->purchase();

        $this->assertRequestedUrl($this->settings()['sandboxAccessTokenUrl'], 0);
        $this->assertRequestedUrl($this->settings()['sandboxPurchaseUrl'], 1);
    }

    public function testPurchaseFailsWithTheErrorOfPaypal(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['access_token' => 'access-token']),
            $this->jsonResponse(['name' => 'INVALID_REQUEST', 'message' => 'the request is not valid'], 400),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('INVALID_REQUEST: the request is not valid');

        $driver->amount(10)->purchase();
    }

    public function testPurchaseFailsWithAGenericMessageWhenPaypalIsSilent(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['access_token' => 'access-token']),
            $this->jsonResponse([], 500),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('Unknown error');

        $driver->amount(10)->purchase();
    }

    public function testPayRedirectsToPaypalWithTheOrderId(): void
    {
        $invoice = (new Invoice)->transactionId('order-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['paymentUrl'].'order-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testPayRedirectsToTheSandboxInSandboxMode(): void
    {
        $invoice = (new Invoice)->transactionId('order-1');
        $form = $this->driver(['mode' => 'sandbox'], $invoice)->pay();

        $this->assertSame($this->settings()['sandboxPaymentUrl'].'order-1', $form->getAction());
    }

    public function testVerifyCapturesTheOrderAndReturnsAReceipt(): void
    {
        $this->fakeRequest(['token' => 'order-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['access_token' => 'access-token']),
            $this->jsonResponse(['id' => 'order-1', 'status' => 'COMPLETED'], 201),
        ]);

        $receipt = $driver->verify();

        $this->assertSame('paypal', $receipt->getDriver());
        $this->assertSame('order-1', $receipt->getReferenceId());
        $this->assertSame('COMPLETED', $receipt->getDetail('status'));
        $this->assertRequestedUrl(
            str_replace('{order_id}', 'order-1', $this->settings()['verificationUrl']),
            1
        );
    }

    public function testVerifyFallsBackToTheTransactionIdOfTheInvoice(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('order-1'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['access_token' => 'access-token']),
            $this->jsonResponse(['id' => 'order-1', 'status' => 'COMPLETED'], 201),
        ]);

        $driver->verify();

        $this->assertRequestedUrl(
            str_replace('{order_id}', 'order-1', $this->settings()['verificationUrl']),
            1
        );
    }

    public function testVerifyFailsWhenTheOrderWasNotCompleted(): void
    {
        $this->fakeRequest(['token' => 'order-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['access_token' => 'access-token']),
            $this->jsonResponse(['id' => 'order-1', 'status' => 'PENDING'], 201),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('Purchase not completed');

        $driver->verify();
    }

    public function testVerifyFailsWithTheErrorOfPaypal(): void
    {
        $this->fakeRequest(['token' => 'order-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['access_token' => 'access-token']),
            $this->jsonResponse(['name' => 'RESOURCE_NOT_FOUND', 'message' => 'the order was not found'], 404),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('RESOURCE_NOT_FOUND: the order was not found');

        $driver->verify();
    }
}
