<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Sepehr\Sepehr;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Tests\Support\StubServer;
use Shetabit\Multipay\Constants\IranCurrency;

/**
 * The driver talks to the gateway with cURL, so it is pointed at the stub http
 * server by overwriting the gateway urls in its settings.
 */
class SepehrTest extends DriverTestCase
{
    private StubServer $server;

    protected function setUp() : void
    {
        parent::setUp();

        $this->server = $this->stubServer();
    }

    protected function driverName() : string
    {
        return 'sepehr';
    }

    protected function driverClass() : string
    {
        return Sepehr::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'apiGetToken' => $this->server->url('token'),
            'apiVerificationUrl' => $this->server->url('advice'),
            'terminalId' => 'terminal-1',
            'callbackUrl' => 'https://example.com/callback',
        ], $overrides));
    }

    public function testPurchaseReturnsTheAccessTokenOfTheGateway(): void
    {
        $this->server->queue([$this->stubResponse(['Status' => 0, 'Accesstoken' => 'the-token'])]);

        $driver = $this->driver();

        $this->assertSame('the-token', $driver->amount(1000)->purchase());
        $this->assertSame('the-token', $driver->getInvoice()->getTransactionId());
        $this->assertSame('/token', $this->server->requests()[0]['uri']);
    }

    public function testPurchaseSendsTheInvoiceToTheGateway(): void
    {
        $this->server->queue([$this->stubResponse(['Status' => 0, 'Accesstoken' => 'the-token'])]);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $driver->detail('mobile', '09120000000')->amount(1000)->purchase();

        $body = [];
        parse_str($this->server->requests()[0]['body'], $body);

        $this->assertSame('10000', $body['Amount']);
        $this->assertSame('https://example.com/callback', $body['callbackURL']);
        $this->assertSame('terminal-1', $body['TerminalID']);
        $this->assertSame('09120000000', $body['CellNumber']);
        $this->assertSame($driver->getInvoice()->getUuid(), $body['InvoiceID']);
    }

    public function testPurchaseFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $this->server->queue([$this->stubResponse(['Status' => -1, 'Accesstoken' => ''])]);

        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);

        $driver->amount(1000)->purchase();
    }

    public function testPayPostsTheTokenToTheGateway(): void
    {
        $invoice = (new Invoice)->transactionId('the-token');

        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['token' => 'the-token', 'terminalID' => 'terminal-1'], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest([
            'respcode' => 0,
            'digitalreceipt' => 'receipt-1',
            'rrn' => 'rrn-1',
        ]);

        $this->server->queue([$this->stubResponse(['Status' => 'Ok', 'ReturnId' => 10000])]);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('sepehr', $receipt->getDriver());
        $this->assertSame('rrn-1', $receipt->getReferenceId());

        $body = [];
        parse_str($this->server->requests()[0]['body'], $body);

        $this->assertSame('/advice', $this->server->requests()[0]['uri']);
        $this->assertSame('receipt-1', $body['digitalreceipt']);
        $this->assertSame('terminal-1', $body['Tid']);
    }

    public function testVerifyFailsWhenTheCallbackReportsAnError(): void
    {
        $this->fakeRequest(['respcode' => -1]);

        $this->expectException(InvalidPaymentException::class);

        $this->driver()->amount(1000)->verify();
    }

    public function testVerifyFailsWhenTheVerifiedAmountDoesNotMatchTheInvoice(): void
    {
        $this->fakeRequest(['respcode' => 0, 'digitalreceipt' => 'receipt-1']);

        $this->server->queue([$this->stubResponse(['Status' => 'Ok', 'ReturnId' => 5000])]);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('مبلغ واریز با قیمت محصول برابر نیست');

        $driver->amount(1000)->verify();
    }

    public function testVerifyFailsWhenTheGatewayRefusesTheConfirmation(): void
    {
        $this->fakeRequest(['respcode' => 0, 'digitalreceipt' => 'receipt-1']);

        $this->server->queue([$this->stubResponse(['Status' => 'Error', 'ReturnId' => 0])]);

        $driver = $this->driver();

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش نا موفق بود');

        $driver->amount(1000)->verify();
    }
}
