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

        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        $orderId = crc32($this->invoice->getUuid()).time();
        if (!empty($details['orderId'])) {
            $orderId = $details['orderId'];
        } elseif (!empty($details['order_id'])) {
            $orderId = $details['order_id'];
        }

        $data = array(
            "merchant"=> $this->settings->merchantId, //required
            "callbackUrl"=> $this->settings->callbackUrl, //required
            "amount"=> $amount, //required
            "orderId"=> $orderId, //optional
        );

        // Pass current $data array to add existing optional details
        $data = $this->checkOptionalDetails($data);

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
        $status = Request::input('status');
        $orderId = Request::input('orderId');
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('trackId');

        if ($successFlag != 1) {
            if ($status == -2) {
                $this->notVerified('خطای داخلی', $status);
            } elseif ($status == -1) {
                $this->notVerified('در انتظار پردخت', $status);
            } elseif ($status == 2) {
                $this->notVerified('پرداخت شده - تاییدنشده', $status);
            } elseif ($status == 3) {
                $this->notVerified('تراکنش توسط کاربر لغو شد.', $status);
            } elseif ($status == 4) {
                $this->notVerified('‌شماره کارت نامعتبر می‌باشد.', $status);
            } elseif ($status == 5) {
                $this->notVerified('موجودی حساب کافی نمی‌باشد.', $status);
            } elseif ($status == 6) {
                $this->notVerified('رمز واردشده اشتباه می‌باشد.', $status);
            } elseif ($status == 7) {
                $this->notVerified('تعداد درخواست‌ها بیش از حد مجاز می‌باشد.', $status);
            } elseif ($status == 8) {
                $this->notVerified('‌تعداد پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد.', $status);
            } elseif ($status == 9) {
                $this->notVerified('مبلغ پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد.', $status);
            } elseif ($status == 10) {
                $this->notVerified('‌صادرکننده‌ی کارت نامعتبر می‌باشد.', $status);
            } elseif ($status == 11) {
                $this->notVerified('‌خطای سوییچ', $status);
            } elseif ($status == 12) {
                $this->notVerified('کارت قابل دسترسی نمی‌باشد.', $status);
            } else {
                $this->notVerified('خطای ناشناخته ای رخ داده است.');
            }
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
            $this->notVerified($body->message, $body->result);
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
