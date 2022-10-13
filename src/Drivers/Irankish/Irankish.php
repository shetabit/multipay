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

        $amountToPay = $this->invoice->getAmount() * 10; // convert to rial
        $token = $this->generateAuthenticationEnvelope($this->settings->publicKey, $this->settings->terminalId, $this->settings->password, $amountToPay);
        
        $data = [];
        $data["request"] = [
            'amount' => $amountToPay,
            'acceptorId' => $this->settings->acceptorId,
            "billInfo" => null,
            "paymentId" => null,
            "requestId" => (string) crc32($this->invoice->getUuid()),
            "requestTimestamp" => time(),
            "revertUri" => $this->settings->callbackUrl,
            "terminalId" => $this->settings->terminalId,
            "transactionType" => "Purchase"
        ];
        $data['authenticationEnvelope'] = $token;

        $data_string = json_encode($data);
        $ch = curl_init($this->settings->apiPurchaseUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=1');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
        ));
        $result = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($result, JSON_OBJECT_AS_ARRAY);

        $responseCode = isset($response["responseCode"]) ? $response["responseCode"] : null;
        if ($responseCode == "00") {
            $this->invoice->transactionId($response['result']['token']);
        } else {
            // error has happened
            $errors = isset($response['errors']) ? json_encode($response['errors']) : null;
            $message = $errors ?? 'خطا در هنگام درخواست برای پرداخت رخ داده است.';
            throw new PurchaseFailedException($message);
        }

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
     * @throws \SoapFault
     */
    public function verify() : ReceiptInterface
    {
        $data = array(
            "terminalId" => $this->settings->terminalId,
            "retrievalReferenceNumber" => $_POST['retrievalReferenceNumber'],
            "systemTraceAuditNumber" => $_POST['systemTraceAuditNumber'],
            "tokenIdentity" => $_POST['token'],
        );
        $data_string = json_encode($data);

        $ch = curl_init($this->settings->apiVerificationUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=1');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
        ));

        $result = curl_exec($ch);
        curl_close($ch);
    
        $response = json_decode($result, JSON_OBJECT_AS_ARRAY);

        if (($response['responseCode'] != "00") || ($response['stauts'] == false)) {
            $this->notVerified($response['responseCode']);
        }

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
        $receipt = new Receipt('irankish', $referenceId);

        return $receipt;
    }

    /**
     * Trigger an exception
     *
     * @param $status
     * @throws InvalidPaymentException
     */
    private function notVerified($status)
    {
        $translations = array(
            110 => 'دارنده کارت انصراف داده است',
            120 => 'موجودی حساب کافی نمی باشد',
            121 => 'مبلغ تراکنشهای کارت بیش از حد مجاز است',
            130 => 'اطلاعات کارت نادرست می باشد',
            131 => 'رمز کارت اشتباه است',
            132 => 'کارت مسدود است',
            133 => 'کارت منقضی شده است',
            140 => 'زمان مورد نظر به پایان رسیده است',
            150 => 'خطای داخلی بانک به وجود آمده است',
            160 => 'خطای انقضای کارت به وجود امده یا اطلاعات CVV2 اشتباه است',
            166 => 'بانک صادر کننده کارت شما مجوز انجام تراکنش را صادر نکرده است',
            167 => 'خطا در مبلغ تراکنش',
            200 => 'مبلغ تراکنش بیش از حدنصاب مجاز',
            201 => 'مبلغ تراکنش بیش از حدنصاب مجاز برای روز کاری',
            202 => 'مبلغ تراکنش بیش از حدنصاب مجاز برای ماه کاری',
            203 => 'تعداد تراکنشهای مجاز از حد نصاب گذشته است',
            499 => 'خطای سیستمی ، لطفا مجددا تالش فرمایید',
            500 => 'خطا در تایید تراکنش های خرد شده',
            501 => 'خطا در تایید تراکتش ، ویزگی تایید خودکار',
            502 => 'آدرس آی پی نا معتبر',
            503 => 'پذیرنده در حالت تستی می باشد ، مبلغ نمی تواند بیش از حد مجاز تایین شده برای پذیرنده تستی باشد',
            504 => 'خطا در بررسی الگوریتم شناسه پرداخت',
            505 => 'مدت زمان الزم برای انجام تراکنش تاییدیه به پایان رسیده است',
            506 => 'ذیرنده یافت نشد',
            507 => 'توکن نامعتبر/طول عمر توکن منقضی شده است',
            508 => 'توکن مورد نظر یافت نشد و یا منقضی شده است',
            509 => 'خطا در پارامترهای اجباری خرید تسهیم شده',
            510 => 'خطا در تعداد تسهیم | مبالغ کل تسهیم مغایر با مبلغ کل ارائه شده | خطای شماره ردیف تکراری',
            511 => 'حساب مسدود است',
            512 => 'حساب تعریف نشده است',
            513 => 'شماره تراکنش تکراری است',
            514 => 'پارامتر های ضروری برای طرح آسان خرید تامین نشده است',
            515 => 'کارت مبدا تراکنش مجوز انجام عملیات در طرح آسان خرید را ندارد',
            -20 => 'در درخواست کارکتر های غیر مجاز وجو دارد',
            -30 => 'تراکنش قبلا برگشت خورده است',
            -50 => 'طول رشته درخواست غیر مجاز است',
            -51 => 'در در خواست خطا وجود دارد',
            -80 => 'تراکنش مورد نظر یافت نشد',
            -81 => ' خطای داخلی بانک',
            -90 => 'تراکنش قبلا تایید شده است',
            -91 => 'تراکنش قبال تایید شده | مدت زمان انتضار برای تایید به پایان رسیده است'
        );
        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status]);
        } else {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.');
        }
    }

    /**
     * generats authentication envelope
     *
     * @param string $pub_key
     * @param string $terminalID
     * @param string $password
     * @param string|integer $amount
     * @return array
     */
    private function generateAuthenticationEnvelope($pub_key, $terminalID, $password, $amount)
    {
        $data = $terminalID . $password . str_pad($amount, 12, '0', STR_PAD_LEFT) . '00';
        $data = hex2bin($data);
        $AESSecretKey = openssl_random_pseudo_bytes(16);
        $ivlen = openssl_cipher_iv_length($cipher = "AES-128-CBC");
        $iv = openssl_random_pseudo_bytes($ivlen);
        $ciphertext_raw = openssl_encrypt($data, $cipher, $AESSecretKey, $options = OPENSSL_RAW_DATA, $iv);
        $hmac = hash('sha256', $ciphertext_raw, true);
        $crypttext = '';

        openssl_public_encrypt($AESSecretKey . $hmac, $crypttext, $pub_key);

        return array(
            "data" => bin2hex($crypttext),
            "iv" => bin2hex($iv),
        );
    }
}
