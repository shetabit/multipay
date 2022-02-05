<?php

namespace Shetabit\Multipay\Drivers\Zibal;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Zibal extends Driver
{
    /**
     * Zibal Client.
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
     * Zibal constructor.
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

        // convert to toman
        $toman = $this->invoice->getAmount() * 10;

        $orderId = $this->invoice->getUuid(true).time();
        if (!empty($details['orderId'])) {
            $orderId = $details['orderId'];
        } elseif (!empty($details['order_id'])) {
            $orderId = $details['order_id'];
        }

        $mobile = null;
        if (!empty($details['mobile'])) {
            $mobile = $details['mobile'];
        } elseif (!empty($details['phone'])) {
            $mobile = $details['phone'];
        }

        $description = null;
        if (!empty($details['description'])) {
            $description = $details['description'];
        } else {
            $description = $this->settings->description;
        }

        $data = array(
            "merchant"=> $this->settings->merchantId, //required
            "callbackUrl"=> $this->settings->callbackUrl, //required
            "amount"=> $toman, //required
            "orderId"=> $orderId, //optional
            'mobile' => $mobile, //optional for mpg
            "description" => $description, //optional
        );

        //checking if optional allowedCards parameter exists
        $allowedCards = null;
        if (!empty($details['allowedCards'])) {
            $allowedCards = $details['allowedCards'];
        } elseif (!empty($this->settings->allowedCards)) {
            $allowedCards = $this->settings->allowedCards;
        }

        if ($allowedCards != null) {
            $allowedCards = array(
                'allowedCards' => $allowedCards,
            );
            $data = array_merge($data, $allowedCards);
        }

        $response = $this->client->request(
            'POST',
            $this->settings->apiPurchaseUrl,
            ["json" => $data, "http_errors" => false]
        );

        $body = json_decode($response->getBody()->getContents(), false);

        if ($body->result != 100) {
            // some error has happened
            throw new PurchaseFailedException($body->message);
        }

        $this->invoice->transactionId($body->trackId);

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

        if (strtolower($this->settings->mode) == 'direct') {
            $payUrl .= '/direct';
        }

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
        $successFlag = Request::input('success');
        $orderId = Request::input('orderId');
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('trackId');

        if ($successFlag != 1) {
            $this->notVerified('پرداخت با شکست مواجه شد');
        }

        //start verfication
        $data = array(
            "merchant" => $this->settings->merchantId, //required
            "trackId" => $transactionId, //required
        );

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            ["json" => $data, "http_errors" => false]
        );

        $body = json_decode($response->getBody()->getContents(), false);

        if ($body->result != 100) {
            $this->notVerified($body->message);
        }

        /*
            for more info:
            var_dump($body);
        */

        return $this->createReceipt($orderId);
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
        $receipt = new Receipt('Zibal', $referenceId);

        return $receipt;
    }

    /**
     * Trigger an exception
     *
     * @param $message
     * @throws InvalidPaymentException
     */
    private function notVerified($message)
    {
        if (empty($message)) {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.');
        } else {
            throw new InvalidPaymentException($message);
        }
    }
}
