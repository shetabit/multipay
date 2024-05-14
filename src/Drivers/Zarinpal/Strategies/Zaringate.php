<?php

namespace Shetabit\Multipay\Drivers\Zarinpal\Strategies;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use SoapClient;

class Zaringate extends Driver
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

        $mobile = !empty($this->invoice->getDetails()['mobile']) ? $this->invoice->getDetails()['mobile'] : '';
        $email = !empty($this->invoice->getDetails()['email']) ? $this->invoice->getDetails()['email'] : '';
        $amount = $this->invoice->getAmount() / ($this->settings->currency == 'T' ? 1 : 10); // convert to toman

        $data = array(
            'MerchantID' => $this->settings->merchantId,
            'Amount' => $amount,
            'CallbackURL' => $this->settings->callbackUrl,
            'Description' => $description,
            'Mobile' => $mobile,
            'Email' => $email,
            'AdditionalData' => $this->invoice->getDetails()
        );

        $client = new SoapClient($this->getPurchaseUrl(), ['encoding' => 'UTF-8']);
        $result = $client->PaymentRequest($data);

        $bodyResponse = $result->Status;
        if ($bodyResponse != 100 || empty($result->Authority)) {
            throw new PurchaseFailedException($this->translateStatus($bodyResponse), $bodyResponse);
        }

        $this->invoice->transactionId($result->Authority);

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

        $payUrl = str_replace(':authority', $transactionId, $paymentUrl);

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
        $amount = $this->invoice->getAmount() / ($this->settings->currency == 'T' ? 1 : 10); // convert to toman
        $authority = $this->invoice->getTransactionId() ?? Request::input('Authority');

        $data = [
            'MerchantID' => $this->settings->merchantId,
            'Authority' => $authority,
            'Amount' => $amount, // convert to toman
        ];

        $client = new SoapClient($this->getVerificationUrl(), ['encoding' => 'UTF-8']);
        $result = $client->PaymentVerification($data);

        $bodyResponse = $result->Status;
        if ($bodyResponse != 100) {
            throw new InvalidPaymentException($this->translateStatus($bodyResponse), $bodyResponse);
        }

        return $this->createReceipt($result->RefID);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    public function createReceipt($referenceId)
    {
        return new Receipt('zarinpal', $referenceId);
    }

    /**
     * Retrieve purchase url
     *
     * @return string
     */
    protected function getPurchaseUrl() : string
    {
        return $this->settings->zaringateApiPurchaseUrl;
    }

    /**
     * Retrieve Payment url
     *
     * @return string
     */
    protected function getPaymentUrl() : string
    {
        return $this->settings->zaringateApiPaymentUrl;
    }

    /**
     * Retrieve verification url
     *
     * @return string
     */
    protected function getVerificationUrl() : string
    {
        return $this->settings->zaringateApiVerificationUrl;
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
            '100' => 'تراکنش با موفقیت انجام گردید',
            '101' => 'عمليات پرداخت موفق بوده و قبلا عملیات وریفای تراكنش انجام شده است',
            '-9' => 'خطای اعتبار سنجی',
            '-10' => 'ای پی و يا مرچنت كد پذيرنده صحيح نمی باشد',
            '-11' => 'مرچنت کد فعال نیست لطفا با تیم پشتیبانی ما تماس بگیرید',
            '-12' => 'تلاش بیش از حد در یک بازه زمانی کوتاه',
            '-15' => 'ترمینال شما به حالت تعلیق در آمده با تیم پشتیبانی تماس بگیرید',
            '-16' => 'سطح تاييد پذيرنده پايين تر از سطح نقره ای می باشد',
            '-30' => 'اجازه دسترسی به تسویه اشتراکی شناور ندارید',
            '-31' => 'حساب بانکی تسویه را به پنل اضافه کنید مقادیر وارد شده برای تسهیم صحيح نمی باشد',
            '-32' => 'مقادیر وارد شده برای تسهیم صحيح نمی باشد',
            '-33' => 'درصد های وارد شده صحيح نمی باشد',
            '-34' => 'مبلغ از کل تراکنش بیشتر است',
            '-35' => 'تعداد افراد دریافت کننده تسهیم بیش از حد مجاز است',
            '-40' => 'پارامترهای اضافی نامعتبر، expire_in معتبر نیست',
            '-50' => 'مبلغ پرداخت شده با مقدار مبلغ در وریفای متفاوت است',
            '-51' => 'پرداخت ناموفق',
            '-52' => 'خطای غیر منتظره با پشتیبانی تماس بگیرید',
            '-53' => 'اتوریتی برای این مرچنت کد نیست',
            '-54' => 'اتوریتی نامعتبر است',
        ];

        $unknownError = 'خطای ناشناخته رخ داده است. در صورت کسر مبلغ از حساب حداکثر پس از 72 ساعت به حسابتان برمیگردد';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }
}
