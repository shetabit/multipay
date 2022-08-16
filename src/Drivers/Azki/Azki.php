<?php

namespace Shetabit\Multipay\Drivers\Azki;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;

class Azki extends Driver
{
    /**
     * Azki Client.
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


    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
        $this->client   = new Client();
    }

    public function purchase()
    {
        $details     = $this->invoice->getDetails();
        $order_id    = $this->invoice->getUuid();
        $merchant_id = $this->settings->merchantId;
        $callback    = $this->settings->callbackUrl;
        $sub_url     = $this->settings->apiPurchaseSubUrl;
        $url         = $this->settings->apiPaymentUrl . $this->settings->apiPurchaseSubUrl;

        $signature = $this->makeSignature(
            $sub_url,
            'POST');

        $data = [
            "amount"        => $this->invoice->getAmount() * 10, // convert toman to rial
            "redirect_uri"  => $callback,
            "fallback_uri"  => $callback,
            "provider_id"   => $order_id,
            "mobile_number" => $details['mobile'] ?? $details['phone'] ?? NULL,
            "merchant_id"   => $merchant_id,
            "description"   => $details['description'] ?? $this->settings->description,
            "items"         => $this->getItems(),
        ];

        $response = $this->ApiCall($data, $signature, $url);

        // set transaction's id
        $this->invoice->transactionId($response['result']['ticket_id']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    public function pay(): RedirectionForm
    {
        // TODO: Implement pay() method.
    }

    public function verify(): ReceiptInterface
    {
        // TODO: Implement verify() method.
    }


    private function makeSignature($sub_url, $request_method = 'POST')
    {
        $time = time();
        $key  = $this->settings->key;

        $plain_signature = "{$sub_url}#{$time}#{$request_method}#{$key}";

        $encrypt_method = "AES/CBC/PKCS5Padding";
        $secret_key     = $key;
        $secret_iv      = str_repeat(0, 16);

        // hash
        $key = hash('sha256', $secret_key);

        return openssl_encrypt($plain_signature, $encrypt_method, $key, 0, $secret_iv);
    }

    private function getItems()
    {
        /**
         * example data
         *
         *  $items = [
         *      [
         *          "name"   => "Item 1",
         *          "count"  => "string",
         *          "amount" => 0,
         *          "url"    => "http://shop.com/items/1",
         *      ],
         *      [
         *          "name"   => "Item 2",
         *          "count"  => 5,
         *          "amount" => 20000,
         *          "url"    => "http://google.com/items/2",
         *      ],
         *  ];
         *
         */
        return $this->invoice->getDetails()['items'];
    }

    /**
     * @param array $data
     * @param       $signature
     * @param       $url
     * @return mixed
     */
    public function ApiCall(array $data, $signature, $url, $request_method = 'POST')
    {
        $response = $this
            ->client
            ->request(
                $request_method,
                $url,
                [
                    "json"        => $data,
                    "headers"     => [
                        'Content-Type' => 'application/json',
                        'Signature'    => $signature,
                        'MerchantId'   => $this->settings->merchantId,
                    ],
                    "http_errors" => FALSE,
                ]
            );

        $data = json_decode($response->getBody()->getContents(), TRUE);

        if (($response->getStatusCode() === NULL or $response->getStatusCode() != 200) || $data['rsCode'] != 0) {
            $this->purchaseFailed($data['rsCode']);
        }
        else {
            return $data;
        }


    }

    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @throws PurchaseFailedException
     */
    protected function purchaseFailed($status)
    {
        $translations = [
            "1"  => "Internal Server Error",
            "2"  => "Resource Not Found",
            "4"  => "Malformed Data",
            "5"  => "Data Not Found",
            "15" => "Access Denied",
            "16" => "Transaction already reversed",
            "17" => "Ticket Expired",
            "18" => "Signature Invalid",
            "19" => "Ticket unpayable",
            "20" => "Ticket customer mismatch",
            "21" => "Insufficient Credit",
            "28" => "Unverifiable ticket due to status",
            "32" => "Invalid Invoice Data",
            "33" => "Contract is not started",
            "34" => "Contract is expired",
            "44" => "Validation exception",
            "51" => "Request data is not valid",
            "59" => "Transaction not reversible",
            "60" => "Transaction must be in verified state",
        ];

        if (array_key_exists($status, $translations)) {
            throw new PurchaseFailedException($translations[$status]);
        }
        else {
            throw new PurchaseFailedException('خطای ناشناخته ای رخ داده است.');
        }
    }
}
