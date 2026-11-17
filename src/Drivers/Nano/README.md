# Nano (XNO) payments

The `nano` driver takes payments in XNO, the currency of the Nano network. The payer sends the exact amount
straight to your account; the driver verifies the payment by reading the send block from a Nano node you choose.
There is no gateway account, no API key, and the driver never needs a private key or a seed: it only reads the
public ledger.

The driver is off until `payTo` is set.

## Configuration

```php
$config['drivers']['nano'] = [
    'nodeUrl' => 'https://your-node.example/rpc',  // the RPC URL of a node you trust
    'payTo' => 'nano_1...',                           // your account; its checksum is checked
    'claimsFile' => '/var/lib/app/nano-claims.log',   // writable, kept across deployments
    'maxAge' => 86400,                                // seconds a send block stays acceptable
    'paymentPageUrl' => 'https://merchant.example/pay/nano',
    'callbackUrl' => 'https://merchant.example/payments/nano/callback',
];
```

The amount of the invoice is in XNO. It is turned into raw, the integer unit of the protocol
(1 XNO = 10^30 raw), with string arithmetic only. Pass the amount as a string (`'0.05'`) when it has more than 8
decimals.

## How a payment is bound to its invoice

A Nano send carries no memo. So that one block can only ever pay the invoice it was made for, the payer sets the
send block's *representative* to an account derived from the invoice (xno-binding v1, option C):

```
representative = nano account of SHA-256("xno-binding/v1/rep|" + orderId + "|" + payTo + "|" + amountRaw)
```

`purchase()` works this out and stores it on the invoice; `paymentTerms()` returns it, with the amount in raw, as
an x402 `exact` requirement on the `nano:mainnet` network for clients that pay without a page:

```php
$invoice = (new Invoice)->amount('0.05')->detail('orderId', 'order-0001');

$payment->via('nano')->purchase($invoice, function ($driver, $transactionId) {
    // Store $transactionId (the order id) with the order.
    // $driver->paymentTerms() is the x402 requirement to hand to a client that pays by itself.
});
```

`pay()` sends the payer to `paymentPageUrl` with `account`, `amount` (raw), `representative` and `order`, for your
page to show.

## Verifying

The callback names the send block with a `hash` parameter:

```php
$receipt = $payment->via('nano')->amount('0.05')->transactionId($orderId)->verify();
```

The block counts as the payment when it is a confirmed send of exactly the amount in raw, to `payTo`, carrying the
representative of this invoice, seen by the node within `maxAge` seconds. The accepted block is then written to
`claimsFile` before the receipt is returned. A block already in that file is refused for another invoice, and a
second verification of the same payment throws `PreviouslyVerifiedException`, also after a restart.
