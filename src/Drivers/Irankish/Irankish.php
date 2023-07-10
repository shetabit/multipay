<?php

namespace Shetabit\Multipay\Drivers\Irankish;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Irankish extends Driver
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
     * Irankish constructor.
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

    private function generateAuthenticationEnvelope($pubKey, $terminalID, $password, $amount)
    {
        $data = $terminalID . $password . str_pad($amount, 12, '0', STR_PAD_LEFT) . '00';
        $data = hex2bin($data);
        $AESSecretKey = openssl_random_pseudo_bytes(16);
        $ivlen = openssl_cipher_iv_length($cipher = "AES-128-CBC");
        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt($data, $cipher, $AESSecretKey, $options = OPENSSL_RAW_DATA, $iv);
        $hmac = hash('sha256', $ciphertext_raw, true);
        $crypttext = '';

        openssl_public_encrypt($AESSecretKey . $hmac, $crypttext, $pubKey);

        return array(
            "data" => bin2hex($crypttext),
            "iv" => bin2hex($iv),
        );
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
        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }

        $pubKey = $this->settings->pubKey;
        $terminalID = $this->settings->terminalId;
        $password = $this->settings->password;
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        $token = $this->generateAuthenticationEnvelope($pubKey, $terminalID, $password, $amount);

        $data = [];
        $data['request'] = [
            'acceptorId' => $this->settings->acceptorId,
            'amount' => $amount,
            'billInfo' => null,
            "paymentId" => null,
            "requestId" => uniqid(),
            "requestTimestamp" => time(),
            "revertUri" => $this->settings->callbackUrl,
            "terminalId" => $this->settings->terminalId,
            "transactionType" => "Purchase",
        ];
        $data['authenticationEnvelope'] = $token;
        $dataString = json_encode($data);

        $ch = curl_init($this->settings->apiPurchaseUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($dataString)
        ));
        curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=1');

        $result = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($result, JSON_OBJECT_AS_ARRAY);

        if (!$response || $response["responseCode"] != "00") {
            // error has happened
            $message = $response["description"] ?? 'خطا در هنگام درخواست برای پرداخت رخ داده است.';
            throw new PurchaseFailedException($message);
        }

        $this->invoice->transactionId($response['result']['token']);

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
        $payUrl = $this->settings->apiPaymentUrl;

        return $this->redirectWithForm(
            $payUrl,
            [
                'tokenIdentity' => $this->invoice->getTransactionId()
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
     */
    public function verify() : ReceiptInterface
    {
        $status = Request::input('responseCode');
        if (Request::input('responseCode') != "00") {
            return $this->notVerified($status);
        }

        $data = [
            'terminalId' => $this->settings->terminalId,
            'retrievalReferenceNumber' => Request::input('retrievalReferenceNumber'),
            'systemTraceAuditNumber' => Request::input('systemTraceAuditNumber'),
            'tokenIdentity' => Request::input('token'),
        ];

        $dataString = json_encode($data);

        $ch = curl_init($this->settings->apiVerificationUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($dataString)
        ));
        curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=1');

        $result = curl_exec($ch);
        if ($result === false || !$data['retrievalReferenceNumber']) {
            $this->notVerified($status);
        }
        curl_close($ch);

        $response = json_decode($result, JSON_OBJECT_AS_ARRAY);

        return $this->createReceipt($data['retrievalReferenceNumber']);
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
        return new Receipt('irankish', $referenceId);
    }

    /**
     * Trigger an exception
     *
     * @param $status
     * @throws InvalidPaymentException
     */
    private function notVerified($status)
    {
        $translations = [
            5 => 'از انجام تراکنش صرف نظر شد',
            17 => 'از انجام تراکنش صرف نظر شد',
            3 => 'پذیرنده فروشگاهی نامعتبر است',
            64 => 'مبلغ تراکنش نادرست است، جمع مبالغ تقسیم وجوه برابر مبلغ کل تراکنش نمی باشد',
            94 => 'تراکنش تکراری است',
            25 => 'تراکنش اصلی یافت نشد',
            77 => 'روز مالی تراکنش نا معتبر است',
            63 => 'کد اعتبار سنجی پیام نا معتبر است',
            97 => 'کد تولید کد اعتبار سنجی نا معتبر است',
            30 => 'فرمت پیام نادرست است',
            86 => 'شتاب در حال  Off Sign است',
            55 => 'رمز کارت نادرست است',
            40 => 'عمل درخواستی پشتیبانی نمی شود',
            57 => 'انجام تراکنش مورد درخواست توسط پایانه انجام دهنده مجاز نمی باشد',
            58 => 'انجام تراکنش مورد درخواست توسط پایانه انجام دهنده مجاز نمی باشد',
//            63 => 'تمهیدات امنیتی نقض گردیده است',
            96 => 'قوانین سامانه نقض گردیده است ، خطای داخلی سامانه',
            2 => 'تراکنش قبال برگشت شده است',
            54 => 'تاریخ انقضا کارت سررسید شده است',
            62 => 'کارت محدود شده است',
            75 => 'تعداد دفعات ورود رمز اشتباه از حد مجاز فراتر رفته است',
            14 => 'اطالعات کارت صحیح نمی باشد',
            51 => 'موجودی حساب کافی نمی باشد',
            56 => 'اطالعات کارت یافت نشد',
            61 => 'مبلغ تراکنش بیش از حد مجاز است',
            65 => 'تعداد دفعات انجام تراکنش بیش از حد مجاز است',
            78 => 'کارت فعال نیست',
            79 => 'حساب متصل به کارت بسته یا دارای اشکال است',
            42 => 'کارت یا حساب مبدا در وضعیت پذیرش نمی باشد',
//            42 => 'کارت یا حساب مقصد در وضعیت پذیرش نمی باشد',
            31 => 'عدم تطابق کد ملی خریدار با دارنده کارت',
            98 => 'سقف استفاده از رمز دوم ایستا به پایان رسیده است',
            901 => 'درخواست نا معتبر است )Tokenization(',
            902 => 'پارامترهای اضافی درخواست نامعتبر می باشد )Tokenization(',
            903 => 'شناسه پرداخت نامعتبر می باشد )Tokenization(',
            904 => 'اطالعات مرتبط با قبض نا معتبر می باشد )Tokenization(',
            905 => 'شناسه درخواست نامعتبر می باشد )Tokenization(',
            906 => 'درخواست تاریخ گذشته است )Tokenization(',
            907 => 'آدرس بازگشت نتیجه پرداخت نامعتبر می باشد )Tokenization(',
            909 => 'پذیرنده نامعتبر می باشد)Tokenization(',
            910 => 'پارامترهای مورد انتظار پرداخت تسهیمی تامین نگردیده است)Tokenization(',
            911 => 'پارامترهای مورد انتظار پرداخت تسهیمی نا معتبر یا دارای اشکال می باشد)Tokenization(',
            912 => 'تراکنش درخواستی برای پذیرنده فعال نیست )Tokenization(',
            913 => 'تراکنش تسهیم برای پذیرنده فعال نیست )Tokenization(',
            914 => 'آدرس آی پی دریافتی درخواست نا معتبر می باشد',
            915 => 'شماره پایانه نامعتبر می باشد )Tokenization(',
            916 => 'شماره پذیرنده نا معتبر می باشد )Tokenization(',
            917 => 'نوع تراکنش اعالم شده در خواست نا معتبر می باشد )Tokenization(',
            918 => 'پذیرنده فعال نیست)Tokenization(',
            919 => 'مبالغ تسهیمی ارائه شده با توجه به قوانین حاکم بر وضعیت تسهیم پذیرنده ، نا معتبر است )Tokenization(',
            920 => 'شناسه نشانه نامعتبر می باشد',
            921 => 'شناسه نشانه نامعتبر و یا منقضی شده است',
            922 => 'نقض امنیت درخواست )Tokenization(',
            923 => 'ارسال شناسه پرداخت در تراکنش قبض مجاز نیست)Tokenization(',
            928 => 'مبلغ مبادله شده نا معتبر می باشد)Tokenization(',
            929 => 'شناسه پرداخت ارائه شده با توجه به الگوریتم متناظر نا معتبر می باشد)Tokenization(',
            930 => 'کد ملی ارائه شده نا معتبر می باشد)Tokenization('
        ];
        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status], (int)$status);
        } else {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.', (int)$status);
        }
    }
}
