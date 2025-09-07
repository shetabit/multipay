<?php

namespace Shetabit\Multipay\Tests;

use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Tests\Drivers\XenditDriver;
use Shetabit\Multipay\Tests\Mocks\MockPaymentManager;

class XenditPaymentTest extends TestCase
{
    protected function getXenditConfig(): array
    {
        $config = $this->config();
        
        // Add Xendit test driver configuration
        $config['drivers']['xendit_test'] = [
            'apiUrl' => 'https://api.xendit.co/',
            'secretKey' => 'test_secret_key',
            'currency' => 'IDR',
            'invoiceDuration' => 86400,
            'payerEmail' => 'test@example.com',
            'customerName' => 'Test Customer',
            'notificationEmail' => 'notify@example.com',
            'successReturnUrl' => 'http://example.com/success',
            'failureReturnUrl' => 'http://example.com/failure',
            'paymentMethods' => ['BANK_TRANSFER', 'EWALLET', 'CREDIT_CARD'],
            'description' => 'Test payment via Xendit',
        ];
        
        // Add Xendit test driver mapping
        $config['map']['xendit_test'] = XenditDriver::class;
        
        return $config;
    }

    protected function getXenditManagerInstance(): MockPaymentManager
    {
        return new MockPaymentManager($this->getXenditConfig());
    }

    public function testXenditDriverCanBecreated(): void
    {
        $manager = $this->getXenditManagerInstance();
        $manager->via('xendit_test');
        
        $this->assertEquals('xendit_test', $manager->getDriver());
    }

    public function testXenditPurchase(): void
    {
        $amount = 100000; // IDR 100,000
        $manager = $this->getXenditManagerInstance();

        $manager
            ->via('xendit_test')
            ->amount($amount)
            ->purchase(null, function ($driver, $transactionId) use ($amount): void {
                $this->assertEquals(XenditDriver::TRANSACTION_ID, $transactionId);
                $this->assertSame($amount, $driver->getInvoice()->getAmount());
            });
    }

    public function testXenditCustomInvoiceCanBeUsedInPurchase(): void
    {
        $manager = $this->getXenditManagerInstance();

        $invoice = new Invoice();
        $invoice->amount(250000); // IDR 250,000
        $invoice->detail('order_id', 'ORDER-123');
        $invoice->detail('customer_name', 'John Doe');

        $manager
            ->via('xendit_test')
            ->purchase($invoice, function ($driver, $transactionId) use ($invoice): void {
                $this->assertEquals(XenditDriver::TRANSACTION_ID, $transactionId);
                $this->assertSame($invoice->getAmount(), $driver->getInvoice()->getAmount());
                $this->assertEquals('ORDER-123', $driver->getInvoice()->getDetail('order_id'));
                $this->assertEquals('John Doe', $driver->getInvoice()->getDetail('customer_name'));
            });
    }

    public function testXenditPay(): void
    {
        $amount = 150000; // IDR 150,000
        $manager = $this->getXenditManagerInstance();

        $redirectionForm = $manager->amount($amount)->via('xendit_test')->purchase()->pay();
        $inputs = $redirectionForm->getInputs();

        $this->assertInstanceOf(RedirectionForm::class, $redirectionForm);
        $this->assertEquals('GET', $redirectionForm->getMethod());
        $this->assertStringContainsString('checkout.xendit.co', $redirectionForm->getAction());
        $this->assertEmpty($inputs); // v2/invoices uses direct URL redirect
    }

    public function testXenditVerify(): void
    {
        $amount = 75000; // IDR 75,000
        $manager = $this->getXenditManagerInstance();

        $receipt = $manager
            ->amount($amount)
            ->via('xendit_test')
            ->transactionId(XenditDriver::TRANSACTION_ID)
            ->verify();

        $this->assertSame(XenditDriver::REFERENCE_ID, $receipt->getReferenceId());
        $this->assertEquals('xendit', $receipt->getDriver());
        $this->assertEquals(XenditDriver::REFERENCE_ID, $receipt->getDetail('external_id'));
        $this->assertEquals('BANK_TRANSFER', $receipt->getDetail('payment_method'));
        $this->assertEquals('BCA', $receipt->getDetail('payment_channel'));
        $this->assertNotEmpty($receipt->getDetail('paid_at'));
    }

    public function testXenditConfigCanBeModified(): void
    {
        $manager = $this->getXenditManagerInstance();
        $manager->via('xendit_test');

        // Test modifying secret key
        $manager->config('secretKey', 'new_test_secret_key');
        $config = $manager->getCurrentDriverSetting();

        $this->assertArrayHasKey('secretKey', $config);
        $this->assertSame('new_test_secret_key', $config['secretKey']);
        
        // Test modifying currency
        $manager->config('currency', 'USD');
        $config = $manager->getCurrentDriverSetting();
        
        $this->assertEquals('USD', $config['currency']);
    }

    public function testXenditMultipleConfigModification(): void
    {
        $manager = $this->getXenditManagerInstance();
        $manager->via('xendit_test');

        $manager->config([
            'currency' => 'SGD',
            'invoiceDuration' => 172800,
            'paymentMethods' => ['EWALLET', 'CREDIT_CARD'],
            'customerName' => 'Updated Customer'
        ]);

        $config = $manager->getCurrentDriverSetting();

        $this->assertEquals('SGD', $config['currency']);
        $this->assertEquals(172800, $config['invoiceDuration']);
        $this->assertEquals(['EWALLET', 'CREDIT_CARD'], $config['paymentMethods']);
        $this->assertEquals('Updated Customer', $config['customerName']);
    }

    public function testXenditPaymentFlow(): void
    {
        $amount = 500000; // IDR 500,000
        $manager = $this->getXenditManagerInstance();

        // Create invoice with metadata
        $invoice = new Invoice();
        $invoice->amount($amount);
        $invoice->detail('product', 'Premium Subscription');
        $invoice->detail('customer_email', 'customer@example.com');

        // Full payment flow test
        $manager
            ->via('xendit_test')
            ->purchase($invoice, function ($driver, $transactionId) use ($amount): void {
                // Purchase callback
                $this->assertEquals(XenditDriver::TRANSACTION_ID, $transactionId);
                $this->assertEquals($amount, $driver->getInvoice()->getAmount());
                $this->assertEquals('Premium Subscription', $driver->getInvoice()->getDetail('product'));
            });

        // Test payment redirection
        $redirectionForm = $manager->pay();
        $this->assertInstanceOf(RedirectionForm::class, $redirectionForm);
        $this->assertStringContainsString(XenditDriver::TRANSACTION_ID, $redirectionForm->getAction());

        // Test verification
        $receipt = $manager->verify();
        $this->assertEquals('xendit', $receipt->getDriver());
        $this->assertEquals(XenditDriver::REFERENCE_ID, $receipt->getReferenceId());
    }
}
