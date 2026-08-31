<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Jibit\Jibit;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;
use Shetabit\Multipay\Constants\IranCurrency;

/**
 * The driver talks to the gateway with cURL through its own client, so it is
 * pointed at the stub http server by overwriting the base url in its settings. Its
 * tokens are cached on disk, so the tests give it a directory of their own.
 */
class JibitTest extends DriverTestCase
{
    private StubServer $server;

    private string $tokenStoragePath;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();
        $this->tokenStoragePath = $this->makeTemporaryDirectory('jibit');
    }

    protected function tearDown() : void
    {
        $this->removeDirectory($this->tokenStoragePath);

        parent::tearDown();
    }

    protected function driverName() : string
    {
        return 'jibit';
    }

    protected function driverClass() : string
    {
        return Jibit::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'apiPaymentUrl' => rtrim($this->server->url(), '/'),
            'apiKey' => 'the-api-key',
            'apiSecret' => 'the-api-secret',
            'tokenStoragePath' => $this->tokenStoragePath,
        ], $overrides));
    }

    public function testPurchaseAuthenticatesAndReturnsThePurchaseId(): void
    {
        $this->server->queue([
            $this->stubResponse(['accessToken' => 'the-access-token', 'refreshToken' => 'the-refresh-token']),
            $this->stubResponse([
                'purchaseId' => 'purchase-1',
                'clientReferenceNumber' => 'reference-1',
                'pspSwitchingUrl' => 'https://napi.jibit.ir/pay/purchase-1',
            ]),
        ]);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);

        $transactionId = $driver->detail('mobile', '09120000000')->amount(1000)->purchase();

        $this->assertSame('purchase-1', $transactionId);
        $this->assertSame('purchase-1', $driver->getInvoice()->getTransactionId());
        $this->assertSame('reference-1', $driver->getInvoice()->getDetail('referenceNumber'));

        $requests = $this->server->requests();

        $this->assertSame('/tokens', $requests[0]['uri']);
        $this->assertSame('/purchases', $requests[1]['uri']);
        $this->assertSame('Bearer the-access-token', $requests[1]['headers']['authorization']);

        $body = json_decode($requests[1]['body'], true);

        $this->assertSame(10000, $body['amount']);
        $this->assertSame('09120000000', $body['userIdentifier']);
        $this->assertSame('IRR', $body['currency']);
        $this->assertSame($this->settings()['callbackUrl'], $body['callbackUrl']);
    }

    public function testPurchaseFailsWithTheErrorCodesOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['accessToken' => 'the-access-token', 'refreshToken' => 'the-refresh-token']),
            $this->stubResponse(['errors' => [['code' => 'purchase.amount_is_too_low']]]),
        ]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('purchase.amount_is_too_low');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWhenTheAuthenticationIsRefused(): void
    {
        $this->server->queue([$this->stubResponse(['errors' => [['code' => 'security.auth_failed']]])]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheSwitchingUrlOfThePurchase(): void
    {
        $this->server->queue([
            $this->stubResponse(['accessToken' => 'the-access-token', 'refreshToken' => 'the-refresh-token']),
            $this->stubResponse([
                'purchaseId' => 'purchase-1',
                'clientReferenceNumber' => 'reference-1',
                'pspSwitchingUrl' => 'https://napi.jibit.ir/pay/purchase-1',
            ]),
        ]);

        $driver = $this->driver();
        $driver->amount(1000)->purchase();

        $form = $driver->pay();

        $this->assertSame('https://napi.jibit.ir/pay/purchase-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->server->queue([
            $this->stubResponse(['accessToken' => 'the-access-token', 'refreshToken' => 'the-refresh-token']),
            $this->stubResponse(['status' => 'SUCCESSFUL']),
            $this->stubResponse(['elements' => ['payerCardNumber' => '6037****1234']]),
        ]);

        $driver = $this->driver([], (new Invoice)->transactionId('purchase-1'));

        $receipt = $driver->verify();

        $this->assertSame('jibit', $receipt->getDriver());
        $this->assertSame('purchase-1', $receipt->getReferenceId());
        $this->assertSame('6037****1234', $receipt->getDetail('payerCard'));

        $requests = $this->server->requests();

        $this->assertSame('/purchases/purchase-1/verify', $requests[1]['uri']);
        $this->assertSame('/purchases?purchaseId=purchase-1', $requests[2]['uri']);
    }

    public function testVerifyFailsWhenThePaymentWasNotSuccessful(): void
    {
        $this->server->queue([
            $this->stubResponse(['accessToken' => 'the-access-token', 'refreshToken' => 'the-refresh-token']),
            $this->stubResponse(['status' => 'FAILED']),
        ]);

        $driver = $this->driver([], (new Invoice)->transactionId('purchase-1'));

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('Payment encountered an issue.');

        $driver->verify();
    }
}
