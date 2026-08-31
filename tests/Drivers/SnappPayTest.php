<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\SnappPay\SnappPay;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;
use Shetabit\Multipay\Constants\IranCurrency;

/**
 * Like the digipay driver, snapp pay authenticates itself while it is being
 * constructed, so it is pointed at the stub http server.
 */
class SnappPayTest extends DriverTestCase
{
    private StubServer $server;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();
    }

    protected function driverName() : string
    {
        return 'snapppay';
    }

    protected function driverClass() : string
    {
        return SnappPay::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge(['apiPaymentUrl' => rtrim($this->server->url(), '/')], $overrides));
    }

    public function testItAuthenticatesWhileItIsBeingConstructed(): void
    {
        $this->server->queue([$this->stubResponse(['access_token' => 'oauth-token'])]);

        $this->driver(['client_id' => 'the-client', 'client_secret' => 'the-secret', 'username' => 'the-user']);

        $requests = $this->server->requests();

        $this->assertCount(1, $requests);
        $this->assertSame('/api/online/v1/oauth/token', $requests[0]['uri']);
        $this->assertSame(
            'Basic '.base64_encode('the-client:the-secret'),
            $requests[0]['headers']['authorization']
        );
        $this->assertStringContainsString('grant_type=password', $requests[0]['body']);
        $this->assertStringContainsString('username=the-user', $requests[0]['body']);
    }

    public function testItFailsWhenTheAuthenticationIsRefused(): void
    {
        $this->server->queue([$this->stubResponse(['error' => 'unauthorized'], 401)]);

        $this->expectException(PurchaseFailedException::class);

        $this->driver();
    }

    public function testPurchaseReturnsThePaymentTokenOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse([
                'successful' => true,
                'response' => [
                    'paymentToken' => 'payment-token-1',
                    'paymentPageUrl' => 'https://snapppay.ir/pay?token=payment-token-1',
                ],
            ]),
        ]);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);

        $transactionId = $driver
            ->detail([
                'mobile' => '09120000000',
                'cartList' => [['shippingAmount' => 100, 'totalAmount' => 1000, 'cartItems' => []]],
            ])
            ->amount(1000)
            ->purchase();

        $this->assertSame('payment-token-1', $transactionId);
        $this->assertSame('payment-token-1', $driver->getInvoice()->getTransactionId());

        $request = $this->server->requests()[1];
        $body = json_decode($request['body'], true);

        $this->assertSame('/api/online/payment/v1/token', $request['uri']);
        $this->assertSame('Bearer oauth-token', $request['headers']['authorization']);
        $this->assertSame(10000, $body['amount']);
        $this->assertSame('+989120000000', $body['mobile']);
        $this->assertSame('INSTALLMENT', $body['paymentMethodTypeDto']);
        $this->assertSame($this->settings()['callbackUrl'], $body['returnURL']);
        $this->assertSame(1000, $body['cartList'][0]['shippingAmount']);
    }

    public function testPurchaseSendsTheOptionalAmountsOfTheInvoice(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse([
                'successful' => true,
                'response' => ['paymentToken' => 'payment-token-1', 'paymentPageUrl' => 'https://snapppay.ir/pay'],
            ]),
        ]);

        $this->driver(['currency' => IranCurrency::TOMAN])
            ->detail([
                'mobile' => '09120000000',
                'cartList' => [['totalAmount' => 1000, 'cartItems' => []]],
                'discountAmount' => 50,
                'externalSourceAmount' => 20,
            ])
            ->amount(1000)
            ->purchase();

        $body = json_decode($this->server->requests()[1]['body'], true);

        $this->assertSame(500, $body['discountAmount']);
        $this->assertSame(200, $body['externalSourceAmount']);
    }

    public function testPurchaseNeedsACartList(): void
    {
        $this->server->queue([$this->stubResponse(['access_token' => 'oauth-token'])]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('"cartList" is required for this driver');

        $driver->detail('mobile', '09120000000')->amount(1000)->purchase();
    }

    public function testPurchaseFailsWithTheMessageOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse(['successful' => false, 'errorData' => ['message' => 'the amount is too low']]),
        ]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the amount is too low');

        $driver
            ->detail(['mobile' => '09120000000', 'cartList' => [['totalAmount' => 1000, 'cartItems' => []]]])
            ->amount(1000)
            ->purchase();
    }

    public function testPayRedirectsToThePaymentPageOfThePurchase(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse([
                'successful' => true,
                'response' => [
                    'paymentToken' => 'payment-token-1',
                    'paymentPageUrl' => 'https://snapppay.ir/pay?token=payment-token-1',
                ],
            ]),
        ]);

        $driver = $this->driver();
        $driver
            ->detail(['mobile' => '09120000000', 'cartList' => [['totalAmount' => 1000, 'cartItems' => []]]])
            ->amount(1000)
            ->purchase();

        $form = $driver->pay();

        $this->assertSame('https://snapppay.ir/pay?token=payment-token-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame(['token' => 'payment-token-1'], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse([
                'successful' => true,
                'response' => ['transactionId' => 'transaction-1', 'amount' => 10000],
            ]),
        ]);

        $driver = $this->driver([], (new Invoice)->transactionId('payment-token-1'));

        $receipt = $driver->verify();

        $this->assertSame('snapppay', $receipt->getDriver());
        $this->assertSame('transaction-1', $receipt->getReferenceId());
        $this->assertSame(10000, $receipt->getDetail('amount'));

        $request = $this->server->requests()[1];

        $this->assertSame('/api/online/payment/v1/verify', $request['uri']);
        $this->assertSame('payment-token-1', json_decode($request['body'], true)['paymentToken']);
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse(['successful' => false, 'errorData' => ['message' => 'the token is not valid']]),
        ]);

        $driver = $this->driver([], (new Invoice)->transactionId('payment-token-1'));

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the token is not valid');

        $driver->verify();
    }
}
