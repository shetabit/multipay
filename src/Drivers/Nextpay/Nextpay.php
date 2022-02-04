<?php

namespace Shetabit\Multipay\Drivers\Nextpay;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Nextpay extends Driver
{
    /**
     * Nextpay Client.
     *
     * @var Client
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
     * Nextpay constructor.
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $data = array(
            'api_key' => $this->settings->merchantId,
            'order_id' => intval(1, time()).crc32($this->invoice->getUuid()),
            'amount' => $this->invoice->getAmount(),
            'callback_uri' => $this->settings->callbackUrl,
        );

        if (isset($this->invoice->getDetails()['customer_phone'])) {
            $data['customer_phone']=$this->invoice->getDetails()['customer_phone'];
        }

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "form_params" => $data,
                    "http_errors" => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);

        if (empty($body['code']) || $body['code'] != -1) {
            // error has happened
            throw new PurchaseFailedException($body['message']);
        }

        $this->invoice->transactionId($body['trans_id']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay() : RedirectionForm
    {
        $payUrl = $this->settings->apiPaymentUrl.$this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('trans_id');

        $data = [
            'api_key' => $this->settings->merchantId,
            'order_id' => Request::input('order_id'),
            'amount' => $this->invoice->getAmount(),
            'trans_id' => $transactionId,
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiVerificationUrl,
                [
                    "form_params" => $data,
                    "http_errors" => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);

        if (!isset($body['code']) || $body['code'] != 0) {
            $message = $body['message'] ?? 'خطای ناشناخته ای رخ داده است';

            throw new InvalidPaymentException($message);
        }

        return $this->createReceipt($transactionId);
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
        $receipt = new Receipt('nextpay', $referenceId);

        return $receipt;
    }
}
