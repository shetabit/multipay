<?php

namespace Shetabit\Multipay\Drivers\Shepa;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Shepa extends Driver
{
    /**
     * HTTP Client.
     *
     * @var object
     */
    protected $client;

    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Shepa constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     */
    public function purchase()
    {
        $data = [
            'api' => $this->settings->merchantId,
            'amount' => $this->getInvoiceAmount(),
            'callback' => $this->settings->callbackUrl,
            'mobile' => $this->extractDetails('mobile'),
            'email' => $this->extractDetails('email'),
            'cardnumber' => $this->extractDetails('cardnumber'),
            'description' => $this->extractDetails('description'),
        ];

        $response = $this->client->request(
            'POST',
            $this->getPurchaseUrl(),
            [
                'form_params' => $data,
                'http_errors' => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if (!empty($body['error']) || !empty($body['errors'])) {
            $errors = !empty($body['error'])
                ? $body['error']
                : $body['errors'];

            throw new PurchaseFailedException(implode(', ', $errors));
        }

        $this->invoice->transactionId($body['result']['token']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $payUrl = $this->getPaymentUrl() . $this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $paymentStatus = Request::input('status');

        if ($paymentStatus !== 'success') {
            throw new InvalidPaymentException('تراکنش از سوی کاربر لغو شد.');
        }

        $data = [
            'api' => $this->settings->merchantId,
            'token' => $this->invoice->getTransactionId() ?? Request::input('token'),
            'amount' => $this->getInvoiceAmount()
        ];

        $response = $this->client->request(
            'POST',
            $this->getVerificationUrl(),
            [
                'json' => $data,
                "headers" => [
                    'Content-Type' => 'application/json',
                ],
                "http_errors" => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if (!empty($body['error']) || !empty($body['errors'])) {
            $errors = !empty($body['error'])
                ? $body['error']
                : $body['errors'];

            throw new InvalidPaymentException(implode(', ', $errors));
        }

        $refId = $body['result']['refid'];
        $receipt =  $this->createReceipt($refId);

        $receipt->detail([
            'refid' => $refId,
            'transaction_id' => $body['result']['transaction_id'],
            'amount' => $body['result']['amount'],
            'card_pan' => $body['result']['card_pan'],
            'date' => $body['result']['date'],
        ]);

        return $receipt;
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    public function createReceipt($referenceId)
    {
        return new Receipt('shepa', $referenceId);
    }

    /**
     * Retrieve invoice amount
     *
     * @return int|float
     */
    protected function getInvoiceAmount()
    {
        return $this->invoice->getAmount() * (strtolower($this->settings->currency) === 't' ? 10 : 1); // convert to rial
    }

    /**
     * Retrieve purchase url
     *
     * @return string
     */
    protected function getPurchaseUrl(): string
    {
        return $this->isSandboxMode()
            ? $this->settings->sandboxApiPurchaseUrl
            : $this->settings->apiPurchaseUrl;
    }

    /**
     * Retrieve Payment url
     *
     * @return string
     */
    protected function getPaymentUrl(): string
    {
        return $this->isSandboxMode()
            ? $this->settings->sandboxApiPaymentUrl
            : $this->settings->apiPaymentUrl;
    }

    /**
     * Retrieve verification url
     *
     * @return string
     */
    protected function getVerificationUrl(): string
    {
        return $this->isSandboxMode()
            ? $this->settings->sandboxApiVerificationUrl
            : $this->settings->apiVerificationUrl;
    }

    /**
     * Retrieve payment in sandbox mode?
     *
     * @return bool
     */
    protected function isSandboxMode() : bool
    {
        return $this->settings->sandbox;
    }
}
