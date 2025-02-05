<?php

namespace Shetabit\Multipay\Drivers\Zibal;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Zibal extends Driver
{
    /**
     * @var \GuzzleHttp\Client
     */
    public $client;
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
        $mobile = $this->invoice->getDetail('phone')
            ?? $this->invoice->getDetail('cellphone')
            ?? $this->invoice->getDetail('mobile');

        $data = [
            'callbackUrl' => $this->settings->callbackUrl,
            'merchant' => $this->settings->merchantId,
            'amount' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1),
            'description' => $this->invoice->getDetail('description') ?? $this->settings->description,
            'mobile' => $mobile,
        ];

        $orderId = $this->invoice->getDetail('orderId')
            ?? $this->invoice->getDetail('order_id');

        if (!is_null($orderId)) {
            $data['orderId'] = $orderId;
        }

        /**
         * can pass optionalField in the invoice's details,
         * and it will be merged with the data
         *
         * supported optionalFields:
         * - allowedCards
         * - ledgerId
         * - nationalCode
         * - checkMobileWithCard
         * - percentMode
         * - feeMode
         * - multiplexingInfos
         *
         * @see https://help.zibal.ir/IPG/API/#request
         */
        if (!is_null($optionalField = $this->invoice->getDetail('optionalField')) && is_array($optionalField)) {
            $data = array_merge($data, $optionalField);
        }

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    RequestOptions::BODY => json_encode($data),
                    RequestOptions::HEADERS => [
                        'Content-Type' => 'application/json',
                    ],
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );


        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200) {
            // connection error
            $message = $body['message'] ?? 'خطا در هنگام درخواست برای پرداخت رخ داده است.';
            throw new PurchaseFailedException($message, (int) $response->getStatusCode());
        } elseif ($body['result'] != 100) {
            // gateway errors
            throw new PurchaseFailedException($this->translateStatus($body['result']) ?? $body['message'], $body['result']);
        }

        $this->invoice->transactionId($body['trackId']);
        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay(): RedirectionForm
    {
        $payUrl = $this->settings->apiPaymentUrl.$this->invoice->getTransactionId();

        if (strtolower($this->settings->mode) === 'direct') {
            $payUrl .= '/direct';
        }

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
    public function verify(): ReceiptInterface
    {
        if (!is_null($successFlag = Request::input('success')) && !in_array($successFlag, [1, 2])) {
            $status = Request::input('status');

            throw new InvalidPaymentException($this->translateStatus($status), $status);
        }

        //start verification
        $data = [
            'merchant' => $this->settings->merchantId,
            'trackId' => (int) ($this->invoice->getTransactionId() ?? Request::input('trackId')),
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                RequestOptions::BODY => json_encode($data),
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200) {
            // connection error
            $message = $body['message'] ?? 'خطا در هنگام وریفای تراکنش رخ داده است.';
            throw new PurchaseFailedException($message, (int) $response->getStatusCode());
        } elseif ($body['result'] == 201) {
            // transaction has been verified before

            throw new PreviouslyVerifiedException($this->translateStatus($body['result']) ?? $body['message'], $body['result']);
        } elseif ($body['result'] != 100) {
            // gateway errors
            throw new PurchaseFailedException($this->translateStatus($body['result']) ?? $body['message'], $body['result']);
        }

        return (new Receipt('Zibal', $body['refNumber']))->detail($body);
    }

    private function translateStatus($status): string
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
            12 => 'کارت قابل دسترسی نمی‌باشد.',
            15 => 'تراکنش استرداد شده',
            16 => 'تراکنش در حال استرداد',
            18 => 'تراکنش ریورس شده',
            102 => 'merchantیافت نشد.',
            103 => 'merchantغیرفعال',
            104 => 'merchantنامعتبر',
            105 => 'amountبایستی بزرگتر از 1,000 ریال باشد.',
            106 => 'callbackUrlنامعتبر می‌باشد. (شروع با http و یا https)',
            113 => 'amountمبلغ تراکنش از سقف میزان تراکنش بیشتر است.',
            114 => 'کدملی ارسالی نامعتبر است.',
            201 => 'قبلا تایید شده',
            202 => 'سفارش پرداخت نشده یا ناموفق بوده است.',
            203 => 'trackId نامعتبر می‌باشد.',
        ];

        $unknownError = 'خطای ناشناخته ای رخ داده است.';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }
}
