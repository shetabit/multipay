<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Panapal\Panapal;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class PanapalTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'panapal';
    }

    protected function driverClass() : string
    {
        return Panapal::class;
    }

    public function testPurchaseReturnsTheAuthorityOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'panapal-merchant']);
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => 100, 'Authority' => 'authority-1'])]);

        $this->assertSame('authority-1', $driver->amount(1000)->purchase());
        $this->assertSame('authority-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('panapal-merchant', $this->requestJson()['MerchantID']);
    }

    public function testPurchaseSendsTheAmountInToman(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => 100, 'Authority' => 'authority-1'])]);

        $driver->amount(15000)->purchase();

        $this->assertSame(1500, $this->requestJson()['Amount']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => 100, 'Authority' => 'authority-1'])]);

        $driver->detail([
            'name' => 'john doe',
            'phone' => '09120000000',
            'mail' => 'john@example.com',
            'desc' => 'a description',
            'order_id' => 'order-1',
        ])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame('john doe', $body['FullName']);
        $this->assertSame('09120000000', $body['Mobile']);
        $this->assertSame('john@example.com', $body['Email']);
        $this->assertSame('a description', $body['Description']);
        $this->assertSame('order-1', $body['InvoiceID']);
        $this->assertSame($this->settings()['callbackUrl'], $body['CallbackURL']);
    }

    public function testPurchaseGeneratesAnOrderIdWhenTheInvoiceHasNone(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => 100, 'Authority' => 'authority-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertMatchesRegularExpression('/^\d+$/', (string) $this->requestJson()['InvoiceID']);
    }

    public function testPurchaseFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => -6])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('MerchantID وارد شده در سیستم یافت نشد');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheAuthority(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'authority-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest([
            'PaymentStatus' => 'OK',
            'Authority' => 'authority-1',
            'InvoiceID' => 'order-1',
        ]);

        $invoice = (new Invoice)->transactionId('authority-1');
        $driver = $this->driver(['currency' => IranCurrency::TOMAN], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'Status' => 100,
                'RefID' => 'reference-1',
                'InvoiceID' => 'order-1',
                'Amount' => 1000,
                'MaskCardNumber' => '6037****1234',
                'PaymentTime' => '2024-01-01 10:20:30',
                'BuyerIP' => '127.0.0.1',
            ]),
        ]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('panapal', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame('authority-1', $receipt->getDetail('Authority'));
        $this->assertSame('order-1', $receipt->getDetail('InvoiceID1'));
        $this->assertSame('6037****1234', $receipt->getDetail('CardNumber'));
        $this->assertSame('127.0.0.1', $receipt->getDetail('PaymenterIP'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);
    }

    public function testVerifyFailsWhenTheUserCanceledThePayment(): void
    {
        $this->fakeRequest(['PaymentStatus' => 'NOK']);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش از سوی کاربر لغو شد');

        $this->driver()->verify();
    }

    public function testVerifyFailsWhenTheCallbackDoesNotBelongToTheInvoice(): void
    {
        $this->fakeRequest(['PaymentStatus' => 'OK', 'Authority' => 'another-authority']);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('اطلاعات تراکنش دریافتی با صورتحساب همخوانی ندارد');

        $this->driver([], (new Invoice)->transactionId('authority-1'))->verify();
    }

    public function testVerifyFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $this->fakeRequest(['PaymentStatus' => 'OK', 'Authority' => 'authority-1']);

        $driver = $this->driver([], (new Invoice)->transactionId('authority-1'));
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => -6])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('MerchantID وارد شده در سیستم یافت نشد');

        $driver->amount(1000)->verify();
    }
}
