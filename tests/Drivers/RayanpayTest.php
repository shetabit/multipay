<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Rayanpay\Rayanpay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;

/**
 * The driver talks to the gateway with cURL, so it is pointed at the stub http
 * server by overwriting the gateway urls in its settings.
 */
class RayanpayTest extends DriverTestCase
{
    private StubServer $server;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();
        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown() : void
    {
        $_SESSION = [];
        $_POST = [];

        parent::tearDown();
    }

    protected function driverName() : string
    {
        return 'rayanpay';
    }

    protected function driverClass() : string
    {
        return Rayanpay::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'apiTokenUrl' => $this->server->url('token'),
            'apiPayStart' => $this->server->url('start'),
            'apiPayVerify' => $this->server->url('verify'),
            'client_id' => 'the-client',
            'username' => 'the-user',
            'password' => 'the-password',
        ], $overrides));
    }

    public function testPurchaseReturnsTheGeneratedReferenceId(): void
    {
        $this->server->queue([
            $this->stubResponse(['accessToken' => 'the-token']),
            $this->stubResponse(['accessToken' => 'the-token']),
            $this->stubResponse([
                'bankRedirectHtml' => '<form><input name="RefId" value="ref-id-1"></form>',
            ]),
        ]);

        $driver = $this->driver();

        $transactionId = $driver->detail('mobile', '989121112233')->amount(20000)->purchase();

        $this->assertMatchesRegularExpression('/^\d+$/', (string) $transactionId);
        $this->assertSame($transactionId, $driver->getInvoice()->getTransactionId());
        $this->assertSame('ref-id-1', $_SESSION['RefId']);

        $requests = $this->server->requests();

        $this->assertSame('/token', $requests[0]['uri']);
        $this->assertSame('/start', $requests[2]['uri']);

        $credentials = json_decode($requests[0]['body'], true);

        $this->assertSame('the-client', $credentials['clientId']);
        $this->assertSame('the-user', $credentials['userName']);
        $this->assertSame('the-password', $credentials['password']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $this->server->queue([
            $this->stubResponse(['accessToken' => 'the-token']),
            $this->stubResponse(['accessToken' => 'the-token']),
            $this->stubResponse(['bankRedirectHtml' => '<input name="RefId" value="ref-id-1">']),
        ]);

        $this->driver(['currency' => 'T'])->detail('mobile', '989121112233')->amount(2000)->purchase();

        $body = json_decode($this->server->requests()[2]['body'], true);

        $this->assertSame(20000, $body['amount']);
        $this->assertSame('989121112233', $body['msisdn']);
        $this->assertSame(100, $body['gatewayId']);
        $this->assertStringContainsString('referenceId=', $body['callbackUrl']);
    }

    public function testPurchaseNeedsAMobileNumber(): void
    {
        $this->server->queue([$this->stubResponse(['accessToken' => 'the-token'])]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('شماره موبایل را وارد کنید.');

        $driver->amount(20000)->purchase();
    }

    public function testPurchaseNeedsAnAmountAboveTenThousandRial(): void
    {
        $this->server->queue([$this->stubResponse(['accessToken' => 'the-token'])]);

        $driver = $this->driver(['currency' => 'R']);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('مقدار مبلغ ارسالی بزگتر از 10000 ریال باشد.');

        $driver->detail('mobile', '989121112233')->amount(10000)->purchase();
    }

    public function testPurchaseFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $this->server->queue([$this->stubResponse(['error' => 'unauthorized'], 401)]);

        $driver = $this->driver();

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('کد کاربری/رمز عبور /کلاینت/آی پی نامعتبر است');

        $driver->detail('mobile', '989121112233')->amount(20000)->purchase();
    }

    public function testPayPostsTheReferenceIdOfThePurchaseToTheGateway(): void
    {
        $_SESSION['RefId'] = 'ref-id-1';

        $form = $this->driver()->pay();

        $this->assertSame($this->settings()['apiPurchaseUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['x_GateChanged' => 0, 'RefId' => 'ref-id-1'], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $_POST = ['RefId' => 'ref-id-1', 'ResCode' => '0'];

        $this->server->queue([
            $this->stubResponse(['accessToken' => 'the-token']),
            $this->stubResponse([
                'paymentId' => 'payment-1',
                'hashedBankCardNumber' => 'the-hash',
                'endDate' => '2024-01-01',
            ]),
        ]);

        $driver = $this->driver([], (new Invoice)->transactionId('12345'));

        $receipt = $driver->verify();

        $this->assertSame('rayanpay', $receipt->getDriver());
        $this->assertSame('payment-1', $receipt->getReferenceId());
        $this->assertSame('the-hash', $receipt->getDetail('hashedBankCardNumber'));
        $this->assertSame('2024-01-01', $receipt->getDetail('endDate'));

        $request = $this->server->requests()[1];
        $body = json_decode($request['body'], true);

        $this->assertSame('/verify', $request['uri']);
        $this->assertSame(12345, $body['referenceId']);
        $this->assertSame(http_build_query($_POST), $body['content']);
    }

    public function testVerifyFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['accessToken' => 'the-token']),
            $this->stubResponse(['error' => 'payment failed'], 601),
        ]);

        $driver = $this->driver([], (new Invoice)->transactionId('12345'));

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('پرداخت ناموفق');

        $driver->verify();
    }

    /**
     * KNOWN ISSUE: the driver authenticates once per request and sends the whole
     * body of the token response as its bearer token, instead of the token inside
     * it. Fixing this needs the field name of the gateway's token response, which
     * is not documented in the package.
     */
    public function testItSendsTheWholeTokenResponseAsBearerToken(): void
    {
        $this->server->queue([
            $this->stubResponse(['accessToken' => 'the-token']),
            $this->stubResponse(['paymentId' => 'payment-1', 'hashedBankCardNumber' => '', 'endDate' => '']),
        ]);

        $this->driver([], (new Invoice)->transactionId('12345'))->verify();

        $this->assertSame(
            'Bearer {"accessToken":"the-token"}',
            $this->server->requests()[1]['headers']['authorization']
        );
    }
}
