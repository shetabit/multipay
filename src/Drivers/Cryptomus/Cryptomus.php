<?php

namespace Shetabit\Multipay\Drivers\Cryptomus;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;

class Cryptomus extends Driver
{
    /**
     * The payment statuses of an invoice that has been paid: with the exact amount, or with more than that.
     *
     * @see https://doc.cryptomus.com/merchant-api/payments/payment-statuses
     */
    protected const PAID_STATUSES = ['paid', 'paid_over'];

    protected Client $client;

    /**
     * The payment page of the invoice, handed out by the gateway on purchase.
     */
    protected string|null $paymentUrl = null;

    /**
     * Cryptomus constructor.
     * Construct the class with the relevant settings.
     */
    public function __construct(Invoice $invoice, array|object $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Create an invoice on the gateway.
     *
     * @see https://doc.cryptomus.com/merchant-api/payments/creating-invoice
     *
     * @throws PurchaseFailedException
     */
    public function purchase(): string|int|null
    {
        $data = [
            'amount' => $this->formatAmount($this->invoice->getAmount()),
            'currency' => $this->settings->currency,
            'order_id' => (string) ($this->invoice->getDetail('orderId') ?? $this->invoice->getUuid()),
            'url_return' => $this->settings->callbackUrl,
            'url_success' => $this->settings->callbackUrl,
        ];

        if (!empty($this->settings->webhookUrl)) {
            $data['url_callback'] = $this->settings->webhookUrl;
        }

        $result = $this->send($this->settings->apiPurchaseUrl, $data, PurchaseFailedException::class);

        $this->invoice->transactionId($result['uuid']);
        $this->paymentUrl = $result['url'];

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @throws InvalidPaymentException
     */
    public function pay(): RedirectionForm
    {
        if ($this->paymentUrl === null) {
            throw new InvalidPaymentException('The invoice has no payment page yet, purchase it first.');
        }

        return $this->redirectWithForm($this->paymentUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * The gateway is asked for the invoice, which counts as paid once its payment status says so.
     *
     * @see https://doc.cryptomus.com/merchant-api/payments/payment-information
     *
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $uuid = $this->invoice->getTransactionId();

        if (empty($uuid)) {
            throw new InvalidPaymentException('The invoice has no transaction id to verify.');
        }

        $result = $this->send(
            $this->settings->apiVerificationUrl,
            ['uuid' => (string) $uuid],
            InvalidPaymentException::class
        );

        $status = $result['payment_status'] ?? null;

        if (!in_array($status, self::PAID_STATUSES, true)) {
            throw new InvalidPaymentException(
                sprintf('The invoice has not been paid, its payment status is [%s].', $status ?? 'unknown')
            );
        }

        return new Receipt('cryptomus', $result['uuid'])->detail($result);
    }

    /**
     * The amount as the gateway takes it: a decimal string.
     *
     * A float is rounded to 8 decimals, the precision the gateway answers with itself, and sent without trailing
     * zeros, so the noise of float arithmetic (0.1 + 0.2 = 0.30000000000000004) never reaches the gateway. An
     * integer or a numeric string is sent as it is.
     */
    protected function formatAmount(int|float|string $amount): string
    {
        if (!is_float($amount)) {
            return (string) $amount;
        }

        return rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.');
    }

    /**
     * Send a signed request to the gateway and hand back the result it answers with.
     *
     * The gateway expects the MD5 of the base64 encoded body followed by the API key as the signature, so the body
     * is encoded here, once, and sent exactly as it was signed.
     *
     * @see https://doc.cryptomus.com/merchant-api/request-format
     *
     * @param class-string<PurchaseFailedException|InvalidPaymentException> $exception thrown when the gateway refuses
     *
     * @throws PurchaseFailedException|InvalidPaymentException
     */
    protected function send(string $url, array $data, string $exception): array
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE);

        $response = $this->client->request('POST', $url, [
            RequestOptions::BODY => $body,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'merchant' => $this->settings->merchantId,
                'sign' => md5(base64_encode($body).$this->settings->apiKey),
            ],
            RequestOptions::HTTP_ERRORS => false,
        ]);

        $answer = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() !== 200
            || !is_array($answer)
            || (int) ($answer['state'] ?? 1) !== 0
            || !is_array($answer['result'] ?? null)
        ) {
            throw new $exception($this->errorMessage($answer), $response->getStatusCode());
        }

        return $answer['result'];
    }

    /**
     * The reason the gateway gave for refusing a request.
     */
    protected function errorMessage(mixed $answer): string
    {
        if (is_array($answer) && is_string($answer['message'] ?? null) && $answer['message'] !== '') {
            return $answer['message'];
        }

        $errors = is_array($answer) && is_array($answer['errors'] ?? null) ? $answer['errors'] : [];
        $messages = [];

        foreach ($errors as $field => $fieldMessages) {
            foreach ((array) $fieldMessages as $message) {
                $messages[] = $field.': '.$message;
            }
        }

        return $messages === [] ? 'The gateway refused the request.' : implode('; ', $messages);
    }
}
