<?php

namespace Shetabit\Multipay\Drivers\Yekpay;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use SoapClient;
use stdClass;

class Yekpay extends Driver
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
     * Yekpay constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
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
        $client = new SoapClient($this->settings->apiPurchaseUrl, array('trace' => true));

        $data = new stdClass();

        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }

        $data->merchantId = $this->settings->merchantId;
        $data->amount = $this->invoice->getAmount();
        $data->callback = $this->settings->callbackUrl;
        $data->orderNumber = intval(1, time()).crc32($this->invoice->getUuid());

        $data->fromCurrencyCode = (int) $this->settings->fromCurrencyCode;
        $data->toCurrencyCode = (int) $this->settings->toCurrencyCode;

        $data->firstName = $this->extractDetails('firstName');
        $data->lastName = $this->extractDetails('lastName');
        $data->email = $this->extractDetails('email');
        $data->mobile = $this->extractDetails('mobile');

        $data->address = $this->extractDetails('address');
        $data->country = $this->extractDetails('country');
        $data->postalCode = $this->extractDetails('postalCode');
        $data->city = $this->extractDetails('city');

        $data->description = $description;

        $response = json_decode($client->request($data));

        if ($response->Code != 100) {
            //"Request failed with Error code: $response->Code and Error message: $response->Description";
            throw new PurchaseFailedException($response->Description);
        }

        $this->invoice->transactionId($response->Authority);

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
        $options = array('trace' => true);
        $client = new SoapClient($this->settings->apiVerificationUrl, $options);

        $data = new stdClass();

        $data->merchantId = $this->settings->merchantId;
        $data->authority = $this->invoice->getTransactionId() ?? Request::input('authority');

        $response = json_decode($client->verify($data));

        if ($response->Code != 100) {
            $this->notVerified($response->message ?? 'payment failed', $response->Code);
        }

        //"Success Payment with reference: $response->Reference and message: $response->message";
        return $this->createReceipt($response->Reference);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return ReceiptInterface
     */
    protected function createReceipt($referenceId) : ReceiptInterface
    {
        $receipt = new Receipt('yekpay', $referenceId);

        return $receipt;
    }

    /**
     * Trigger an exception
     *
     * @param $message
     * @param $status
     *
     * @throws InvalidPaymentException
     */
    private function notVerified($message, $status)
    {
        if ($message) {
            throw new InvalidPaymentException($message, (int)$status);
        } else {
            throw new InvalidPaymentException('payment failed', (int)$status);
        }
    }
}
