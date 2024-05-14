<?php

namespace Shetabit\Multipay\Drivers\Sepehr;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Sepehr extends Driver
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
     * Sepehr constructor.
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
     */
    public function purchase()
    {
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        $mobile = '';
        //set CellNumber for get user cards
        if (!empty($this->invoice->getDetails()['mobile'])) {
            $mobile = '&CellNumber=' . $this->invoice->getDetails()['mobile'];
        }

        $data_query = 'Amount=' . $this->test_input($amount) . '&callbackURL=' . $this->test_input($this->settings->callbackUrl) . '&InvoiceID=' . $this->test_input($this->invoice->getUuid()) . '&TerminalID=' . $this->test_input($this->settings->terminalId) . '&Payload=' . $this->test_input("") . $mobile;
        $address_service_token = $this->settings->apiGetToken;

        $token_array = $this->makeHttpChargeRequest('POST', $data_query, $address_service_token);

        if ($token_array === false) {
            throw new PurchaseFailedException('درگاه مورد نظر پاسخگو نمی‌باشد، لطفا لحظاتی بعد امتحان کنید.');
        }

        $decode_token_array = json_decode($token_array);

        $status = $decode_token_array->Status;
        $access_token = $decode_token_array->Accesstoken;

        if (empty($access_token) && $status != 0) {
            $this->purchaseFailed($status);
        }

        $this->invoice->transactionId($access_token);
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
        return $this->redirectWithForm($this->settings->apiPaymentUrl, [
            'token' => $this->invoice->getTransactionId(),
            'terminalID' => $this->settings->terminalId
        ], 'POST');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     *
     */
    public function verify(): ReceiptInterface
    {
        $responseCode = Request::input('respcode');
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        if ($responseCode != 0) {
            $this->notVerified($responseCode);
        }

        $data_query = 'digitalreceipt=' . Request::input('digitalreceipt') . '&Tid=' . $this->settings->terminalId;
        $advice_array = $this->makeHttpChargeRequest('POST', $data_query, $this->settings->apiVerificationUrl);
        $decode_advice_array = json_decode($advice_array);

        $status = $decode_advice_array->Status;
        $return_id = $decode_advice_array->ReturnId;

        if ($status == "Ok") {
            if ($return_id != $amount) {
                throw new InvalidPaymentException('مبلغ واریز با قیمت محصول برابر نیست');
            }
            return $this->createReceipt(Request::input('rrn'));
        } else {
            $message = 'تراکنش نا موفق بود در صورت کسر مبلغ از حساب شما حداکثر پس از 72 ساعت مبلغ به حسابتان برمیگردد.';
            throw new InvalidPaymentException($message);
        }
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
        $receipt = new Receipt('sepehr', $referenceId);

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
            -1 => 'تراکنش پیدا نشد.',
            -2 => 'عدم تطابق ip و یا بسته بودن port 8081',
            -3 => '‫ها‬ ‫‪Exception‬‬ ‫خطای‬ ‫–‬ ‫عمومی‬ ‫خطای‬ ‫‪Total‬‬ ‫‪Error‬‬',
            -4 => 'امکان انجام درخواست برای این تراکنش وجود ندارد.',
            -5 => 'آدرس ip نامعتبر می‌باشد.',
            -6 => 'عدم فعال بودن سرویس برگشت تراکنش برای پذیرنده',
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
            -1 => ' تراکنش توسط خریدار کنسل شده است.',
            -2 => 'زمان انجام تراکنش برای کاربر به پایان رسیده است.',
            -3 => '‫ها‬ ‫‪Exception‬‬ ‫خطای‬ ‫–‬ ‫عمومی‬ ‫خطای‬ ‫‪Total‬‬ ‫‪Error‬‬',
            -4 => 'امکان انجام درخواست برای این تراکنش وجود ندارد.',
            -5 => 'آدرس ip نامعتبر می‌باشد.',
            -6 => 'عدم فعال بودن سرویس برگشت تراکنش برای پذیرنده',
        );

        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status], (int)$status);
        } else {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.', (int)$status);
        }
    }

    private function test_input($data)
    {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }

    private function makeHttpChargeRequest($_Method, $_Data, $_Address)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $_Address);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $_Method);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $_Data);
        $result = curl_exec($curl);
        curl_close($curl);
        return $result;
    }
}
