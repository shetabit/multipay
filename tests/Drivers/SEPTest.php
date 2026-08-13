<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\SEP\SEP;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class SEPTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'sep';
    }

    protected function driverClass() : string
    {
        return SEP::class;
    }

    public function testPurchaseReturnsTheTokenOfTheGateway(): void
    {
        $driver = $this->driver(['terminalId' => 'terminal-1']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'token' => 'token-1'])]);

        $this->assertSame('token-1', $driver->amount(1000)->purchase());
        $this->assertSame('token-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiGetToken']);

        $body = $this->requestJson();

        $this->assertSame('token', $body['action']);
        $this->assertSame('terminal-1', $body['TerminalId']);
        $this->assertSame($this->settings()['callbackUrl'], $body['RedirectUrl']);
        $this->assertSame($driver->getInvoice()->getUuid(), $body['ResNum']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => 'T']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'token' => 'token-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertSame(10000, $this->requestJson()['Amount']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'token' => 'token-1'])]);

        $driver->detail([
            'mobile' => '09120000000',
            'ResNum1' => 'first',
            'ResNum2' => 'second',
            'ResNum3' => 'third',
            'ResNum4' => 'fourth',
        ])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame('09120000000', $body['CellNumber']);
        $this->assertSame('first', $body['ResNum1']);
        $this->assertSame('fourth', $body['ResNum4']);
    }

    public function testPurchaseFailsWithTheTranslatedErrorOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => -1, 'errorCode' => -1])]);

        $this->expectException(PurchaseFailedException::class);

        $driver->amount(1000)->purchase();
    }

    public function testPayPostsTheTokenToTheGateway(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame(['Token' => 'token-1', 'GetMethod' => false], $form->getInputs());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest([
            'Status' => 2,
            'RefNum' => 'reference-1',
            'Token' => 'token-1',
            'ResNum' => 'reservation-1',
        ]);

        $invoice = (new Invoice)->transactionId('token-1');
        $driver = $this->driver(['terminalId' => 'terminal-1', 'currency' => 'T'], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'ResultCode' => 0,
                'TransactionDetail' => [
                    'AffectiveAmount' => 10000,
                    'OrginalAmount' => 10000,
                    'StraceNo' => 'trace-1',
                    'StraceDate' => '2024-01-01',
                    'RRN' => 'rrn-1',
                    'RefNum' => 'reference-1',
                    'MaskedPan' => '6037****1234',
                    'TerminalNumber' => 'terminal-1',
                ],
            ]),
        ]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('saman', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame('trace-1', $receipt->getDetail('traceNo'));
        $this->assertSame('rrn-1', $receipt->getDetail('referenceNo'));
        $this->assertSame('reservation-1', $receipt->getDetail('transactionId'));
        $this->assertSame('6037****1234', $receipt->getDetail('cardNo'));
        $this->assertSame(10000, $receipt->getDetail('Amount'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $body = $this->requestJson();

        $this->assertSame('reference-1', $body['RefNum']);
        $this->assertSame('terminal-1', $body['TerminalNumber']);
    }

    public function testVerifyFailsWhenTheCallbackReportsAnUnsuccessfulPayment(): void
    {
        $this->fakeRequest(['Status' => 1]);

        $this->expectException(PurchaseFailedException::class);

        $this->driver()->verify();
    }

    public function testVerifyFailsWhenTheTokenOfTheCallbackDoesNotMatchTheInvoice(): void
    {
        $this->fakeRequest(['Status' => 2, 'Token' => 'another-token']);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('اطلاعات پرداخت با فاکتور همخوانی ندارد.');

        $this->driver([], (new Invoice)->transactionId('token-1'))->verify();
    }

    public function testVerifyFailsWhenTheVerifiedAmountDoesNotMatchTheInvoice(): void
    {
        $this->fakeRequest(['Status' => 2, 'Token' => 'token-1', 'RefNum' => 'reference-1']);

        $driver = $this->driver(['currency' => 'T'], (new Invoice)->transactionId('token-1'));
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'ResultCode' => 0,
                'TransactionDetail' => ['AffectiveAmount' => 5000],
            ]),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('مبلغ برگشتی با مبلغ فاکتور همخوانی ندارد.');

        $driver->amount(1000)->verify();
    }

    public function testVerifyFailsWithTheTranslatedResultOfTheGateway(): void
    {
        $this->fakeRequest(['Status' => 2, 'Token' => 'token-1', 'RefNum' => 'reference-1']);

        $driver = $this->driver([], (new Invoice)->transactionId('token-1'));
        $this->fakeHttp($driver, [$this->jsonResponse(['ResultCode' => -1])]);

        $this->expectException(InvalidPaymentException::class);

        $driver->amount(1000)->verify();
    }
}
