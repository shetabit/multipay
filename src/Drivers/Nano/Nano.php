<?php

namespace Shetabit\Multipay\Drivers\Nano;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Drivers\Nano\Core\Address;
use Shetabit\Multipay\Drivers\Nano\Core\Amount;
use Shetabit\Multipay\Drivers\Nano\Core\Binding;
use Shetabit\Multipay\Drivers\Nano\Core\ClaimsFile;
use Shetabit\Multipay\Drivers\Nano\Core\Verifier;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

/**
 * Nano (XNO): the payer sends the exact amount straight to the merchant's account, and the payment is verified by
 * reading the send block from a Nano node the merchant chooses. There is no gateway account, no API key and no
 * private key here: the driver only reads the public ledger.
 *
 * The amount of the invoice is in XNO. It is turned into raw, the integer unit of the protocol (1 XNO = 10^30 raw),
 * without any float on the way.
 *
 * A Nano send has no memo, so the block is bound to the invoice through its representative field (xno-binding v1,
 * option C): the payer sets the representative to an account derived from the order id, the receiving account and
 * the amount in raw, so one block can only ever pay the invoice it was made for. Every accepted block is written to a
 * claims file before the receipt is handed out, so the same block is refused for another invoice, and a second time
 * for the same one, also after a restart of the application.
 *
 * @see https://docs.nano.org/commands/rpc-protocol/#block_info
 */
class Nano extends Driver
{
    protected Client $client;

    /**
     * Nano driver constructor.
     * Construct the class with the relevant settings.
     */
    public function __construct(Invoice $invoice, array|object $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Prepare the invoice: the amount in raw and the representative the send block has to carry.
     *
     * Nothing is sent anywhere, the terms are derived from the invoice and the configured account.
     *
     * @throws PurchaseFailedException
     */
    public function purchase(): string|int|null
    {
        $payTo = $this->payTo(PurchaseFailedException::class);
        $orderId = (string) ($this->invoice->getDetail('orderId') ?? $this->invoice->getUuid());
        $amountRaw = $this->amountRaw(PurchaseFailedException::class);

        $this->invoice->transactionId($orderId);
        $this->invoice->detail([
            'nanoPayTo' => $payTo,
            'nanoAmountRaw' => $amountRaw,
            'nanoRepresentative' => Binding::representativeAccount($orderId, $payTo, $amountRaw),
        ]);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * The payer is sent to the merchant's own payment page with the terms of the send: the account, the amount in
     * raw and the representative.
     *
     * @throws InvalidPaymentException
     */
    public function pay(): RedirectionForm
    {
        if (empty($this->settings->paymentPageUrl)) {
            throw new InvalidPaymentException('The Nano driver has no payment page configured.');
        }

        $terms = $this->paymentTerms();

        return $this->redirectWithForm($this->settings->paymentPageUrl, [
            'account' => $terms['payTo'],
            'amount' => $terms['amount'],
            'representative' => $terms['extra']['representative'],
            'order' => $terms['extra']['orderId'],
            'callback' => (string) ($this->settings->callbackUrl ?? ''),
        ], 'GET');
    }

    /**
     * The terms of the purchased invoice as an x402 `exact` payment requirement on the `nano:mainnet` network, for
     * clients (agents among them) that pay without a payment page.
     *
     * @return array<string, mixed> scheme, network, asset, amount (raw), payTo and extra (binding, orderId,
     *                              representative)
     *
     * @throws InvalidPaymentException
     */
    public function paymentTerms(): array
    {
        $representative = $this->invoice->getDetail('nanoRepresentative');

        if (empty($this->invoice->getTransactionId()) || !is_string($representative)) {
            throw new InvalidPaymentException('The invoice has no Nano payment terms yet, purchase it first.');
        }

        return [
            'scheme' => 'exact',
            'network' => 'nano:mainnet',
            'asset' => 'XNO',
            'amount' => (string) $this->invoice->getDetail('nanoAmountRaw'),
            'payTo' => (string) $this->invoice->getDetail('nanoPayTo'),
            'extra' => [
                'binding' => 'xno-binding/v1/rep',
                'orderId' => (string) $this->invoice->getTransactionId(),
                'representative' => $representative,
            ],
        ];
    }

    /**
     * Verify payment
     *
     * The send block named by the callback (`hash`) is read from the configured node. It counts as the payment of
     * the invoice when it is a confirmed send of exactly the amount in raw, to the configured account, carrying the
     * representative of this invoice, and when no other invoice was paid with it before.
     *
     * @throws InvalidPaymentException
     * @throws PreviouslyVerifiedException
     */
    public function verify(): ReceiptInterface
    {
        $orderId = (string) $this->invoice->getTransactionId();

        if ($orderId === '') {
            throw new InvalidPaymentException('The invoice has no transaction id to verify.');
        }

        $hash = strtoupper(trim((string) (Request::input('hash') ?? $this->invoice->getDetail('nanoBlockHash'))));

        if (preg_match('/^[0-9A-F]{64}$/', $hash) !== 1) {
            throw new InvalidPaymentException('The payment has no valid Nano block hash.');
        }

        if (empty($this->settings->claimsFile)) {
            throw new InvalidPaymentException('The Nano driver has no claims file configured.');
        }

        $payTo = $this->payTo(InvalidPaymentException::class);
        $amountRaw = $this->amountRaw(InvalidPaymentException::class);
        $representative = Binding::representativeAccount($orderId, $payTo, $amountRaw);

        $block = $this->blockInfo($hash);
        $result = Verifier::verify(
            $block,
            $payTo,
            $amountRaw,
            $representative,
            (int) ($this->settings->maxAge ?? 86400),
            $this->now()
        );

        if (!$result['ok']) {
            throw new InvalidPaymentException($result['error']);
        }

        $claims = new ClaimsFile($this->settings->claimsFile);

        if (!$claims->claim($hash, $orderId)) {
            throw new PreviouslyVerifiedException('This Nano block was already verified for this invoice.');
        }

        return (new Receipt('nano', $hash))->detail([
            'orderId' => $orderId,
            'amountRaw' => $result['amount'],
            'payer' => $result['payer'],
        ]);
    }

    /**
     * The current time, as a unix timestamp.
     */
    protected function now(): int
    {
        return time();
    }

    /**
     * The configured account the payments go to, checked against its checksum.
     *
     * @param class-string<PurchaseFailedException|InvalidPaymentException> $exception
     *
     * @throws PurchaseFailedException|InvalidPaymentException
     */
    protected function payTo(string $exception): string
    {
        $payTo = trim((string) ($this->settings->payTo ?? ''));

        if (!Address::isValid($payTo)) {
            throw new $exception('The Nano driver has no valid payTo account configured.');
        }

        return $payTo;
    }

    /**
     * The amount of the invoice, which is in XNO, in raw.
     *
     * A float is rounded to 8 decimals and sent without trailing zeros, so the noise of float arithmetic never
     * reaches the payer. Pass a numeric string for any finer amount.
     *
     * @param class-string<PurchaseFailedException|InvalidPaymentException> $exception
     *
     * @throws PurchaseFailedException|InvalidPaymentException
     */
    protected function amountRaw(string $exception): string
    {
        $amount = $this->invoice->getAmount();

        if (is_float($amount)) {
            $amount = rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.');
        }

        try {
            $raw = Amount::xnoToRaw((string) $amount);
        } catch (InvalidArgumentException $e) {
            throw new $exception($e->getMessage());
        }

        if ($raw === '0') {
            throw new $exception('The amount of a Nano invoice must be more than zero.');
        }

        return $raw;
    }

    /**
     * Read a block from the configured node.
     *
     * @throws InvalidPaymentException
     */
    protected function blockInfo(string $hash): array
    {
        if (empty($this->settings->nodeUrl)) {
            throw new InvalidPaymentException('The Nano driver has no node configured.');
        }

        try {
            $response = $this->client->request('POST', $this->settings->nodeUrl, [
                RequestOptions::JSON => ['action' => 'block_info', 'json_block' => 'true', 'hash' => $hash],
                RequestOptions::HEADERS => ['Accept' => 'application/json'],
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (GuzzleException $e) {
            throw new InvalidPaymentException('The Nano node could not be reached.', 0, $e);
        }

        $answer = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() !== 200 || !is_array($answer) || isset($answer['error'])) {
            $error = is_array($answer) && is_string($answer['error'] ?? null) ? $answer['error'] : 'no block';

            throw new InvalidPaymentException(sprintf('The Nano node did not return the block: %s.', $error));
        }

        return $answer;
    }
}
