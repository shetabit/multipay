<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Azki\Azki;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Constants\IranCurrency;

class AzkiTest extends DriverTestCase
{
    /**
     * The driver signs its requests with an AES key, which has to be hexadecimal.
     */
    private const string KEY = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    protected function driverName() : string
    {
        return 'azki';
    }

    protected function driverClass() : string
    {
        return Azki::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge(['key' => self::KEY, 'merchantId' => 'azki-merchant'], $overrides));
    }

    public function testPurchaseReturnsTheTicketIdOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'rsCode' => 0,
                'result' => ['ticket_id' => 'ticket-1', 'payment_uri' => 'https://api.azkivam.com/pay'],
            ]),
        ]);

        $this->assertSame('ticket-1', $this->purchase($driver));
        $this->assertSame('ticket-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPaymentUrl'].'/payment/purchase');
        $this->assertSame('azki-merchant', $this->request()->getHeaderLine('MerchantId'));
        $this->assertNotEmpty($this->request()->getHeaderLine('Signature'));
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'rsCode' => 0,
                'result' => ['ticket_id' => 'ticket-1', 'payment_uri' => 'https://api.azkivam.com/pay'],
            ]),
        ]);

        $this->purchase($driver);

        $body = $this->requestJson();

        $this->assertSame(10000, $body['amount']);
        $this->assertSame($this->settings()['callbackUrl'], $body['redirect_uri']);
        $this->assertSame('09120000000', $body['mobile_number']);
        $this->assertSame($driver->getInvoice()->getUuid(), $body['provider_id']);
    }

    public function testPurchaseSendsTheItemsOfTheInvoice(): void
    {
        $items = [['name' => 'a product', 'count' => 1, 'amount' => 1000, 'url' => 'https://example.com']];

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'rsCode' => 0,
                'result' => ['ticket_id' => 'ticket-1', 'payment_uri' => 'https://api.azkivam.com/pay'],
            ]),
        ]);

        $driver->detail(['mobile' => '09120000000', 'items' => $items])->amount(1000)->purchase();

        $this->assertSame($items, $this->requestJson()['items']);
    }

    public function testPurchaseNeedsAPhoneNumber(): void
    {
        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('Phone number is required');

        $driver->detail('items', [['name' => 'a product']])->amount(1000)->purchase();
    }

    public function testPurchaseNeedsItems(): void
    {
        $driver = $this->driver();

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('Items is required for this driver');

        $driver->detail('mobile', '09120000000')->amount(1000)->purchase();
    }

    public function testPurchaseFailsWhenTheGatewayReportsAnError(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['rsCode' => 3])]);

        $this->expectException(PurchaseFailedException::class);

        $this->purchase($driver);
    }

    public function testPayRedirectsToThePaymentUriOfThePurchase(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'rsCode' => 0,
                'result' => ['ticket_id' => 'ticket-1', 'payment_uri' => 'https://api.azkivam.com/pay'],
            ]),
        ]);

        $this->purchase($driver);

        $form = $driver->pay();

        $this->assertSame('https://api.azkivam.com/pay', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame(['ticketId' => 'ticket-1'], $form->getInputs());
    }

    public function testVerifyReturnsAReceiptWhenTheTicketIsDone(): void
    {
        $invoice = (new Invoice)->transactionId('ticket-1');
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['rsCode' => 0, 'result' => ['status' => 8]]),
            $this->jsonResponse(['rsCode' => 0, 'result' => []]),
        ]);

        $receipt = $driver->verify();

        $this->assertSame('azki', $receipt->getDriver());
        $this->assertSame('ticket-1', $receipt->getReferenceId());
        $this->assertRequestedUrl($this->settings()['apiPaymentUrl'].'/payment/status', 0);
        $this->assertRequestedUrl($this->settings()['apiPaymentUrl'].'/payment/verify', 1);
        $this->assertSame('ticket-1', $this->requestJson(1)['ticket_id']);
    }

    public function testVerifyFailsWhenTheTicketWasCanceled(): void
    {
        $invoice = (new Invoice)->transactionId('ticket-1');
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [$this->jsonResponse(['rsCode' => 0, 'result' => ['status' => 5]])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('Canceled');

        $driver->verify();
    }

    /**
     * Purchase an invoice that carries everything the driver requires.
     */
    private function purchase(Driver $driver) : string|null
    {
        return $driver
            ->detail([
                'mobile' => '09120000000',
                'items' => [['name' => 'a product', 'count' => 1, 'amount' => 1000]],
            ])
            ->amount(1000)
            ->purchase();
    }
}
