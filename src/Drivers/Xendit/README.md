# Xendit Payment Gateway Driver

This driver provides integration with Xendit payment gateway for the Multipay package.

## Configuration

Add Xendit configuration to your payment config:

```php
'xendit' => [
    'secretKey' => 'your-xendit-secret-key',
    'apiUrl' => 'https://api.xendit.co/', // Optional, defaults to production URL
    'currency' => 'IDR', // Optional, defaults to IDR
    'description' => 'Payment via Xendit', // Optional
    'invoiceDuration' => 86400, // Optional, in seconds (24 hours default)
    
    // Optional customer information
    'payerEmail' => 'customer@example.com',
    'customerName' => 'John Doe',
    'notificationEmail' => 'notifications@yoursite.com',
    
    // Optional redirect URLs
    'successReturnUrl' => 'https://yoursite.com/payment/success',
    'failureReturnUrl' => 'https://yoursite.com/payment/failure',
    
    // Optional payment methods restriction
    'paymentMethods' => ['CREDIT_CARD', 'BCA', 'MANDIRI', 'BNI'], // Leave empty for all methods
],
```

## Usage

### Basic Payment Flow

```php
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Payment;

// Create an invoice
$invoice = new Invoice;
$invoice->amount(100000) // Amount in smallest currency unit (e.g., cents for IDR)
        ->uuid('unique-order-id-123');

// Optional: Add invoice details as metadata
$invoice->detail('order_id', 'ORD-123')
        ->detail('customer_id', 'CUST-456');

// Initialize payment with Xendit driver
$payment = Payment::via('xendit');

// Purchase (create invoice)
$transactionId = $payment->purchase($invoice);

// Redirect user to payment page
return $payment->pay();
```

### Payment Verification

```php
use Shetabit\Multipay\Payment;

// Verify payment (usually in webhook or return URL handler)
$payment = Payment::via('xendit');

try {
    $receipt = $payment->verify();
    
    // Payment successful
    $transactionId = $receipt->getReferenceId();
    $paymentMethod = $receipt->getDetail('payment_method');
    $paidAt = $receipt->getDetail('paid_at');
    
    // Handle successful payment
    
} catch (\Shetabit\Multipay\Exceptions\InvalidPaymentException $e) {
    // Payment failed or invalid
    // Handle failed payment
}
```

### Manual Invoice Retrieval

```php
$payment = Payment::via('xendit');
$invoiceData = $payment->getInvoice('invoice-id-here');
```

## Webhook Handling

Xendit sends webhook notifications for payment status updates. Set up your webhook endpoint in Xendit dashboard and handle the verification:

```php
// In your webhook controller
public function handleWebhook(Request $request)
{
    $payment = Payment::via('xendit');
    
    try {
        // Set the invoice ID from webhook data
        $invoice = new Invoice;
        $invoice->transactionId($request->input('id'));
        
        $receipt = $payment->verify();
        
        // Update order status in your database
        // Send confirmation emails, etc.
        
        return response('OK');
        
    } catch (\Exception $e) {
        \Log::error('Xendit webhook verification failed: ' . $e->getMessage());
        return response('Error', 400);
    }
}
```

## Available Payment Methods

Xendit supports various payment methods in Indonesia:

- **Credit Cards**: VISA, MasterCard, JCB
- **Bank Transfers**: BCA, Mandiri, BNI, BRI, Permata, and others
- **E-Wallets**: OVO, DANA, LinkAja, ShopeePay
- **Retail**: Indomaret, Alfamart
- **Direct Debit**: BCA KlikPay, CIMB Clicks

## Error Handling

The driver throws the following exceptions:

- `PurchaseFailedException`: When invoice creation fails
- `InvalidPaymentException`: When payment verification fails or payment is not completed

## Security Notes

1. **Never expose your secret key** in client-side code
2. **Always verify payments** on your server before fulfilling orders
3. **Use HTTPS** for all payment-related endpoints
4. **Validate webhook signatures** if implementing webhook verification
5. **Store sensitive data securely** and follow PCI compliance guidelines

## Testing

For testing, use Xendit's test environment:
- API URL: `https://api.xendit.co/`
- Use test secret keys from your Xendit dashboard
- Test payment methods and scenarios are available in Xendit documentation