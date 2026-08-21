<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Asanpardakht\Asanpardakht;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;

/**
 * The driver builds its http client inside the method that uses it, so it is
 * pointed at the stub http server by overwriting the base url of its rest api.
 */
class AsanpardakhtTest extends DriverTestCase
{
    private StubServer $server;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();
    }

    protected function driverName() : string
    {
        return 'asanpardakht';
    }

    protected function driverClass() : string
    {
        return Asanpardakht::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'apiRestPaymentUrl' => $this->server->url(),
            'username' => 'the-user',
            'password' => 'the-password',
            'merchantConfigID' => '1234',
        ], $overrides));
    }

    public function testPurchaseAsksTheGatewayForATokenAndReturnsIt(): void
    {
        $this->server->queue([
            $this->stubResponse('2024-01-01 10:20:30'),
            $this->stubResponse('the-token'),
        ]);

        $driver = $this->driver(['currency' => 'T']);

        $this->assertSame('the-token', $driver->amount(1000)->purchase());
        $this->assertSame('the-token', $driver->getInvoice()->getTransactionId());

        $requests = $this->server->requests();

        $this->assertSame('/Time', $requests[0]['uri']);
        $this->assertSame('/Token', $requests[1]['uri']);
        $this->assertSame('the-user', $requests[1]['headers']['usr']);
        $this->assertSame('the-password', $requests[1]['headers']['pwd']);

        $body = json_decode($requests[1]['body'], true);

        $this->assertSame(1, $body['serviceTypeId']);
        $this->assertSame('1234', $body['merchantConfigurationId']);
        $this->assertSame(10000, $body['amountInRials']);
        $this->assertSame('2024-01-01 10:20:30', $body['localDate']);
        $this->assertStringContainsString('invoice=', $body['callbackURL']);
    }

    public function testPurchaseTurnsTheUuidOfTheInvoiceIntoANumber(): void
    {
        $this->server->queue([
            $this->stubResponse('2024-01-01 10:20:30'),
            $this->stubResponse('the-token'),
        ]);

        $driver = $this->driver();
        $driver->amount(1000)->purchase();

        $this->assertMatchesRegularExpression('/^\d+$/', (string) $driver->getInvoice()->getUuid());
    }

    public function testPurchaseFailsWhenTheGatewayRefusesTheRequest(): void
    {
        $this->server->queue([
            $this->stubResponse('2024-01-01 10:20:30'),
            $this->stubResponse(['message' => 'unauthorized'], 401),
        ]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);

        $driver->amount(1000)->purchase();
    }

    public function testPayPostsTheReferenceIdToTheGateway(): void
    {
        $invoice = (new Invoice)->transactionId('the-token');

        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['RefID' => 'the-token'], $form->getInputs());
    }

    public function testPaySendsTheMobileNumberOfTheInvoice(): void
    {
        $invoice = (new Invoice)->transactionId('the-token');
        $driver = $this->driver([], $invoice);

        $form = $driver->detail('mobile', '09120000000')->pay();

        $this->assertSame(
            ['RefID' => 'the-token', 'mobileap' => '09120000000'],
            $form->getInputs()
        );
    }

    public function testVerifyVerifiesAndSettlesTheTransaction(): void
    {
        $this->server->queue([
            $this->stubResponse([
                'payGateTranID' => 987654,
                'rrn' => 'rrn-1',
                'refID' => 'reference-1',
                'cardNumber' => '6037****1234',
            ]),
            $this->stubResponse([]),
            $this->stubResponse([]),
        ]);

        $driver = $this->driver([], (new Invoice)->transactionId('the-token'));

        $receipt = $driver->verify();

        $this->assertSame('asanpardakht', $receipt->getDriver());
        $this->assertSame('987654', $receipt->getReferenceId());
        $this->assertSame('rrn-1', $receipt->getDetail('referenceNo'));
        $this->assertSame('reference-1', $receipt->getDetail('transactionId'));
        $this->assertSame('6037****1234', $receipt->getDetail('cardNo'));

        $requests = $this->server->requests();

        $this->assertStringStartsWith('/TranResult?', $requests[0]['uri']);
        $this->assertSame('/Verify', $requests[1]['uri']);
        $this->assertSame('/Settlement', $requests[2]['uri']);
        $this->assertSame(987654, json_decode($requests[1]['body'], true)['payGateTranId']);
        $this->assertSame(987654, json_decode($requests[2]['body'], true)['payGateTranId']);
    }

    public function testVerifyFailsWhenTheTransactionCanNotBeLookedUp(): void
    {
        $this->server->queue([$this->stubResponse(['message' => 'not found'], 404)]);

        $driver = $this->driver([], (new Invoice)->transactionId('the-token'));

        $this->expectException(PurchaseFailedException::class);

        $driver->verify();
    }

    public function testVerifyFailsWhenTheGatewayRefusesTheVerification(): void
    {
        $this->server->queue([
            $this->stubResponse(['payGateTranID' => 987654, 'rrn' => '', 'refID' => '', 'cardNumber' => '']),
            $this->stubResponse(['message' => 'already verified'], 400),
        ]);

        $driver = $this->driver([], (new Invoice)->transactionId('the-token'));

        $this->expectException(PurchaseFailedException::class);

        $driver->verify();
    }
}
