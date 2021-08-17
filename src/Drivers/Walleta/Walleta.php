<?php

namespace Shetabit\Multipay\Drivers\Walleta;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;

class Walleta extends Driver
{
    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Response
     *
     * @var object
     */
    protected $response;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Walleta constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
    }

    /**
     * Purchase Invoice.09214125578
     *
     * @return string
     *
     * @throws PurchaseFailedException
     */
    public function purchase()
    {
        $result = $this->token();

        if (!isset($result['status_code']) or $result['status_code'] != 200) {
            $this->purchaseFailed($result['content']['type']);
        }

        $this->invoice->transactionId($result['content']['token']);

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
        return $this->redirectWithForm($this->settings->apiPaymentUrl . $this->invoice->getTransactionId(), [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return mixed|Receipt
     *
     * @throws PurchaseFailedException
     */
    public function verify(): ReceiptInterface
    {
        $result = $this->verifyTransaction();

        if (!isset($result['status_code']) or $result['status_code'] != 200) {
            $this->purchaseFailed($result['content']['type']);
        }

        $receipt = $this->createReceipt($this->invoice->getTransactionId());

        return $receipt;
    }

    /**
     * send request to Walleta
     *
     * @param $method
     * @param $url
     * @param array $data
     * @return array
     */
    protected function callApi($method, $url, $data = []): array
    {
        $client = new Client();

        $response = $client->request($method, $url, [
            "json" => $data,
            "headers" => [
                'Content-Type' => 'application/json',
            ],
            "http_errors" => false,
        ]);

        return [
            'status_code' => $response->getStatusCode(),
            'content' => json_decode($response->getBody()->getContents(), true)
        ];
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId): Receipt
    {
        $receipt = new Receipt('walleta', $referenceId);

        return $receipt;
    }

    /**
     * call create token request
     *
     * @return array
     */
    public function token(): array
    {
        return $this->callApi('POST', $this->settings->apiPurchaseUrl, [
            'merchant_code' => $this->settings->merchantId,
            'invoice_reference' => $this->invoice->getUuid(),
            'invoice_date' => date('Y-m-d H:i:s'),
            'invoice_amount' => $this->invoice->getAmount(),
            'payer_first_name' => $this->invoice->getDetails()['first_name'],
            'payer_last_name' => $this->invoice->getDetails()['last_name'],
            'payer_national_code' => $this->invoice->getDetails()['national_code'],
            'payer_mobile' => $this->invoice->getDetails()['mobile'],
            'callback_url' => $this->settings->callbackUrl,
            'items' => $this->getItems(),
        ]);
    }

    /**
     * call verift transaction request
     *
     * @return array
     */
    public function verifyTransaction(): array
    {
        return $this->callApi('POST', $this->settings->apiVerificationUrl, [
            'merchant_code' => $this->settings->merchantId,
            'token' => $this->invoice->getTransactionId(),
            'invoice_reference' => $this->invoice->getDetail('uuid'),
            'invoice_amount' => $this->invoice->getAmount(),
        ]);
    }

    /**
     * get Items for
     *
     *
     */
    private function getItems()
    {
        /**
         * example data
         *
         *   $items = [
         *       [
         *           "reference" => "string",
         *           "name" => "string",
         *           "quantity" => 0,
         *           "unit_price" => 0,
         *           "unit_discount" => 0,
         *           "unit_tax_amount" => 0,
         *           "total_amount" => 0
         *       ]
         *   ];
         */
        return $this->invoice->getDetails()['items'];
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
            "server_error" => "یک خطای داخلی رخ داده است.",
            "ip_address_error" => "آدرس IP پذیرنده صحیح نیست.",
            "validation_error" => "اطلاعات ارسال شده صحیح نیست.",
            "merchant_error" => "کد پذیرنده معتبر نیست.",
            "payment_token_error" => "شناسه پرداخت معتبر نیست.",
            "invoice_amount_error" => "مبلغ تراکنش با مبلغ پرداخت شده مطابقت ندارد.",
        ];

        if (array_key_exists($status, $translations)) {
            throw new PurchaseFailedException($translations[$status]);
        } else {
            throw new PurchaseFailedException('خطای ناشناخته ای رخ داده است.');
        }
    }
}
