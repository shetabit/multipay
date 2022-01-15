<?php

namespace Shetabit\Multipay\Drivers\Zarinpal\Strategies;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Normal extends Driver
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
     * Zarinpal constructor.
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
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \SoapFault
     */
    public function purchase()
    {
        $metadata = [];

        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }

        if (!empty($this->invoice->getDetails()['mobile'])) {
            $metadata['mobile'] = $this->invoice->getDetails()['mobile'];
        }

        if (!empty($this->invoice->getDetails()['email'])) {
            $metadata['email'] = $this->invoice->getDetails()['email'];
        }

        $data = [
            "merchant_id" => $this->settings->merchantId,
            "amount" => $this->invoice->getAmount() * 10, // convert toman to rial
            "callback_url" => $this->settings->callbackUrl,
            "description" => $description,
            "metadata" => array_merge($this->invoice->getDetails() ?? [], $metadata),
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "json" => $data,
                    "headers" => [
                        'Content-Type' => 'application/json',
                    ],
                    "http_errors" => false,
                ]
            );

        $result = json_decode($response->getBody()->getContents(), true);

        // some error has happened
        if (!empty($result['errors']) || empty($result['data']) || $result['data']['code'] != 100) {
            throw new PurchaseFailedException($result['errors']['message'], $result['errors']['code']);
        }

        $this->invoice->transactionId($result['data']["authority"]);

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
        $transactionId = $this->invoice->getTransactionId();
        $paymentUrl = $this->getPaymentUrl();

        $payUrl = $paymentUrl . $transactionId;

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
        $status = Request::input('Status');
        if ($status != 'OK') {
            throw new InvalidPaymentException('عملیات پرداخت توسط کاربر لغو شد.', -54);
        }

        $authority = $this->invoice->getTransactionId() ?? Request::input('Authority');
        $data = [
            "merchant_id" => $this->settings->merchantId,
            "authority" => $authority,
            "amount" => $this->invoice->getAmount() * 10, // convert toman to rial
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

        $result = json_decode($response->getBody()->getContents(), true);

        if (
            empty($result['data'])
            || !isset($result['data']['ref_id'])
            || ($result['data']['code'] != 100 && $result['data']['code'] != 101)
        ) {
            $message = $result['errors']['message'] ?? "";
            $code = $result['errors']['code'];
            throw new InvalidPaymentException($message, $code);
        }

        if ($result['data']['code'] == 101) {
            $message = $result['data']['message'] ?? "";
            $code = $result['data']['code'];
            throw new InvalidPaymentException($message, $code);
        }

        return $this->createReceipt($result['data']['ref_id']);
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
        return new Receipt('zarinpal', $referenceId);
    }

    /**
     * Retrieve purchase url
     *
     * @return string
     */
    protected function getPurchaseUrl(): string
    {
        return $this->settings->apiPurchaseUrl;
    }

    /**
     * Retrieve Payment url
     *
     * @return string
     */
    protected function getPaymentUrl(): string
    {
        return $this->settings->apiPaymentUrl;
    }

    /**
     * Retrieve verification url
     *
     * @return string
     */
    protected function getVerificationUrl(): string
    {
        return $this->settings->apiVerificationUrl;
    }
}
