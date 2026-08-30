<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Constants\IranCurrency;
use Shetabit\Multipay\Drivers\IranDargah\IranDargah;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class IranDargahTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'irandargah';
    }

    protected function driverClass() : string
    {
        return IranDargah::class;
    }

    public function testPurchaseReturnsTheAuthorityOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'irandargah-merchant']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 200, 'authority' => 'authority-1'])]);

        $this->assertSame('authority-1', $driver->amount(1000)->purchase());
        $this->assertSame('authority-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('irandargah-merchant', $this->requestForm()['merchantID']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 200, 'authority' => 'authority-1'])]);

        $driver->amount(1000)->purchase();

        $form = $this->requestForm();

        $this->assertSame('10000', $form['amount']);
        $this->assertSame($this->settings()['callbackUrl'], $form['callbackURL']);
        $this->assertSame($driver->getInvoice()->getUuid(), $form['orderId']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 200, 'authority' => 'authority-1'])]);

        $driver->detail([
            'cardNumber' => '6037********1234',
            'mobile' => '09120000000',
            'description' => 'a description',
        ])->amount(1000)->purchase();

        $form = $this->requestForm();

        $this->assertSame('1000', $form['amount']);
        $this->assertSame('6037********1234', $form['cardNumber']);
        $this->assertSame('09120000000', $form['mobile']);
        $this->assertSame('a description', $form['description']);
    }

    public function testPurchaseUsesTheSandboxInSandboxMode(): void
    {
        $driver = $this->driver(['sandbox' => true]);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 200, 'authority' => 'authority-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertRequestedUrl($this->settings()['sandboxApiPurchaseUrl']);
    }

    public function testPurchaseFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => -10])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('مبلغ تراکنش کمتر از ۱۰،۰۰۰ ریال است');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheAuthority(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'authority-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testPayRedirectsToTheSandboxInSandboxMode(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $form = $this->driver(['sandbox' => true], $invoice)->pay();

        $this->assertSame($this->settings()['sandboxApiPaymentUrl'].'authority-1', $form->getAction());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['code' => 100, 'authority' => 'authority-1', 'orderId' => 'order-1']);

        $driver = $this->driver(['merchantId' => 'irandargah-merchant', 'currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'status' => 100,
                'refId' => 'reference-1',
                'message' => 'transaction was successful',
                'orderId' => 'order-1',
                'cardNumber' => '6037****1234',
            ]),
        ]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('irandargah', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame('order-1', $receipt->getDetail('orderId'));
        $this->assertSame('6037****1234', $receipt->getDetail('cardNumber'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $body = $this->requestJson();

        $this->assertSame('authority-1', $body['authority']);
        $this->assertSame(1000, $body['amount']);
    }

    public function testVerifyFailsWhenTheUserCanceledThePayment(): void
    {
        $this->fakeRequest(['code' => -1]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('کاربر از انجام تراکنش منصرف‌ شده است');

        $this->driver()->amount(1000)->verify();
    }

    public function testVerifyFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $this->fakeRequest(['code' => 100, 'authority' => 'authority-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 101])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش قبلا وریفای شده است');

        $driver->amount(1000)->verify();
    }
}
