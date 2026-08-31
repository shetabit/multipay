<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Constants\IranCurrency;
use Shetabit\Multipay\Drivers\Sepordeh\Sepordeh;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class SepordehTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'sepordeh';
    }

    protected function driverClass() : string
    {
        return Sepordeh::class;
    }

    public function testPurchaseReturnsTheInvoiceIdOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'sepordeh-merchant']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 200, 'information' => ['invoice_id' => 'invoice-1']]),
        ]);

        $this->assertSame('invoice-1', $driver->amount(1000)->purchase());
        $this->assertSame('invoice-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);

        $form = $this->requestForm();

        $this->assertSame('sepordeh-merchant', $form['merchant']);
        $this->assertSame($this->settings()['callbackUrl'], $form['callback']);
    }

    public function testPurchaseSendsTheAmountInToman(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 200, 'information' => ['invoice_id' => 'invoice-1']]),
        ]);

        $driver->amount(10000)->purchase();

        $this->assertSame('1000', $this->requestForm()['amount']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 200, 'information' => ['invoice_id' => 'invoice-1']]),
        ]);

        $driver->detail([
            'orderId' => 'order-1',
            'phone' => '09120000000',
            'description' => 'a description',
        ])->amount(1000)->purchase();

        $form = $this->requestForm();

        $this->assertSame('1000', $form['amount']);
        $this->assertSame('order-1', $form['orderId']);
        $this->assertSame('09120000000', $form['phone']);
        $this->assertSame('a description', $form['description']);
    }

    public function testPurchaseFallsBackToTheDescriptionOfTheSettings(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 200, 'information' => ['invoice_id' => 'invoice-1']]),
        ]);

        $driver->amount(1000)->purchase();

        $this->assertSame($this->settings()['description'], $this->requestForm()['description']);
    }

    public function testPurchaseFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 401, 'message' => 'the merchant is not valid']),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the merchant is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWithATranslatedStatusCode(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 503])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('سرور درگاه پرداخت در حال حاضر قادر به پاسخگویی نمی باشد');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheInvoiceId(): void
    {
        $invoice = (new Invoice)->transactionId('invoice-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'invoice-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testPayRedirectsToTheDirectGatewayInDirectMode(): void
    {
        $invoice = (new Invoice)->transactionId('invoice-1');
        $form = $this->driver(['mode' => 'direct'], $invoice)->pay();

        $this->assertSame($this->settings()['apiDirectPaymentUrl'].'invoice-1', $form->getAction());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['orderId' => 'order-1']);

        $invoice = (new Invoice)->transactionId('invoice-1');
        $driver = $this->driver(['merchantId' => 'sepordeh-merchant'], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'status' => 200,
                'information' => ['invoice_id' => 'invoice-1', 'card' => '6037****1234'],
            ]),
        ]);

        $receipt = $driver->verify();

        $this->assertSame('sepordeh', $receipt->getDriver());
        $this->assertSame('invoice-1', $receipt->getReferenceId());
        $this->assertSame('6037****1234', $receipt->getDetail('card'));
        $this->assertSame('order-1', $receipt->getDetail('orderId'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);
        $this->assertSame('invoice-1', $this->requestForm()['authority']);
    }

    public function testVerifyFallsBackToTheAuthorityOfTheCallback(): void
    {
        $this->fakeRequest(['authority' => 'authority-from-callback']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'status' => 200,
                'information' => ['invoice_id' => 'invoice-1', 'card' => '6037****1234'],
            ]),
        ]);

        $driver->verify();

        $this->assertSame('authority-from-callback', $this->requestForm()['authority']);
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('invoice-1'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 404, 'message' => 'the invoice was not found']),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('the invoice was not found');

        $driver->verify();
    }
}
