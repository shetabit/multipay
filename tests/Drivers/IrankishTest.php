<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Irankish\Irankish;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;

/**
 * The driver talks to the gateway with cURL, so it is pointed at the stub http
 * server by overwriting the gateway urls in its settings.
 */
class IrankishTest extends DriverTestCase
{
    private StubServer $server;

    /**
     * The driver encrypts its credentials with the public key of the gateway.
     */
    private string $publicKey;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
    }

    protected function driverName() : string
    {
        return 'irankish';
    }

    protected function driverClass() : string
    {
        return Irankish::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'apiPurchaseUrl' => $this->server->url('purchase'),
            'apiVerificationUrl' => $this->server->url('verify'),
            // the terminal id and the password are read as hexadecimal
            'terminalId' => 'aabbccdd',
            'password' => '11223344',
            'acceptorId' => 'acceptor-1',
            'pubKey' => $this->publicKey,
        ], $overrides));
    }

    public function testPurchaseReturnsTheTokenOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['responseCode' => '00', 'result' => ['token' => 'token-1']]),
        ]);

        $driver = $this->driver();

        $this->assertSame('token-1', $driver->amount(1000)->purchase());
        $this->assertSame('token-1', $driver->getInvoice()->getTransactionId());
        $this->assertSame('/purchase', $this->server->requests()[0]['uri']);
    }

    public function testPurchaseSendsTheInvoiceToTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['responseCode' => '00', 'result' => ['token' => 'token-1']]),
        ]);

        $this->driver(['currency' => 'T'])->amount(1000)->purchase();

        $body = json_decode($this->server->requests()[0]['body'], true);

        $this->assertSame(10000, $body['request']['amount']);
        $this->assertSame('acceptor-1', $body['request']['acceptorId']);
        $this->assertSame('aabbccdd', $body['request']['terminalId']);
        $this->assertSame('Purchase', $body['request']['transactionType']);
        $this->assertSame($this->settings()['callbackUrl'], $body['request']['revertUri']);
        $this->assertNotEmpty($body['authenticationEnvelope']['data']);
        $this->assertNotEmpty($body['authenticationEnvelope']['iv']);
    }

    public function testPurchaseFailsWithTheDescriptionOfTheGateway(): void
    {
        $this->server->queue([
            $this->stubResponse(['responseCode' => '03', 'description' => 'the acceptor is not valid']),
        ]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the acceptor is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPayPostsTheTokenToTheGateway(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');

        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['tokenIdentity' => 'token-1'], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest([
            'responseCode' => '00',
            'retrievalReferenceNumber' => 'rrn-1',
            'systemTraceAuditNumber' => 'trace-1',
            'token' => 'token-1',
        ]);

        $this->server->queue([$this->stubResponse(['responseCode' => '00'])]);

        $receipt = $this->driver()->verify();

        $this->assertSame('irankish', $receipt->getDriver());
        $this->assertSame('rrn-1', $receipt->getReferenceId());

        $request = $this->server->requests()[0];
        $body = json_decode($request['body'], true);

        $this->assertSame('/verify', $request['uri']);
        $this->assertSame('rrn-1', $body['retrievalReferenceNumber']);
        $this->assertSame('trace-1', $body['systemTraceAuditNumber']);
        $this->assertSame('token-1', $body['tokenIdentity']);
    }

    public function testVerifyFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $this->fakeRequest(['responseCode' => 94]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش تکراری است');

        $this->driver()->verify();
    }
}
