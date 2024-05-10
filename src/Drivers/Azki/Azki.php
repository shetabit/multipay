<?php

namespace Shetabit\Multipay\Drivers\Azki;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;

class Azki extends Driver
{
    const STATUS_DONE = 8;

    const SUCCESSFUL = 0;

    const subUrls = [
        'purchase'      => '/payment/purchase',
        'paymentStatus' => '/payment/status',
        'verify'        => '/payment/verify',
    ];
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

    protected $paymentUrl;

    /**
     * @return string
     */
    public function getPaymentUrl(): string
    {
        return $this->paymentUrl;
    }

    /**
     * @param mixed $paymentUrl
     */
    public function setPaymentUrl($paymentUrl): void
    {
        $this->paymentUrl = $paymentUrl;
    }


    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
        $this->client   = new Client();
        $this->convertAmountItems();
    }

    public function purchase()
    {
        $details  = $this->invoice->getDetails();
        $order_id = $this->invoice->getUuid();

        if (empty($details['phone']) && empty($details['mobile'])) {
            throw new PurchaseFailedException('Phone number is required');
        }
        if (!isset($details['items']) ||  count($details['items']) == 0) {
            throw new PurchaseFailedException('Items is required for this driver');
        }

        $merchant_id = $this->settings->merchantId;
        $callback    = $this->settings->callbackUrl;
        $fallback    =
            $this->settings->fallbackUrl != 'http://yoursite.com/path/to' && $this->settings->fallbackUrl == ''
                ? $this->settings->fallbackUrl
                : $callback;
        $sub_url     = self::subUrls['purchase'];
        $url         = $this->settings->apiPaymentUrl . $sub_url;

        $signature = $this->makeSignature(
            $sub_url,
            'POST'
        );

        $data = [
            "amount"        => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            "redirect_uri"  => $callback,
            "fallback_uri"  => $fallback,
            "provider_id"   => $order_id,
            "mobile_number" => $details['mobile'] ?? $details['phone'] ?? null,
            "merchant_id"   => $merchant_id,
            "description"   => $details['description'] ?? $this->settings->description,
            "items"         => $details['items'],
        ];

        $response = $this->ApiCall($data, $signature, $url);

        // set transaction's id
        $this->invoice->transactionId($response['ticket_id']);
        $this->setPaymentUrl($response['payment_uri']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    public function pay(): RedirectionForm
    {
        $url = $this->getPaymentUrl();
        return $this->redirectWithForm(
            $url,
            [
                'ticketId' => $this->invoice->getTransactionId(),
            ],
            'GET'
        );
    }

    public function verify(): ReceiptInterface
    {
        $paymentStatus = $this->getPaymentStatus();

        if ($paymentStatus != self::STATUS_DONE) {
            $this->verifyFailed($paymentStatus);
        }

        $this->VerifyTransaction();

        return $this->createReceipt($this->invoice->getTransactionId());
    }


    private function makeSignature($sub_url, $request_method = 'POST')
    {
        $time = time();
        $key  = $this->settings->key;

        $plain_signature = "{$sub_url}#{$time}#{$request_method}#{$key}";
        $encrypt_method  = "AES-256-CBC";
        $secret_key      = hex2bin($key);
        $secret_iv       = str_repeat(0, 16);

        $digest = @openssl_encrypt($plain_signature, $encrypt_method, $secret_key, OPENSSL_RAW_DATA);

        return bin2hex($digest);
    }

    private function convertAmountItems()
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

        $new_items = array_map(
            function ($item) {
                $item['amount'] *= ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
                return $item;
            },
            $this->invoice->getDetails()['items'] ?? []
        );

        $this->invoice->detail('items', $new_items);
        return $new_items;
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
                    "http_errors" => false,
                ]
            );

        $response_array = json_decode($response->getBody()->getContents(), true);


        if (($response->getStatusCode() === null or $response->getStatusCode() != 200) || $response_array['rsCode'] != self::SUCCESSFUL) {
            $this->purchaseFailed($response_array['rsCode']);
        } else {
            return $response_array['result'];
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
        } else {
            throw new PurchaseFailedException('خطای ناشناخته ای رخ داده است.');
        }
    }

    private function getPaymentStatus()
    {
        $sub_url = self::subUrls['paymentStatus'];
        $url     = $this->settings->apiPaymentUrl . $sub_url;

        $signature = $this->makeSignature(
            $sub_url,
            'POST'
        );

        $data = [
            "ticket_id" => $this->invoice->getTransactionId(),
        ];

        return $this->ApiCall($data, $signature, $url)['status'];
    }


    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @throws PurchaseFailedException
     */
    protected function verifyFailed($status)
    {
        $translations = [
            "1" => "Created",
            "2" => "Verified",
            "3" => "Reversed",
            "4" => "Failed",
            "5" => "Canceled",
            "6" => "Settled",
            "7" => "Expired",
            "8" => "Done",
            "9" => "Settle Queue",
        ];

        if (array_key_exists($status, $translations)) {
            throw new PurchaseFailedException("تراکنش در وضعیت " . $translations[$status] . " است.");
        } else {
            throw new PurchaseFailedException('خطای ناشناخته ای رخ داده است.');
        }
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    private function createReceipt($referenceId): Receipt
    {
        $receipt = new Receipt('azki', $referenceId);

        return $receipt;
    }

    private function VerifyTransaction()
    {
        $sub_url = self::subUrls['verify'];
        $url     = $this->settings->apiPaymentUrl . $sub_url;

        $signature = $this->makeSignature(
            $sub_url,
            'POST'
        );

        $data = [
            "ticket_id" => $this->invoice->getTransactionId(),
        ];

        return $this->ApiCall($data, $signature, $url);
    }
}
