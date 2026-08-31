<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Gooyapay\Gooyapay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class GooyapayTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'gooyapay';
    }

    protected function driverClass() : string
    {
        return Gooyapay::class;
    }

    public function testPurchaseReturnsTheAuthorityOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'gooyapay-merchant']);
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => 100, 'Authority' => 'authority-1'])]);

        $this->assertSame('authority-1', $driver->amount(1000)->purchase());
        $this->assertSame('authority-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('gooyapay-merchant', $this->requestJson()['MerchantID']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => 100, 'Authority' => 'authority-1'])]);

        $driver->detail([
            'name' => 'john doe',
            'mobile' => '09120000000',
            'email' => 'john@example.com',
            'description' => 'a description',
            'orderId' => 'order-1',
        ])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame('john doe', $body['FullName']);
        $this->assertSame('09120000000', $body['Mobile']);
        $this->assertSame('john@example.com', $body['Email']);
        $this->assertSame('a description', $body['Description']);
        $this->assertSame('order-1', $body['InvoiceID']);
        $this->assertSame($this->settings()['callbackUrl'], $body['CallbackURL']);
    }

    public function testPurchaseSendsTheAmountInToman(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => 100, 'Authority' => 'authority-1'])]);

        $driver->amount(15000)->purchase();

        $this->assertSame(1500, $this->requestJson()['Amount']);
    }

    public function testPurchaseKeepsTheAmountWhenTheCurrencyIsToman(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => 100, 'Authority' => 'authority-1'])]);

        $driver->amount(1500)->purchase();

        $this->assertSame(1500, $this->requestJson()['Amount']);
    }

    public function testPurchaseFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => -1, 'Message' => 'merchant is not valid'])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('merchant is not valid');

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
        $driver = $this->driver([], $invoice);
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

        $this->assertSame('gooyapay', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame('authority-1', $receipt->getDetail('Authority'));
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

        $invoice = (new Invoice)->transactionId('authority-1');

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('اطلاعات تراکنش دریافتی با صورتحساب همخوانی ندارد');

        $this->driver([], $invoice)->verify();
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $this->fakeRequest(['PaymentStatus' => 'OK', 'Authority' => 'authority-1']);

        $invoice = (new Invoice)->transactionId('authority-1');
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [$this->jsonResponse(['Status' => -1, 'Message' => 'transaction failed'])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('transaction failed');

        $driver->amount(1000)->verify();
    }
}
