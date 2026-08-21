<?php

namespace Shetabit\Multipay\Tests\Drivers;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Drivers\Xendit\Xendit;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;

class XenditTest extends DriverTestCase
{
    protected function driverName() : string
    {
        return 'xendit';
    }

    protected function driverClass() : string
    {
        return Xendit::class;
    }

    /**
     * The driver talks to the xendit api with relative urls.
     */
    protected function fakeHttp(Driver $driver, array $responses, array $clientOptions = []) : void
    {
        parent::fakeHttp($driver, $responses, $clientOptions + ['base_uri' => $this->settings()['apiUrl']]);
    }

    public function testPurchaseReturnsTheInvoiceIdOfXendit(): void
    {
        $driver = $this->driver(['secretKey' => 'xendit-secret']);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'invoice-1', 'invoice_url' => 'https://checkout.xendit.co/web/invoice-1']),
        ]);

        $this->assertSame('invoice-1', $driver->amount(100000)->purchase());
        $this->assertRequestedUrl($this->settings()['apiUrl'].'v2/invoices');
    }

    public function testPurchaseSendsTheInvoiceToXendit(): void
    {
        $invoice = new Invoice;
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'invoice-1', 'invoice_url' => 'https://checkout.xendit.co/web/invoice-1']),
        ]);

        $driver->amount(100000)->purchase();

        $body = $this->requestJson();
        $settings = $this->settings();

        $this->assertSame($invoice->getUuid(), $body['external_id']);
        $this->assertSame(100000, $body['amount']);
        $this->assertSame($settings['currency'], $body['currency']);
        $this->assertSame($settings['description'], $body['description']);
        $this->assertSame($settings['invoiceDuration'], $body['invoice_duration']);
        $this->assertSame($settings['successReturnUrl'], $body['success_redirect_url']);
        $this->assertSame($settings['failureReturnUrl'], $body['failure_redirect_url']);
        $this->assertSame($settings['paymentMethods'], $body['payment_methods']);
    }

    /**
     * Fetching an invoice from the gateway must not get in the way of
     * `Driver::getInvoice()`, which the payment manager and the payment events use
     * to read the invoice a driver is working on.
     */
    public function testItKeepsTheInvoiceOfTheDriverApartFromTheOneOfTheGateway(): void
    {
        $invoice = (new Invoice)->transactionId('invoice-1');
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [$this->jsonResponse(['id' => 'invoice-1', 'status' => 'PAID'])]);

        $this->assertSame($invoice, $driver->getInvoice());
        $this->assertSame(0, $this->requestCount());

        $this->assertSame(['id' => 'invoice-1', 'status' => 'PAID'], $driver->fetchInvoice('invoice-1'));
        $this->assertRequestedUrl($this->settings()['apiUrl'].'v2/invoices/invoice-1');
    }

    public function testPurchaseSendsTheOptionalCustomerSettings(): void
    {
        $driver = $this->driver([
            'payerEmail' => 'john@example.com',
            'customerName' => 'john doe',
            'notificationEmail' => 'notify@example.com',
        ]);
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'invoice-1', 'invoice_url' => 'https://checkout.xendit.co/web/invoice-1']),
        ]);

        $driver->amount(100000)->purchase();

        $body = $this->requestJson();

        $this->assertSame('john@example.com', $body['payer_email']);
        $this->assertSame(['given_names' => 'john doe'], $body['customer']);
        $this->assertSame(
            ['notify@example.com'],
            $body['customer_notification_preference']['invoice_paid']
        );
    }

    public function testPurchaseSendsTheDetailsOfTheInvoiceAsMetadata(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'invoice-1', 'invoice_url' => 'https://checkout.xendit.co/web/invoice-1']),
        ]);

        $driver->detail(['order_id' => 'ORDER-123'])->amount(100000)->purchase();


        $this->assertSame(['order_id' => 'ORDER-123'], $this->requestJson()['metadata']);
    }

    public function testPurchaseFailsWhenXenditReturnsNoInvoice(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [$this->jsonResponse(['error_code' => 'API_VALIDATION_ERROR'])]);

        $this->expectException(PurchaseFailedException::class);
        $this->expectExceptionMessage('Failed to create invoice: API_VALIDATION_ERROR');

        $driver->amount(100000)->purchase();
    }

    public function testPayRedirectsToTheInvoiceUrlOfThePurchase(): void
    {
        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'invoice-1', 'invoice_url' => 'https://checkout.xendit.co/web/invoice-1']),
        ]);

        $driver->amount(100000)->purchase();

        $form = $driver->pay();

        $this->assertSame('https://checkout.xendit.co/web/invoice-1', $form->getAction());
        $this->assertSame('GET', $form->getMethod());
        $this->assertSame([], $form->getInputs());
    }

    public function testPayNeedsAPurchaseFirst(): void
    {
        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('Invoice URL not found. Call purchase() first.');

        $this->driver()->pay();
    }

    public function testVerifyReturnsAReceipt(): void
    {
        $invoice = (new Invoice)->transactionId('invoice-1');
        $driver = $this->driver([], $invoice);
        $this->fakeHttp($driver, [
            $this->jsonResponse([
                'id' => 'invoice-1',
                'status' => 'PAID',
                'amount' => 100000,
                'external_id' => 'external-1',
                'payment_method' => 'BANK_TRANSFER',
                'payment_channel' => 'BCA',
                'paid_at' => '2024-01-01T10:20:30Z',
            ]),
        ]);

        $receipt = $driver->amount(100000)->verify();

        $this->assertSame('xendit', $receipt->getDriver());
        $this->assertSame('invoice-1', $receipt->getReferenceId());
        $this->assertSame('external-1', $receipt->getDetail('external_id'));
        $this->assertSame('BANK_TRANSFER', $receipt->getDetail('payment_method'));
        $this->assertSame('BCA', $receipt->getDetail('payment_channel'));
        $this->assertRequestedUrl($this->settings()['apiUrl'].'v2/invoices/invoice-1');
        $this->assertSame('GET', $this->request()->getMethod());
    }

    public function testVerifyFallsBackToTheInvoiceIdOfTheCallback(): void
    {
        $this->fakeRequest(['invoice_id' => 'invoice-from-callback']);

        $driver = $this->driver();
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'invoice-from-callback', 'status' => 'PAID', 'amount' => 100000]),
        ]);

        $driver->amount(100000)->verify();

        $this->assertRequestedUrl($this->settings()['apiUrl'].'v2/invoices/invoice-from-callback');
    }

    public function testVerifyNeedsAnInvoiceId(): void
    {
        $this->fakeRequest([]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('Invoice ID not found for verification.');

        $this->driver()->verify();
    }

    public function testVerifyFailsWhenTheInvoiceWasNotPaid(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('invoice-1'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'invoice-1', 'status' => 'EXPIRED', 'amount' => 100000]),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('Invoice not paid. Status: EXPIRED');

        $driver->amount(100000)->verify();
    }

    public function testVerifyFailsWhenTheAmountDoesNotMatchTheInvoice(): void
    {
        $driver = $this->driver([], (new Invoice)->transactionId('invoice-1'));
        $this->fakeHttp($driver, [
            $this->jsonResponse(['id' => 'invoice-1', 'status' => 'PAID', 'amount' => 50000]),
        ]);

        $this->expectException(InvalidPaymentException::class);
        $this->expectExceptionMessage('Invoice amount mismatch.');

        $driver->amount(100000)->verify();
    }
}
