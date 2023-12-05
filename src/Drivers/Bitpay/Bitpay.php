<?php

namespace Shetabit\Multipay\Drivers\Bitpay;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Bitpay extends Driver
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

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
    }

    /**
     * @return string
     *
     * @throws \Shetabit\Multipay\Exceptions\PurchaseFailedException
     */
    public function purchase()
    {
        $name = $this->extractDetails('name');
        $email = $this->extractDetails('email');
        $description = $this->extractDetails('description');
        $factorId = $this->extractDetails('factorId');
        $api = $this->settings->api_token;
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        $redirect = $this->settings->callbackUrl;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->settings->apiPurchaseUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "api={$api}&amount={$amount}&redirect={$redirect}&factorId={$factorId}&name={$name}&email={$email}&description={$description}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        curl_close($ch);

        if ($res > 0 && is_numeric($res)) {
            $this->invoice->transactionId($res);

            return $this->invoice->getTransactionId();
        }

        throw new PurchaseFailedException($this->purchaseTranslateStatus($res), $res);
    }

    /**
     * @return \Shetabit\Multipay\RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $url = str_replace('{id_get}', $this->invoice->getTransactionId(), $this->settings->apiPaymentUrl);

        return $this->redirectWithForm($url, [], 'GET');
    }

    /**
     * @return ReceiptInterface
     *
     * @throws \Shetabit\Multipay\Exceptions\InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $trans_id = Request::get('trans_id');
        $id_get = Request::get('id_get');
        $api = $this->settings->api_token;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->settings->apiVerificationUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "api=$api&id_get=$id_get&trans_id=$trans_id&json=1");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        curl_close($ch);

        $parseDecode = json_decode($res);
        $statusCode = $parseDecode->status;

        if ($statusCode != 1) {
            throw new InvalidPaymentException($this->paymentTranslateStatus($statusCode), $statusCode);
        }

        $receipt = $this->createReceipt($trans_id);

        $receipt->detail([
            "amount" => $parseDecode->amount / ($this->settings->currency == 'T' ? 10 : 1), // convert to config currency
            "cardNum" => $parseDecode->cardNum,
            "factorId" => $parseDecode->factorId,
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
        $receipt = new Receipt('bitpay', $referenceId);

        return $receipt;
    }

    /**
     * Convert purchase status to a readable message.
     *
     * @param $status
     *
     * @return string
     */
    private function purchaseTranslateStatus($status)
    {
        $translations = [
            '-1' => 'Api ارسالی با نوع Api تعریف شده در bitpay سازگار نیست',
            '-2' => 'مقدار amount داده عددی نمی‌باشد و یا کمتر از ۱۰۰۰ ریال است',
            '-3' => 'مقدار redirect رشته null است',
            '-4' => 'درگاهی با اطلاعات ارسالی شما وجود ندارد و یا در حالت انتظار می‌باشد',
            '-5' => 'خطا در اتصال به درگاه، لطفا مجدد تلاش کنید',
        ];

        $unknownError = 'خطای ناشناخته رخ داده است. در صورت کسر مبلغ از حساب حداکثر پس از 72 ساعت به حسابتان برمیگردد';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }

    /**
     * Convert payment status to a readable message.
     *
     * @param $status
     *
     * @return string
     */
    private function paymentTranslateStatus($status)
    {
        $translations = [
            '-1' => 'Api ارسالی با نوع Api تعریف شده در bitpay سازگار نیست',
            '-2' => 'tran_id ارسال شده، داده عددی نمی‌باشد',
            '-3' => 'id_get ارسال شده، داده عددی نمی‌باشد',
            '-4' => 'چنین تراکنشی در پایگاه داده وجود ندارد و یا موفقیت آمیز نبوده است',
            '11' => 'تراکنش از قبل وریفای شده است',
        ];

        $unknownError = 'خطای ناشناخته رخ داده است. در صورت کسر مبلغ از حساب حداکثر پس از 72 ساعت به حسابتان برمیگردد';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }
}
