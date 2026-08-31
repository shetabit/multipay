<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Payping\Payping;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class PaypingTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'payping';
    }

    protected function driverClass() : string
    {
        return Payping::class;
    }

    public function testPurchaseSendsTheInvoiceToTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'payping-token']);
        $this->fakeHttp($driver, [$this->jsonResponse(['paymentCode' => 'payment-1'])]);

        $driver->detail([
            'name' => 'john doe',
            'mobile' => '09120000000',
            'description' => 'a description',
        ])->amount(1000);

        $driver->purchase();

        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
        $this->assertSame('bearer payping-token', $this->request()->getHeaderLine('Authorization'));

        $body = $this->requestJson();

        $this->assertSame(1000, $body['amount']);
        $this->assertSame($this->settings()['callbackUrl'], $body['returnUrl']);
        $this->assertSame('09120000000', $body['payerIdentity']);
        $this->assertSame('john doe', $body['payerName']);
        $this->assertSame('a description', $body['description']);
        $this->assertSame($driver->getInvoice()->getUuid(), $body['clientRefId']);
    }

    public function testPurchaseSendsTheAmountInToman(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL]);
        $this->fakeHttp($driver, [$this->jsonResponse(['paymentCode' => 'payment-1'])]);

        $driver->amount(10000)->purchase();

        $this->assertSame(1000, $this->requestJson()['amount']);
    }

    public function testPurchaseFallsBackToTheEmailAsPayerIdentity(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['paymentCode' => 'payment-1'])]);

        $driver->detail('email', 'john@example.com')->amount(1000)->purchase();

        $this->assertSame('john@example.com', $this->requestJson()['payerIdentity']);
    }

    public function testPurchaseReturnsThePaymentCodeOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['paymentCode' => 'payment-1'])]);

        $this->assertSame('payment-1', $driver->amount(1000)->purchase());
        $this->assertSame('payment-1', $driver->getInvoice()->getTransactionId());
    }

    /**
     * The gateway is not consistent about the case of its response keys.
     */
    public function testPurchaseReadsThePaymentCodeCaseInsensitively(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['paymentcode' => 'payment-1'])]);

        $this->assertSame('payment-1', $driver->amount(1000)->purchase());
    }

    public function testPurchaseFailsWhenTheGatewayReturnsNoPaymentCode(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse([])]);

        $this->expectException(PurchaseFailedException::class);

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWithTheMessageOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['amount' => 'the amount is not valid'], 400)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the amount is not valid');

        $driver->amount(1000)->purchase();
    }

    public function testPurchaseFailsWithATranslatedStatusCode(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->response('', 503)]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('سرور درگاه پرداخت در حال حاضر قادر به پاسخگویی نمی باشد');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithThePaymentCode(): void
    {
        $invoice = (new Invoice)->transactionId('payment-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'payment-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceiptWithTheReferenceIdOfTheCallback(): void
    {
        $this->fakeRequest(['refid' => 'reference-1']);

        $driver = $this->driver(['merchantId' => 'payping-token']);
        $this->fakeHttp($driver, [$this->jsonResponse(['cardNumber' => '6037****1234'])]);

        $receipt = $driver->verify();

        $this->assertSame('payping', $receipt->getDriver());
        $this->assertSame('reference-1', $receipt->getReferenceId());
        $this->assertSame('6037****1234', $receipt->getDetail('cardNumber'));
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);
        $this->assertSame('reference-1', $this->requestJson()['paymentRefId']);
    }

    public function testVerifyFailsWithTheMessageOfTheGateway(): void
    {
        $this->fakeRequest(['refid' => 'reference-1']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['error' => 'the payment was not found'], 404)]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('the payment was not found');

        $driver->verify();
    }
}
