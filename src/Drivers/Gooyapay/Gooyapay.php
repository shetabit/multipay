<?php

namespace Shetabit\Multipay\Drivers\Gooyapay;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Gooyapay extends Driver
{
    /**
     * Gooyapay Client.
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
     * Gooyapay constructor.
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

        $name = '';
        if (!empty($details['name'])) {
            $name = $details['name'];
        }

        $mobile = '';
        if (!empty($details['mobile'])) {
            $mobile = $details['mobile'];
        } elseif (!empty($details['phone'])) {
            $mobile = $details['phone'];
        }

        $email = '';
        if (!empty($details['mail'])) {
            $email = $details['mail'];
        } elseif (!empty($details['email'])) {
            $email = $details['email'];
        }

        $desc = '';
        if (!empty($details['desc'])) {
            $desc = $details['desc'];
        } elseif (!empty($details['description'])) {
            $desc = $details['description'];
        }

        $amount = $this->invoice->getAmount();
        if ($this->settings->currency != 'T') {
            $amount /= 10;
        }
        $amount = intval(ceil($amount));

        $orderId = crc32($this->invoice->getUuid());
        if (!empty($details['orderId'])) {
            $orderId = $details['orderId'];
        } elseif (!empty($details['order_id'])) {
            $orderId = $details['order_id'];
        }

        $data = array(
            "MerchantID" 	=> $this->settings->merchantId,
            "Amount" 		=> $amount,
            "InvoiceID" 	=> $orderId,
            "Description" 	=> $desc,
            "FullName" 		=> $name,
            "Email" 		=> $email,
            "Mobile" 		=> $mobile,
            "CallbackURL" 	=> $this->settings->callbackUrl,
        );

        $response = $this->client->request('POST', $this->settings->apiPurchaseUrl, ["json" => $data, "http_errors" => false]);

        $body = json_decode($response->getBody()->getContents(), false);

        if ($body->Status != 100) {
            // some error has happened
            throw new PurchaseFailedException($body->Message);
        }

        $this->invoice->transactionId($body->Authority);

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
     * @return mixed|void
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $PaymentStatus = Request::input('PaymentStatus');
        if ($PaymentStatus != 'OK') {
            throw new InvalidPaymentException('تراکنش از سوی کاربر لغو شد', $PaymentStatus);
        }

        $Authority = Request::input('Authority');
        $InvoiceID = Request::input('InvoiceID');

        if ($Authority != $this->invoice->getTransactionId()) {
            throw new InvalidPaymentException('اطلاعات تراکنش دریافتی با صورتحساب همخوانی ندارد', 'DATAMISMATCH');
        }

        $amount = $this->invoice->getAmount();
        if ($this->settings->currency != 'T') {
            $amount /= 10;
        }
        $amount = intval(ceil($amount));

        //start verfication
        $data = array(
            "MerchantID" 	=> $this->settings->merchantId,
            "Authority" 	=> $Authority,
            "Amount" 		=> $amount,
        );

        $response = $this->client->request('POST', $this->settings->apiVerificationUrl, ["json" => $data, "http_errors" => false]);

        $body = json_decode($response->getBody()->getContents(), false);

        if ($body->Status != 100) {
            throw new InvalidPaymentException($body->Message, $body->Status);
        }

        $receipt = new Receipt('gooyapay', $body->RefID);

        $receipt->detail([
            'Authority' 	=> $data['Authority'],
            'InvoiceID1' 	=> $InvoiceID,
            'InvoiceID2' 	=> $body->InvoiceID,
            'Amount1' 		=> $data['Amount'],
            'Amount2' 		=> $body->Amount,
            'CardNumber' 	=> $body->MaskCardNumber,
            'PaymentTime' 	=> $body->PaymentTime,
            'PaymenterIP' 	=> $body->BuyerIP
        ]);

        return $receipt;
    }
}
