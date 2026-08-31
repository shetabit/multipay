<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Parspal\Parspal;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class ParspalTest extends DriverTestCase
{
    /**
     * The driver caches the payment link on disk, so the tests give it a
     * directory of their own and clean it up afterwards.
     */
    private string $cachePath;

    protected function setUp() : void
    {
        parent::setUp();

        $this->cachePath = $this->makeTemporaryDirectory('parspal');
    }

    protected function tearDown() : void
    {
        $this->removeDirectory($this->cachePath);

        parent::tearDown();
    }

    protected function driverName() : string
    {
        return 'parspal';
    }

    protected function driverClass() : string
    {
        return Parspal::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge(['cachePath' => $this->cachePath], $overrides));
    }

    public function testPurchaseReturnsThePaymentIdOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'parspal-api-key']);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'status' => 'ACCEPTED',
                'payment_id' => 'payment-1',
                'link' => 'https://api.parspal.com/pay/payment-1',
            ]),
        ]);

        $this->assertSame('payment-1', $driver->amount(1000)->purchase());
        $this->assertSame('payment-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('parspal-api-key', $this->request()->getHeaderLine('ApiKey'));
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 'ACCEPTED', 'payment_id' => 'payment-1', 'link' => 'https://pay']),
        ]);

        $driver->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame(10000, $body['amount']);
        $this->assertSame($this->settings()['callbackUrl'], $body['return_url']);
        $this->assertSame($driver->getInvoice()->getUuid(), $body['order_id']);
    }

    public function testPurchaseSendsThePayerOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 'ACCEPTED', 'payment_id' => 'payment-1', 'link' => 'https://pay']),
        ]);

        $driver->detail([
            'name' => 'john doe',
            'mobile' => '09120000000',
            'email' => 'john@example.com',
            'description' => 'a description',
        ])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame('a description', $body['description']);
        $this->assertSame('john doe', $body['payer']['name']);
        $this->assertSame('09120000000', $body['payer']['mobile']);
        $this->assertSame('john@example.com', $body['payer']['email']);
    }

    public function testPurchaseUsesTheSandboxInSandboxMode(): void
    {
        $driver = $this->driver(['sandbox' => true]);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 'ACCEPTED', 'payment_id' => 'payment-1', 'link' => 'https://pay']),
        ]);

        $driver->amount(1000)->purchase();

        $this->assertRequestedUrl($this->settings()['sandboxApiPurchaseUrl']);
    }

    public function testPurchaseFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 'REJECTED', 'message' => 'the api key is not valid', 'error_code' => 11]),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the api key is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToThePaymentLinkOfThePurchase(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'status' => 'ACCEPTED',
                'payment_id' => 'payment-1',
                'link' => 'https://api.parspal.com/pay/payment-1',
            ]),
        ]);

        $driver->amount(1000)->purchase();

        $form = $driver->pay();

        $this->assertSame('https://api.parspal.com/pay/payment-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['status' => 100, 'receipt_number' => 'receipt-1']);

        $driver = $this->driver(['merchantId' => 'parspal-api-key', 'currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'status' => 'SUCCESSFUL',
                'id' => 'payment-1',
                'paid_amount' => 10000,
                'message' => 'the payment was successful',
            ]),
        ]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('parspal', $receipt->getDriver());
        $this->assertSame('receipt-1', $receipt->getReferenceId());
        $this->assertSame('payment-1', $receipt->getDetail('id'));
        $this->assertSame(10000, $receipt->getDetail('paid_amount'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $body = $this->requestJson();

        $this->assertSame(10000, $body['amount']);
        $this->assertSame('receipt-1', $body['receipt_number']);
    }

    public function testVerifyFailsWhenTheUserCanceledThePayment(): void
    {
        $this->fakeRequest(['status' => 99]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('انصراف کاربر از پرداخت');

        $this->driver()->amount(1000)->verify();
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $this->fakeRequest(['status' => 100, 'receipt_number' => 'receipt-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['status' => 'FAILED', 'message' => 'the payment was not successful']),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('the payment was not successful');

        $driver->amount(1000)->verify();
    }
}
