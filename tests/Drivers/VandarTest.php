<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Vandar\Vandar;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class VandarTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'vandar';
    }

    protected function driverClass() : string
    {
        return Vandar::class;
    }

    public function testPurchaseReturnsTheTokenOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'vandar-api-key']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'token' => 'token-1'])]);

        $this->assertSame('token-1', $driver->amount(1000)->purchase());
        $this->assertSame('token-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('vandar-api-key', $this->requestJson()['api_key']);
    }

    public function testPurchaseSendsTheAmountInToman(): void
    {
        $driver = $this->driver(['currency' => 'R']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'token' => 'token-1'])]);

        $driver->amount(10000)->purchase();

        $this->assertSame(1000, $this->requestJson()['amount']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => 'T']);
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 1, 'token' => 'token-1'])]);

        $driver->detail([
            'mobile' => '09120000000',
            'description' => 'a description',
            'national_code' => '0010000000',
            'valid_card_number' => '6037********1234',
            'factorNumber' => 'factor-1',
        ])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame(1000, $body['amount']);
        $this->assertSame('09120000000', $body['mobile_number']);
        $this->assertSame('a description', $body['description']);
        $this->assertSame('0010000000', $body['national_code']);
        $this->assertSame('6037********1234', $body['valid_card_number']);
        $this->assertSame('factor-1', $body['factorNumber']);
    }

    public function testPurchaseFailsWithTheErrorOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 0, 'errors' => ['api key is wrong']])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('api key is wrong');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheToken(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'token-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['token' => 'token-1', 'payment_status' => 'OK']);

        $driver = $this->driver(['merchantId' => 'vandar-api-key']);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'status' => 1,
                'amount' => 1000,
                'realAmount' => 950,
                'wage' => 50,
                'cardNumber' => '6037****1234',
            ]),
        ]);

        $receipt = $driver->verify();

        $this->assertSame('vandar', $receipt->getDriver());
        $this->assertSame('token-1', $receipt->getReferenceId());
        $this->assertSame(1000, $receipt->getDetail('amount'));
        $this->assertSame(950, $receipt->getDetail('realAmount'));
        $this->assertSame(50, $receipt->getDetail('wage'));
        $this->assertSame('6037****1234', $receipt->getDetail('cardNumber'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);
        $this->assertSame('token-1', $this->requestJson()['token']);
    }

    public function testVerifyFailsWhenTheGatewayReportsAFailedPayment(): void
    {
        $this->fakeRequest(['token' => 'token-1', 'payment_status' => 'FAILED']);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('پرداخت با شکست مواجه شد.');

        $this->driver()->verify();
    }

    public function testVerifyFailsWithTheErrorOfTheGateway(): void
    {
        $this->fakeRequest(['token' => 'token-1', 'payment_status' => 'OK']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 0, 'error' => 'token is not valid'])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('token is not valid');

        $driver->verify();
    }

    public function testVerifyFailsWithAGenericMessageWhenTheGatewayIsSilent(): void
    {
        $this->fakeRequest(['token' => 'token-1', 'payment_status' => 'OK']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['status' => 0])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('خطای ناشناخته ای رخ داده است.');

        $driver->verify();
    }
}
