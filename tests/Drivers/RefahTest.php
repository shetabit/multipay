<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Refah\Refah;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;

class RefahTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'refah';
    }

    protected function driverClass() : string
    {
        return Refah::class;
    }

    public function testPurchaseReturnsTheTokenOfTheGateway(): void
    {
        $driver = $this->driver([
            'username' => 'refah-user',
            'password' => 'refah-password',
            'terminalNumber' => 'terminal-1',
        ]);
        $this->fakeHttp($driver, [$this->jsonResponse(['success' => true, 'data' => ['token' => 12345]])]);

        $this->assertSame(12345, $driver->amount(1000)->purchase());
        $this->assertSame(12345, $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);

        $body = $this->requestJson();

        $this->assertSame('refah-user', $body['userName']);
        $this->assertSame('refah-password', $body['password']);
        $this->assertSame('terminal-1', $body['terminalNumber']);
        $this->assertSame($this->settings()['callbackUrl'], $body['callBack']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => 'T']);
        $this->fakeHttp($driver, [$this->jsonResponse(['success' => true, 'data' => ['token' => 1]])]);

        $driver->amount(1000)->purchase();

        $this->assertSame(10000, $this->requestJson()['amount']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => 'R']);
        $this->fakeHttp($driver, [$this->jsonResponse(['success' => true, 'data' => ['token' => 1]])]);

        $driver->detail(['mobile' => '09120000000', 'description' => 'a description'])->amount(1000)->purchase();

        $body = $this->requestJson();

        $this->assertSame(1000, $body['amount']);
        $this->assertSame('09120000000', $body['celNo']);
        $this->assertSame('a description', $body['additionalData']);
    }

    public function testPurchaseTurnsTheUuidOfTheInvoiceIntoTheOrderId(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['success' => true, 'data' => ['token' => 1]])]);

        $driver->amount(1000)->purchase();

        $this->assertSame((string) $driver->getInvoice()->getUuid(), $this->requestJson()['orderId']);
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $driver->getInvoice()->getUuid());
    }

    public function testPurchaseFailsWithTheErrorOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['success' => false, 'errors' => [['message' => 'the terminal is not valid']]]),
        ]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the terminal is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWhenTheGatewayReturnsNoToken(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['success' => true, 'data' => ['token' => 0]])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('Error');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheTokenOfThePurchase(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['success' => true, 'data' => ['token' => 12345]])]);

        $driver->amount(1000)->purchase();

        $form = $driver->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'], $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame(['token' => 12345], $form->getInputs());
    }

    public function testVerifyReturnsAReceiptWithTheReferenceNumber(): void
    {
        $this->fakeRequest(['RRN' => 100, 'status' => 0, 'Token' => 'token-1']);

        $driver = $this->driver(['username' => 'refah-user', 'currency' => 'T']);
        $this->fakeHttp($driver, [$this->jsonResponse(['success' => true, 'data' => ['rrn' => 'rrn-1']])]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('refah', $receipt->getDriver());
        $this->assertSame('rrn-1', $receipt->getReferenceId());
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $body = $this->requestJson();

        $this->assertSame('token-1', $body['token']);
        $this->assertSame(10000, $body['amount']);
        $this->assertSame('refah-user', $body['userName']);
    }

    public function testVerifyFailsWhenTheCallbackReportsAnUnsuccessfulPayment(): void
    {
        $this->fakeRequest(['RRN' => 0, 'status' => 1]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('Verify failed with status code : 1');

        $this->driver()->amount(1000)->verify();
    }

    public function testVerifyFailsWhenTheGatewayRefusesTheConfirmation(): void
    {
        $this->fakeRequest(['RRN' => 100, 'status' => 0, 'Token' => 'token-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['success' => false, 'errors' => [['message' => 'the token is not valid']]]),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('the token is not valid');

        $driver->amount(1000)->verify();
    }
}
