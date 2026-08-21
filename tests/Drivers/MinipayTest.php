<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Minipay\Minipay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class MinipayTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'minipay';
    }

    protected function driverClass() : string
    {
        return Minipay::class;
    }

    public function testPurchaseReturnsTheAuthorityOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'minipay-merchant']);
        $this->fakeHttp($driver, [$this->jsonResponse(['data' => ['authority' => 'authority-1']])]);

        $this->assertSame('authority-1', $driver->amount(1000)->purchase());
        $this->assertSame('authority-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('minipay-merchant', $this->requestJson()['merchant_id']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => 'T']);
        $this->fakeHttp($driver, [$this->jsonResponse(['data' => ['authority' => 'authority-1']])]);

        $driver->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame(10000, $body['amount']);
        $this->assertSame($this->settings()['callbackUrl'], $body['callback_url']);
    }

    public function testPurchaseFallsBackToTheDescriptionOfTheSettings(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['data' => ['authority' => 'authority-1']])]);

        $driver->amount(1000)->purchase();

        $this->assertSame($this->settings()['description'], $this->requestJson()['description']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoiceAsMetadata(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['data' => ['authority' => 'authority-1']])]);

        $driver->detail(['description' => 'a description', 'orderId' => 'order-1'])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame('a description', $body['description']);
        $this->assertSame(['description' => 'a description', 'orderId' => 'order-1'], $body['metadata']);
    }

    public function testPurchaseFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['messages' => [['text' => 'the merchant is not valid']]], 422),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the merchant is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWithAGenericMessageWhenTheGatewayIsSilent(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse([], 500)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('خطا در هنگام درخواست برای پرداخت رخ داده است.');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheAuthority(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'?authority=authority-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $invoice = (new Invoice)->transactionId('authority-1');
        $driver = $this->driver(['merchantId' => 'minipay-merchant', 'currency' => 'T'], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'data' => [
                    'id' => 'reference-1',
                    'installments_count' => 3,
                    'price' => 10000,
                    'credit_price' => 9000,
                ],
            ]),
        ]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('minipay', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame('reference-1', $receipt->getDetail('ref_id'));
        $this->assertSame(3, $receipt->getDetail('installments_count'));
        $this->assertSame(10000, $receipt->getDetail('price'));
        $this->assertSame(9000, $receipt->getDetail('credit_price'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $body = $this->requestJson();

        $this->assertSame('authority-1', $body['authority']);
        $this->assertSame(10000, $body['amount']);
    }

    public function testVerifyFallsBackToTheAuthorityOfTheCallback(): void
    {
        $this->fakeRequest(['Authority' => 'authority-from-callback']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['data' => ['id' => 'reference-1']])]);

        $driver->amount(1000)->verify();

        $this->assertSame('authority-from-callback', $this->requestJson()['authority']);
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('authority-1'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['messages' => [['text' => 'the payment was not completed']]], 400),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('the payment was not completed');

        $driver->amount(1000)->verify();
    }

    public function testVerifyFailsWithAGenericMessageWhenTheGatewayIsSilent(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('authority-1'));
        $this->fakeHttp($driver, [$this->jsonResponse([], 500)]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش تایید نشد.');

        $driver->amount(1000)->verify();
    }
}
