<?php

namespace Shetabit\Multipay\Drivers\Parsian;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Parsian extends Driver
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
     * Parsian constructor.
     * Construct the class with the relevant settings.
     *
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
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
        $soap = new \SoapClient($this->settings->apiPurchaseUrl);
        $response = $soap->SalePaymentRequest(
            ['requestData' => $this->preparePurchaseData()]
        );

        // no response from bank
        if (empty($response->SalePaymentRequestResult)) {
            throw new PurchaseFailedException('bank gateway not response');
        }

        $result = $response->SalePaymentRequestResult;

        if (isset($result->Status) && $result->Status == 0 && !empty($result->Token)) {
            $this->invoice->transactionId($result->Token);
        } else {
            // an error has happened
            $statusCode = $result->Status;
            $errorMessage = $this->translateStatus($statusCode, $result->Message);
            throw new PurchaseFailedException($errorMessage, (int)$statusCode);
        }

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay() : RedirectionForm
    {
        $payUrl = sprintf(
            '%s?Token=%s',
            $this->settings->apiPaymentUrl,
            $this->invoice->getTransactionId()
        );

        return $this->redirectWithForm(
            $payUrl,
            ['Token' => $this->invoice->getTransactionId()],
            'GET'
        );
    }

    /**
     * Verify payment
     *
     *
     * @throws InvalidPaymentException
     * @throws \SoapFault
     */
    public function verify() : ReceiptInterface
    {
        $status = Request::input('status');
        $token = $this->invoice->getTransactionId() ?? Request::input('Token');

        if (empty($token)) {
            throw new InvalidPaymentException('تراکنش توسط کاربر کنسل شده است.', (int)$status);
        }

        $data = $this->prepareVerificationData();
        $soap = new \SoapClient($this->settings->apiVerificationUrl);

        $response = $soap->ConfirmPayment(['requestData' => $data]);
        if (empty($response->ConfirmPaymentResult)) {
            throw new InvalidPaymentException('از سمت بانک پاسخی دریافت نشد.');
        }
        $result = $response->ConfirmPaymentResult;

        $hasWrongStatus = (!isset($result->Status) || $result->Status != 0);
        $hasWrongRRN = (!isset($result->RRN) || $result->RRN <= 0);
        if ($hasWrongStatus || $hasWrongRRN) {
            $statusCode = $result->Status;
            $errorMessage = $this->translateStatus($statusCode, $result->Message);
            throw new InvalidPaymentException($errorMessage, (int)$statusCode);
        }

        return $this->createReceipt($result->RRN);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     */
    protected function createReceipt($referenceId): \Shetabit\Multipay\Receipt
    {
        return new Receipt('parsian', $referenceId);
    }

    /**
     * Prepare data for payment verification
     */
    protected function prepareVerificationData(): array
    {
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('Token');

        return [
            'LoginAccount' => $this->settings->merchantId,
            'Token'        => $transactionId,
        ];
    }

    /**
     * Prepare data for purchasing invoice
     */
    protected function preparePurchaseData(): array
    {
        // The bank suggests that an English description is better
        if (empty($description = $this->invoice->getDetail('description'))) {
            $description = $this->settings->description;
        }

        $phone = $this->invoice->getDetail('phone')
            ?? $this->invoice->getDetail('cellphone')
            ?? $this->invoice->getDetail('mobile');


        return [
            'LoginAccount'   => $this->settings->merchantId,
            'Amount'         => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'OrderId'        => crc32($this->invoice->getUuid()),
            'CallBackUrl'    => $this->settings->callbackUrl,
            'Originator'     => $phone,
            'AdditionalData' => $description,
        ];
    }

    /**
     * Convert status code to a readable Persian message.
     *
     * @param int|string $status
     * @param string $fallbackMessage
     *
     * @return string
     */
    private function translateStatus($status, string $fallbackMessage = ''): string
    {
        $translations = [
            -32768 => 'خطای ناشناخته رخ داده است',
            -1552 => 'برگشت تراکنش مجاز نمی باشد',
            -1551 => 'برگشت تراکنش قبلاً انجام شده است',
            -1550 => 'برگشت تراکنش در وضعیت جاری امکان پذیر نمی باشد',
            -1549 => 'زمان مجاز برای درخواست برگشت تراکنش به اتمام رسیده است',
            -1548 => 'فراخوانی سرویس درخواست پرداخت قبض ناموفق بود',
            -1540 => 'تایید تراکنش ناموفق می باشد',
            -1536 => 'فراخوانی سرویس درخواست شارژ تاپ آپ ناموفق بود',
            -1533 => 'تراکنش قبلاً تایید شده است',
            -1532 => 'تراکنش از سوی پذیرنده تایید شد',
            -1531 => 'تایید تراکنش ناموفق امکان پذیر نمی باشد',
            -1530 => 'پذیرنده مجاز به تایید این تراکنش نمی باشد',
            -1528 => 'اطلاعات پرداخت یافت نشد',
            -1527 => 'انجام عملیات درخواست پرداخت تراکنش خرید ناموفق بود',
            -1507 => 'تراکنش برگشت به سوئیچ ارسال شد',
            -1505 => 'تایید تراکنش توسط پذیرنده انجام شد',
            -1000 => 'خطا در دریافت اطلاعات از سوئیچ',
            -138 => 'عملیات پرداخت توسط کاربر لغو شد',
            -132 => 'مبلغ تراکنش کمتر از حداقل مجاز میباشد',
            -131 => 'Token نامعتبر می باشد',
            -130 => 'Token زمان منقضی شده است',
            -128 => 'قالب آدرس IP معتبر نمی باشد',
            -127 => 'آدرس اینترنتی معتبر نمی باشد',
            -126 => 'کد شناسایی پذیرنده معتبر نمی باشد',
            -121 => 'رشته داده شده بطور کامل عددی نمی باشد',
            -120 => 'طول داده ورودی معتبر نمی باشد',
            -119 => 'سازمان نامعتبر می باشد',
            -118 => 'مقدار ارسال شده عدد نمی باشد',
            -117 => 'طول رشته کم تر از حد مجاز می باشد',
            -116 => 'طول رشته بیش از حد مجاز می باشد',
            -115 => 'شناسه پرداخت نامعتبر می باشد',
            -114 => 'شناسه قبض نامعتبر می باشد',
            -113 => 'پارامتر ورودی خالی می باشد',
            -112 => 'شماره سفارش تکراری است',
            -111 => 'مبلغ تراکنش بیش از حد مجاز پذیرنده می باشد',
            -108 => 'قابلیت برگشت تراکنش برای پذیرنده غیر فعال می باشد',
            -107 => 'قابلیت ارسال تاییده تراکنش برای پذیرنده غیر فعال می باشد',
            -106 => 'قابلیت شارژ برای پذیرنده غیر فعال می باشد',
            -105 => 'قابلیت تاپ آپ برای پذیرنده غیر فعال می باشد',
            -104 => 'قابلیت پرداخت قبض برای پذیرنده غیر فعال می باشد',
            -103 => 'قابلیت خرید برای پذیرنده غیر فعال می باشد',
            -102 => 'تراکنش با موفقیت برگشت داده شد',
            -101 => 'پذیرنده احراز هویت نشد',
            -100 => 'پذیرنده غیرفعال می باشد',
            -1 => 'خطای سرور',
            0 => 'عملیات موفق می باشد',
            1 => 'صادرکننده کارت از انجام تراکنش صرف نظر کرد',
            2 => 'عملیات تاییدیه این تراکنش قبلا با موفقیت صورت پذیرفته است',
            3 => 'پذیرنده فروشگاهی نامعتبر می باشد',
            5 => 'از انجام تراکنش صرف نظر شد',
            6 => 'بروز خطای ناشناخته',
            8 => 'با تشخیص هویت دارنده کارت، تراکنش موفق می باشد',
            9 => 'درخواست رسیده در حال پی گیری و انجام است',
            10 => 'تراکنش با مبلغی پایین تر از مبلغ درخواستی (کمبود حساب مشتری) پذیرفته شده است',
            12 => 'تراکنش نامعتبر است',
            13 => 'مبلغ تراکنش نادرست است',
            14 => 'شماره کارت ارسالی نامعتبر است (وجود ندارد)',
            15 => 'صادرکننده کارت نامعتبر است (وجود ندارد)',
            17 => 'مشتری درخواست کننده حذف شده است',
            20 => 'در موقعیتی که سوئیچ جهت پذیرش تراکنش نیازمند پرس و جو از کارت است ممکن است درخواست از کارت (ترمینال) بنماید این پیام مبین نامعتبر بودن جواب است',
            21 => 'در صورتی که پاسخ به درخواست ترمینال نیازمند هیچ پاسخ خاص یا عملکردی نباشیم این پیام را خواهیم داشت',
            22 => 'تراکنش مشکوک به بد عمل کردن (کارت، ترمینال، دارنده کارت) بوده است لذا پذیرفته نشده است',
            30 => 'قالب پیام دارای اشکال است',
            31 => 'پذیرنده توسط سوئیچ پشتیبانی نمی شود',
            32 => 'تراکنش به صورت غیر قطعی کامل شده است (به عنوان مثال تراکنش سپرده گذاری که از دید مشتری کامل شده است ولی می بایست تکمیل گردد)',
            33 => 'تاریخ انقضای کارت سپری شده است',
            38 => 'تعداد دفعات ورود رمز غلط بیش از حد مجاز است. کارت توسط دستگاه ضبط شود',
            39 => 'کارت حساب اعتباری ندارد',
            40 => 'عملیات درخواستی پشتیبانی نمی گردد',
            41 => 'کارت مفقودی می باشد',
            43 => 'کارت مسروقه می باشد',
            45 => 'قبض قابل پرداخت نمی باشد',
            51 => 'موجودی کافی نمی باشد',
            54 => 'تاریخ انقضای کارت سپری شده است',
            55 => 'رمز کارت نامعتبر است',
            56 => 'کارت نامعتبر است',
            57 => 'انجام تراکنش مربوطه توسط دارنده کارت مجاز نمی باشد',
            58 => 'انجام تراکنش مربوطه توسط پایانه انجام دهنده مجاز نمی باشد',
            59 => 'کارت مظنون به تقلب است',
            61 => 'مبلغ تراکنش بیش از حد مجاز می باشد',
            62 => 'کارت محدود شده است',
            63 => 'تمهیدات امنیتی نقض گردیده است',
            65 => 'تعداد درخواست تراکنش بیش از حد مجاز می باشد',
            68 => 'پاسخ لازم برای تکمیل یا انجام تراکنش خیلی دیر رسیده است',
            69 => 'تعداد دفعات تکرار رمز از حد مجاز گذشته است',
            75 => 'تعداد دفعات ورود رمز غلط بیش از حد مجاز است',
            78 => 'کارت فعال نیست',
            79 => 'حساب متصل به کارت نامعتبر است یا دارای اشکال است',
            80 => 'درخواست تراکنش رد شده است',
            81 => 'کارت پذیرفته نشد',
            83 => 'سرویس دهنده سوئیچ کارت تراکنش را نپذیرفته است',
            84 => 'در تراکنش هایی که انجام آن مستلزم ارتباط با صادر کننده است در صورت فعال نبودن صادر کننده این پیام در پاسخ ارسال خواهد شد',
            90 => 'سامانه مقصد تراکنش در حال انجام عملیات پایان روز می باشد',
            91 => 'سیستم صدور مجوز انجام تراکنش موقتا غیر فعال است و یا زمان تعیین شده برای صدور مجوز به پایان رسیده است',
            92 => 'مقصد تراکنش پیدا نشد',
            93 => 'امکان تکمیل تراکنش وجود ندارد',
            94 => 'ارسال تکراری تراکنش بوجود آمده است',
            95 => 'در عملیات مغایرت گیری ترمینال اشکال رخ داده است',
            96 => 'اشکال در عملکرد سیستم',
            97 => 'تراکنش از سوی صادرکننده کارت مردود شده است',
            99 => 'خطای صادرکننده',
            200 => 'سایر خطاهای سامانه های بانکی',
        ];

        $statusCode = (int) $status;

        if (array_key_exists($statusCode, $translations)) {
            return $translations[$statusCode];
        }

        if (!empty($fallbackMessage)) {
            return $fallbackMessage;
        }

        return 'خطای ناشناخته رخ داده است';
    }
}
