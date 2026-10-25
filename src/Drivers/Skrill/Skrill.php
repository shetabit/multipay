<?php

namespace Shetabit\Multipay\Drivers\Skrill;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Skrill extends Driver
{
    /**
     * The status of a transaction that has been processed: the funds have been received.
     *
     * The other statuses are 0 (pending), -1 (cancelled), -2 (failed) and -3 (chargeback); the Quick Checkout
     * guide warns against handing out an order on a pending one.
     */
    protected const PROCESSED = '2';

    protected Client $client;

    /**
     * The payment session the gateway prepared on purchase.
     */
    protected string|null $sessionId = null;

    /**
     * Skrill constructor.
     * Construct the class with the relevant settings.
     */
    public function __construct(Invoice $invoice, array|object $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Prepare a payment session on the gateway.
     *
     * The details of the payment go to the gateway from the server, with prepare_only, and the payer only gets to
     * see the id of the session: the secure redirection method the Quick Checkout guide recommends.
     *
     * @see https://www.skrill.com/fileadmin/content/pdf/Skrill_Quick_Checkout_Guide.pdf
     *
     * @throws PurchaseFailedException
     */
    public function purchase(): string|int|null
    {
        $transactionId = (string) ($this->invoice->getDetail('orderId') ?? $this->invoice->getUuid());

        $form = [
            'pay_to_email' => $this->settings->merchantEmail,
            'transaction_id' => $transactionId,
            'amount' => $this->formatAmount($this->invoice->getAmount()),
            'currency' => $this->settings->currency,
            'language' => $this->settings->language,
            'return_url' => $this->settings->callbackUrl,
            'cancel_url' => $this->settings->callbackUrl,
            'prepare_only' => '1',
        ];

        if (!empty($this->settings->webhookUrl)) {
            $form['status_url'] = $this->settings->webhookUrl;
        }

        if (($email = $this->invoice->getDetail('email')) !== null) {
            $form['pay_from_email'] = $email;
        }

        $response = $this->client->request('POST', $this->settings->apiPaymentUrl, [
            RequestOptions::FORM_PARAMS => $form,
            RequestOptions::HTTP_ERRORS => false,
        ]);

        $sessionId = trim((string) $response->getBody());

        if ($response->getStatusCode() !== 200 || preg_match('/^[A-Za-z0-9]{1,32}$/', $sessionId) !== 1) {
            throw new PurchaseFailedException(
                'The gateway did not prepare a payment session.',
                $response->getStatusCode()
            );
        }

        $this->sessionId = $sessionId;
        $this->invoice->transactionId($transactionId);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * The id of the session goes to the payment page in the body of a POST request, which the guide allows as well
     * as the query string.
     *
     * @throws InvalidPaymentException
     */
    public function pay(): RedirectionForm
    {
        if ($this->sessionId === null) {
            throw new InvalidPaymentException('The payment has no session yet, purchase it first.');
        }

        return $this->redirectWithForm($this->settings->apiPaymentUrl, ['sid' => $this->sessionId], 'POST');
    }

    /**
     * Verify payment
     *
     * The status of the transaction is looked up with the Merchant Query Interface, which has to be enabled for the
     * merchant account, with its password and the IP address of the server, in Settings > Developer Settings.
     *
     * When the invoice has an amount, the transaction also has to be for that amount and in the configured currency:
     * the transaction id may come from the return url, which the payer can change, and a cheap payment must not pass
     * for a more expensive invoice.
     *
     * @see https://www.skrill.com/fileadmin/content/pdf/Skrill_Automated_Payments_Interface_Guide.pdf
     *
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('transaction_id');

        if (empty($transactionId)) {
            throw new InvalidPaymentException('The payment has no transaction id to verify.');
        }

        $response = $this->client->request('POST', $this->settings->apiQueryUrl, [
            RequestOptions::FORM_PARAMS => [
                'action' => 'status_trn',
                'email' => $this->settings->merchantEmail,
                'password' => md5($this->settings->mqiPassword),
                'trn_id' => (string) $transactionId,
            ],
            RequestOptions::HEADERS => [
                'Accept' => 'text/html',
            ],
            RequestOptions::HTTP_ERRORS => false,
        ]);

        // The answer is a status line, such as "200\t\tOK", followed by the url encoded details of the transaction.
        $lines = explode("\n", trim((string) $response->getBody()), 2);
        $status = array_values(array_filter(explode("\t", $lines[0]), fn ($part) => $part !== ''));

        if (($status[0] ?? null) !== '200') {
            throw new InvalidPaymentException(
                implode(' ', $status) ?: 'The gateway did not answer the query.',
                (int) ($status[0] ?? $response->getStatusCode())
            );
        }

        parse_str(trim($lines[1] ?? ''), $transaction);

        $paymentStatus = is_string($transaction['status'] ?? null) ? $transaction['status'] : null;

        if ($paymentStatus !== self::PROCESSED) {
            throw new InvalidPaymentException($this->translateStatus($paymentStatus), (int) $paymentStatus);
        }

        if ((float) $this->invoice->getAmount() > 0) {
            $this->ensureTheTransactionPaysTheInvoice($transaction);
        }

        $referenceId = $transaction['mb_transaction_id'] ?? (string) $transactionId;

        return new Receipt('skrill', $referenceId)->detail($transaction);
    }

    /**
     * Make sure the transaction was for the amount of the invoice, in the configured currency.
     *
     * @param array<int|string, mixed> $transaction the details the Merchant Query Interface answered with
     *
     * @throws InvalidPaymentException
     */
    protected function ensureTheTransactionPaysTheInvoice(array $transaction): void
    {
        $amount = is_string($transaction['amount'] ?? null) ? $this->formatAmount($transaction['amount']) : null;
        $currency = is_string($transaction['currency'] ?? null) ? strtoupper($transaction['currency']) : null;

        $expectedAmount = $this->formatAmount($this->invoice->getAmount());
        $expectedCurrency = strtoupper($this->settings->currency);

        if ($amount !== $expectedAmount || $currency !== $expectedCurrency) {
            throw new InvalidPaymentException(sprintf(
                'The payment of [%s %s] does not match the invoice of [%s %s].',
                $amount ?? '?',
                $currency ?? '?',
                $expectedAmount,
                $expectedCurrency
            ));
        }
    }

    /**
     * The amount as the gateway expects it: with at most 2 decimals and without trailing zeros, "23" rather than
     * "23.00", which also keeps the noise of float arithmetic (0.1 + 0.2) away from the gateway.
     */
    protected function formatAmount(int|float|string $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }

    private function translateStatus(string|null $status): string
    {
        $translations = [
            '0' => 'The payment is pending.',
            '-1' => 'The payment was cancelled.',
            '-2' => 'The payment failed.',
            '-3' => 'The payment was charged back.',
        ];

        return $translations[$status ?? ''] ?? 'The status of the payment is unknown.';
    }
}
