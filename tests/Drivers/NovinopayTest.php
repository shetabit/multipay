<?php

namespace Shetabit\Multipay\Tests\Drivers;

use GuzzleHttp\Psr7\Response;
use Shetabit\Multipay\Constants\IranCurrency;
use Shetabit\Multipay\Drivers\Novinopay\Novinopay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class NovinopayTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'novinopay';
    }

    protected function driverClass() : string
    {
        return Novinopay::class;
    }

    public function testPurchaseReturnsTheAuthorityOfTheTransaction(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->purchaseResponse()]);

        $this->assertSame('the-authority', $driver->amount(1000)->purchase());
        $this->assertSame('the-authority', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('POST', $this->request()->getMethod());
    }

    public function testPurchaseSendsTheTransactionToTheGateway(): void
    {
        $invoice = new Invoice();
        $driver = $this->driver(['merchantId' => 'the-merchant', 'currency' => IranCurrency::TOMAN], $invoice);
        $this->fakeHttp($driver, [$this->purchaseResponse()]);

        $driver->amount(1000)->purchase();

        $this->assertSame(
            [
                'merchant_id' => 'the-merchant',
                'amount' => 10000,
                'callback_url' => $this->settings()['callbackUrl'],
                'invoice_id' => (string) $invoice->getUuid(),
                'description' => $this->settings()['description'],
            ],
            $this->requestJson()
        );
    }

    public function testPurchaseKeepsTheAmountWhenTheCurrencyIsRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->purchaseResponse()]);

        $driver->amount(10000)->purchase();

        $this->assertSame(10000, $this->requestJson()['amount']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->purchaseResponse()]);

        $driver->detail([
            'orderId' => 'order-1',
            'description' => 'a description',
            'mobile' => '09120000000',
            'email' => 'buyer@example.com',
            'name' => 'the buyer',
            'cardPan' => '6037990000000000',
        ])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame('order-1', $body['invoice_id']);
        $this->assertSame('a description', $body['description']);
        $this->assertSame('09120000000', $body['mobile']);
        $this->assertSame('buyer@example.com', $body['email']);
        $this->assertSame('the buyer', $body['name']);
        $this->assertSame('6037990000000000', $body['card_pan']);
    }

    public function testPurchaseTakesTheMobileFromThePhoneOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->purchaseResponse()]);

        $driver->detail('phone', '09120000000')->amount(1000)->purchase();

        $this->assertSame('09120000000', $this->requestJson()['mobile']);
    }

    public function testPurchaseLeavesOutTheDetailsTheInvoiceDoesNotHave(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->purchaseResponse()]);

        $driver->amount(1000)->purchase();

        foreach (['mobile', 'email', 'name', 'card_pan'] as $field) {
            $this->assertArrayNotHasKey($field, $this->requestJson());
        }
    }

    public function testPurchaseFailsWithTheMeaningOfTheStatus(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => '-101', 'message' => 'error', 'data' => null, 'errors' => null]),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('کد پذیرنده وارد شده نامعتبر یا غیرفعال است.');
        $this->expectExceptionCode(-101);

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWithTheMessageOfTheGatewayForAnUnknownStatus(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => '-999', 'message' => 'پیغام درگاه', 'data' => null, 'errors' => null]),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('پیغام درگاه');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWhenASuccessfulAnswerHasNoTransaction(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => '100', 'message' => 'done', 'data' => null, 'errors' => null]),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('درگاه بدون شناسه تراکنش و آدرس پرداخت پاسخ داد.');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWhenTheGatewayCanNotBeUnderstood(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->response('Bad Gateway', 502)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('خطای ناشناخته‌ای رخ داده است.');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToThePaymentPageOfTheTransaction(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->purchaseResponse()]);

        $driver->amount(1000)->purchase();
        $form = $driver->pay();

        $this->assertSame('https://ipg.novinopay.com/StartPay/the-authority', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testPayFailsBeforeTheTransactionIsPurchased(): void
    {
        $this->expectException(InvalidPaymentException::class);

        $this->driver()->pay();
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['PaymentStatus' => 'OK', 'Authority' => 'the-authority']);

        $invoice = (new Invoice())->amount(1000)->transactionId('the-authority');
        $driver = $this->driver(['merchantId' => 'the-merchant', 'currency' => IranCurrency::TOMAN], $invoice);
        $this->fakeHttp($driver, [$this->verificationResponse()]);

        $receipt = $driver->verify();

        $this->assertSame('novinopay', $receipt->getDriver());
        $this->assertSame('223003535268', $receipt->getReferenceId());
        $this->assertSame('504172******0613', $receipt->getDetail('card_pan'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);
        $this->assertSame(
            ['merchant_id' => 'the-merchant', 'amount' => 10000, 'authority' => 'the-authority'],
            $this->requestJson()
        );
    }

    public function testVerifyTakesTheAuthorityFromTheCallbackWhenTheInvoiceHasNone(): void
    {
        $this->fakeRequest(['PaymentStatus' => 'OK', 'Authority' => 'the-callback-authority']);

        $driver = $this->driver([], (new Invoice())->amount(1000));
        $this->fakeHttp($driver, [$this->verificationResponse()]);

        $driver->verify();

        $this->assertSame('the-callback-authority', $this->requestJson()['authority']);
    }

    public function testVerifyFailsWhenThePaymentWasNotSuccessful(): void
    {
        $this->fakeRequest(['PaymentStatus' => 'NOK', 'Authority' => 'the-authority']);

        $driver = $this->driver([], (new Invoice())->amount(1000)->transactionId('the-authority'));
        $this->fakeHttp($driver, []);

        try {
            $this->expectException(InvalidPaymentException::class);

            $driver->verify();
        } finally {
            $this->assertSame(0, $this->requestCount());
        }
    }

    public function testVerifyFailsWhenASuccessfulAnswerHasNoTrackingNumber(): void
    {
        $this->fakeRequest(['PaymentStatus' => 'OK', 'Authority' => 'the-authority']);

        $driver = $this->driver([], (new Invoice())->amount(1000)->transactionId('the-authority'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => '100', 'message' => 'عمليات موفق', 'data' => null, 'errors' => null]),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('درگاه بدون شماره پیگیری تراکنش پاسخ داد.');

        $driver->verify();
    }

    public function testVerifyReportsAnAlreadyVerifiedTransaction(): void
    {
        $this->fakeRequest(['PaymentStatus' => 'OK', 'Authority' => 'the-authority']);

        $driver = $this->driver([], (new Invoice())->amount(1000)->transactionId('the-authority'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => '101', 'message' => 'verified before', 'data' => null, 'errors' => null]),
        ]);

        $this->expectException(PreviouslyVerifiedException::class);
        $this->expectExceptionMessage('عملیات پرداخت موفق بوده و قبلا تاییدیه تراکنش انجام شده است.');

        $driver->verify();
    }

    public function testVerifyFailsWhenTheGatewayRefusesTheVerification(): void
    {
        $this->fakeRequest(['PaymentStatus' => 'OK', 'Authority' => 'the-authority']);

        $driver = $this->driver([], (new Invoice())->amount(1000)->transactionId('the-authority'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => '-110', 'message' => 'error', 'data' => null, 'errors' => null]),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('مبلغ ارسال شده برای تاییدیه با مبلغ تراکنش یکسان نیست.');
        $this->expectExceptionCode(-110);

        $driver->verify();
    }

    private function purchaseResponse(): Response
    {
        return $this->jsonResponse([
            'status' => '100',
            'message' => 'عملیات موفقیت آمیز',
            'data' => [
                'wage' => 0,
                'wage_payer' => 'merchant',
                'authority' => 'the-authority',
                'trans_id' => 337811,
                'payment_url' => 'https://ipg.novinopay.com/StartPay/the-authority',
            ],
            'errors' => null,
        ]);
    }

    private function verificationResponse(): Response
    {
        return $this->jsonResponse([
            'status' => '100',
            'message' => 'عمليات موفق',
            'data' => [
                'trans_id' => 337811,
                'ref_id' => '223003535268',
                'authority' => 'the-authority',
                'card_pan' => '504172******0613',
                'amount' => 10000,
                'invoice_id' => null,
                'buyer_ip' => '5.113.185.222',
                'payment_time' => 1663376322,
            ],
            'errors' => null,
        ]);
    }
}
