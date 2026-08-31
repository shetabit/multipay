<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Nextpay\Nextpay;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Constants\IranCurrency;

class NextpayTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'nextpay';
    }

    protected function driverClass() : string
    {
        return Nextpay::class;
    }

    public function testPurchaseReturnsTheTransactionIdOfTheGateway(): void
    {
        $driver = $this->driver(['merchantId' => 'nextpay-key']);
        $this->fakeHttp($driver, [$this->jsonResponse(['code' => -1, 'trans_id' => 'transaction-1'])]);

        $this->assertSame('transaction-1', $driver->amount(1000)->purchase());
        $this->assertSame('transaction-1', $driver->getInvoice()->getTransactionId());
        $this->assertRequestedUrl($this->settings()['apiPurchaseUrl']);
    }

    public function testPurchaseSendsTheAmountInToman(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::RIAL, 'merchantId' => 'nextpay-key']);
        $this->fakeHttp($driver, [$this->jsonResponse(['code' => -1, 'trans_id' => 'transaction-1'])]);

        $driver->amount(10000)->purchase();

        $form = $this->requestForm();

        $this->assertSame('1000', $form['amount']);
        $this->assertSame('nextpay-key', $form['api_key']);
        $this->assertSame($this->settings()['callbackUrl'], $form['callback_uri']);
    }

    public function testPurchaseKeepsTheAmountWhenTheCurrencyIsToman(): void
    {
        $driver = $this->driver(['currency' => IranCurrency::TOMAN]);
        $this->fakeHttp($driver, [$this->jsonResponse(['code' => -1, 'trans_id' => 'transaction-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertSame('1000', $this->requestForm()['amount']);
    }

    public function testPurchaseSendsTheOptionalDetailsOfTheInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['code' => -1, 'trans_id' => 'transaction-1'])]);

        $driver->detail([
            'customer_phone' => '09120000000',
            'payer_name' => 'john doe',
            'payer_desc' => 'a description',
            'auto_verify' => 'yes',
            'allowed_card' => '6037********1234',
        ])->amount(1000)->purchase();

        $form = $this->requestForm();

        $this->assertSame('09120000000', $form['customer_phone']);
        $this->assertSame('john doe', $form['payer_name']);
        $this->assertSame('a description', $form['payer_desc']);
        $this->assertSame('yes', $form['auto_verify']);
        $this->assertSame('6037********1234', $form['allowed_card']);
    }

    public function testPurchaseOmitsTheOptionalDetailsThatWereNotGiven(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['code' => -1, 'trans_id' => 'transaction-1'])]);

        $driver->amount(1000)->purchase();

        $this->assertSame(
            ['api_key', 'order_id', 'amount', 'callback_uri'],
            array_keys($this->requestForm())
        );
    }

    public function testPurchaseFailsWithTheTranslatedErrorOfTheGateway(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['code' => -33])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('کلید مجوز دهی صحیح نیست');

        $driver->amount(1000)->purchase();
    }

    public function testPayRedirectsToTheGatewayWithTheTransactionId(): void
    {
        $invoice = (new Invoice)->transactionId('transaction-1');
        $form = $this->driver([], $invoice)->pay();

        $this->assertSame($this->settings()['apiPaymentUrl'].'transaction-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
    }

    public function testVerifyReturnsAReceiptWithTheShaparakReferenceId(): void
    {
        $this->fakeRequest(['order_id' => 'order-1']);

        $invoice = (new Invoice)->transactionId('transaction-1');
        $driver = $this->driver(['merchantId' => 'nextpay-key'], $invoice);
        $this->fakeHttp($driver, [$this->jsonResponse(['code' => 0, 'Shaparak_Ref_Id' => 'shaparak-1'])]);

        $receipt = $driver->amount(1000)->verify();

        $this->assertSame('nextpay', $receipt->getDriver());
        $this->assertSame('shaparak-1', $receipt->getReferenceId());
        $this->assertRequestedUrl($this->settings()['apiVerificationUrl']);

        $form = $this->requestForm();

        $this->assertSame('transaction-1', $form['trans_id']);
        $this->assertSame('order-1', $form['order_id']);
    }

    public function testVerifyFallsBackToTheTransactionIdOfTheCallback(): void
    {
        $this->fakeRequest(['trans_id' => 'transaction-from-callback']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['code' => 0, 'Shaparak_Ref_Id' => 'shaparak-1'])]);

        $driver->amount(1000)->verify();

        $this->assertSame('transaction-from-callback', $this->requestForm()['trans_id']);
    }

    public function testVerifyFailsWithTheTranslatedErrorOfTheGateway(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('transaction-1'));
        $this->fakeHttp($driver, [$this->jsonResponse(['code' => -2])]);

        $this->expectException(InvalidPaymentException::class);

        $driver->amount(1000)->verify();
    }
}
