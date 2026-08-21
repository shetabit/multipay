<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Digify\Digify;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class DigifyTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'digify';
    }

    protected function driverClass() : string
    {
        return Digify::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'baseUrl' => 'https://api.digify.test',
            'apiKey' => 'digify-api-key',
        ], $overrides));
    }

    public function testPurchaseReturnsTheOrderUuidOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'order_uuid' => 'order-1',
                'order_start_url' => 'https://digify.test/pay/order-1',
            ]),
        ]);

        $this->assertSame('order-1', $driver->amount(1000)->purchase());
        $this->assertSame('order-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl('https://api.digify.test/orders/api/v1/create-order/');
        $this->assertSame('Api-Key digify-api-key', $this->request()->getHeaderLine('Authorization'));
    }

    public function testPurchaseSendsTheAmountsOfTheInvoice(): void
    {
        $invoice = new Invoice;
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['order_uuid' => 'order-1', 'order_start_url' => 'https://digify.test/pay']),
        ]);

        $driver->detail('discountAmount', 200)->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame($invoice->getUuid(), $body['merchant_unique_id']);
        $this->assertSame($invoice->getUuid(), $body['merchant_order_id']);
        $this->assertSame(1200, $body['main_amount']);
        $this->assertSame(200, $body['discount_amount']);
        $this->assertSame(1000, $body['final_amount']);
        $this->assertSame($this->settings()['callbackUrl'], $body['callback_url']);
        $this->assertGreaterThan(time(), $body['reservation_expired_at']);
    }

    public function testPurchaseSendsTheItemsOfTheInvoice(): void
    {
        $items = [['name' => 'a product', 'count' => 1, 'amount' => 1000]];

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['order_uuid' => 'order-1', 'order_start_url' => 'https://digify.test/pay']),
        ]);

        $driver->detail('items', $items)->amount(1000)->purchase();

        $this->assertSame($items, $this->requestJson()['items']);
    }

    public function testPurchaseFailsWithTheErrorOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['non_field_errors' => ['the amount is not valid']], 400),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the amount is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWithAGenericMessageWhenTheGatewayIsSilent(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse([], 500)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('خطا در ارتباط با سرویس دیجیفای');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheOrderStartUrlOfThePurchase(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'order_uuid' => 'order-1',
                'order_start_url' => 'https://digify.test/pay/order-1',
            ]),
        ]);

        $driver->amount(1000)->purchase();

        $form = $driver->pay();

        $this->assertSame('https://digify.test/pay/order-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testPayNeedsAPurchaseFirst(): void
    {
        $this->expectException(InvalidPaymentException::class);

        $this->driver()->pay();
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['order_uuid' => 'order-1', 'merchant_order_id' => 'merchant-order-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'is_paid' => true,
                'status' => 9,
                'reference_code' => 'reference-1',
            ]),
        ]);

        $receipt = $driver->verify();

        $this->assertSame('digify', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame(9, $receipt->getDetail('status'));
        $this->assertRequestedUrl('https://api.digify.test/orders/api/v1/manager/order-1/verify/');
        $this->assertSame('merchant-order-1', $this->requestJson()['merchant_unique_id']);
    }

    public function testVerifyFallsBackToTheOrderUuidAsReferenceId(): void
    {
        $this->fakeRequest(['order_uuid' => 'order-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['is_paid' => true, 'status' => 9])]);

        $this->assertSame('order-1', $driver->verify()->getReferenceId());
    }

    public function testVerifyNeedsAnOrderUuid(): void
    {
        $this->fakeRequest([]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('order_uuid ارسال نشده است');

        $this->driver()->verify();
    }

    public function testVerifyFailsWhenTheOrderWasNotPaid(): void
    {
        $this->fakeRequest(['order_uuid' => 'order-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['is_paid' => false, 'status' => 3, 'status_display' => 'the order was canceled']),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('the order was canceled');

        $driver->verify();
    }

    public function testVerifyFailsWithTheErrorOfTheGateway(): void
    {
        $this->fakeRequest(['order_uuid' => 'order-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['detail' => 'the order was not found'], 404)]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('the order was not found');

        $driver->verify();
    }
}
