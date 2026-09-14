# Bajet credit payments (JETPAY)

The `bajet` driver implements authentication, order creation, redirection, inquiry,
and final verification from the JETPAY v1.3.2 merchant API. It does not implement
refund or reversal endpoints, which are absent from that version.

## Configuration

Configure `drivers.bajet` with credentials issued for your terminal:

```php
use Shetabit\Multipay\Constants\IranCurrency;

$config['drivers']['bajet'] = [
    'apiUrl' => 'https://jetpay.mybajet.ir',
    'username' => getenv('BAJET_USERNAME'),
    'password' => getenv('BAJET_PASSWORD'),
    'terminalId' => getenv('BAJET_TERMINAL_ID'),
    'callbackUrl' => 'https://merchant.example/payments/bajet/callback',
    'currency' => IranCurrency::TOMAN,
    'apiCurrency' => getenv('BAJET_API_CURRENCY'),
];
```

`currency` is the unit used by your invoice. `apiCurrency` is the unit expected by
your Bajet contract: set it explicitly to `R` for rial or `T` for toman after
confirming it with Bajet. The supplied API specification does not identify that
unit, so the driver deliberately has no default for it. Both settings also accept
`IranCurrency` values or `R`/`T` strings. Amounts must be positive whole units;
conversions that would truncate fractions or overflow are rejected.

Use the hostname with normal TLS validation. The API URL is an origin, optionally
with a deployment prefix; the driver appends `/api/v1/jetpay/{operation}`.
Credentials and terminal settings belong in private application configuration.

## Purchase and redirect

```php
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Payment;

$invoice = (new Invoice)->amount(1000)->detail([
    'orderId' => 'merchant-order-123',
    'mobile' => '09120000000',
]);

$payment = new Payment($config);
$payment->via('bajet')->purchase($invoice, function ($driver, $referenceId) {
    // Persist $referenceId, the invoice amount and currency, and
    // $driver->getInvoice()->getDetails() against your local payment attempt.
});

echo $payment->pay()->render();
```

The `mobile` detail is required. `orderId` is a string; when omitted, the driver
uses the invoice UUID. Optional details are `nationalId` and `basketItems`, a list
of items containing a string `brand`, numeric `productType`, and positive integer
`count`. Obtain the product type values applicable to your merchant from Bajet.

The API returns the transaction reference and a complete `referUrl`. The driver
stores the latter as the invoice detail `bajetReferUrl`; preserve this detail if
you recreate the invoice before calling `pay()`. Do not build a payment URL from
the reference alone. GET form inputs preserve the URL's query parameters.

## Verify

Look up your own stored payment attempt in the callback handler. Use its amount,
reference, order ID and original terminal configuration:

```php
$receipt = (new Payment($config))->via('bajet')
    ->amount($storedAmount)
    ->transactionId($storedReferenceId)
    ->detail('orderId', $storedOrderId)
    ->verify();
```

The callback's `id`, `orderId`, and `status` are untrusted hints; the driver never
uses them as proof of payment or as a substitute for the stored reference.
It first calls inquiry and checks the reference, order (when supplied), and
invoice amount. Only `SUCCESS` proceeds to final verification. `VERIFIED`
raises `PreviouslyVerifiedException`; pending, failed, reversed, refunded or
unknown states raise `InvalidPaymentException` without settling the transaction.

After verification, the driver checks the returned reference and order again,
and requires `creditAmount + cashAmount` to equal the invoice amount in API units.
The receipt contains `orderId`, `creditAmount`, `cashAmount`, and `apiCurrency`.

**Call final verification within 15 minutes.** Bajet automatically reverses a
payment that is not verified within the documented window. Fulfil the local
order only once, using your application's transaction/locking and duplicate
callback handling. An already-verified response requires reconciliation with
your local payment record; it is not a new successful payment event.

## Inquiry and failures

```php
use Shetabit\Multipay\Drivers\Bajet\Bajet;

$invoice = (new Invoice)->transactionId($storedReferenceId)
    ->detail('orderId', $storedOrderId);
$result = (new Bajet($invoice, $config['drivers']['bajet']))->inquiry();
```

Inquiry reads status and does not settle a payment. After an ambiguous timeout,
reconcile using the stored reference before retrying. The driver does not
automatically retry order creation or settlement. If order creation timed out
before a reference was received, consult the provider using your local order ID.

Authentication is lazy, with a fresh token for each public API operation and one
shared token for inquiry plus verification. No token is cached across merchants
or persisted to disk. HTTP redirects are disabled, TLS verification stays on,
and requests have 10-second connection and 30-second total timeouts.

Purchase failures raise `PurchaseFailedException`; verification/inquiry failures
raise `InvalidPaymentException`. Documented numeric gateway error codes are
preserved where provided. Messages omit raw server errors, credentials, and
request/response bodies.

## Tests

Run `vendor/bin/phpunit tests/Drivers/BajetTest.php` and `composer ci`.
All Bajet tests use queued HTTP responses and synthetic merchant data. A passing
unit suite does not establish live merchant connectivity or validate the
contract's amount unit; perform merchant acceptance testing before enabling it.
