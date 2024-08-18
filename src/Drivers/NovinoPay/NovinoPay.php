<?php
/*
 * @Author: Arvin Loripour - ViraEcosystem 
 * @Date: 2024-08-18 21:52:23 
 * Copyright by Arvin Loripour 
 * WebSite : http://www.arvinlp.ir 
 * @Last Modified by:   Arvin.Loripour 
 * @Last Modified time: 2024-08-18 21:52:23 
 */

namespace Shetabit\Multipay\Drivers\NovinoPay;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class NovinoPay extends Driver
{
    /**
     * Novinopay Client.
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
     * Novinopay constructor.
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $details = $this->invoice->getDetails();

        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        $orderId = crc32($this->invoice->getUuid()).time();
        if (!empty($details['orderId'])) {
            $orderId = $details['orderId'];
        } elseif (!empty($details['order_id'])) {
            $orderId = $details['order_id'];
        }
        
        $data = array(
            "merchant_id"=> $this->settings->merchantId, //required
            "callback_url"=> $this->settings->callbackUrl, //required
            "amount"=> $amount, //required
        );

        // Pass current $data array to add existing optional details
        $data = $this->checkOptionalDetails($data);

        $response = $this->client->request(
            'POST',
            $this->settings->apiPurchaseUrl,
            ["json" => $data, "http_errors" => false]
        );

        $body = json_decode($response->getBody()->getContents(), false);
        
        if ($body->status != 100) {
            // some error has happened
            throw new PurchaseFailedException($body->message);
        }

        $this->invoice->transactionId($body->data->authority);

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

        return $this->redirectWithForm($payUrl);
    }

    /**
     * Verify payment
     *
     * @return mixed|void
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $successFlag = Request::input('PaymentStatus');
        $status = Request::input('status');
        $orderId = Request::input('InvoiceID');
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('Authority');

        if ($successFlag != "NOK") {
            $this->notVerified('انصراف از پرداخت', 0);
        }

        //start verfication
        $data = array(
            "merchant_id" => $this->settings->merchantId, //required
            "authority" => $transactionId, //required
            'amount' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 1 : 10), // convert to rial
        );

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            ["json" => $data, "http_errors" => false]
        );

        $body = json_decode($response->getBody()->getContents(), false);

        if ($body->status != 100) {
            $this->notVerified($body->message, $body->status);
        }

        /*
            for more info:
            var_dump($body);
        */

        return $this->createReceipt($body->data->ref_id);
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
        $receipt = new Receipt('Novinopay', $referenceId);

        return $receipt;
    }

    private function translateStatus($status)
    {
        $translations = [
            'NOK' => 'انصراف از پرداخت',
        ];

        $unknownError = 'خطای ناشناخته ای رخ داده است.';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }

    /**
     * Trigger an exception
     *
     * @param $message
     * @throws InvalidPaymentException
     */
    private function notVerified($message, $code = 0)
    {
        if (empty($message)) {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.', $code);
        } else {
            throw new InvalidPaymentException($message, $code);
        }
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        $detail = null;
        if (!empty($this->invoice->getDetails()[$name])) {
            $detail = $this->invoice->getDetails()[$name];
        } elseif (!empty($this->settings->$name)) {
            $detail = $this->settings->$name;
        }

        return $detail;
    }

    /**
     * Checks optional parameters existence (except orderId) and
     * adds them to the given $data array and returns new array
     * with optional parameters for api call.
     *
     * To avoid errors and have a cleaner api call log, `null`
     * parameters are not sent.
     *
     * To add new parameter support in the future, all that
     * is needed is to add parameter name to $optionalParameters
     * array.
     *
     * @param $data
     *
     * @return array
     */
    private function checkOptionalDetails($data)
    {
        $optionalParameters = [
            'mobile',
            'description',
            'allowedCards',
            'feeMode',
            'percentMode',
            'multiplexingInfos'
        ];

        foreach ($optionalParameters as $parameter) {
            if (!is_null($this->extractDetails($parameter))) {
                $parameterArray = array(
                    $parameter => $this->extractDetails($parameter)
                );
                $data = array_merge($data, $parameterArray);
            }
        }

        return $data;
    }
}
