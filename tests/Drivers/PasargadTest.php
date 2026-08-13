<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Pasargad\Pasargad;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;

class PasargadTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'pasargad';
    }

    protected function driverClass() : string
    {
        return Pasargad::class;
    }

    public function testPurchaseGeneratesAnInvoiceNumberWithoutCallingTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, []);

        $transactionId = $driver->amount(1000)->purchase();

        $this->assertMatchesRegularExpression('/^\d+$/', (string) $transactionId);
        $this->assertSame($transactionId, $driver->getInvoice()->getTransactionId());
        $this->assertSame(0, $this->requestCount());
    }

    public function testPayAsksTheGatewayForThePaymentUrl(): void
    {
        $driver = $this->driver([
            'userName' => 'pasargad-user',
            'password' => 'pasargad-password',
            'terminalCode' => 'terminal-1',
            'currency' => 'T',
        ]);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['resultCode' => 0, 'token' => 'the-token']),
            $this->jsonResponse(['resultCode' => 0, 'data' => ['url' => 'https://pep.shaparak.ir/pay/1']]),
        ]);

        $driver->amount(1000);
        $form = $driver->pay();

        $this->assertSame('https://pep.shaparak.ir/pay/1', $form->getAction());
        $this->assertSame('POST', $form->getMethod());

        $baseUrl = $this->settings()['baseUrl'];

        $this->assertRequestedUrl($baseUrl.'/token/getToken', 0);
        $this->assertRequestedUrl($baseUrl.'/api/payment/purchase', 1);
        $this->assertSame('Bearer the-token', $this->request(1)->getHeaderLine('Authorization'));

        $body = $this->requestJson(1);

        $this->assertSame(10000, $body['amount']);
        $this->assertSame('terminal-1', $body['terminalNumber']);
        $this->assertSame('PURCHASE', $body['serviceType']);
        $this->assertSame($this->settings()['callbackUrl'], $body['callbackApi']);
    }

    public function testPayUsesTheInvoiceDateOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['resultCode' => 0, 'token' => 'the-token']),
            $this->jsonResponse(['resultCode' => 0, 'data' => ['url' => 'https://pep.shaparak.ir/pay/1']]),
        ]);

        $driver->detail('date', '2024/01/01 10:20:30')->amount(1000)->pay();

        $this->assertSame('2024/01/01 10:20:30', $this->requestJson(1)['invoiceDate']);
    }

    public function testPayFailsWhenTheAuthenticationIsRefused(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['resultCode' => 1, 'resultMsg' => 'wrong credentials'])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('wrong credentials');

        $driver->amount(1000)->pay();
    }

    public function testPayFailsWhenTheGatewayDoesNotReturnAPaymentUrl(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['resultCode' => 0, 'token' => 'the-token']),
            $this->jsonResponse(['resultCode' => 0, 'data' => []]),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('مشکلی در دریافت اطلاعات از بانک به وجود آمده‌است.');

        $driver->amount(1000)->pay();
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['invoiceId' => 'invoice-1']);

        $driver = $this->driver(['currency' => 'T']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['resultCode' => 0, 'token' => 'the-token']),
            $this->jsonResponse([
                'resultCode' => 0,
                'data' => [
                    'status' => 13032,
                    'amount' => 10000,
                    'url' => 'url-id-1',
                    'transactionId' => 'transaction-1',
                    'trackId' => 'track-1',
                    'referenceNumber' => 'reference-1',
                ],
            ]),
            $this->jsonResponse(['resultCode' => 0, 'token' => 'the-token']),
            $this->jsonResponse(['resultCode' => 0, 'data' => ['maskedCardNumber' => '6037****1234']]),
        ]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('Pasargad', $receipt->getDriver());
        $this->assertSame('transaction-1', $receipt->getReferenceId());
        $this->assertSame('track-1', $receipt->getDetail('TraceNumber'));
        $this->assertSame('reference-1', $receipt->getDetail('ReferenceNumber'));
        $this->assertSame('url-id-1', $receipt->getDetail('urlId'));
        $this->assertSame('6037****1234', $receipt->getDetail('MaskedCardNumber'));

        $baseUrl = $this->settings()['baseUrl'];

        $this->assertRequestedUrl($baseUrl.'/api/payment/payment-inquiry', 1);
        $this->assertRequestedUrl($baseUrl.'/api/payment/confirm-transactions', 3);
        $this->assertSame(
            ['invoice' => 'invoice-1', 'urlId' => 'url-id-1'],
            $this->requestJson(3)
        );
    }

    public function testVerifyFailsWithTheTranslatedStatusOfTheGateway(): void
    {
        $this->fakeRequest(['invoiceId' => 'invoice-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['resultCode' => 0, 'token' => 'the-token']),
            $this->jsonResponse(['resultCode' => 0, 'data' => ['status' => 13022]]),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش پرداخت نشده‌است!');

        $driver->amount(1000)->verify();
    }

    public function testVerifyFailsWhenTheAmountDoesNotMatchTheInvoice(): void
    {
        $this->fakeRequest(['invoiceId' => 'invoice-1']);

        $driver = $this->driver(['currency' => 'T']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['resultCode' => 0, 'token' => 'the-token']),
            $this->jsonResponse(['resultCode' => 0, 'data' => ['status' => 13032, 'amount' => 5000]]),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('Invalid amount');

        $driver->amount(1000)->verify();
    }
}
