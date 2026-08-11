<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Digipay\Digipay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;

/**
 * The driver authenticates itself while it is being constructed, so its client can
 * not be replaced afterwards. The gateway url is pointed at the stub http server
 * instead.
 */
class DigipayTest extends DriverTestCase
{
    private StubServer $server;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();
    }

    protected function driverName() : string
    {
        return 'digipay';
    }

    protected function driverClass() : string
    {
        return Digipay::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge(['apiPaymentUrl' => rtrim($this->server->url(), '/')], $overrides));
    }

    public function testItAuthenticatesWhileItIsBeingConstructed(): void
    {
        $this->server->queue([$this->stubResponse(['access_token' => 'oauth-token'])]);

        $this->driver(['client_id' => 'the-client', 'client_secret' => 'the-secret']);

        $requests = $this->server->requests();

        $this->assertCount(1, $requests);
        $this->assertSame('/digipay/api/oauth/token', $requests[0]['uri']);
        $this->assertSame(
            'Basic '.base64_encode('the-client:the-secret'),
            $requests[0]['headers']['authorization']
        );
    }

    public function testItFailsWhenTheCredentialsAreWrong(): void
    {
        $this->server->queue([$this->stubResponse(['error' => 'unauthorized'], 401)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('خطا نام کاربری یا رمز عبور شما اشتباه می‌باشد.');

        $this->driver();
    }

    public function testItFailsWhenTheAuthenticationBreaks(): void
    {
        $this->server->queue([$this->stubResponse(['error' => 'server error'], 500)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('خطا در هنگام احراز هویت.');

        $this->driver();
    }

    public function testPurchaseReturnsTheTicketOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse(['ticket' => 'ticket-1', 'redirectUrl' => 'https://mydigipay.com/pay/ticket-1']),
        ]);

        $driver = $this->driver();

        $transactionId = $driver->detail('mobile', '09120000000')->amount(1000)->purchase();

        $this->assertSame('ticket-1', $transactionId);
        $this->assertSame('ticket-1', $driver->getInvoice()->getTransactionId());

        $request = $this->server->requests()[1];
        $body = json_decode($request['body'], true);

        $this->assertSame('/digipay/api/tickets/business?type=11', $request['uri']);
        $this->assertSame('Bearer oauth-token', $request['headers']['authorization']);
        $this->assertSame('WEB', $request['headers']['agent']);
        $this->assertSame(1000, $body['amount']);
        $this->assertSame('09120000000', $body['cellNumber']);
        $this->assertSame($this->settings()['callbackUrl'], $body['callbackUrl']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse(['ticket' => 'ticket-1', 'redirectUrl' => 'https://mydigipay.com/pay']),
        ]);

        $this->driver(['currency' => 'T'])->detail('mobile', '09120000000')->amount(1000)->purchase();

        $body = json_decode($this->server->requests()[1]['body'], true);

        $this->assertSame(10000, $body['amount']);
    }

    public function testPurchaseSendsTheOptionalDetailsOfTheInvoice(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse(['ticket' => 'ticket-1', 'redirectUrl' => 'https://mydigipay.com/pay']),
        ]);

        $basket = [['name' => 'a product', 'amount' => 1000]];

        $this->driver()
            ->detail([
                'mobile' => '09120000000',
                'digipayType' => 0,
                'agent' => 'MOBILE',
                'basketDetailsDto' => $basket,
                'preferredGateway' => 'SEP',
            ])
            ->amount(1000)
            ->purchase();

        $request = $this->server->requests()[1];
        $body = json_decode($request['body'], true);

        $this->assertSame('/digipay/api/tickets/business?type=0', $request['uri']);
        $this->assertSame('MOBILE', $request['headers']['agent']);
        $this->assertSame($basket, $body['basketDetailsDto']);
        $this->assertSame('SEP', $body['preferredGateway']);
    }

    public function testPurchaseFailsWithTheMessageOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse(['result' => ['message' => 'the amount is not valid']], 400),
        ]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the amount is not valid');

        $driver->detail('mobile', '09120000000')->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheUrlOfThePurchase(): void
    {
        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse(['ticket' => 'ticket-1', 'redirectUrl' => 'https://mydigipay.com/pay/ticket-1']),
        ]);

        $driver = $this->driver();
        $driver->detail('mobile', '09120000000')->amount(1000)->purchase();

        $form = $driver->pay();

        $this->assertSame('https://mydigipay.com/pay/ticket-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame([], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['type' => 11, 'trackingCode' => 'tracking-1']);

        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse(['trackingCode' => 'tracking-1', 'paymentMethod' => 'IPG']),
        ]);

        $receipt = $this->driver()->verify();

        $this->assertSame('digipay', $receipt->getDriver());
        $this->assertSame('tracking-1', $receipt->getReferenceId());
        $this->assertSame('IPG', $receipt->getDetail('paymentMethod'));
        $this->assertSame(
            '/digipay/api/purchases/verify/tracking-1?type=11',
            $this->server->requests()[1]['uri']
        );
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $this->fakeRequest(['type' => 11, 'trackingCode' => 'tracking-1']);

        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse(['result' => ['message' => 'the ticket was not paid']], 400),
        ]);

        $driver = $this->driver();

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('the ticket was not paid');

        $driver->verify();
    }

    public function testVerifyFailsWithAGenericMessageWhenTheGatewayIsSilent(): void
    {
        $this->fakeRequest(['type' => 11, 'trackingCode' => 'tracking-1']);

        $this->server->queue([
            $this->stubResponse(['access_token' => 'oauth-token']),
            $this->stubResponse([], 500),
        ]);

        $driver = $this->driver();

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش تایید نشد');

        $driver->verify();
    }
}
