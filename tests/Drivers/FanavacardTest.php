<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Drivers\Fanavacard\Fanavacard;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class FanavacardTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'fanavacard';
    }

    protected function driverClass() : string
    {
        return Fanavacard::class;
    }

    /**
     * The driver talks to the gateway with relative urls, so its client needs the
     * base uri of the gateway.
     */
    protected function fakeHttp(Driver $driver, array $responses, array $clientOptions = []) : void
    {
        parent::fakeHttp($driver, $responses, $clientOptions + ['base_uri' => $this->settings()['baseUri']]);
    }

    public function testPurchaseReturnsTheTokenOfTheGateway(): void
    {
        $driver = $this->driver(['username' => 'fanava-user', 'password' => 'fanava-password']);
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erSucceed', 'Token' => 'token-1'])]);

        $this->assertSame('token-1', $driver->amount(1000)->purchase());
        $this->assertSame('token-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl(
            $this->settings()['baseUri'].'/'.$this->settings()['apiPurchaseUrl']
        );

        $body = $this->requestJson();

        $this->assertSame('fanava-user', $body['WSContext']['UserId']);
        $this->assertSame('fanava-password', $body['WSContext']['Password']);
        $this->assertSame('EN_GOODS', $body['TransType']);
        $this->assertSame($this->settings()['callbackUrl'], $body['RedirectUrl']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erSucceed', 'Token' => 'token-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertSame(10000, $this->requestJson()['Amount']);
    }

    public function testPurchaseUsesTheInvoiceNumberOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erSucceed', 'Token' => 'token-1'])]);

        $driver->detail('invoice_number', 'invoice-1')->amount(1000)->purchase();

        $this->assertSame('invoice-1', $this->requestJson()['ReserveNum']);
    }

    public function testPurchaseFailsWhenTheGatewayDoesNotReturnAToken(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erAAS_InvalidUseridOrPass'])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('cant get token');

        $driver->amount(1000)->purchase();
    }

    public function testPayPostsTheTokenToTheGateway(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame(
            rtrim($this->settings()['baseUri'], '/').'/'.$this->settings()['apiPaymentUrl'],
            $form->getAction()
        );
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['token' => 'token-1', 'language' => 'fa'], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest([
            'transactionAmount' => 10000,
            'token' => 'token-1',
            'RefNum' => 'reference-1',
            'ResNum' => 'reservation-1',
            'CustomerRefNum' => 'customer-1',
            'CardMaskPan' => '6037****1234',
        ]);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erSucceed', 'Amount' => 10000])]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('fanavacard', $receipt->getDriver());
        $this->assertSame('reservation-1', $receipt->getReferenceId());
        $this->assertSame('reference-1', $receipt->getDetail('RefNum'));
        $this->assertSame('token-1', $receipt->getDetail('token'));
        $this->assertSame('6037****1234', $receipt->getDetail('CardMaskPan'));
        $this->assertRequestedUrl(
            $this->settings()['baseUri'].'/'.$this->settings()['apiVerificationUrl']
        );
    }

    public function testVerifyFailsWhenTheGatewayReportsAnError(): void
    {
        $this->fakeRequest(['transactionAmount' => 10000, 'token' => 'token-1']);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['Result' => 'erAAS_InvalidUseridOrPass'])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('erAAS_InvalidUseridOrPass');

        $driver->amount(1000)->verify();
    }

    public function testVerifyReversesThePaymentWhenTheAmountDoesNotMatch(): void
    {
        $this->fakeRequest(['transactionAmount' => 10000, 'token' => 'token-1', 'RefNum' => 'reference-1']);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['Result' => 'erSucceed', 'Amount' => 5000]),
            $this->jsonResponse(['Result' => 'erSucceed']),
        ]);

        try {
            $driver->amount(1000)->verify();
            $this->fail('The payment should not be verified.');
        } catch (InvalidPaymentException $exception) {
            $this->assertSame('مبلغ تراکنش برگشت داده شد', $exception->getMessage());
        }

        $this->assertRequestedUrl(
            $this->settings()['baseUri'].'/'.$this->settings()['apiReverseAmountUrl'],
            1
        );
    }

    /**
     * The driver only asks the gateway when the amount of the callback matches the
     * amount of the invoice, and hands out a receipt without verification otherwise.
     */
    public function testVerifySkipsTheGatewayWhenTheAmountDoesNotMatchTheInvoice(): void
    {
        $this->fakeRequest(['transactionAmount' => 5000, 'ResNum' => 'reservation-1']);

        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, []);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('reservation-1', $receipt->getReferenceId());
        $this->assertSame(0, $this->requestCount());
    }
}
