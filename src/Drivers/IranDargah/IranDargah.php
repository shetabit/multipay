<?php

namespace Shetabit\Multipay\Drivers\IranDargah;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class IranDargah extends Driver
{
    /**
     * HTTP Client.
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
     * IranDargah constructor.
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
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
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
        $data = [
            'merchantID' => $this->settings->merchantId,
            'amount' => $this->getInvoiceAmount(),
            'callbackURL' => $this->settings->callbackUrl,
            'orderId' => $this->invoice->getUuid(),
            'cardNumber' => $this->extractDetails('cardNumber'),
            'mobile' => $this->extractDetails('mobile'),
            'description' => $this->extractDetails('description'),
        ];

        $response = $this->client->request(
            'POST',
            $this->getPurchaseUrl(),
            [
                'form_params' => $data,
                'http_errors' => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($body['status'] != 200) {
            throw new PurchaseFailedException($this->translateStatus($body['status']), $body['status']);
        }

        $this->invoice->transactionId($body['authority']);

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
        $payUrl = $this->getPaymentUrl() . $this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $paymentCode = Request::input('code');

        if ($paymentCode != 100) {
            throw new InvalidPaymentException($this->translateStatus($paymentCode), $paymentCode);
        }

        $data = [
            'merchantID' => $this->settings->merchantId,
            'authority' => $this->invoice->getTransactionId() ?? Request::input('authority'),
            'amount' => $this->getInvoiceAmount(),
            'orderId' => Request::input('orderId'),
        ];

        $response = $this->client->request(
            'POST',
            $this->getVerificationUrl(),
            [
                'json' => $data,
                "headers" => [
                    'Content-Type' => 'application/json',
                ],
                "http_errors" => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($body['status'] != 100) {
            throw new InvalidPaymentException($this->translateStatus($body['status']), $body['status']);
        }

        $refId = $body['refId'];
        $receipt =  $this->createReceipt($refId);

        $receipt->detail([
            'message' => $body['message'],
            'status' => $body['status'],
            'refId' => $refId,
            'orderId' => $body['orderId'],
            'cardNumber' => $body['cardNumber'],
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
    public function createReceipt($referenceId)
    {
        return new Receipt('irandargah', $referenceId);
    }

    /**
     * Retrieve invoice amount
     *
     * @return int|float
     */
    protected function getInvoiceAmount()
    {
        return $this->invoice->getAmount() * (strtolower($this->settings->currency) === 't' ? 10 : 1); // convert to rial
    }

    /**
     * Retrieve purchase url
     *
     * @return string
     */
    protected function getPurchaseUrl(): string
    {
        return $this->isSandboxMode()
            ? $this->settings->sandboxApiPurchaseUrl
            : $this->settings->apiPurchaseUrl;
    }

    /**
     * Retrieve Payment url
     *
     * @return string
     */
    protected function getPaymentUrl(): string
    {
        return $this->isSandboxMode()
            ? $this->settings->sandboxApiPaymentUrl
            : $this->settings->apiPaymentUrl;
    }

    /**
     * Retrieve verification url
     *
     * @return string
     */
    protected function getVerificationUrl(): string
    {
        return $this->isSandboxMode()
            ? $this->settings->sandboxApiVerificationUrl
            : $this->settings->apiVerificationUrl;
    }

    /**
     * Retrieve payment in sandbox mode?
     *
     * @return bool
     */
    protected function isSandboxMode() : bool
    {
        return $this->settings->sandbox;
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
            '100' => 'تراکنش با موفقیت انجام ‌شده‌ است',
            '101' => 'تراکنش قبلا وریفای شده است',
            '200' => 'اتصال به درگاه بانک با موفقیت انجام ‌شده است',
            '201' => 'در حال پرداخت در درگاه بانک',
            '403' => 'کد مرچنت صحیح نمی‌باشد',
            '404' => 'تراکنش یافت نشد',
            '-1' => 'کاربر از انجام تراکنش منصرف‌ شده است',
            '-2' => 'اطلاعات ارسالی صحیح نمی‌باشد',
            '-10' => 'مبلغ تراکنش کمتر از ۱۰،۰۰۰ ریال است',
            '-11' => 'مبلغ تراکنش با مبلغ پرداخت، یکسان نیست. مبلغ برگشت خورد',
            '-12' => 'شماره کارتی که با آن، تراکنش انجام ‌شده است با شماره کارت ارسالی، مغایرت دارد. مبلغ برگشت خورد',
            '-13' => 'تراکنش تکراری است',
            '-20' => 'شناسه تراکنش یافت‌ نشد',
            '-21' => 'مدت زمان مجاز، جهت ارسال به بانک گذشته‌است',
            '-22' => 'تراکنش برای بانک ارسال شده است',
            '-23' => 'خطا در اتصال به درگاه بانک',
            '-30' => 'اشکالی در فرایند پرداخت ایجاد ‌شده است. مبلغ برگشت خورد',
            '-31' => 'خطای ناشناخته',
        ];

        $unknownError = 'خطای ناشناخته رخ داده است. در صورت کسر مبلغ از حساب حداکثر پس از 72 ساعت به حسابتان برمیگردد';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }
}
