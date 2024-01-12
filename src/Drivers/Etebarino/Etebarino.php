<?php

namespace Shetabit\Multipay\Drivers\Etebarino;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;

class Etebarino extends Driver
{
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
     * Etebarino constructor.
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
     * Purchase Invoice
     *
     * @return string
     *
     * @throws PurchaseFailedException
     */
    public function purchase()
    {
        $this->invoice->uuid(crc32($this->invoice->getUuid()));

        $result = $this->token();

        if (!isset($result['status_code']) or $result['status_code'] != 200) {
            $this->purchaseFailed($result['content']);
        }

        $this->invoice->transactionId($result['content']);

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
        return $this->redirectWithForm($this->settings->apiPaymentUrl, [
            'token' => $this->invoice->getTransactionId()
        ], 'GET');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws PurchaseFailedException
     */
    public function verify(): ReceiptInterface
    {
        $result = $this->verifyTransaction();

        if (!isset($result['status_code']) or $result['status_code'] != 200) {
            $this->purchaseFailed($result['content']);
        }

        $receipt = $this->createReceipt($this->invoice->getDetail('referenceCode'));
        $receipt->detail([
            'referenceNo' => $this->invoice->getDetail('referenceCode'),
        ]);

        return $receipt;
    }

    /**
     * send request to Etebarino
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
            'content' => $response->getBody()->getContents()
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
        $receipt = new Receipt('etebarino', $referenceId);

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
            'terminalCode' => $this->settings->terminalId,
            'terminalUser' => $this->settings->username,
            'merchantCode' => $this->settings->merchantId,
            'terminalPass' => $this->settings->password,
            'merchantRefCode' => $this->invoice->getUuid(),
            "description" => $this->invoice->getDetail('description'),
            "returnUrl" => $this->settings->callbackUrl,
            'paymentItems' => $this->getItems(),
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
            'terminalCode' => $this->settings->terminalId,
            'terminalUser' => $this->settings->username,
            'merchantCode' => $this->settings->merchantId,
            'terminalPass' => $this->settings->password,
            'merchantRefCode' => $this->invoice->getDetail('uuid'),
            'referenceCode' => $this->invoice->getDetail('referenceCode')
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
         *           "productGroup" => 1000,
         *           "amount" => 1000, //Rial
         *           "description" => "desc"
         *       ]
         *   ];
         */
        return $this->invoice->getDetails()['items'];
    }


    /**
     * Trigger an exception
     *
     * @param $message
     *
     * @throws PurchaseFailedException
     */
    protected function purchaseFailed($message)
    {
        throw new PurchaseFailedException($message);
    }
}
