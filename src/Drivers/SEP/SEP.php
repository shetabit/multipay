<?php

namespace Shetabit\Multipay\Drivers\SEP;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class SEP extends Driver
{
    /**
     * SEP HTTP Client.
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
     * SEP constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
        $this->client = new Client([
            'curl' => [CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1'],
        ]);
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
            'action' => 'token',
            'TerminalId' => $this->settings->terminalId,
            'Amount' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'ResNum' => $this->invoice->getUuid(),
            'RedirectUrl' => $this->settings->callbackUrl,
            'CellNumber' => $this->invoice->getDetail('mobile') ?? '',
            'ResNum1' => $this->invoice->getDetail('ResNum1') ?? '',
            'ResNum2' => $this->invoice->getDetail('ResNum2') ?? '',
            'ResNum3' => $this->invoice->getDetail('ResNum3') ?? '',
            'ResNum4' => $this->invoice->getDetail('ResNum4') ?? '',
        );

        $response = $this->client->post(
            $this->settings->apiGetToken,
            [
                'json' => $data,
            ]
        );

        $responseStatus = $response->getStatusCode();

        if ($responseStatus != 200) { // if something has done in a wrong way
            $this->purchaseFailed(0);
        }

        $jsonBody = $response->getBody()->getContents();
        $responseData = json_decode($jsonBody, true);

        if ($responseData['status'] != 1) {
            $this->purchaseFailed($responseData['errorCode']);
        }

        // set transaction id
        $this->invoice->transactionId($responseData['token']);

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
                'GetMethod' => false,
            ]
        );
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \SoapFault
     * @throws PurchaseFailedException
     */
    public function verify(): ReceiptInterface
    {
        $status = (int)Request::input('Status');
        if ($status != 2) {
            $this->purchaseFailed($status);
        }

        $data = array(
            'RefNum' => Request::input('RefNum'),
            'TerminalNumber' => $this->settings->terminalId,
        );

        $response = $this->client->post(
            $this->settings->apiVerificationUrl,
            [
                'json' => $data,
            ]
        );

        if ($response->getStatusCode() != 200) {
            $this->notVerified(0);
        }

        $jsonData = $response->getBody()->getContents();
        $responseData = json_decode($jsonData, true);

        if ($responseData['ResultCode'] != 0) {
            $this->notVerified($responseData['ResultCode']);
        }

        $transactionDetail = $responseData['TransactionDetail'];

        $receipt = $this->createReceipt($data['RefNum']);
        $receipt->detail([
            'traceNo' => $transactionDetail['StraceNo'],
            'referenceNo' => $transactionDetail['RRN'],
            'transactionId' => $transactionDetail['RefNum'],
            'cardNo' => $transactionDetail['MaskedPan'],
        ]);

        // Add additional data specific for SEP gateway
        $receipt->detail([
            'TerminalNumber' => $transactionDetail['TerminalNumber'],
            'OrginalAmount' => $transactionDetail['OrginalAmount'],
            'AffectiveAmount' => $transactionDetail['AffectiveAmount'],
            'StraceDate' => $transactionDetail['StraceDate'],
            // SEP documents are not up-to-date. This will fix different
            // between variable name in docs and actual returned values.
            'Amount' => $transactionDetail['OrginalAmount'],
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
            1 => ' تراکنش توسط خریدار لغو شده است.',
            2 => 'پرداخت با موفقیت انجام شد.',
            3 => 'پرداخت انجام نشد.',
            4 => 'کاربر در بازه زمانی تعیین شده پاسخی ارسال نکرده است.',
            5 => 'پارامترهای ارسالی نامعتبر است.',
            8 => 'آدرس سرور پذیرنده نامعتبر است.',
            9 => 'رمز کارت 3 مرتبه اشتباه وارد شده است در نتیجه کارت غیر فعال خواهد شد.',
            10 => 'توکن ارسال شده یافت نشد.',
            11 => 'با این شماره ترمینال فقط تراکنش های توکنی قابل پرداخت هستند.',
            12 => 'شماره ترمینال ارسال شده یافت نشد.',
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
            -2 => ' تراکنش یافت نشد.',
            -6 => 'بیش از 30 دقیقه از زمان اجرای تراکنش گذشته است.',
            2 => 'کاربر در بازه زمانی تعیین شده پاسخی ارسال نکرده است.',
            -104 => 'پارامترهای ارسالی نامعتبر است.',
            -105 => 'آدرس سرور پذیرنده نامعتبر است.',
            -106 => 'رمز کارت 3 مرتبه اشتباه وارد شده است در نتیجه کارت غیر فعال خواهد شد.',
        );

        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status], (int)$status);
        } else {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.', (int)$status);
        }
    }
}
