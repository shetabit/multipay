<?php

namespace Shetabit\Multipay\Drivers\Sizpay;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Sizpay extends Driver
{
    /**
     * Nextpay Client.
     */
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
     * Nextpay constructor.
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
     * @throws \SoapFault
     */
    public function purchase()
    {
        $client = new \SoapClient($this->settings->apiPurchaseUrl);
        $response = $client->GetToken2([
            'MerchantID' => $this->settings->merchantId,
            'TerminalID' => $this->settings->terminal,
            'UserName' => $this->settings->username,
            'Password' => $this->settings->password,
            'Amount' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'OrderID' => time(),
            'ReturnURL' => $this->settings->callbackUrl,
            'InvoiceNo' => time(),
            'DocDate' => '',
            'ExtraInf' => time(),
            'AppExtraInf' => '',
            'SignData' => $this->settings->SignData
        ])->GetToken2Result;
        $result = json_decode($response);

        if (! isset($result->ResCod) || ! in_array($result->ResCod, ['0', '00'])) {
            // error has happened
            $message = $result->Message ?? 'خطای ناشناخته رخ داده';
            throw new PurchaseFailedException($message);
        }

        $this->invoice->transactionId($result->Token);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay() : RedirectionForm
    {
        $payUrl = $this->settings->apiPaymentUrl;

        return $this->redirectWithForm(
            $payUrl,
            [
                'MerchantID' => $this->settings->merchantId,
                'TerminalID' => $this->settings->terminal,
                'Token' => $this->invoice->getTransactionId(),
                'SignData' => $this->settings->SignData
            ],
            'POST'
        );
    }

    /**
     * Verify payment
     *
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $resCode = Request::input('ResCod');
        if (! in_array($resCode, ['0', '00'])) {
            $message = 'پرداخت توسط کاربر لغو شد';
            throw new InvalidPaymentException($message);
        }

        $data = [
            'MerchantID'  => $this->settings->merchantId,
            'TerminalID'  => $this->settings->terminal,
            'UserName'    => $this->settings->username,
            'Password'    => $this->settings->password,
            'Token'       => Request::input('Token'),
            'SignData'    => $this->settings->SignData
        ];

        $client = new \SoapClient($this->settings->apiVerificationUrl);
        $response = $client->Confirm2($data)->Confirm2Result;
        $result = json_decode($response);

        if (! isset($result->ResCod) || ! in_array($result->ResCod, ['0', '00'])) {
            $message = $result->Message ?? 'خطا در انجام عملیات رخ داده است';
            throw new InvalidPaymentException($message, (int)($result->ResCod ?? 0));
        }

        return $this->createReceipt($result->RefNo);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $resCode
     */
    protected function createReceipt($resCode): \Shetabit\Multipay\Receipt
    {
        return new Receipt('sizpay', $resCode);
    }
}
