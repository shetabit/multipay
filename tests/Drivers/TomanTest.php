<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Toman\Toman;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Invoice;

class TomanTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'toman';
    }

    protected function driverClass() : string
    {
        return Toman::class;
    }

    protected function settings(array $overrides = []) : array
    {
        return parent::settings(array_merge([
            'shop_slug' => 'the-shop',
            'auth_code' => 'the-auth-code',
            'data' => ['amount' => 1000, 'description' => 'a description'],
        ], $overrides));
    }

    public function testPurchaseReturnsTheTraceNumberOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['trace_number' => 'trace-1'])]);

        $this->assertSame('trace-1', $driver->purchase());
        $this->assertSame('trace-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['base_url'].'/users/me/shops/the-shop/deals');
    }

    public function testPurchaseSendsTheConfiguredDeal(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['trace_number' => 'trace-1'])]);

        $driver->purchase();

        $this->assertSame(
            ['amount' => 1000, 'description' => 'a description'],
            $this->requestJson()
        );
    }

    public function testPurchaseFailsWhenTheGatewayReturnsNoTraceNumber(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['errors' => ['the shop is unknown']])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('پرداخت با مشکل مواجه شد، لطفا با ما در ارتباط باشید');

        $driver->purchase();
    }

    public function testPayRedirectsToTheDealOfTheGateway(): void
    {
        $invoice = (new Invoice)->transactionId('trace-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['base_url'].'/deals/trace-1/redirect', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyConfirmsTheDealAndReturnsAReceipt(): void
    {
        $this->fakeRequest(['state' => 'funded']);

        $invoice = (new Invoice)->transactionId('trace-1');
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [$this->jsonResponse([])]);

        $receipt = $driver->verify();

        $this->assertSame('toman', $receipt->getDriver());
        $this->assertSame('trace-1', $receipt->getReferenceId());
        $this->assertSame('PATCH', $this->request()->getMethod());
        $this->assertRequestedUrl(
            $this->settings()['base_url'].'/users/me/shops/the-shop/deals/trace-1/verify'
        );
    }

    public function testVerifyFailsWhenTheDealWasNotFunded(): void
    {
        $this->fakeRequest(['state' => 'canceled']);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('پرداخت انجام نشد');

        $this->driver([], (new Invoice)->transactionId('trace-1'))->verify();
    }
}
