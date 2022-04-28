<?php

namespace Shetabit\Multipay\Drivers\Vandar;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Vandar extends Driver
{
    /**
     * Vandar Client.
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

    const PAYMENT_STATUS_FAILED = 'FAILED';
    const PAYMENT_STATUS_OK = 'OK';

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Shetabit\Multipay\Exceptions\PurchaseFailedException
     */
    public function purchase()
    {
        $data = [
            'api_key' => $this->settings->merchantId,
            'amount' => $this->invoice->getAmount(),
            'callback_url' => $this->settings->callbackUrl
        ];

        $response = $this->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    'json' => $data,
                    'headers' => [
                        "Accept" => "application/json",
                    ],
                    'http_errors' => false,
                ]
            );

        $responseBody = json_decode($response->getBody()->getContents(), true);
        $statusCode = (int) $responseBody['status'];

        if ($statusCode !== 1) {
            $errors = array_pop($responseBody['errors']);

            throw new PurchaseFailedException($errors);
        }

        $this->invoice->transactionId($responseBody['token']);

        return $this->invoice->getTransactionId();
    }

    /**
     * @return \Shetabit\Multipay\RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $url = $this->settings->apiPaymentUrl . $this->invoice->getTransactionId();

        return $this->redirectWithForm($url, [], 'GET');
    }

    /**
     * @return ReceiptInterface
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Shetabit\Multipay\Exceptions\InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $token = Request::get('token');
        $paymentStatus = Request::get('payment_status');
        $data = [
            'api_key' => $this->settings->merchantId,
            'token' => $token
        ];

        if ($paymentStatus == self::PAYMENT_STATUS_FAILED) {
            $this->notVerified('پرداخت با شکست مواجه شد.');
        }

        $response = $this->client
            ->request(
                'POST',
                $this->settings->apiVerificationUrl,
                [
                    'json' => $data,
                    'headers' => [
                        "Accept" => "application/json",
                    ],
                    'http_errors' => false,
                ]
            );

        $responseBody = json_decode($response->getBody()->getContents(), true);
        $statusCode = (int) $responseBody['status'];

        if ($statusCode !== 1) {
            if (isset($responseBody['error'])) {
                $message = is_array($responseBody['error']) ? array_pop($responseBody['error']) : $responseBody['error'];
            }

            if (isset($responseBody['errors']) and is_array($responseBody['errors'])) {
                $message = array_pop($responseBody['errors']);
            }

            $this->notVerified($message ?? '');
        }

        return $this->createReceipt($token);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId)
    {
        $receipt = new Receipt('vandar', $referenceId);

        return $receipt;
    }

    /**
     * @param $message
     * @throws \Shetabit\Multipay\Exceptions\InvalidPaymentException
     */
    protected function notVerified($message)
    {
        if (empty($message)) {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.');
        } else {
            throw new InvalidPaymentException($message);
        }
    }
}
