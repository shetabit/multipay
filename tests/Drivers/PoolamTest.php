<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Poolam\Poolam;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class PoolamTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'poolam';
    }

    protected function driverClass() : string
    {
        return Poolam::class;
    }

    public function testPurchaseReturnsTheInvoiceKeyOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'poolam-key']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'invoice_key' => 'invoice-1'])]);

        $this->assertSame('invoice-1', $driver->amount(1000)->purchase());
        $this->assertSame('invoice-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN, 'merchantId' => 'poolam-key']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'invoice_key' => 'invoice-1'])]);

        $driver->amount(1000)->purchase();

        $form = $this->requestForm();

        $this->assertSame('10000', $form['amount']);
        $this->assertSame('poolam-key', $form['api_key']);
        $this->assertSame($this->settings()['callbackUrl'], $form['return_url']);
    }

    public function testPurchaseKeepsTheAmountWhenTheCurrencyIsRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'invoice_key' => 'invoice-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertSame('1000', $this->requestForm()['amount']);
    }

    public function testPurchaseFailsWhenTheGatewayReportsAnError(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 0])]);

        $this->expectException(PurchaseFailedException::class);

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheInvoiceKey(): void
    {
        $invoice = (new Invoice)->transactionId('invoice-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'invoice-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceiptWithTheBankCode(): void
    {
        $invoice = (new Invoice)->transactionId('invoice-1');
        $driver = $this->driver(['merchantId' => 'poolam-key'], $invoice);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'bank_code' => 'bank-code-1'])]);

        $receipt = $driver->verify();

        $this->assertSame('poolam', $receipt->getDriver());
        $this->assertSame('bank-code-1', $receipt->getReferenceId());
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl'].'invoice-1');
        $this->assertSame('poolam-key', $this->requestForm()['api_key']);
    }

    public function testVerifyFallsBackToTheInvoiceKeyOfTheCallback(): void
    {
        $this->fakeRequest(['invoice_key' => 'invoice-from-callback']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'bank_code' => 'bank-code-1'])]);

        $driver->verify();

        $this->assertRequestedUrl($this->settings()['apiVerificationUrl'].'invoice-from-callback');
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('invoice-1'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 0, 'errorDescription' => 'transaction was not paid']),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('transaction was not paid');

        $driver->verify();
    }

    public function testVerifyFailsWithAGenericMessageWhenTheGatewayIsSilent(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('invoice-1'));
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 0])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('خطای ناشناخته ای رخ داده است.');

        $driver->verify();
    }
}
