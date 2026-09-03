<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Zibal\Zibal;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class ZibalTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'zibal';
    }

    protected function driverClass() : string
    {
        return Zibal::class;
    }

    public function testPurchaseReturnsTheTrackIdOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'zibal-merchant']);
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => 100, 'trackId' => 1234567])]);

        $driver->amount(1000);

        $this->assertSame(1234567, $driver->purchase());
        $this->assertSame(1234567, $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN, 'merchantId' => 'zibal-merchant']);
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => 100, 'trackId' => 1])]);

        $driver->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame(10000, $body['amount']);
        $this->assertSame('zibal-merchant', $body['merchant']);
        $this->assertSame($this->settings()['callbackUrl'], $body['callbackUrl']);
    }

    public function testPurchaseKeepsTheAmountWhenTheCurrencyIsRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => 100, 'trackId' => 1])]);

        $driver->amount(1000)->purchase();

        $this->assertSame(1000, $this->requestJson()['amount']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => 100, 'trackId' => 1])]);

        $driver->detail([
            'mobile' => '09120000000',
            'orderId' => 'order-1',
            'description' => 'a description',
        ])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame('09120000000', $body['mobile']);
        $this->assertSame('order-1', $body['orderId']);
        $this->assertSame('a description', $body['description']);
    }

    public function testPurchaseMergesTheOptionalFieldsIntoTheRequest(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => 100, 'trackId' => 1])]);

        $driver->detail('optionalField', ['nationalCode' => '0010000000'])->amount(1000)->purchase();

        $this->assertSame('0010000000', $this->requestJson()['nationalCode']);
    }

    public function testPurchaseSendsTheConfiguredReferer(): void
    {
        $driver = $this->driver(['referer' => 'https://example.com']);
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => 100, 'trackId' => 1])]);

        $driver->amount(1000)->purchase();

        $this->assertSame('https://example.com', $this->request()->getHeaderLine('Referer'));
    }

    public function testPurchaseOmitsTheRefererByDefault(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => 100, 'trackId' => 1])]);

        $driver->amount(1000)->purchase();

        $this->assertFalse($this->request()->hasHeader('Referer'));
    }

    public function testPurchaseFailsWhenTheGatewayReportsAnError(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => 102, 'message' => 'merchant not found'])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('merchantیافت نشد.');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWhenTheGatewayIsNotReachable(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['message' => 'service unavailable'], 503)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('service unavailable');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheTrackId(): void
    {
        $invoice = (new Invoice)->transactionId('1234567');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'1234567', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame([], $form->getInputs());
    }

    public function testPayRedirectsToTheDirectGatewayInDirectMode(): void
    {
        $invoice = (new Invoice)->transactionId('1234567');
        $form = $this->driver(['mode' => 'direct'], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'1234567/direct', $form->getAction());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['success' => 1, 'trackId' => 1234567]);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['result' => 100, 'refNumber' => 'ref-1', 'cardNumber' => '6037****1234']),
        ]);

        $receipt = $driver->verify();

        $this->assertSame('Zibal', $receipt->getDriver());
        $this->assertSame('ref-1', $receipt->getReferenceId());
        $this->assertSame('6037****1234', $receipt->getDetail('cardNumber'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);
        $this->assertSame(1234567, $this->requestJson()['trackId']);
    }

    public function testVerifySendsTheConfiguredReferer(): void
    {
        $this->fakeRequest(['success' => 1, 'trackId' => 1234567]);

        $driver = $this->driver(['referer' => 'https://example.com']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['result' => 100, 'refNumber' => 'ref-1']),
        ]);

        $driver->verify();

        $this->assertSame('https://example.com', $this->request()->getHeaderLine('Referer'));
    }

    public function testVerifyOmitsTheRefererByDefault(): void
    {
        $this->fakeRequest(['success' => 1, 'trackId' => 1234567]);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['result' => 100, 'refNumber' => 'ref-1']),
        ]);

        $driver->verify();

        $this->assertFalse($this->request()->hasHeader('Referer'));
    }

    public function testVerifyFailsWhenThePaymentWasNotSuccessful(): void
    {
        $this->fakeRequest(['success' => 0, 'status' => 3]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش توسط کاربر لغو شد.');

        $this->driver()->verify();
    }

    public function testVerifyReportsAnAlreadyVerifiedTransaction(): void
    {
        $this->fakeRequest(['success' => 1, 'trackId' => 1234567]);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => 201])]);

        $this->expectException(PreviouslyVerifiedException::class);
        $this->expectExceptionMessage('قبلا تایید شده');

        $driver->verify();
    }

    public function testVerifyFailsWhenTheGatewayReportsAnError(): void
    {
        $this->fakeRequest(['success' => 1, 'trackId' => 1234567]);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => 202])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('سفارش پرداخت نشده یا ناموفق بوده است.');

        $driver->verify();
    }
}
