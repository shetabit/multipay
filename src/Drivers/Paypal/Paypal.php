<?php

namespace Shetabit\Multipay\Drivers\Paypal;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Paypal extends Driver
{
    protected \GuzzleHttp\Client $client;

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
     * Paypal constructor.
     * Construct the class with the relevant settings.
     *
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
     * @throws GuzzleException
     */
    public function purchase()
    {
        $params = $this->makeCheckoutParams();
        $accessToken = $this->getAccessToken();

        $response = $this
            ->client
            ->request(
                'POST',
                $this->getPurchaseUrl(),
                [
                    "json" => $params,
                    "headers" => [
                        'Content-Type' => 'application/json',
                        'Authorization' => "Bearer $accessToken",
                    ],
                    "http_errors" => false,
                ]
            );
        $result = json_decode($response->getBody()->getContents(), true);

        // handle possible errors
        if ($response->getStatusCode() != 201) {
            if (isset($result['name']) && isset($result['message'])) {
                $message = $result['name'] . ': ' . $result['message'];
            } else {
                $message = "Unknown error";
            }

            throw new PurchaseFailedException($message);
        }


        $this->invoice->transactionId($result['id']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay() : RedirectionForm
    {
        $transactionId = $this->invoice->getTransactionId();
        $paymentUrl = $this->getPaymentUrl();

        $payUrl = $paymentUrl.$transactionId;

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     *
     * @throws InvalidPaymentException
     * @throws PurchaseFailedException
     */
    public function verify() : ReceiptInterface
    {
        $transactionId = Request::input('token') ?? $this->invoice->getTransactionId();

        $accessToken = $this->getAccessToken();
        $verificationUrl = str_replace('{order_id}', $transactionId, $this->getVerificationUrl());

        $response = $this
            ->client
            ->request(
                'POST',
                $verificationUrl,
                [
                    "headers" => [
                        'Content-Type' => 'application/json',
                        'Authorization' => "Bearer $accessToken",
                    ],
                    "http_errors" => false,
                ]
            );
        $result = json_decode($response->getBody()->getContents(), true);


        // handle possible errors
        if (!in_array($response->getStatusCode(), [200, 201])) {
            if (isset($result['name']) && isset($result['message'])) {
                $message = $result['name'] . ': ' . $result['message'];
            } else {
                $message = "Unknown error";
            }

            throw new PurchaseFailedException($message);
        }

        if (isset($result['status']) && $result['status'] != 'COMPLETED') {
            throw new PurchaseFailedException("Purchase not completed");
        }

        // finalize verification
        return $this->createReceipt($result);
    }

    /**
     * Generate the payment's receipt
     *
     * @param array $result
     * @return Receipt
     */
    public function createReceipt(array $result): \Shetabit\Multipay\Receipt
    {
        $receipt = new Receipt('paypal', $result['id']);
        $receipt->detail($result);

        return $receipt;
    }

    /**
     * Retrieve access token url
     */
    protected function getAccessTokenUrl() : string
    {
        if ($this->settings->mode == 'sandbox') {
            return $this->settings->sandboxAccessTokenUrl;
        } else {
            return $this->settings->accessTokenUrl;
        }
    }

    /**
     * Retrieve purchase url
     */
    protected function getPurchaseUrl() : string
    {
        if ($this->settings->mode == 'sandbox') {
            return $this->settings->sandboxPurchaseUrl;
        } else {
            return $this->settings->purchaseUrl;
        }
    }

    /**
     * Retrieve Payment url
     */
    protected function getPaymentUrl(): string
    {
        if ($this->settings->mode == 'sandbox') {
            return $this->settings->sandboxPaymentUrl;
        } else {
            return $this->settings->paymentUrl;
        }
    }

    /**
     * Retrieve verification url
     */
    protected function getVerificationUrl() : string
    {
        if ($this->settings->mode == 'sandbox') {
            return $this->settings->sandboxVerificationUrl;
        } else {
            return $this->settings->verificationUrl;
        }
    }

    protected function makeCheckoutParams():array
    {
        return [
            'intent' => 'CAPTURE',
            "purchase_units" => [
                [
                    'amount' => [
                        'currency_code' => $this->settings->currency,
                        'value' => $this->invoice->getAmount(),
                    ]
                ]
            ],
            'application_context' => [
//                "landing_page" => "LOGIN",
                "shipping_preference" => "NO_SHIPPING",
                "return_url" => $this->settings->callbackUrl,
                "cancel_url" => $this->settings->callbackUrl,
            ],
        ];
    }

    /**
     * @throws GuzzleException
     */
    protected function getAccessToken() : string
    {
        $authorization = "Basic " . base64_encode($this->settings->clientId . ':' . $this->settings->clientSecret);

        $response = $this
            ->client
            ->request(
                'POST',
                $this->getAccessTokenUrl(),
                [
                    "form_params" => [
                        'grant_type' => 'client_credentials',
                    ],
                    "headers" => [
                        'Authorization' => $authorization,
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    ],
                    "http_errors" => false,
                ]
            );

        $result = json_decode($response->getBody()->getContents(), true);

        return $result['access_token'];
    }
}
