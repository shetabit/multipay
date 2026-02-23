<?php

namespace Shetabit\Multipay\Drivers\Pasargad;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use DateTimeZone;
use DateTime;

class Pasargad extends Driver
{
    /**
     * Guzzle client
     *
     * @var Client
     */
    protected Client $client;

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
     * Prepared invoice's data
     *
     * @var array
     */
    protected array $preparedData = array();

    /**
     * Pasargad(PEP) constructor.
     * Construct the class with the relevant settings.
     *
     * @param  Invoice  $invoice
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
     * @throws \DateMalformedStringException
     */
    public function purchase(): string
    {
        $invoiceData = $this->getPreparedInvoiceData();

        $this->invoice->transactionId($invoiceData['invoice']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     * @throws \DateMalformedStringException
     * @throws InvalidPaymentException|GuzzleException
     */
    public function pay(): RedirectionForm
    {
        $baseUrl = $this->settings->baseUrl;

        $paymentResult = $this->request($baseUrl.'/api/payment/purchase', $this->getPreparedInvoiceData());

        $statusCode = $paymentResult['resultCode'];

        if ($statusCode !== 0 || empty($paymentResult['data']) || empty($paymentResult['data']['url'])) {
            $errorMessage = $this->translateStatus($statusCode, $paymentResult['resultMsg'] ?? null);

            throw new InvalidPaymentException($errorMessage, $statusCode);
        }

        $paymentUrl = $paymentResult['data']['url'];

        // redirect using the HTML form
        return $this->redirectWithForm($paymentUrl);
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws GuzzleException
     */
    public function verify(): ReceiptInterface
    {
        $baseUrl = $this->settings->baseUrl;

        $invoiceInquiry = $this->request(
            $baseUrl.'/api/payment/payment-inquiry',
            [
                'invoiceId' => Request::input('invoiceId')
            ]
        );

        $statusCode = $invoiceInquiry['resultCode'];

        if ($statusCode !== 0 || empty($invoiceInquiry['data'])) {
            $errorMessage = $this->translateStatus($statusCode, $invoiceInquiry['resultMsg'] ?? null);

            throw new InvalidPaymentException($errorMessage, $statusCode);
        }

        $invoiceDetails = $invoiceInquiry['data'];
        $invoiceInquiryStatus = $invoiceDetails['status'];

        if ($invoiceInquiryStatus !== 0) {
            throw new InvalidPaymentException($invoiceInquiryStatus);
        }

        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        if ($amount != $invoiceDetails['amount']) {
            throw new InvalidPaymentException('Invalid amount');
        }

        $paymentUrlId = $invoiceDetails['url'];

        $verifyResult = $this->request(
            $baseUrl.'/api/payment/confirm-transactions',
            [
                'invoice' => Request::input('invoiceId'),
                'urlId' => $paymentUrlId
            ]
        );

        return $this->createReceipt($verifyResult, $invoiceDetails);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $verifyResult
     * @param $invoiceDetails
     * @return Receipt
     */
    protected function createReceipt($verifyResult, $invoiceDetails): Receipt
    {
        $verifyResultData = $verifyResult['data'];
        $referenceId = $invoiceDetails['transactionId'];
        $trackId = $invoiceDetails['trackId'];
        $referenceNumber = $invoiceDetails['referenceNumber'];

        $receipt = new Receipt('Pasargad', $referenceId);

        $receipt->detail('TraceNumber', $trackId);
        $receipt->detail('ReferenceNumber', $referenceNumber);
        $receipt->detail('urlId', $invoiceDetails['url']);
        $receipt->detail('MaskedCardNumber', $verifyResultData['maskedCardNumber']);

        return $receipt;
    }

    /**
     * Return response status message
     *
     * @param $status
     * @param  string|null  $fallbackMessage
     * @return string
     */
    protected function translateStatus($status, ?string $fallbackMessage = null): string
    {
        $translations = [
            0 => 'تراکنش موفق است',
            1 => 'ناموفق',
            2 => 'نامشخص',
            401 => 'مجاز به استفاده از سرویس نیستید',
            500 => 'خطا داخلی سرور',
            13000 => 'ورودی نامعتبر است',
            13001 => 'آدرس نامعتبر است',
            13002 => 'توکن نامعتبر است',
            13003 => 'کد رهگیری یکتا نیست',
            13004 => 'توکن خالی است',
            13005 => 'عدم امکان دسترسی موقت به سرور',
            13007 => 'کپچا منقضی شده است',
            13008 => 'کپچا اشتباه است',
            13009 => 'توکن یافت نشد',
            13010 => 'شماره موبایل نامعتبر است',
            13011 => 'کد محصول نامعتبر است',
            13012 => 'شناسه قبض نامعتبر است',
            13013 => 'شناسه پرداخت نامعتبر است',
            13015 => 'برای فراخوانی مجدد 2 دقیقه صبر کنید',
            13016 => 'تراکنش یافت نشد',
            13017 => 'طول ورودی نامعتبر است',
            13018 => 'یافت نشد',
            13019 => 'کد رهگیری برای کاربر لاگین شده در سیستم وجود ندارد',
            13020 => 'خطا هنگام چک کردن کد شرکت',
            13021 => 'پرداخت از پیش لغو شده است',
            13022 => 'تراکنش پرداخت نشده است',
            13025 => 'تراکنش ناموفق است',
            13026 => 'شماره کارت نامعتبر است',
            13027 => 'شماره موبایل برای شماره کارت وارد شده نیست',
            13028 => 'آدرس کالبک نامعتبر است',
            13029 => 'تراکنش موفق و تایید شده است',
            13030 => 'تراکنش تایید شده و ناموفق است',
            13031 => 'تراکنش منتظر تایید است',
            13032 => 'کارت منقضی شده است',
            13033 => 'تراکنش و بازگشت تراکنش با موفقیت انجام شده است',
            13035 => 'تراکنش مجاز به انجام عملیات بازگشت نمی‌باشد',
            13045 => 'دارنده کارت تراکنش را لغو کرد',
            13046 => 'تراکنش تسویه شده است',
            13047 => 'پذیرنده کارت نامعتبر است',
            13049 => 'تراکنش کامل نشده است',
            13054 => 'تراکنش نامعتبر است',
            13055 => 'مبلغ تراکنش نامعتبر است',
            13056 => 'صادرکننده کارت نامعتبر است',
            13057 => 'تاریخ کارت منقضی شده است',
            13058 => 'کارت موقتاً مسدود شده است',
            13059 => 'حساب تعریف نشده است',
            13060 => 'نوع تراکنش نامعتبر است',
            13061 => 'شناسه انتقال نامعتبر است',
            13062 => 'تراکنش تکراری',
            13063 => 'مبلغ کافی نیست',
            13064 => 'پین اشتباه است',
            13065 => 'کارت نامعتبر است',
            13066 => 'سرویس روی کارت مجاز نیست',
            13067 => 'کارت برای ترمینال مجاز نیست',
            13068 => 'مبلغ تراکنش بیش از حد مجاز است',
            13069 => 'کارت محدود است',
            13070 => 'خطای امنیتی',
            13071 => 'مبلغ تراکنش با قیمت نهایی مطابقت ندارد',
            13072 => 'درخواست تراکنش بیش از حد مجاز است',
            13073 => 'حساب غیرفعال است',
            13074 => 'کارت توسط ترمینال مسدود شد',
            13075 => 'زمان تأییدیه منقضی شده است',
            13076 => 'زمان اصلاحیه منقضی شده است',
            13077 => 'تراکنش اصلی نامعتبر است',
            13078 => 'مبلغ اشتباه است',
            13079 => 'روز کاری نامعتبر است',
            13080 => 'کارت غیر فعال است',
            13081 => 'حساب کارت نامعتبر است',
            13082 => 'تراکنش ناموفق است',
            13083 => 'داده‌های رمزگذاری شده نامعتبر است',
            13084 => 'سوئیچ یا شاپرک خاموش است',
            13085 => 'میزبان بانک مقصد پایین است',
            13086 => 'اطلاعات تاییدیه نامعتبر است',
            13087 => 'سوئیچ یا شاپرک در حال خاموش شدن است',
            13088 => 'کلید رمزگذاری شده نامعتبر است',
            13089 => 'انجام تراکنش با واسط غیرتماسی مردود شده است، تراکنش با واسط تماسی انجام گردد',
            13090 => 'پایان روز کاری',
            13091 => 'سوئیچ غیر فعال است',
            13092 => 'صادرکننده کارت نامعتبر است',
            13093 => 'تراکنش موفقیت‌آمیز نیست',
            13094 => 'تراکنش تکراری است',
            13095 => 'پین قدیمی اشتباه است',
            13096 => 'خطای داخلی سوئیچ',
            13097 => 'در حال انجام فرآیند تغییر کلید',
            13098 => 'پین استاتیک فراتر از محدودیت',
            13099 => 'پایانه فروش نامعتبر است',
            13300 => 'پایانه در سیستم غیرفعال است',
            13301 => 'فروشگاه در سیستم تعریف نشده است',
            13302 => 'فرمت کد ملی نامعتبر است',
            13303 => 'پسورد ترمینال نامعتبر است',
            13304 => 'شناسه پرداخت نامعتبر است',
            13305 => 'داده‌های تراکنش ناکافی است',
            13306 => 'کد شارژ نامعتبر است',
            13307 => 'مهلت زمانی برای کارت یا پین به پایان رسیده است',
            13308 => 'اطلاعات حساب ناشناس است',
            13309 => 'مهم نیست',
            13310 => 'خطای دیگر',
            13311 => 'تمام شارژها فروخته شده',
            13312 => 'شارژ موجود نیست',
            13313 => 'اپراتور در دسترس نیست',
            13314 => 'شماره موبایل خالی است',
            13315 => 'در رزرو فاکتور مشکلی وجود دارد',
            13316 => 'اصل تراکنش مالی موفق نمی‌باشد',
            13317 => 'کپچا اشتباه است',
            13318 => 'کد سازمان ناموجود است',
            13319 => 'سیستم با اختلال همراه است',
            13320 => 'کارت مشکوک به کلاهبرداری',
            13321 => 'اطلاعات تکمیلی پایانه موجود نیست',
            13322 => 'درخواست رمز پویا بیش از حد مجاز است',
            13323 => 'عدم انطباق کد ملی با شماره کارت',
            13324 => 'اصل تراکنش مالی موفق نمی‌باشد',
            13325 => 'اصل تراکنش یافت نشد',
            13326 => 'کارت مسدود است',
            13327 => 'کارت به دلایل ویژه مسدود شده است',
            13328 => 'موفق با احراز هویت دارنده کارت',
            13329 => 'سیستم مشغول است',
            13331 => 'کارمزد نامعتبر است',
            13332 => 'PSP توسط شاپرک پشتیبانی نمی‌شود',
            13333 => 'کارت مشکوک به کلاهبرداری است',
            13334 => 'ورود پین از حد مجاز گذشته است',
            13335 => 'کارت گمشده است',
            13336 => 'کارت حساب عمومی ندارد',
            13337 => 'کارت سرقت شده است',
            13338 => 'حساب تعریف نشده',
            13339 => 'پاسخ خیلی دیر دریافت شد',
            13340 => 'کارت یا حساب مبدا در وضعیت نامناسب می‌باشد',
            13341 => 'کارت یا حساب مقصد در وضعیت نامناسب می‌باشد',
            13342 => 'ورود پین اشتباه بیش از حد مجاز است',
            13343 => 'فروشگاه نامعتبر است',
            13344 => 'خطای داخلی ثبت کارت',
            13345 => 'شماره کارت تکراری است',
            13346 => 'فرمت شماره موبایل نامعتبر است',
            13347 => 'فرمت شماره شناسنامه نامعتبر است',
            13348 => 'کد ملی یا سازمان تکراری است',
            13350 => 'خطای اعتبارسنجی',
            13351 => 'قالب شماره فاکتور معتبر نیست',
            13352 => 'نام کاربری معتبر نیست',
            13353 => 'دسترسی اپراتور لغو شد',
            13354 => 'دسترسی سرویس لغو شده است',
            13355 => 'اعتبار اپراتور کافی نیست',
            13356 => 'در رزرو فاکتور مشکلی وجود دارد',
            13357 => 'اعتبار سرویس کافی نیست',
            13358 => 'وضعیت تراکنش باید نامشخص باشد',
            13359 => 'پروتکل یافت نشد',
            13360 => 'تراکنش اصلی باید مالی باشد',
            13361 => 'تراکنش تسویه ناموفق است',
            13364 => 'نوع وصول آنی نامعتبر می‌باشد',
            13365 => 'تراکنش پشتیبانی نمی‌شود',
            13366 => 'توکن فعال نشده است',
            13367 => 'فرمت ورود نامعتبر',
            13368 => 'خطای ورود',
            13369 => 'فرمت ورودی نامعتبر',
            13370 => 'خطای محدودیت در زمان',
            13371 => 'خطای دسترسی',
            13372 => 'خطای بازیابی آدرس',
            13373 => 'گروه ترمینال یافت نشد',
            13374 => 'خطای درخواست',
            13375 => 'شماره تلفن نامعتبر',
            13376 => 'خطای اعتبارسنجی',
            13377 => 'خطا در تراکنش',
            13378 => 'خطای پروتکل',
            13379 => 'پین بلاک نامعتبر است',
            13380 => 'CVV2 نامعتبر است',
            13381 => 'فرمت نادرست شماره تماس',
            13382 => 'خطا در نوع اصلاحیه',
            13383 => 'تراکنش تسویه شده است',
            13384 => 'رمز اشتباه است',
            13385 => 'رکورد تکراری',
            13386 => 'خطا در انتقال',
            13390 => 'خطا در تغییر سایز تصویر',
            13391 => 'خطا در پارامترهای ورودی',
            13392 => 'خطا در درخواست رمزنگاری سخت‌افزاری',
            13393 => 'درخواستی یافت نشد',
            13394 => 'خطا در فرمت ورودی',
            13395 => 'تراکنش برگشت از خرید ناموفق است',
            13396 => 'خطا در سریال پایانه فروش',
            13397 => 'ورود مجدد تراکنش',
            13398 => 'درخواست کپچا بیش از حد مجاز است',
            13399 => 'ترمینال یافت نشد',
            13400 => 'کلید عمومی یافت نشد',
            13401 => 'شارژ مورد نظر موجود نیست',
            13402 => 'ارجاع دهنده با اطلاعات ثبت شده تطابق ندارد',
            13403 => 'شبا معتبر نیست',
            13404 => 'خطا در تغییر نوع متغیر',
            13405 => 'خطا (مقدار خالی)',
            13406 => 'عملیات به خطا خورد',
            13407 => 'خطا در احراز هویت',
            13408 => 'کد سازمان یافت نشد',
            13409 => 'خطا در Offset',
            13410 => 'خطا در سایز صفحه',
            13411 => 'خطا درهم‌سازی',
            13412 => 'خطا در خواندن مقدار',
            13413 => 'خطا در عملیات',
            13414 => 'خطا در آپدیت',
            13415 => 'خطا در خرید شناسه‌دار',
            13416 => 'خطای چندشبایی',
            13417 => 'عملیات مورد نظر پیاده‌سازی نشده است',
            13418 => 'خطا در تاریخ درخواست',
            13419 => 'خطا در زمان ورود',
        ];

        $statusCode = (int) $status;

        if (!is_null($status) && array_key_exists($statusCode, $translations)) {
            return $translations[$statusCode];
        }

        if (!empty($fallbackMessage)) {
            return $fallbackMessage;
        }

        return 'خطای ناشناخته رخ داده است';
    }

    /**
     * Retrieve prepared invoice's data
     *
     * @return array
     * @throws \DateMalformedStringException
     */
    protected function getPreparedInvoiceData(): array
    {
        if (empty($this->preparedData)) {
            $this->preparedData = $this->prepareInvoiceData();
        }

        return $this->preparedData;
    }

    /**
     * Prepare invoice data
     *
     * @return array
     * @throws \DateMalformedStringException
     */
    protected function prepareInvoiceData(): array
    {
        $serviceCode = 8; // 8 : for PURCHASE request
        $terminalCode = $this->settings->terminalCode;
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        $redirectAddress = $this->settings->callbackUrl;
        $invoiceNumber = crc32($this->invoice->getUuid()).rand(0, time());

        $iranTime = new DateTime('now', new DateTimeZone('Asia/Tehran'));
        $invoiceDate = $iranTime->format("Y/m/d H:i:s");

        if (!empty($this->invoice->getDetails()['date'])) {
            $invoiceDate = $this->invoice->getDetails()['date'];
        }

        return [
            'invoice' => $invoiceNumber,
            'invoiceDate' => $invoiceDate,
            'amount' => $amount,
            'serviceCode' => $serviceCode,
            'serviceType' => 'PURCHASE',
            'terminalNumber' => $terminalCode,
            'callbackApi' => $redirectAddress,
        ];
    }

    /**
     * Get action token
     *
     * @throws InvalidPaymentException
     * @throws GuzzleException
     */
    protected function getToken()
    {
        $baseUrl = $this->settings->baseUrl;
        $userName = $this->settings->userName;
        $password = $this->settings->password;

        $response = $this->client->request(
            'POST',
            $baseUrl.'/token/getToken',
            [
                'body' => json_encode(['username' => $userName, 'password' => $password]),
                'headers' => [
                    'content-type' => 'application/json'
                ],
                'http_errors' => false,
            ]
        );

        $result = json_decode($response->getBody(), true);

        $statusCode = $result['resultCode'];

        if ($statusCode !== 0 || empty($result['token'])) {
            $errorMessage = $this->translateStatus($statusCode, $paymentResult['resultMsg'] ?? 'Invalid Authentication');

            throw new InvalidPaymentException($errorMessage, $statusCode);
        }

        return $result['token'];
    }

    /**
     * Make request to Pasargad's Api
     *
     * @param  string  $url
     * @param  array  $body
     * @param  string  $method
     * @return array
     * @throws InvalidPaymentException
     * @throws GuzzleException
     */
    protected function request(string $url, array $body, string $method = 'POST'): array
    {
        $body = json_encode($body);
        $token = $this->getToken();

        $response = $this->client->request(
            'POST',
            $url,
            [
                'body' => $body,
                'headers' => [
                    'content-type' => 'application/json',
                    'Authorization' => "Bearer {$token}"
                ],
                'http_errors' => false,
            ]
        );

        $result = json_decode($response->getBody(), true);

        $statusCode = $result['resultCode'];
        if ($statusCode !== 0) {
            $errorMessage = $this->translateStatus($statusCode, $result['resultMsg'] ?? null);
            throw new InvalidPaymentException($errorMessage, $statusCode);
        }

        return $result;
    }
}
