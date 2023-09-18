<?php

namespace Shetabit\Multipay\Drivers\Nextpay;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
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
        $this->settings = (object)$settings;
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
        $data = [
            'api_key' => $this->settings->merchantId,
            'order_id' => intval(1, time()) . crc32($this->invoice->getUuid()),
            'amount' => $this->invoice->getAmount() / ($this->settings->currency == 'T' ? 1 : 10), // convert to toman
            'callback_uri' => $this->settings->callbackUrl,
        ];

        if (isset($this->invoice->getDetails()['customer_phone'])) {
            $data['customer_phone'] = $this->invoice->getDetails()['customer_phone'];
        }

        if (isset($this->invoice->getDetails()['custom_json_fields'])) {
            $data['custom_json_fields'] = $this->invoice->getDetails()['custom_json_fields'];
        }

        if (isset($this->invoice->getDetails()['payer_name'])) {
            $data['payer_name'] = $this->invoice->getDetails()['payer_name'];
        }

        if (isset($this->invoice->getDetails()['payer_desc'])) {
            $data['payer_desc'] = $this->invoice->getDetails()['payer_desc'];
        }

        if (isset($this->invoice->getDetails()['auto_verify'])) {
            $data['auto_verify'] = $this->invoice->getDetails()['auto_verify'];
        }

        if (isset($this->invoice->getDetails()['allowed_card'])) {
            $data['allowed_card'] = $this->invoice->getDetails()['allowed_card'];
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
        $code = isset($body['code']) ? $body['code'] : '';

        if ($code != -1) {
            throw new PurchaseFailedException($this->translateStatusCode($code), $code);
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
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('trans_id');

        $data = [
            'api_key' => $this->settings->merchantId,
            'order_id' => Request::input('order_id'),
            'amount' => $this->invoice->getAmount() / ($this->settings->currency == 'T' ? 1 : 10), // convert to toman
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
        $code = isset($body['code']) ? $body['code'] : '';

        if ($code != 0) {
            throw new InvalidPaymentException($this->translateStatusCode($code), $code);
        }

        return $this->createReceipt($body['Shaparak_Ref_Id']);
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


    /**
     * Convert status to a readable message.
     *
     * @param $code
     *
     * @return string
     */
    private function translateStatusCode($code): string
    {
        $translations = [
            0 => 'پرداخت تکمیل و با موفقیت انجام شده است',
            -1 => 'منتظر ارسال تراکنش و ادامه پرداخت',
            -2 => 'پرداخت رد شده توسط کاربر یا بانک',
            -3 => 'پرداخت در حال انتظار جواب بانک',
            -4 => 'پرداخت لغو شده است',
            -20 => 'کد api_key ارسال نشده است',
            -21 => 'کد trans_id ارسال نشده است',
            -22 => 'مبلغ ارسال نشده',
            -23 => 'لینک ارسال نشده',
            -24 => 'مبلغ صحیح نیست',
            -25 => 'تراکنش قبلا انجام و قابل ارسال نیست',
            -26 => 'مقدار توکن ارسال نشده است',
            -27 => 'شماره سفارش صحیح نیست',
            -28 => 'مقدار فیلد سفارشی [custom_json_fields] از نوع json نیست',
            -29 => 'کد بازگشت مبلغ صحیح نیست',
            -30 => 'مبلغ کمتر از حداقل پرداختی است',
            -31 => 'صندوق کاربری موجود نیست',
            -32 => 'مسیر بازگشت صحیح نیست',
            -33 => 'کلید مجوز دهی صحیح نیست',
            -34 => 'کد تراکنش صحیح نیست',
            -35 => 'ساختار کلید مجوز دهی صحیح نیست',
            -36 => 'شماره سفارش ارسال نشد است',
            -37 => 'شماره تراکنش یافت نشد',
            -38 => 'توکن ارسالی موجود نیست',
            -39 => 'کلید مجوز دهی موجود نیست',
            -40 => 'کلید مجوزدهی مسدود شده است',
            -41 => 'خطا در دریافت پارامتر، شماره شناسایی صحت اعتبار که از بانک ارسال شده موجود نیست',
            -42 => 'سیستم پرداخت دچار مشکل شده است',
            -43 => 'درگاه پرداختی برای انجام درخواست یافت نشد',
            -44 => 'پاسخ دریاف شده از بانک نامعتبر است',
            -45 => 'سیستم پرداخت غیر فعال است',
            -46 => 'درخواست نامعتبر',
            -47 => 'کلید مجوز دهی یافت نشد [حذف شده]',
            -48 => 'نرخ کمیسیون تعیین نشده است',
            -49 => 'تراکنش مورد نظر تکراریست',
            -50 => 'حساب کاربری برای صندوق مالی یافت نشد',
            -51 => 'شناسه کاربری یافت نشد',
            -52 => 'حساب کاربری تایید نشده است',
            -60 => 'ایمیل صحیح نیست',
            -61 => 'کد ملی صحیح نیست',
            -62 => 'کد پستی صحیح نیست',
            -63 => 'آدرس پستی صحیح نیست و یا بیش از ۱۵۰ کارکتر است',
            -64 => 'توضیحات صحیح نیست و یا بیش از ۱۵۰ کارکتر است',
            -65 => 'نام و نام خانوادگی صحیح نیست و یا بیش از ۳۵ کاکتر است',
            -66 => 'تلفن صحیح نیست',
            -67 => 'نام کاربری صحیح نیست یا بیش از ۳۰ کارکتر است',
            -68 => 'نام محصول صحیح نیست و یا بیش از ۳۰ کارکتر است',
            -69 => 'آدرس ارسالی برای بازگشت موفق صحیح نیست و یا بیش از ۱۰۰ کارکتر است',
            -70 => 'آدرس ارسالی برای بازگشت ناموفق صحیح نیست و یا بیش از ۱۰۰ کارکتر است',
            -71 => 'موبایل صحیح نیست',
            -72 => 'بانک پاسخگو نبوده است لطفا با نکست پی تماس بگیرید',
            -73 => 'مسیر بازگشت دارای خطا میباشد یا بسیار طولانیست',
            -90 => 'بازگشت مبلغ بدرستی انجام شد',
            -91 => 'عملیات ناموفق در بازگشت مبلغ',
            -92 => 'در عملیات بازگشت مبلغ خطا رخ داده است',
            -93 => 'موجودی صندوق کاربری برای بازگشت مبلغ کافی نیست',
            -94 => 'کلید بازگشت مبلغ یافت نشد',
        ];
        $unknownError = 'خطای ناشناخته رخ داده است. در صورت کسر مبلغ از حساب حداکثر پس از 72 ساعت به حسابتان برمیگردد';

        return array_key_exists($code, $translations) ? $translations[$code] : $unknownError;
    }
}
