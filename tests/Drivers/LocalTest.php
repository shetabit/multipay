<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Drivers\Local\Local;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use Shetabit\Multipay\Tests\TestCase;

class LocalTest extends TestCase
{
    public function testPurchaseGeneratesATransactionId(): void
    {
        $driver = $this->getDriver();

        $transactionId = $driver->purchase();

        $this->assertNotEmpty($transactionId);
        $this->assertMatchesRegularExpression('/^\d+$/', $transactionId);
        $this->assertSame($transactionId, (string) $driver->getInvoice()->getTransactionId());
    }

    public function testPurchaseFailsWhenTheInvoiceIsMarkedAsFailed(): void
    {
        $driver = $this->getDriver();
        $driver->detail('failedPurchase', 'the gateway is unavailable');

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('the gateway is unavailable');

        $driver->purchase();
    }

    public function testPayBuildsTheLocalGatewayForm(): void
    {
        $driver = $this->getDriver();
        $driver->detail('orderId', 'order-1');
        $driver->amount(120000);
        $driver->purchase();

        $form = $driver->pay();
        $inputs = $form->getInputs();

        $this->assertInstanceOf(RedirectionForm::class, $form);
        $this->assertSame('POST', $form->getMethod());
        $this->assertSame('order-1', $inputs['orderId']);
        $this->assertSame('120,000', $inputs['price']);
        $this->assertSame($this->settings()['title'], $inputs['title']);
        $this->assertSame($this->settings()['description'], $inputs['description']);
        $this->assertSame($this->settings()['orderLabel'], $inputs['orderLabel']);
        $this->assertSame($this->settings()['amountLabel'], $inputs['amountLabel']);
        $this->assertSame($this->settings()['payButton'], $inputs['payButton']);
        $this->assertSame($this->settings()['cancelButton'], $inputs['cancelButton']);
    }

    public function testPayAddsTheTransactionIdToTheCallbackUrls(): void
    {
        $driver = $this->getDriver();
        $transactionId = $driver->purchase();

        $inputs = $driver->pay()->getInputs();

        $this->assertSame('/callback?transactionId='.$transactionId, $inputs['successUrl']);
        $this->assertSame('/callback?transactionId='.$transactionId.'&cancel=true', $inputs['cancelUrl']);
    }

    public function testPayKeepsAnExistingQueryStringOfTheCallbackUrl(): void
    {
        $driver = $this->getDriver(['callbackUrl' => '/callback?foo=bar']);
        $transactionId = $driver->purchase();

        $inputs = $driver->pay()->getInputs();

        $this->assertSame('/callback?foo=bar&transactionId='.$transactionId, $inputs['successUrl']);
    }

    public function testPayUsesTheLocalGatewayView(): void
    {
        $driver = $this->getDriver();
        $driver->purchase();
        $driver->pay();

        $this->assertStringEndsWith('resources/views/local-form.php', RedirectionForm::getViewPath());
        $this->assertFileExists(RedirectionForm::getViewPath());
    }

    public function testVerifyReturnsAReceiptForASuccessfulPayment(): void
    {
        $this->fakeRequest(['transactionId' => '1234567']);

        $receipt = $this->getDriver()->verify();

        $this->assertSame('local', $receipt->getDriver());
        $this->assertSame('1234567', $receipt->getReferenceId());
        $this->assertSame('1234567', $receipt->getDetail('referenceNo'));
        $this->assertNotEmpty($receipt->getDetail('orderId'));
        $this->assertNotEmpty($receipt->getDetail('traceNo'));
        $this->assertNotEmpty($receipt->getDetail('cardNo'));
    }

    public function testVerifyTakesTheReceiptDetailsFromTheInvoiceWhenAvailable(): void
    {
        $this->fakeRequest(['transactionId' => '1234567']);

        $driver = $this->getDriver();
        $driver->detail([
            'orderId' => 'order-1',
            'traceNo' => 'trace-1',
            // The driver reads the card number from the "cartNo" detail.
            'cartNo' => '6037-****-****-1234',
        ]);

        $receipt = $driver->verify();

        $this->assertSame('order-1', $receipt->getDetail('orderId'));
        $this->assertSame('trace-1', $receipt->getDetail('traceNo'));
        $this->assertSame('6037-****-****-1234', $receipt->getDetail('cardNo'));
    }

    public function testVerifyFailsWhenThePaymentIsCanceled(): void
    {
        $this->fakeRequest(['transactionId' => '1234567', 'cancel' => 'true']);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('تراکنش توسط خریدار لغو شده است.');

        $this->getDriver()->verify();
    }

    public function testVerifyFailsWhenTheTransactionIdIsMissing(): void
    {
        $this->fakeRequest([]);

        $this->expectException(InvalidPaymentException::class);

        $this->getDriver()->verify();
    }

    private function getDriver(array $overrides = []) : Local
    {
        return new Local(new Invoice, array_merge($this->settings(), $overrides));
    }

    private function settings() : array
    {
        return array_merge($this->config()['drivers']['local'], ['callbackUrl' => '/callback']);
    }

    private function fakeRequest(array $data) : void
    {
        Request::overwrite('input', fn ($name) => $data[$name] ?? null);
    }
}
