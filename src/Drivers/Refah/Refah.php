<?php

namespace Shetabit\Multipay\Drivers\Refah;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Refah extends Driver
{
    /**
     * Refah Client.
     *
     * @var object
     */
    protected Client $client;

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
     * payment token
     */
    protected $token;

    /**
     * Refah constructor.
     * Construct the class with the relevant settings.
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client;
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws GuzzleException
     */
    public function purchase()
    {
        $this->invoice->uuid(crc32($this->invoice->getUuid()));
        $details = $this->invoice->getDetails();
        $order_id = (string) $this->invoice->getUuid();
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        $callback = $this->settings->callbackUrl;

        $data = [
            'userName' => $this->settings->username,
            'password' => $this->settings->password,
            'amount' => $amount,
            'callBack' => $callback,
            'celNo' => $details['mobile'] ?? $details['phone'] ?? null,
            'additionalData' => $details['description'] ?? $this->settings->description,
            'terminalNumber' => $this->settings->terminalNumber,
            'orderId' => $order_id,
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    'http_errors' => false,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => json_encode($data),
                ]
            );

        $body = json_decode($response->getBody()->getContents());

        if (! ($response->getStatusCode() == 200 && $body->success && $body->data->token > 0)) {
            throw new PurchaseFailedException($body->errors[0]->message ?? 'Error', $response->getStatusCode());
        }

        $this->token = $body->data->token;
        $this->invoice->transactionId($this->token);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay(): RedirectionForm
    {
        return $this->redirectWithForm($this->settings->apiPaymentUrl, ['token' => $this->token], 'GET');
    }

    /**
     * Verify payment
     *
     * @return mixed|void
     *
     * @throws InvalidPaymentException
     * @throws GuzzleException
     */
    public function verify(): ReceiptInterface
    {
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        $refNum = Request::input('RRN');
        $status = Request::input('status');

        if (! ($refNum > 0 && $status == 0)) {
            throw new InvalidPaymentException('Verify failed with status code : '.$status, $status);
        }

        $data = [
            'userName' => $this->settings->username,
            'password' => $this->settings->password,
            'amount' => $amount,
            'token' => Request::input('Token'),
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                'http_errors' => false,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($data),
            ]
        );

        $body = json_decode($response->getBody()->getContents());

        if (! ($response->getStatusCode() == 200 && $body->success)) {
            throw new InvalidPaymentException($body->errors[0]->message ?? 'Error', $response->getStatusCode());
        }

        return $this->createReceipt($body->data->rrn);
    }

    /**
     * Generate the payment's receipt
     */
    protected function createReceipt($referenceId): Receipt
    {
        return new Receipt('refah', $referenceId);
    }
}
