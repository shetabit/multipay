<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Bitpay\Bitpay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;
use Shetabit\Multipay\Constants\IranCurrency;

/**
 * The driver talks to the gateway with cURL, so it is pointed at the stub http
 * server by overwriting the gateway urls in its settings.
 */
class BitpayTest extends DriverTestCase
{
    private StubServer $server;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();
    }

    protected function driverName() : string
    {
        return 'bitpay';
    }

    protected function driverClass() : string
    {
        return Bitpay::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'apiPurchaseUrl' => $this->server->url('purchase'),
            'apiVerificationUrl' => $this->server->url('verify'),
            'api_token' => 'the-api-token',
            'callbackUrl' => 'https://example.com/callback',
        ], $overrides));
    }

    public function testPurchaseReturnsTheTransactionIdOfTheGateway(): void
    {
        $this->server->queue([$this->rawStubResponse('123456')]);

        $driver = $this->driver();

        $this->assertSame('123456', $driver->amount(1000)->purchase());
        $this->assertSame('123456', $driver->getInvoice()->getTransactionId());
        $this->assertSame('/purchase', $this->server->requests()[0]['uri']);
    }

    public function testPurchaseSendsTheInvoiceToTheGateway(): void
    {
        $this->server->queue([$this->rawStubResponse('123456')]);

        $this->driver(['currency' => IranCurrency::TOMAN])
            ->detail([
                'name' => 'john doe',
                'email' => 'john@example.com',
                'description' => 'a description',
                'factorId' => 'factor-1',
            ])
            ->amount(1000)
            ->purchase();

        $body = [];
        parse_str($this->server->requests()[0]['body'], $body);

        $this->assertSame('the-api-token', $body['api']);
        $this->assertSame('10000', $body['amount']);
        $this->assertSame('https://example.com/callback', $body['redirect']);
        $this->assertSame('john doe', $body['name']);
        $this->assertSame('john@example.com', $body['email']);
        $this->assertSame('a description', $body['description']);
        $this->assertSame('factor-1', $body['factorId']);
    }

    public function testPurchaseFailsWithTheTranslatedErrorOfTheGateway(): void
    {
        $this->server->queue([$this->rawStubResponse('-1')]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheTransactionId(): void
    {
        $invoice = (new Invoice)->transactionId('123456');

        $form = $this->driver([], $invoice)->pay();

        $this->assertSame(
            str_replace('{id_get}', '123456', $this->settings()['apiPaymentUrl']),
            $form->getAction()
        );
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['trans_id' => 'transaction-1', 'id_get' => '123456']);

        $this->server->queue([
            $this->stubResponse([
                'status' => 1,
                'amount' => 10000,
                'cardNum' => '6037****1234',
                'factorId' => 'factor-1',
            ]),
        ]);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);

        $receipt = $driver->verify();

        $this->assertSame('bitpay', $receipt->getDriver());
        $this->assertSame('transaction-1', $receipt->getReferenceId());
        $this->assertSame(1000, $receipt->getDetail('amount'));
        $this->assertSame('6037****1234', $receipt->getDetail('cardNum'));
        $this->assertSame('factor-1', $receipt->getDetail('factorId'));

        $body = [];
        parse_str($this->server->requests()[0]['body'], $body);

        $this->assertSame('/verify', $this->server->requests()[0]['uri']);
        $this->assertSame('the-api-token', $body['api']);
        $this->assertSame('123456', $body['id_get']);
        $this->assertSame('transaction-1', $body['trans_id']);
    }

    public function testVerifyFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $this->fakeRequest(['trans_id' => 'transaction-1', 'id_get' => '123456']);

        $this->server->queue([$this->stubResponse(['status' => 4])]);

        $driver = $this->driver();

        $this->expectException(InvalidPaymentException::class);

        $driver->verify();
    }
}
