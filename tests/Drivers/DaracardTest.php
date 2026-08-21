<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Daracard\Daracard;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;

/**
 * The driver builds its http client inside the method that uses it, so it is
 * pointed at the stub http server by overwriting the gateway urls in its settings.
 */
class DaracardTest extends DriverTestCase
{
    private StubServer $server;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();
    }

    protected function driverName() : string
    {
        return 'daracard';
    }

    protected function driverClass() : string
    {
        return Daracard::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'apiPurchaseUrl' => $this->server->url('purchase'),
            'apiVerificationUrl' => $this->server->url('verify'),
            // the merchant id is used as a base64 encoded triple DES key
            'merchantId' => base64_encode('123456789012345678901234'),
            'terminalId' => 'terminal-1',
            'orderId' => 'order-1',
        ], $overrides));
    }

    public function testPurchaseSendsTheInvoiceToTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['ResultData' => ['PurchaseToken' => 'token-1']]),
        ]);

        $driver = $this->driver();

        $driver->detail('description', 'a description')->amount(1000)->purchase();

        $request = $this->server->requests()[0];
        $body = json_decode($request['body'], true);

        $this->assertSame('/purchase', $request['uri']);
        $this->assertSame('terminal-1', $body['TerminalId']);
        $this->assertSame('order-1', $body['OrderId']);
        $this->assertSame(10000, $body['Amount']);
        $this->assertSame('a description', $body['description']);
        $this->assertSame($this->settings()['callbackUrl'], $body['ReturnUrl']);
        $this->assertNotEmpty($body['SignData']);
        $this->assertNotEmpty($body['LocalDateTime']);
    }

    public function testPurchaseKeepsTheResponseOfTheGatewayAsTransactionId(): void
    {
        $response = ['ResultData' => ['PurchaseToken' => 'token-1']];

        $this->server->queue([$this->stubResponse($response)]);

        $driver = $this->driver();

        $this->assertSame(json_encode($response), $driver->amount(1000)->purchase());
    }

    public function testPurchaseTurnsTheUuidOfTheInvoiceIntoANumber(): void
    {
        $this->server->queue([$this->stubResponse(['ResultData' => ['PurchaseToken' => 'token-1']])]);

        $driver = $this->driver();
        $driver->amount(1000)->purchase();

        $this->assertMatchesRegularExpression('/^\d+$/', (string) $driver->getInvoice()->getUuid());
    }

    public function testPurchaseFailsWhenTheGatewayRefusesTheRequest(): void
    {
        $this->server->queue([$this->rawStubResponse('the terminal is not valid', 400)]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the terminal is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithThePurchaseToken(): void
    {
        $invoice = (new Invoice)->transactionId(json_encode(['ResultData' => ['PurchaseToken' => 'token-1']]));

        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame(['token' => 'token-1'], $form->getInputs());
    }

    public function testPayFallsBackToTheTransactionIdWhenItIsNoPurchaseResponse(): void
    {
        $form = $this->driver([], (new Invoice)->transactionId('token-1'))->pay();

        $this->assertSame(['token' => 'token-1'], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['Token' => 'token-1']);

        $this->server->queue([
            $this->stubResponse([
                'resultCode' => 0,
                'resultMessage' => 'the payment was successful',
                'IssuerRRN' => 'issuer-1',
                'AcquirerRRN' => 'acquirer-1',
            ]),
        ]);

        $driver = $this->driver();

        $receipt = $driver->detail('referenceCode', 'reference-1')->verify();

        $this->assertSame('daracard', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame(
            'IssuerRRN: issuer-1, AcquirerRRN: acquirer-1',
            $receipt->getDetail('referenceNo')
        );

        $request = $this->server->requests()[0];

        $this->assertSame('/verify', $request['uri']);
        $this->assertSame(['Token' => 'token-1'], json_decode($request['body'], true));
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $this->fakeRequest(['Token' => 'token-1']);

        $this->server->queue([
            $this->stubResponse(['resultCode' => -1, 'resultMessage' => 'the token is not valid']),
        ]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the token is not valid');

        $driver->verify();
    }
}
