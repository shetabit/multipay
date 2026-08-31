<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Shepa\Shepa;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class ShepaTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'shepa';
    }

    protected function driverClass() : string
    {
        return Shepa::class;
    }

    public function testPurchaseReturnsTheTokenOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'shepa-api-key']);
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => ['token' => 'token-1']])]);

        $this->assertSame('token-1', $driver->amount(1000)->purchase());
        $this->assertSame('token-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('shepa-api-key', $this->requestForm()['api']);
    }

    public function testPurchaseSendsTheAmountInRial(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => ['token' => 'token-1']])]);

        $driver->amount(1000)->purchase();

        $this->assertSame('10000', $this->requestForm()['amount']);
    }

    public function testPurchaseSendsTheDetailsOfTheInvoice(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => ['token' => 'token-1']])]);

        $driver->detail([
            'mobile' => '09120000000',
            'email' => 'john@example.com',
            'cardnumber' => '6037********1234',
            'description' => 'a description',
        ])->amount(1000)->purchase();

        $form = $this->requestForm();

        $this->assertSame('1000', $form['amount']);
        $this->assertSame('09120000000', $form['mobile']);
        $this->assertSame('john@example.com', $form['email']);
        $this->assertSame('6037********1234', $form['cardnumber']);
        $this->assertSame('a description', $form['description']);
    }

    public function testPurchaseUsesTheSandboxInSandboxMode(): void
    {
        $driver = $this->driver(['sandbox' => true]);
        $this->fakeHttp($driver, [$this->jsonResponse(['result' => ['token' => 'token-1']])]);

        $driver->amount(1000)->purchase();

        $this->assertRequestedUrl($this->settings()['sandboxApiPurchaseUrl']);
    }

    public function testPurchaseFailsWithTheErrorsOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['errors' => ['amount is too low', 'api key is wrong']])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('amount is too low, api key is wrong');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheToken(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'token-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testPayRedirectsToTheSandboxInSandboxMode(): void
    {
        $invoice = (new Invoice)->transactionId('token-1');
        $form = $this->driver(['sandbox' => true], $invoice)->pay();

        $this->assertSame($this->settings()['sandboxApiPaymentUrl'].'token-1', $form->getAction());
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $this->fakeRequest(['status' => 'success', 'token' => 'token-1']);

        $driver = $this->driver(['merchantId' => 'shepa-api-key']);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'result' => [
                    'refid' => 'reference-1',
                    'transaction_id' => 'transaction-1',
                    'amount' => 1000,
                    'card_pan' => '6037****1234',
                    'date' => '2024-01-01',
                ],
            ]),
        ]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('shepa', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame('reference-1', $receipt->getDetail('refid'));
        $this->assertSame('transaction-1', $receipt->getDetail('transaction_id'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);
        $this->assertSame('token-1', $this->requestJson()['token']);
    }

    public function testVerifyFailsWhenTheUserCanceledThePayment(): void
    {
        $this->fakeRequest(['status' => 'cancel']);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش از سوی کاربر لغو شد.');

        $this->driver()->verify();
    }

    public function testVerifyFailsWithTheErrorOfTheGateway(): void
    {
        $this->fakeRequest(['status' => 'success', 'token' => 'token-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['error' => ['token is not valid']])]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('token is not valid');

        $driver->amount(1000)->verify();
    }
}
