<?php

/**
 * Xendit Payment Gateway Example
 * 
 * This example demonstrates how to use the Xendit payment gateway
 * with the Multipay package for Southeast Asian payments.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Payment;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;

// Load payment configuration
$paymentConfig = require(__DIR__ . '/../config/payment.php');

// Configure Xendit credentials
$paymentConfig['drivers']['xendit']['secretKey'] = 'your-xendit-secret-key-here';
$paymentConfig['drivers']['xendit']['successReturnUrl'] = 'https://yoursite.com/payment/success';
$paymentConfig['drivers']['xendit']['failureReturnUrl'] = 'https://yoursite.com/payment/failure';

// Initialize payment manager
$payment = new Payment($paymentConfig);

// Example 1: Invoice Payment with Bank Transfer
echo "=== Xendit Invoice Payment Example ===\n";

try {
    // Create invoice for IDR 100,000
    $invoice = new Invoice();
    $invoice->amount(100000); // IDR 100,000
    $invoice->detail('customer_name', 'John Doe');
    $invoice->detail('customer_email', 'john.doe@example.com');
    $invoice->detail('order_id', 'ORDER-' . date('YmdHis'));

    // Configure for Invoice payment
    $payment->via('xendit')
           ->config('currency', 'IDR')
           ->config('paymentMethods', ['BANK_TRANSFER', 'EWALLET'])
           ->config('customerName', 'John Doe')
           ->config('payerEmail', 'john.doe@example.com');

    // Purchase the invoice
    $payment->purchase($invoice, function ($driver, $transactionId) {
        echo "Payment request created successfully!\n";
        echo "Transaction ID: {$transactionId}\n";
        
        // In real application, save this transaction ID to database
        // for later verification
    });

    // Get payment redirection form
    $redirectionForm = $payment->pay();
    
    echo "Invoice URL: " . $redirectionForm->getAction() . "\n";
    echo "HTTP Method: " . $redirectionForm->getMethod() . "\n";
    echo "Redirect user to this URL to complete payment\n\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Example 2: Invoice with E-Wallet Options
echo "=== Xendit E-Wallet Invoice Example ===\n";

try {
    // Create invoice for IDR 50,000
    $invoice = new Invoice();
    $invoice->amount(50000); // IDR 50,000
    $invoice->detail('product', 'Premium Subscription');
    $invoice->detail('customer_phone', '+6281234567890');

    // Configure for E-Wallet payment options
    $payment->via('xendit')
           ->config('currency', 'IDR')
           ->config('paymentMethods', ['EWALLET'])
           ->config('customerName', 'Premium Customer')
           ->config('invoiceDuration', 172800); // 48 hours

    // Purchase and redirect
    $payment->purchase($invoice, function ($driver, $transactionId) {
        echo "Invoice created for E-Wallet payment!\n";
        echo "Invoice ID: {$transactionId}\n";
    });

    $redirectionForm = $payment->pay();
    echo "E-Wallet Invoice URL: " . $redirectionForm->getAction() . "\n\n";

} catch (Exception $e) {
    echo "E-Wallet Error: " . $e->getMessage() . "\n\n";
}

// Example 3: Invoice Verification (callback handling)
echo "=== Invoice Verification Example ===\n";

// Simulate invoice verification (normally done in callback URL)
$invoiceId = '624b8d1c8f1a9b001c7e0c5e';

try {
    // Verify invoice payment
    $receipt = $payment->via('xendit')
                      ->transactionId($invoiceId)
                      ->verify();

    echo "Invoice payment verified successfully!\n";
    echo "Driver: " . $receipt->getDriver() . "\n";
    echo "Invoice ID: " . $receipt->getReferenceId() . "\n";
    echo "External ID: " . $receipt->getDetail('external_id') . "\n";
    echo "Payment Method: " . $receipt->getDetail('payment_method') . "\n";
    echo "Payment Channel: " . $receipt->getDetail('payment_channel') . "\n";
    echo "Paid At: " . $receipt->getDetail('paid_at') . "\n";

} catch (InvalidPaymentException $e) {
    echo "Invoice verification failed: " . $e->getMessage() . "\n";
}

// Example 4: Multi-Currency Support
echo "\n=== Multi-Currency Example ===\n";

$currencies = [
    ['currency' => 'PHP', 'country' => 'PH', 'amount' => 2500], // Philippine Peso
    ['currency' => 'SGD', 'country' => 'SG', 'amount' => 50],   // Singapore Dollar
    ['currency' => 'MYR', 'country' => 'MY', 'amount' => 200],  // Malaysian Ringgit
];

foreach ($currencies as $config) {
    try {
        $invoice = new Invoice();
        $invoice->amount($config['amount']);
        
        echo "Creating payment for {$config['amount']} {$config['currency']} in {$config['country']}\n";
        
        $payment->via('xendit')
               ->config('currency', $config['currency'])
               ->config('country', $config['country'])
               ->config('paymentMethodType', 'VIRTUAL_ACCOUNT');

        $payment->purchase($invoice, function ($driver, $transactionId) use ($config) {
            echo "✓ {$config['currency']} payment created: {$transactionId}\n";
        });
        
    } catch (Exception $e) {
        echo "✗ {$config['currency']} error: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Example completed ===\n";
echo "Remember to:\n";
echo "1. Set your actual Xendit secret key\n";
echo "2. Configure your callback URLs\n";
echo "3. Handle webhooks for real-time payment status updates\n";
echo "4. Store transaction IDs in your database\n";
echo "5. Test with Xendit sandbox environment first\n";