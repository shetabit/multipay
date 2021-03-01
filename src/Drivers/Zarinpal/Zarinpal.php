<?php

namespace Shetabit\Multipay\Drivers\Zarinpal;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Zarinpal extends Driver
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
     * Zarinpal constructor.
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
        $mobile = $email = null;
        if (!empty($this->invoice->getDetails()['mobile'])) {
            $mobile = $this->invoice->getDetails()['mobile'];
        }

        if (!empty($this->invoice->getDetails()['email'])) {
            $email = $this->invoice->getDetails()['email'];
        }
        $data = array(
            'merchant_id' => $this->settings->merchantId,
            'amount' => $this->invoice->getAmount()*10,
            'callback_url' => $this->settings->callbackUrl,
            'description' => $description,
        );
        if($mobile)
        {
            $data["metadata"]["mobile"] = $mobile;
        }

        if($email)
        {
            $data["metadata"]["email"] = $email;
        }

        $data = json_encode($data,JSON_UNESCAPED_UNICODE);
        $ch = curl_init($this->getPurchaseUrl());
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        ));

        $result = curl_exec($ch);
        $result = json_decode($result);
        if (!isset($result->data->code) || $result->data->code != 100 || empty($result->data->authority)) {
            // some error has happened
            $message = $this->translateStatus( $result->errors->code);
            throw new PurchaseFailedException($message,  $result->errors->code);
        }
        $this->invoice->transactionId($result->data->authority);

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
        $transactionId = $this->invoice->getTransactionId();
        $paymentUrl = $this->getPaymentUrl();

        $payUrl = $paymentUrl.$transactionId;


        return $this->redirectWithForm($payUrl, [], 'GET');
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
        $authority = $this->invoice->getTransactionId() ?? Request::input('Authority');
        $status = Request::input('Status');

        $data = [
            'merchant_id' => $this->settings->merchantId,
            'authority' => $authority,
            'amount' => $this->invoice->getAmount()*10,
        ];

        if ($status != 'OK') {
            throw new InvalidPaymentException('عملیات پرداخت توسط کاربر لغو شد.', -22);
        }

        $data = json_encode($data,JSON_UNESCAPED_UNICODE);
        $ch = curl_init($this->getVerificationUrl());
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        ));
        $result = curl_exec($ch);
        $result = json_decode($result);

        if (!isset($result->data->code) || $result->data->code != 100) {
            $message = $this->translateStatus($result->errors->code);
            throw new InvalidPaymentException($message, $result->errors->code);
        }
        return $this->createReceipt($result->data->ref_id,$result->data->card_pan,$result->data->card_hash);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     * @param $card
     * @param $hash
     * @return Receipt
     */
    public function createReceipt($referenceId , $card , $hash)
    {
        return new Receipt('zarinpal', $referenceId,$card,$hash);
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
        $translations = array(
            "-1" => "اطلاعات ارسال شده ناقص است.",
            "-2" => "IP و يا مرچنت كد پذيرنده صحيح نيست",
            "-3" => "با توجه به محدوديت هاي شاپرك امكان پرداخت با رقم درخواست شده ميسر نمي باشد",
            "-4" => "سطح تاييد پذيرنده پايين تر از سطح نقره اي است.",
            "-9" => "خطای اعتبارسنجی.",
            "-10" => "ی پی و يا مرچنت كد پذيرنده صحيح نيست.",
            "-11" => "مرچنت کد فعال نیست لطفا با تیم پشتیبانی ما تماس بگیرید.",
            "-12" => "تلاش بیش از حد در یک بازه زمانی کوتاه.",
            "-15" => "تلاش بیش از حد در یک بازه زمانی کوتاه.",
            "-16" => "ترمینال شما به حالت تعلیق در آمده با تیم پشتیبانی تماس بگیرید",
            "-21" => "هيچ نوع عمليات مالي براي اين تراكنش يافت نشد",
            "-22" => "تراكنش نا موفق ميباشد",
            "-30" => "اجازه دسترسی به تسویه اشتراکی شناور ندارید",
            "-31" => "حساب بانکی تسویه را به پنل اضافه کنید مقادیر وارد شده واسه تسهیم درست نیست",
            "-33" => "درصد های وارد شده درست نیست",
            "-34" => "مبلغ از کل تراکنش بیشتر است",
            "-35" => "تعداد افراد دریافت کننده تسهیم بیش از حد مجاز است",
            "-40" => "اجازه دسترسي به متد مربوطه وجود ندارد.",
            "-41" => "اطلاعات ارسال شده مربوط به AdditionalData غيرمعتبر ميباشد.",
            "-42" => "مدت زمان معتبر طول عمر شناسه پرداخت بايد بين 30 دقيه تا 45 روز مي باشد.",
            "-50" => "مبلغ پرداخت شده با مقدار مبلغ در وریفای متفاوت است",
            "-51" => "پرداخت ناموفق",
            "-52" => "خطای غیر منتظره با پشتیبانی تماس بگیرید",
            "-53" => "اتوریتی برای این مرچنت کد نیست",
            "-54" => "اتوریتی نامعتبر است",
            "101" => "تراکنش وریفای شده",
        );

        $unknownError = 'خطای ناشناخته رخ داده است.';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }

    /**
     * Retrieve purchase url
     *
     * @return string
     */
    protected function getPurchaseUrl() : string
    {
        $mode = $this->getMode();

        switch ($mode) {
            case 'sandbox':
                $url = $this->settings->sandboxApiPurchaseUrl;
                break;
            default: // default: normal
                $url = $this->settings->apiPurchaseUrl;
                break;
        }

        return $url;
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
            case 'sandbox':
                $url = $this->settings->sandboxApiPaymentUrl;
                break;
            default: // default: normal
                $url = $this->settings->apiPaymentUrl;
                break;
        }

        return $url;
    }

    /**
     * Retrieve verification url
     *
     * @return string
     */
    protected function getVerificationUrl() : string
    {
        $mode = $this->getMode();

        switch ($mode) {
            case 'sandbox':
                $url = $this->settings->sandboxApiVerificationUrl;
                break;
            default: // default: normal
                $url = $this->settings->apiVerificationUrl;
                break;
        }

        return $url;
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
}
