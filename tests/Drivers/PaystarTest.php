<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Paystar\Paystar;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class PaystarTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'paystar';
    }

    protected function driverClass() : string
    {
        return Paystar::class;
    }

    public function testPurchaseReturnsTheReferenceNumberOfTheGateway(): void
    {
        $driver = $this->driver(['gatewayId' => 'paystar-gateway', 'signKey' => 'sign-key']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 1, 'data' => ['ref_num' => 'ref-1', 'token' => 'token-1']]),
        ]);

        $this->assertSame('ref-1', $driver->amount(1000)->purchase());
        $this->assertSame('ref-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('Bearer paystar-gateway', $this->request()->getHeaderLine('Authorization'));
    }

    public function testPurchaseSendsTheAmountInRialAndSignsIt(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN, 'signKey' => 'sign-key']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 1, 'data' => ['ref_num' => 'ref-1', 'token' => 'token-1']]),
        ]);

        $driver->amount(1000)->purchase();

        $body = $this->requestJson();
        $callback = $this->settings()['callbackUrl'];

        $this->assertSame(10000, $body['amount']);
        $this->assertSame($callback, $body['callback']);
        $this->assertSame(
            hash_hmac('SHA512', '10000#'.$body['order_id'].'#'.$callback, 'sign-key'),
            $body['sign']
        );
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 1, 'data' => ['ref_num' => 'ref-1', 'token' => 'token-1']]),
        ]);

        $driver->detail([
            'email' => 'john@example.com',
            'mobile' => '09120000000',
            'description' => 'a description',
        ])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame('john@example.com', $body['mail']);
        $this->assertSame('09120000000', $body['phone']);
        $this->assertSame('a description', $body['description']);
    }

    public function testPurchaseFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => -2])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('درگاه فعال نیست');

        $driver->amount(1000)->purchase();
    }

    public function testPayPostsTheTokenToTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 1, 'data' => ['ref_num' => 'ref-1', 'token' => 'token-1']]),
        ]);

        $driver->amount(1000)->purchase();

        $form = $driver->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['token' => 'token-1'], $form->getInputs());
    }

    public function testVerifyReturnsAReceiptWithTheReferenceNumber(): void
    {
        $this->fakeRequest([
            'ref_num' => 'ref-1',
            'card_number' => '6037****1234',
            'tracking_code' => 'tracking-1',
        ]);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN, 'signKey' => 'sign-key']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1])]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('paystar', $receipt->getDriver());
        $this->assertSame('ref-1', $receipt->getReferenceId());
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $body = $this->requestJson();

        $this->assertSame(10000, $body['amount']);
        $this->assertSame('tracking-1', $body['tracking_code']);
        $this->assertSame(
            hash_hmac('SHA512', '10000#ref-1#6037****1234#tracking-1', 'sign-key'),
            $body['sign']
        );
    }

    public function testVerifyFailsWhenTheCallbackHasNoTrackingCode(): void
    {
        $this->fakeRequest(['ref_num' => 'ref-1']);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('درخواست نامعتبر (خطا در پارامترهای ورودی)');

        $this->driver()->amount(1000)->verify();
    }

    public function testVerifyFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $this->fakeRequest(['ref_num' => 'ref-1', 'tracking_code' => 'tracking-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => -6])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش قبلا وریفای شده است');

        $driver->amount(1000)->verify();
    }
}
