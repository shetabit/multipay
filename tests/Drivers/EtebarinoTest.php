<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Etebarino\Etebarino;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;

/**
 * The driver builds its http client inside the method that uses it, so it is
 * pointed at the stub http server by overwriting the gateway urls in its settings.
 */
class EtebarinoTest extends DriverTestCase
{
    private StubServer $server;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();
    }

    protected function driverName() : string
    {
        return 'etebarino';
    }

    protected function driverClass() : string
    {
        return Etebarino::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'apiPurchaseUrl' => $this->server->url('purchase'),
            'apiVerificationUrl' => $this->server->url('verify'),
            'merchantId' => 'etebarino-merchant',
            'terminalId' => 'terminal-1',
            'username' => 'the-user',
            'password' => 'the-password',
        ], $overrides));
    }

    public function testPurchaseSendsTheInvoiceToTheGateway(): void
    {
        $this->server->queue([$this->stubResponse(['token' => 'token-1'])]);

        $driver = $this->driver();

        $driver
            ->detail([
                'description' => 'a description',
                'items' => [['productGroup' => 1000, 'amount' => 10000, 'description' => 'an item']],
            ])
            ->amount(1000)
            ->purchase();

        $request = $this->server->requests()[0];
        $body = json_decode($request['body'], true);

        $this->assertSame('/purchase', $request['uri']);
        $this->assertSame('terminal-1', $body['terminalCode']);
        $this->assertSame('the-user', $body['terminalUser']);
        $this->assertSame('etebarino-merchant', $body['merchantCode']);
        $this->assertSame('the-password', $body['terminalPass']);
        $this->assertSame('a description', $body['description']);
        $this->assertSame($this->settings()['callbackUrl'], $body['returnUrl']);
        $this->assertSame(
            [['productGroup' => 1000, 'amount' => 10000, 'description' => 'an item']],
            $body['paymentItems']
        );
    }

    public function testPurchaseKeepsTheResponseOfTheGatewayAsTransactionId(): void
    {
        $response = ['token' => 'token-1'];

        $this->server->queue([$this->stubResponse($response)]);

        $driver = $this->driver();

        $this->assertSame(json_encode($response), $driver->amount(1000)->purchase());
    }

    public function testPurchaseTurnsTheUuidOfTheInvoiceIntoANumber(): void
    {
        $this->server->queue([$this->stubResponse(['token' => 'token-1'])]);

        $driver = $this->driver();
        $driver->amount(1000)->purchase();

        $this->assertMatchesRegularExpression('/^\d+$/', (string) $driver->getInvoice()->getUuid());
    }

    public function testPurchaseFailsWhenTheGatewayRefusesTheRequest(): void
    {
        $this->server->queue([$this->rawStubResponse('the merchant is not valid', 400)]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the merchant is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheToken(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');

        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame(['token' => 'token-1'], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->server->queue([$this->stubResponse(['status' => 'OK'])]);

        $driver = $this->driver();

        $receipt = $driver->detail(['referenceCode' => 'reference-1', 'uuid' => 'uuid-1'])->verify();

        $this->assertSame('etebarino', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame('reference-1', $receipt->getDetail('referenceNo'));

        $request = $this->server->requests()[0];
        $body = json_decode($request['body'], true);

        $this->assertSame('/verify', $request['uri']);
        $this->assertSame('reference-1', $body['referenceCode']);
        $this->assertSame('uuid-1', $body['merchantRefCode']);
    }

    public function testVerifyFailsWhenTheGatewayRefusesTheConfirmation(): void
    {
        $this->server->queue([$this->rawStubResponse('the reference code is not valid', 400)]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the reference code is not valid');

        $driver->verify();
    }
}
