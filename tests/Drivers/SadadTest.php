<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Sadad\Sadad;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class SadadTest extends DriverTestCase
{
    /**
     * The driver signs its requests with a base64 encoded triple DES key.
     */
    private const string KEY = 'MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0';

    protected function driverName() : string
    {
        return 'sadad';
    }

    protected function driverClass() : string
    {
        return Sadad::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'key' => self::KEY,
            'merchantId' => 'sadad-merchant',
            'terminalId' => 'terminal-1',
        ], $overrides));
    }

    public function testPurchaseReturnsTheTokenOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['ResCode' => 0, 'Token' => 'token-1'])]);

        $this->assertSame('token-1', $driver->amount(1000)->purchase());
        $this->assertSame('token-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPaymentUrl']);

        $body = $this->requestJson();

        $this->assertSame('sadad-merchant', $body['MerchantId']);
        $this->assertSame('terminal-1', $body['TerminalId']);
        $this->assertSame($this->settings()['callbackUrl'], $body['ReturnUrl']);
        $this->assertNotEmpty($body['SignData']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['ResCode' => 0, 'Token' => 'token-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertSame(10000, $this->requestJson()['Amount']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->jsonResponse(['ResCode' => 0, 'Token' => 'token-1'])]);

        $driver->detail(['description' => 'a description', 'mobile' => '09120000000'])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame(1000, $body['Amount']);
        $this->assertSame('a description', $body['additionalData']);
        $this->assertSame('09120000000', $body['UserId']);
    }

    public function testPurchaseUsesThePaymentByIdentityEndpointInThatMode(): void
    {
        $driver = $this->driver(['mode' => 'paymentByIdentity']);
        $this->fakeHttp($driver, [$this->jsonResponse(['ResCode' => 0, 'Token' => 'token-1'])]);

        $driver->detail('payment_identity', 'identity-1')->amount(1000)->purchase();

        $this->assertRequestedUrl($this->settings()['apiPaymentByIdentityUrl']);
        $this->assertSame('identity-1', $this->requestJson()['PaymentIdentity']);
    }

    public function testPurchaseUsesThePaymentByMultiIdentityEndpointInThatMode(): void
    {
        $rows = [['IbanNumber' => 'IR000000000000000000000000', 'Amount' => 100, 'PaymentIdentity' => 'identity-1']];

        $driver = $this->driver(['mode' => 'paymentByMultiIdentity', 'currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['ResCode' => 0, 'Token' => 'token-1'])]);

        $driver->detail('multi_identity_rows', $rows)->amount(1000)->purchase();

        $this->assertRequestedUrl($this->settings()['apiPaymentByMultiIdentityUrl']);
        $this->assertSame(1000, $this->requestJson()['MultiIdentityData']['MultiIdentityRows'][0]['Amount']);
    }

    public function testPurchaseFailsWithTheDescriptionOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['ResCode' => -1, 'Description' => 'the terminal is wrong'])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the terminal is wrong');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWhenTheGatewayIsUnreachable(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->response('', 502)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('دسترسی به صفحه مورد نظر امکان پذیر نمی باشد.');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheToken(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPurchaseUrl'], $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame(['Token' => 'token-1'], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['ResCode' => 0, 'token' => 'token-1']);

        $invoice = (new Invoice)->transactionId('token-1');
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'ResCode' => 0,
                'SystemTraceNo' => 'trace-1',
                'RetrivalRefNo' => 'reference-1',
                'OrderId' => 'order-1',
                'Description' => 'the payment was successful',
            ]),
        ]);

        $receipt = $driver->verify();

        $this->assertSame('sadad', $receipt->getDriver());
        $this->assertSame('trace-1', $receipt->getReferenceId());
        $this->assertSame('order-1', $receipt->getDetail('orderId'));
        $this->assertSame('reference-1', $receipt->getDetail('referenceNo'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $body = $this->requestJson();

        $this->assertSame('token-1', $body['Token']);
        $this->assertNotEmpty($body['SignData']);
    }

    public function testVerifyFailsWhenTheCallbackReportsAnError(): void
    {
        $this->fakeRequest(['ResCode' => -1]);

        $this->expectException(InvalidPaymentException::class);

        $this->driver()->verify();
    }

    public function testVerifyFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $this->fakeRequest(['ResCode' => 0, 'token' => 'token-1']);

        $driver = $this->driver([], (new Invoice)->transactionId('token-1'));
        $this->fakeHttp($driver, [$this->jsonResponse(['ResCode' => -1])]);

        $this->expectException(InvalidPaymentException::class);

        $driver->verify();
    }
}
