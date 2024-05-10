<?php

namespace Shetabit\Multipay\Drivers\Sadad;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use DateTimeZone;
use DateTime;

class Sadad extends Driver
{
    /**
     * Sadad Client.
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
     * Sadad constructor.
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
     * @throws PurchaseFailedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $terminalId = $this->settings->terminalId;
        $orderId = crc32($this->invoice->getUuid());
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        $key = $this->settings->key;

        $signData = $this->encrypt_pkcs7("$terminalId;$orderId;$amount", $key);
        $iranTime = new DateTime('now', new DateTimeZone('Asia/Tehran'));

        //set Description for payment
        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }

        //set MobileNo for get user cards
        if (!empty($this->invoice->getDetails()['mobile'])) {
            $mobile = $this->invoice->getDetails()['mobile'];
        } else {
            $mobile = "";
        }

        $data = [
            'MerchantId' => $this->settings->merchantId,
            'ReturnUrl' => $this->settings->callbackUrl,
            'LocalDateTime' => $iranTime->format("m/d/Y g:i:s a"),
            'SignData' => $signData,
            'TerminalId' => $terminalId,
            'Amount' => $amount,
            'OrderId' => $orderId,
            'additionalData' => $description,
            'UserId' => $mobile,
        ];

        $mode = $this->getMode();

        if ($mode == 'paymentbyidentity') {
            //set PaymentIdentity for payment
            if (!empty($this->invoice->getDetails()['payment_identity'])) {
                $data['PaymentIdentity'] = $this->invoice->getDetails()['payment_identity'];
            } else {
                $data['PaymentIdentity'] = $this->settings->PaymentIdentity;
            }
        } elseif ($mode == 'paymentbymultiidentity') {
            //set MultiIdentityData for payment
            if (!empty($this->invoice->getDetails()['multi_identity_rows'])) {
                $multiIdentityRows = $this->invoice->getDetails()['multi_identity_rows'];
            } else {
                $multiIdentityRows = $this->settings->MultiIdentityRows;
            }

            // convert to rial
            if ($this->settings->currency == 'T') {
                $multiIdentityRows = array_map(function ($item) {
                    $item['Amount'] = $item['Amount'] * 10;
                    return $item;
                }, $multiIdentityRows);
            }

            $data['MultiIdentityData'] = ['MultiIdentityRows' => $multiIdentityRows];
        }

        $response = $this
            ->client
            ->request(
                'POST',
                $this->getPaymentUrl(),
                [
                    "json" => $data,
                    "headers" => [
                        'Content-Type' => 'application/json',
                        'User-Agent' => '',
                    ],
                    "http_errors" => false,
                ]
            );

        $body = @json_decode($response->getBody()->getContents());

        if (empty($body)) {
            throw new PurchaseFailedException('دسترسی به صفحه مورد نظر امکان پذیر نمی باشد.');
        } elseif ($body->ResCode != 0) {
            throw new PurchaseFailedException($body->Description);
        }

        $this->invoice->transactionId($body->Token);

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
        $token = $this->invoice->getTransactionId();
        $payUrl = $this->getPurchaseUrl();

        return $this->redirectWithForm($payUrl, ['Token' => $token], 'GET');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $key = $this->settings->key;
        $token = $this->invoice->getTransactionId() ?? Request::input('token');
        $resCode = Request::input('ResCode');

        if ($resCode != 0) {
            throw new InvalidPaymentException($this->translateStatus($resCode), $resCode);
        }

        $data = array(
            'Token' => $token,
            'SignData' => $this->encrypt_pkcs7($token, $key)
        );

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiVerificationUrl,
                [
                    "json" => $data,
                    "headers" => [
                        'Content-Type' => 'application/json',
                        'User-Agent' => '',
                    ],
                    "http_errors" => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents());

        $bodyResponse = $body->ResCode;
        if ($bodyResponse != 0) {
            throw new InvalidPaymentException($this->translateStatus($bodyResponse), $bodyResponse);
        }

        /**
         * شماره سفارش : $orderId = Request::input('OrderId')
         * شماره پیگیری : $body->SystemTraceNo
         * شماره مرجع : $body->RetrievalRefNo
         */

        $receipt = $this->createReceipt($body->SystemTraceNo);
        $receipt->detail([
            'orderId' => $body->OrderId,
            'traceNo' => $body->SystemTraceNo,
            'referenceNo' => $body->RetrivalRefNo,
            'description' => $body->Description,
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
        $receipt = new Receipt('sadad', $referenceId);

        return $receipt;
    }

    /**
     * Create sign data(Tripledes(ECB,PKCS7))
     *
     * @param $str
     * @param $key
     *
     * @return string
     */
    protected function encrypt_pkcs7($str, $key)
    {
        $key = base64_decode($key);
        $ciphertext = OpenSSL_encrypt($str, "DES-EDE3", $key, OPENSSL_RAW_DATA);

        return base64_encode($ciphertext);
    }

    /**
     * Retrieve payment mode.
     *
     * @return string
     */
    protected function getMode() : string
    {
        return strtolower($this->settings->mode);
    }


    /**
     * Retrieve purchase url
     *
     * @return string
     */
    protected function getPurchaseUrl() : string
    {
        return $this->settings->apiPurchaseUrl;
    }

    /**
     * Retrieve Payment url
     *
     * @return string
     */
    protected function getPaymentUrl() : string
    {
        $mode = $this->getMode();

        switch ($mode) {
            case 'paymentbyidentity':
                $url = $this->settings->apiPaymentByIdentityUrl;
                break;
            case 'paymentbymultiidentity':
                $url = $this->settings->apiPaymentByMultiIdentityUrl;
                break;
            default: // default: normal
                $url = $this->settings->apiPaymentUrl;
                break;
        }

        return $url;
    }

    /**
     * Convert status to a readable message.
     *
     * @param $status
     *
     * @return mixed|string
     */
    private function translateStatus($status)
    {
        $translations = [
            '0' => 'تراکنش با موفقیت انجام شد',
            '3' => 'پذيرنده کارت فعال نیست لطفا با بخش امور پذيرندگان، تماس حاصل فرمائید',
            '23' => 'پذيرنده کارت نا معتبر لطفا با بخش امور پذيرندگان، تماس حاصل فرمائید',
            '58' => 'انجام تراکنش مربوطه توسط پايانه ی انجام دهنده مجاز نمی باشد',
            '61' => 'مبلغ تراکنش از حد مجاز بالاتر است',
            '101' => 'مهلت ارسال تراکنش به پايان رسیده است',
            '1000' => 'ترتیب پارامترهای ارسالی اشتباه می باشد',
            '1001' => 'پارامترهای پرداخت اشتباه می باشد',
            '1002' => 'خطا در سیستم- تراکنش ناموفق',
            '1003' => 'IP پذيرنده اشتباه است',
            '1004' => 'شماره پذيرنده اشتباه است',
            '1005' => 'خطای دسترسی:لطفا بعدا تلاش فرمايید',
            '1006' => 'خطا در سیستم',
            '1011' => 'درخواست تکراری- شماره سفارش تکراری می باشد',
            '1012' => 'اطلاعات پذيرنده صحیح نیست، يکی از موارد تاريخ،زمان يا کلید تراکنش اشتباه است',
            '1015' => 'پاسخ خطای نامشخص از سمت مرکز',
            '1017' => 'مبلغ درخواستی شما جهت پرداخت از حد مجاز تعريف شده برای اين پذيرنده بیشتر است',
            '1018' => 'اشکال در تاريخ و زمان سیستم. لطفا تاريخ و زمان سرور خود را با بانک هماهنگ نمايید',
            '1019' => 'امکان پرداخت از طريق سیستم شتاب برای اين پذيرنده امکان پذير نیست',
            '1020' => 'پذيرنده غیرفعال شده است',
            '1023' => 'آدرس بازگشت پذيرنده نامعتبر است',
            '1024' => 'مهر زمانی پذيرنده نامعتبر است',
            '1025' => 'امضا تراکنش نامعتبر است',
            '1026' => 'شماره سفارش تراکنش نامعتبر است',
            '1027' => 'شماره پذيرنده نامعتبر است',
            '1028' => 'شماره ترمینال پذيرنده نامعتبر است',
            '1029' => 'آدرس IP پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست',
            '1030' => 'آدرس Domain پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذیرنده نیست',
            '1031' => 'مهلت زمانی شما جهت پرداخت به پايان رسیده است.لطفا مجددا سعی بفرمایید',
            '1032' => 'پرداخت با اين کارت , برای پذيرنده مورد نظر شما امکان پذير نیست',
            '1033' => 'به علت مشکل در سايت پذيرنده, پرداخت برای اين پذيرنده غیرفعال شده است',
            '1036' => 'اطلاعات اضافی ارسال نشده يا دارای اشکال است',
            '1037' => 'شماره پذيرنده يا شماره ترمینال پذيرنده صحیح نمیباشد',
            '1053' => 'خطا: درخواست معتبر، از سمت پذيرنده صورت نگرفته است لطفا اطلاعات پذيرنده خود را چک کنید',
            '1055' => 'مقدار غیرمجاز در ورود اطلاعات',
            '1056' => 'سیستم موقتا قطع میباشد.لطفا بعدا تلاش فرمايید',
            '1058' => 'سرويس پرداخت اينترنتی خارج از سرويس می باشد.لطفا بعدا سعی بفرمایید',
            '1061' => 'اشکال در تولید کد يکتا. لطفا مرورگر خود را بسته و با اجرای مجدد عملیات پرداخت را انجام دهید',
            '1064' => 'لطفا مجددا سعی بفرمايید',
            '1065' => 'ارتباط ناموفق .لطفا چند لحظه ديگر مجددا سعی کنید',
            '1066' => 'سیستم سرويس دهی پرداخت موقتا غیر فعال شده است',
            '1068' => 'با عرض پوزش به علت بروزرسانی , سیستم موقتا قطع میباشد',
            '1072' => 'خطا در پردازش پارامترهای اختیاری پذيرنده',
            '1101' => 'مبلغ تراکنش نامعتبر است',
            '1103' => 'توکن ارسالی نامعتبر است',
            '1104' => 'اطلاعات تسهیم صحیح نیست',
            '1105' => 'تراکنش بازگشت داده شده است(مهلت زمانی به پايان رسیده است)'
        ];

        $unknownError = 'خطای ناشناخته رخ داده است. در صورت کسر مبلغ از حساب حداکثر پس از 72 ساعت به حسابتان برمیگردد';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }
}
