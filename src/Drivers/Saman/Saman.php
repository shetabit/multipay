<?php

namespace Shetabit\Multipay\Drivers\Saman;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Saman extends Driver
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
     * Saman constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
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
        $data = array(
            'MID' => $this->settings->merchantId,
            'ResNum' => $this->invoice->getUuid(),
            'Amount' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'CellNumber' => ''
        );

        //set CellNumber for get user cards
        if (!empty($this->invoice->getDetails()['mobile'])) {
            $data['CellNumber'] = $this->invoice->getDetails()['mobile'];
        }

        $soap = new \SoapClient(
            $this->settings->apiPurchaseUrl,
            [
                'encoding'       => 'UTF-8',
                'cache_wsdl'     => WSDL_CACHE_NONE,
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'ciphers' => 'DEFAULT:!DH',
                    ],
                ]),
            ]
        );

        $response = $soap->RequestToken($data['MID'], $data['ResNum'], $data['Amount'], $data['CellNumber']);

        $status = (int)$response;

        if ($status < 0) { // if something has done in a wrong way
            $this->purchaseFailed($response);
        }

        // set transaction id
        $this->invoice->transactionId($response);

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
        $payUrl = $this->settings->apiPaymentUrl;

        return $this->redirectWithForm(
            $payUrl,
            [
                'Token' => $this->invoice->getTransactionId(),
                'RedirectUrl' => $this->settings->callbackUrl,
            ],
            'POST'
        );
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \SoapFault
     */
    public function verify(): ReceiptInterface
    {
        $data = array(
            'RefNum' => Request::input('RefNum'),
            'merchantId' => $this->settings->merchantId,
        );

        $soap = new \SoapClient(
            $this->settings->apiVerificationUrl,
            [
                'encoding'       => 'UTF-8',
                'cache_wsdl'     => WSDL_CACHE_NONE,
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'ciphers' => 'DEFAULT:!DH',
                    ],
                ]),
            ]
        );
        $status = (int)$soap->VerifyTransaction($data['RefNum'], $data['merchantId']);

        if ($status < 0) {
            $this->notVerified($status);
        }

        $receipt =  $this->createReceipt($data['RefNum']);
        $receipt->detail([
            'traceNo' => Request::input('TraceNo'),
            'referenceNo' => Request::input('RRN'),
            'transactionId' => Request::input('RefNum'),
            'cardNo' => Request::input('SecurePan'),
        ]);

        return $receipt;
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
        $receipt = new Receipt('saman', $referenceId);

        return $receipt;
    }

    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @throws PurchaseFailedException
     */
    protected function purchaseFailed($status)
    {
        $translations = array(
            -1 => ' تراکنش توسط خریدار کنسل شده است.',
            -6 => 'سند قابل برگشت کامل یافته است. یا خارج از زمان 30 دقیقه ارسال شده است.',
            -18 => 'IP Address فروشنده نا‌معتبر است.',
            79 => 'مبلغ سند برگشتی، از مبلغ تراکنش اصلی بیشتر است.',
            12 => 'درخواست برگشت یک تراکنش رسیده است، در حالی که تراکنش اصلی پیدا نمی شود.',
            14 => 'شماره کارت نامعتبر است.',
            15 => 'چنین صادر کننده کارتی وجود ندارد.',
            33 => 'از تاریخ انقضای کارت گذشته است و کارت دیگر معتبر نیست.',
            38 => 'رمز کارت 3 مرتبه اشتباه وارد شده است در نتیجه کارت غیر فعال خواهد شد.',
            55 => 'خریدار رمز کارت را اشتباه وارد کرده است.',
            61 => 'مبلغ بیش از سقف برداشت می باشد.',
            93 => 'تراکنش Authorize شده است (شماره PIN و PAN درست هستند) ولی امکان سند خوردن وجود ندارد.',
            68 => 'تراکنش در شبکه بانکی Timeout خورده است.',
            34 => 'خریدار یا فیلد CVV2 و یا فیلد ExpDate را اشتباه وارد کرده است (یا اصلا وارد نکرده است).',
            51 => 'موجودی حساب خریدار، کافی نیست.',
            84 => 'سیستم بانک صادر کننده کارت خریدار، در وضعیت عملیاتی نیست.',
            96 => 'کلیه خطاهای دیگر بانکی باعث ایجاد چنین خطایی می گردد.',
        );

        if (array_key_exists($status, $translations)) {
            throw new PurchaseFailedException($translations[$status]);
        } else {
            throw new PurchaseFailedException('خطای ناشناخته ای رخ داده است.');
        }
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
            -1 => 'خطا در پردازش اطلاعات ارسالی (مشکل در یکی از ورودی ها و ناموفق بودن فراخوانی متد برگشت تراکنش)',
            -3 => 'ورودی ها حاوی کارکترهای غیرمجاز میباشند.',
            -4 => 'کلمه عبور یا کد فروشنده اشتباه است (Merchant Authentication Failed)',
            -6 => 'سند قابل برگشت کامل یافته است. یا خارج از زمان 30 دقیقه ارسال شده است.',
            -7 => 'رسید دیجیتالی تهی است.',
            -8 => 'طول ورودی ها بیشتر از حد مجاز است.',
            -9 => 'وجود کارکترهای غیرمجاز در مبلغ برگشتی.',
            -10 => 'رسید دیجیتالی به صورت Base64 نیست (حاوی کاراکترهای غیرمجاز است)',
            -11 => 'طول ورودی ها کمتر از حد مجاز است.',
            -12 => 'مبلغ برگشتی منفی است.',
            -13 => 'مبلغ برگشتی برای برگشت جزئی بیش از مبلغ برگشت نخورده ی رسید دیجیتالی است.',
            -14 => 'چنین تراکنشی تعریف نشده است.',
            -15 => 'مبلغ برگشتی به صورت اعشاری داده شده است.',
            -16 => 'خطای داخلی سیستم',
            -17 => 'برگشت زدن جزیی تراکنش مجاز نمی باشد.',
            -18 => 'IP Address فروشنده نا معتبر است و یا رمز تابع بازگشتی (reverseTransaction) اشتباه است.',
        );

        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status], (int)$status);
        } else {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.', (int)$status);
        }
    }
}
