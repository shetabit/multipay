# Bajet credit payments (JETPAY)

The `bajet` driver follows the provider's BajetPay WooCommerce plugin API flow:
authentication, order creation, redirection, direct verification, inquiry,
reversal, refunds, refund inquiry, and terminal refund capability checks.
WordPress-specific storage, UI, and demo checkout are not part of this PHP driver.

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
    'apiCurrency' => IranCurrency::RIAL,
];
```

`currency` is the unit used by your invoice. `apiCurrency` defaults to rial, so a
100,000-toman invoice sends an API amount of 1,000,000. This matches the provider's
WooCommerce plugin, which converts IRT to IRR before creating an order or refund.
Override `apiCurrency` if your provider contract specifies another unit.
Both settings accept `IranCurrency` values or `R`/`T` strings. Amounts must be positive whole units;
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

The `mobile` detail is optional, matching the plugin's minimal order request.
`orderId` is a string; when omitted, the driver uses the invoice UUID.
Other optional details are `nationalId` and `basketItems`, a list
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
It calls `verify` directly, without requiring an `inquiry` response first.
A successful API response must contain the matching reference and either a
`status` of `success`, `successful`, or `completed` (case-insensitive), or paid
credit/cash amounts when the status is absent. Explicit failed or unknown states
are rejected. An explicit `VERIFIED` state raises `PreviouslyVerifiedException`.

Returned order IDs and amounts are checked when present. When either split amount
is present, `creditAmount + cashAmount` must equal the invoice amount in API units
(an omitted split component is zero). Status-only responses are supported, as in
the official plugin; they do not independently echo the paid amount. The receipt
contains the supplied payment fields and `apiCurrency`. A bare `success: true`
without a payment status or paid split is not sufficient.

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
automatically retry a timed-out order creation or settlement. If order creation timed out
before a reference was received, consult the provider using your local order ID.

Authentication is lazy. A token is reused within one driver instance for at most
14 minutes (or the shorter `expiresIn` returned by the server). There is no static
cache shared across merchants, or disk storage. An HTTP 401/403 triggers one token
refresh and one retry with the identical payload; transport errors and other
HTTP failures are never automatically retried. `checkAuthentication()` forces a
fresh token request without creating an order and returns true on success.
HTTP redirects are disabled, TLS verification stays on, and requests have
10-second connection and 80-second total timeouts.

Purchase failures raise `PurchaseFailedException`; verification/inquiry failures
raise `InvalidPaymentException`. Documented numeric gateway error codes are
preserved where provided. Messages omit raw server errors, credentials, and
request/response bodies.

## Reversal and refunds

Use a driver instance with your **stored** transaction reference and terminal
settings. The application must authorize these actions and persist a unique,
stable refund track ID before sending the request:

```php
$invoice = (new Invoice)->transactionId($storedReferenceId)->amount($storedAmount);
$driver = new Bajet($invoice, $config['drivers']['bajet']);

$enabled = $driver->isRefundEnabled(); // GET terminal/check-refund
$result = $driver->refund(250, $storedRefundTrackId); // 250 in invoice currency
$status = $driver->refundInquiry($storedRefundTrackId);
// For a transaction reversal instead of a refund:
// $result = $driver->reverse();
```

`refund()` defaults to the invoice amount if its amount argument is omitted.
Both refund methods can use the invoice's `trackId` detail instead of an argument.
The refund amount is sent as a string in API currency, matching the plugin.
Do not generate a new track ID when reconciling an uncertain refund response.
An accepted refund request may still require refund inquiry; the driver does not
update application order state or assume that acceptance means completed settlement.
These methods raise `InvalidPaymentException` on failure. `isRefundEnabled()`
returns false when no positive capability is reported and throws on API failures.

All paths are relative to `https://jetpay.mybajet.ir/api/v1/jetpay/`:

| Method | Path |
| --- | --- |
| POST | `token`, `order`, `verify`, `inquiry` |
| POST | `reverse`, `refund`, `refund-inquiry` |
| GET | `terminal/check-refund` |

## Tests

Run `vendor/bin/phpunit tests/Drivers/BajetTest.php` and `composer ci`.
All Bajet tests use queued HTTP responses and synthetic merchant data. A passing
unit suite does not establish live merchant connectivity or validate the
contract's amount unit; perform merchant acceptance testing before enabling it.
