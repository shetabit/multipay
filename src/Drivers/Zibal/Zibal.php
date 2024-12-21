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

        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        $orderId = crc32($this->invoice->getUuid()).time();
        if (!empty($details['orderId'])) {
            $orderId = $details['orderId'];
        } elseif (!empty($details['order_id'])) {
            $orderId = $details['order_id'];
        }

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->settings->apiPurchaseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{
			"merchant": "'.$this->settings->merchantId.'",
			"amount": "'.$amount.'",
			"callbackUrl": "'.$this->settings->callbackUrl.'",
			"orderId": "'.$orderId.'"
		}',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $body = json_decode($response, false);

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
        $status = Request::input('status');
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('trackId');

        if ($successFlag != 1) {
            $this->notVerified($this->translateStatus($status), $status);
        }

        //start verfication

        $curl = curl_init();

        $postData = json_encode([
            "merchant" => $this->settings->merchantId,
            "trackId" => $transactionId,
        ]);

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->settings->apiVerificationUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($postData),
            ],
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $body = json_decode($response, false);

        if ($body->result != 100) {
            $this->notVerified($body->message, $body->result);
        }

        /*
            for more info:
            var_dump($body);
        */

        return $this->createReceipt($body->refNumber);
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

    private function translateStatus($status)
    {
        $translations = [
            -2 => 'خطای داخلی',
            -1 => 'در انتظار پردخت',
            2 => 'پرداخت شده - تاییدنشده',
            3 => 'تراکنش توسط کاربر لغو شد.',
            4 => 'شماره کارت نامعتبر می‌باشد.',
            5 => 'موجودی حساب کافی نمی‌باشد.',
            6 => 'رمز واردشده اشتباه می‌باشد.',
            7 => 'تعداد درخواست‌ها بیش از حد مجاز می‌باشد.',
            8 => 'تعداد پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد.',
            9 => 'مبلغ پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد.',
            10 => 'صادرکننده‌ی کارت نامعتبر می‌باشد.',
            11 => '‌خطای سوییچ',
            12 => 'کارت قابل دسترسی نمی‌باشد.'
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
