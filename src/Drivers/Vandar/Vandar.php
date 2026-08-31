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
use Shetabit\Multipay\Traits\HasIranCurrency;

class Vandar extends Driver
{
    use HasIranCurrency;
    /**
     * Vandar Client.
     */
    protected Client $client;

    const PAYMENT_STATUS_FAILED = 'FAILED';
    const PAYMENT_STATUS_OK = 'OK';

    public function __construct(Invoice $invoice, array|object $settings)
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
    private function extractDetails(string $name) : mixed
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
    }

    /**
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Shetabit\Multipay\Exceptions\PurchaseFailedException
     */
    public function purchase(): string|int|null
    {
        $mobile = $this->extractDetails('mobile');
        $description = $this->extractDetails('description');
        $nationalCode = $this->extractDetails('national_code');
        $validCard = $this->extractDetails('valid_card_number');
        $factorNumber = $this->extractDetails('factorNumber');

        $data = [
            'api_key' => $this->settings->merchantId,
            'amount' => $this->convertAmountToRial($this->invoice->getAmount()),
            'callback_url' => $this->settings->callbackUrl,
            'description' => $description,
            'mobile_number' => $mobile,
            'national_code' => $nationalCode,
            'valid_card_number' => $validCard,
            'factorNumber' => $factorNumber,
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

    public function pay(): RedirectionForm
    {
        $url = $this->settings->apiPaymentUrl . $this->invoice->getTransactionId();

        return $this->redirectWithForm($url, [], 'GET');
    }

    /**
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

            if (isset($responseBody['errors']) && is_array($responseBody['errors'])) {
                $message = array_pop($responseBody['errors']);
            }

            $this->notVerified($message ?? '', $statusCode);
        }

        $receipt = $this->createReceipt($token);

        $receipt->detail([
            "amount" => $responseBody['amount'],
            "realAmount" => $responseBody['realAmount'],
            "wage" => $responseBody['wage'],
            "cardNumber" => $responseBody['cardNumber'],
        ]);

        return $receipt;
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     */
    protected function createReceipt(string|int $referenceId): Receipt
    {
        return new Receipt('vandar', $referenceId);
    }

    /**
     * @param $message
     * @throws \Shetabit\Multipay\Exceptions\InvalidPaymentException
     */
    protected function notVerified(string|null $message, int|string $status = 0) : never
    {
        if (empty($message)) {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.', (int)$status);
        }
        throw new InvalidPaymentException($message, (int)$status);
    }
}
