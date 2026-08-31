<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Omidpay\Omidpay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class OmidpayTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'omidpay';
    }

    protected function driverClass() : string
    {
        return Omidpay::class;
    }

    public function testPurchaseReturnsTheTokenOfTheGateway(): void
    {
        $driver = $this->driver([
            'username' => 'omidpay-user',
            'password' => 'omidpay-password',
            'merchantId' => 'omidpay-merchant',
        ]);
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erSucceed', 'Token' => 'token-1'])]);

        $this->assertSame('token-1', $driver->amount(1000)->purchase());
        $this->assertSame('token-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiGenerateTokenUrl']);

        $body = $this->requestJson();

        $this->assertSame('omidpay-user', $body['WSContext']['UserId']);
        $this->assertSame('omidpay-password', $body['WSContext']['Password']);
        $this->assertSame('omidpay-merchant', $body['MerchantId']);
        $this->assertSame('EN_GOODS', $body['TransType']);
        $this->assertSame($this->settings()['callbackUrl'], $body['RedirectUrl']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erSucceed', 'Token' => 'token-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertSame(10000, $this->requestJson()['Amount']);
    }

    public function testPurchaseFailsWithTheTranslatedResultOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erAAS_InvalidUseridOrPass'])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('کد کاربری یا رمز صحیح نمی باشد.');

        $driver->amount(1000)->purchase();
    }

    public function testPayPostsTheTokenToTheGateway(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['token' => 'token-1', 'language' => 'fa'], $form->getInputs());
    }

    public function testVerifyReturnsAReceiptWithTheReferenceNumber(): void
    {
        $this->fakeRequest(['RefNum' => 'reference-1']);

        $invoice = (new Invoice)->transactionId('token-1');
        $driver = $this->driver(['username' => 'omidpay-user'], $invoice);
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erSucceed', 'RefNum' => 'reference-1'])]);

        $receipt = $driver->verify();

        $this->assertSame('omidpay', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $body = $this->requestJson();

        $this->assertSame('token-1', $body['Token']);
        $this->assertSame('reference-1', $body['RefNum']);
        $this->assertSame('omidpay-user', $body['WSContext']['UserId']);
    }

    public function testVerifyFallsBackToTheTokenOfTheCallback(): void
    {
        $this->fakeRequest(['token' => 'token-from-callback', 'RefNum' => 'reference-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erSucceed', 'RefNum' => 'reference-1'])]);

        $driver->verify();

        $this->assertSame('token-from-callback', $this->requestJson()['Token']);
    }

    public function testVerifyFailsWithTheTranslatedResultOfTheGateway(): void
    {
        $this->fakeRequest(['RefNum' => 'reference-1']);

        $driver = $this->driver([], (new Invoice)->transactionId('token-1'));
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erAAS_InvalidUseridOrPass'])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('کد کاربری یا رمز صحیح نمی باشد.');

        $driver->verify();
    }
}
