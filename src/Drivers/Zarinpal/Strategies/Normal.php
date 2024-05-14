<?php

namespace Shetabit\Multipay\Drivers\Zarinpal\Strategies;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Normal extends Driver
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
        $this->client = new Client();
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
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }

        $data = [
            "merchant_id" => $this->settings->merchantId,
            "amount" => $amount,
            "currency" => 'IRR',
            "callback_url" => $this->settings->callbackUrl,
            "description" => $description,
            "metadata" => $this->metadata(),
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "json" => $data,
                    "headers" => [
                        'Content-Type' => 'application/json',
                    ],
                    "http_errors" => false,
                ]
            );

        $result = json_decode($response->getBody()->getContents(), true);

        if (!empty($result['errors']) || empty($result['data']) || $result['data']['code'] != 100) {
            $bodyResponse = $result['errors']['code'];
            throw new PurchaseFailedException($this->translateStatus($bodyResponse), $bodyResponse);
        }

        $this->invoice->transactionId($result['data']["authority"]);

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
        $transactionId = $this->invoice->getTransactionId();
        $paymentUrl = $this->getPaymentUrl();

        $payUrl = $paymentUrl . $transactionId;

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
        $authority = $this->invoice->getTransactionId() ?? Request::input('Authority');
        $data = [
            "merchant_id" => $this->settings->merchantId,
            "authority" => $authority,
            "amount" => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
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

        $result = json_decode($response->getBody()->getContents(), true);

        if (empty($result['data']) || !isset($result['data']['ref_id']) || ($result['data']['code'] != 100 && $result['data']['code'] != 101)) {
            $bodyResponse = $result['errors']['code'];
            throw new InvalidPaymentException($this->translateStatus($bodyResponse), $bodyResponse);
        }

        $refId = $result['data']['ref_id'];

        $receipt =  $this->createReceipt($refId);
        $receipt->detail([
            'code' => $result['data']['code'],
            'message' => $result['data']['message'] ?? null,
            'card_hash' => $result['data']['card_hash'] ?? null,
            'card_pan' => $result['data']['card_pan'] ?? null,
            'ref_id' => $refId,
            'fee_type' => $result['data']['fee_type'] ?? null,
            'fee' => $result['data']['fee'] ?? null,
            'order_id' => $result['data']['order_id'] ?? null,
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
        return new Receipt('zarinpal', $referenceId);
    }

    /**
     * Retrieve purchase url
     *
     * @return string
     */
    protected function getPurchaseUrl(): string
    {
        return $this->settings->apiPurchaseUrl;
    }

    /**
     * Retrieve Payment url
     *
     * @return string
     */
    protected function getPaymentUrl(): string
    {
        return $this->settings->apiPaymentUrl;
    }

    /**
     * Retrieve verification url
     *
     * @return string
     */
    protected function getVerificationUrl(): string
    {
        return $this->settings->apiVerificationUrl;
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

    private function metadata(): array
    {
        $metadata = [];

        if (!empty($this->invoice->getDetails()['mobile'])) {
            $metadata['mobile'] = $this->invoice->getDetails()['mobile'];
        }

        if (!empty($this->invoice->getDetails()['email'])) {
            $metadata['email'] = $this->invoice->getDetails()['email'];
        }

        return array_merge($this->invoice->getDetails() ?? [], $metadata);
    }
}
