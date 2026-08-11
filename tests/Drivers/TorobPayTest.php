<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\TorobPay\TorobPay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;

/**
 * The driver fetches a jwt token while it is being constructed, so it is pointed
 * at the stub http server.
 */
class TorobPayTest extends DriverTestCase
{
    private StubServer $server;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();
    }

    protected function driverName() : string
    {
        return 'torobpay';
    }

    protected function driverClass() : string
    {
        return TorobPay::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge(['api_url' => rtrim($this->server->url(), '/')], $overrides));
    }

    public function testItFetchesAJwtTokenWhileItIsBeingConstructed(): void
    {
        $this->server->queue([$this->stubResponse(['access_token' => 'jwt-token'])]);

        $this->driver(['client_id' => 'the-client', 'client_secret' => 'the-secret']);

        $requests = $this->server->requests();

        $this->assertCount(1, $requests);
        $this->assertSame('/api/online/v1/oauth/token', $requests[0]['uri']);
        $this->assertSame(
            'Basic '.base64_encode('the-client:the-secret'),
            $requests[0]['headers']['authorization']
        );
    }

    public function testPurchaseReturnsThePaymentTokenOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'jwt-token']),
            $this->stubResponse([
                'response' => [
                    'paymentToken' => 'payment-token-1',
                    'paymentPageUrl' => 'https://cpg.torobpay.com/pay',
                ],
            ]),
        ]);

        $driver = $this->driver(['currency' => 'T']);

        $transactionId = $driver->detail('mobile', '09120000000')->amount(1000)->purchase();

        $this->assertSame('payment-token-1', $transactionId);
        $this->assertSame('payment-token-1', $driver->getInvoice()->getTransactionId());

        $request = $this->server->requests()[1];
        $body = json_decode($request['body'], true);

        $this->assertSame('/api/online/payment/v1/token', $request['uri']);
        $this->assertSame('Bearer jwt-token', $request['headers']['authorization']);
        $this->assertSame(10000, $body['amount']);
        $this->assertSame('ONLINE_CREDIT', $body['paymentMethodTypeDto']);
        $this->assertSame('09120000000', $body['mobile']);
        $this->assertSame($this->settings()['callbackUrl'], $body['returnURL']);
    }

    public function testPurchaseSendsTheCartOfTheInvoice(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'jwt-token']),
            $this->stubResponse([
                'response' => ['paymentToken' => 'payment-token-1', 'paymentPageUrl' => 'https://cpg.torobpay.com'],
            ]),
        ]);

        $this->driver()
            ->detail([
                'tax_amount' => 90,
                'shipping_amount' => 50,
                'is_tax_included' => true,
                'cartItems' => [
                    ['id' => 1, 'name' => 'a product', 'count' => 2, 'amount' => 500, 'category' => 'books'],
                ],
            ])
            ->amount(1000)
            ->purchase();

        $cart = json_decode($this->server->requests()[1]['body'], true)['cartList'][0];

        $this->assertSame(1000, $cart['totalAmount']);
        $this->assertSame(90, $cart['tax_amount']);
        $this->assertSame(50, $cart['shipping_amount']);
        $this->assertTrue($cart['is_tax_included']);
        $this->assertSame(
            ['id' => '1', 'name' => 'a product', 'count' => 2, 'amount' => 500, 'category' => 'books'],
            $cart['cartItems'][0]
        );
    }

    public function testPurchaseFailsWithTheMessageOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'jwt-token']),
            $this->stubResponse(['result' => ['message' => 'the amount is not valid']], 400),
        ]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the amount is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToThePaymentPageOfThePurchase(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'jwt-token']),
            $this->stubResponse([
                'response' => [
                    'paymentToken' => 'payment-token-1',
                    'paymentPageUrl' => 'https://cpg.torobpay.com/pay',
                ],
            ]),
        ]);

        $driver = $this->driver();
        $driver->amount(1000)->purchase();

        $form = $driver->pay();

        $this->assertSame('https://cpg.torobpay.com/pay', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame(['payment_token' => 'payment-token-1'], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'jwt-token']),
            $this->stubResponse([
                'successful' => true,
                'response' => ['transactionId' => 'transaction-1'],
            ]),
        ]);

        $driver = $this->driver([], (new Invoice)->transactionId('payment-token-1'));

        $receipt = $driver->verify();

        $this->assertSame('torobpay', $receipt->getDriver());
        $this->assertSame('transaction-1', $receipt->getReferenceId());
        $this->assertTrue($receipt->getDetail('successful'));

        $request = $this->server->requests()[1];

        $this->assertSame('/api/online/payment/v1/verify', $request['uri']);
        $this->assertSame('payment-token-1', json_decode($request['body'], true)['paymentToken']);
    }

    public function testVerifyFallsBackToTheTransactionIdOfTheCallback(): void
    {
        $this->fakeRequest(['transactionId' => 'payment-token-from-callback']);

        $this->server->queue([
            $this->stubResponse(['access_token' => 'jwt-token']),
            $this->stubResponse(['successful' => true, 'response' => ['transactionId' => 'transaction-1']]),
        ]);

        $this->driver()->verify();

        $body = json_decode($this->server->requests()[1]['body'], true);

        $this->assertSame('payment-token-from-callback', $body['paymentToken']);
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'jwt-token']),
            $this->stubResponse(['successful' => false, 'error' => ['message' => 'the token is not valid']]),
        ]);

        $driver = $this->driver([], (new Invoice)->transactionId('payment-token-1'));

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('the token is not valid');

        $driver->verify();
    }
}
