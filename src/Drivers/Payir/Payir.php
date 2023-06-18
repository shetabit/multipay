<?php

namespace Shetabit\Multipay\Drivers\Payir;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Payir extends Driver
{
    /**
     * Payir Client.
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
     * Payir constructor.
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $mobile = $this->extractDetails('mobile');
        $description = $this->extractDetails('description');
        $validCardNumber = $this->extractDetails('validCardNumber');

        $data = array(
            'api' => $this->settings->merchantId,
            'amount' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'redirect' => $this->settings->callbackUrl,
            'mobile' => $mobile,
            'description' => $description,
            'factorNumber' => $this->invoice->getUuid(),
            'validCardNumber' => $validCardNumber
        );

        $response = $this->client->request(
            'POST',
            $this->settings->apiPurchaseUrl,
            [
                "form_params" => $data,
                "http_errors" => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents(), true);

        if ($body['status'] != 1) {
            // some error has happened
            throw new PurchaseFailedException($body['errorMessage']);
        }

        $this->invoice->transactionId($body['token']);

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
        $payUrl = $this->settings->apiPaymentUrl . $this->invoice->getTransactionId();

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
    public function verify(): ReceiptInterface
    {
        $data = [
            'api' => $this->settings->merchantId,
            'token'  => $this->invoice->getTransactionId() ?? Request::input('token'),
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                "form_params" => $data,
                "http_errors" => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents(), true);

        if (isset($body['status'])) {
            if ($body['status'] != 1) {
                $this->notVerified($body['errorCode']);
            }
        } else {
            $this->notVerified(null);
        }

        return $this->createReceipt($body['transId']);
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
        $receipt = new Receipt('payir', $referenceId);

        return $receipt;
    }

    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @throws InvalidPaymentException
     */
    private function notVerified($status)
    {
        $translations = array(
            0 => 'درحال حاضر درگاه بانکی قطع شده و مشکل بزودی برطرف می شود',
            -1 => 'API Key ارسال نمی شود',
            -2 => 'Token ارسال نمی شود',
            -3 => 'API Key ارسال شده اشتباه است',
            -4 => 'امکان انجام تراکنش برای این پذیرنده وجود ندارد',
            -5 => 'تراکنش با خطا مواجه شده است',
            -6 => 'تراکنش تکراریست یا قبلا انجام شده',
            -7 => 'مقدار Token ارسالی اشتباه است',
            -8 => 'شماره تراکنش ارسالی اشتباه است',
            -9 => 'زمان مجاز برای انجام تراکنش تمام شده',
            -10 => 'مبلغ تراکنش ارسال نمی شود',
            -11 => 'مبلغ تراکنش باید به صورت عددی و با کاراکترهای لاتین باشد',
            -12 => 'مبلغ تراکنش می بایست عددی بین 10,000 و 500,000,000 ریال باشد',
            -13 => 'مقدار آدرس بازگشتی ارسال نمی شود',
            -14 => 'آدرس بازگشتی ارسالی با آدرس درگاه ثبت شده در شبکه پرداخت پی یکسان نیست',
            -15 => 'امکان وریفای وجود ندارد. این تراکنش پرداخت نشده است',
            -16 => 'یک یا چند شماره موبایل از اطلاعات پذیرندگان ارسال شده اشتباه است',
            -17 => 'میزان سهم ارسالی باید بصورت عددی و بین 1 تا 100 باشد',
            -18 => 'فرمت پذیرندگان صحیح نمی باشد',
            -19 => 'هر پذیرنده فقط یک سهم میتواند داشته باشد',
            -20 => 'مجموع سهم پذیرنده ها باید 100 درصد باشد',
            -21 => 'Reseller ID ارسالی اشتباه است',
            -22 => 'فرمت یا طول مقادیر ارسالی به درگاه اشتباه است',
            -23 => 'سوییچ PSP ( درگاه بانک ) قادر به پردازش درخواست نیست. لطفا لحظاتی بعد مجددا تلاش کنید',
            -24 => 'شماره کارت باید بصورت 16 رقمی، لاتین و چسبیده بهم باشد',
            -25 => 'امکان استفاده از سرویس در کشور مبدا شما وجود نداره',
            -26 => 'امکان انجام تراکنش برای این درگاه وجود ندارد',
        );

        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status], (int)$status);
        } else {
            throw new InvalidPaymentException('تراکنش با خطا مواجه شد.', (int)$status);
        }
    }
}
