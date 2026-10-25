<?php

namespace Shetabit\Multipay\Drivers\Novinopay;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use Shetabit\Multipay\Traits\HasIranCurrency;

class Novinopay extends Driver
{
    use HasIranCurrency;

    protected Client $client;

    /**
     * The payment page of the transaction, handed out by the gateway on purchase.
     */
    protected string|null $paymentUrl = null;

    /**
     * Novinopay constructor.
     * Construct the class with the relevant settings.
     */
    public function __construct(Invoice $invoice, array|object $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Purchase Invoice.
     *
     * @see https://novinopay.com/docs
     *
     * @throws PurchaseFailedException
     */
    public function purchase(): string|int|null
    {
        $data = [
            'merchant_id' => $this->settings->merchantId,
            'amount' => (int) $this->convertAmountToRial($this->invoice->getAmount()),
            'callback_url' => $this->settings->callbackUrl,
            'invoice_id' => (string) ($this->invoice->getDetail('orderId') ?? $this->invoice->getUuid()),
            'description' => $this->invoice->getDetail('description') ?? $this->settings->description,
        ];

        $optional = [
            'mobile' => $this->invoice->getDetail('mobile')
                ?? $this->invoice->getDetail('phone')
                ?? $this->invoice->getDetail('cellphone'),
            'email' => $this->invoice->getDetail('email'),
            'name' => $this->invoice->getDetail('name'),
            'card_pan' => $this->invoice->getDetail('cardPan'),
        ];

        $data += array_filter($optional, fn ($value) => $value !== null);

        $body = $this->send($this->settings->apiPurchaseUrl, $data);

        if ($this->status($body) !== '100') {
            throw new PurchaseFailedException($this->errorMessage($body), (int) $this->status($body));
        }

        if (!isset($body['data']['authority'], $body['data']['payment_url'])) {
            throw new PurchaseFailedException('درگاه بدون شناسه تراکنش و آدرس پرداخت پاسخ داد.');
        }

        $this->invoice->transactionId($body['data']['authority']);
        $this->paymentUrl = $body['data']['payment_url'];

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @throws InvalidPaymentException
     */
    public function pay(): RedirectionForm
    {
        if ($this->paymentUrl === null) {
            throw new InvalidPaymentException('The transaction has no payment page yet, purchase it first.');
        }

        return $this->redirectWithForm($this->paymentUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @see https://novinopay.com/docs
     *
     * @throws InvalidPaymentException
     * @throws PreviouslyVerifiedException
     */
    public function verify(): ReceiptInterface
    {
        $paymentStatus = Request::input('PaymentStatus');

        if ($paymentStatus !== null && $paymentStatus !== 'OK') {
            throw new InvalidPaymentException('پرداخت ناموفق بوده و یا توسط کاربر لغو شده است.');
        }

        $body = $this->send($this->settings->apiVerificationUrl, [
            'merchant_id' => $this->settings->merchantId,
            'amount' => (int) $this->convertAmountToRial($this->invoice->getAmount()),
            'authority' => (string) ($this->invoice->getTransactionId() ?? Request::input('Authority')),
        ]);

        $status = $this->status($body);

        if ($status === '101') {
            throw new PreviouslyVerifiedException($this->errorMessage($body), 101);
        }

        if ($status !== '100') {
            throw new InvalidPaymentException($this->errorMessage($body), (int) $status);
        }

        if (!isset($body['data']['ref_id'])) {
            throw new InvalidPaymentException('درگاه بدون شماره پیگیری تراکنش پاسخ داد.');
        }

        return new Receipt('novinopay', $body['data']['ref_id'])->detail($body['data']);
    }

    /**
     * Send a request to the gateway and hand back its decoded answer.
     */
    protected function send(string $url, array $data): array
    {
        $response = $this->client->request('POST', $url, [
            RequestOptions::JSON => $data,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
            ],
            RequestOptions::HTTP_ERRORS => false,
        ]);

        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) ? $body : [];
    }

    /**
     * The status of an answer of the gateway, which the gateway sends as a string.
     */
    protected function status(array $body): string|null
    {
        return isset($body['status']) ? (string) $body['status'] : null;
    }

    /**
     * The reason the gateway gave for the status of its answer.
     */
    protected function errorMessage(array $body): string
    {
        $status = $this->status($body);
        $translations = [
            101 => 'عملیات پرداخت موفق بوده و قبلا تاییدیه تراکنش انجام شده است.',
            -1 => 'درخواست بر اساس اعتبارسنجی اولیه رد شد.',
            -101 => 'کد پذیرنده وارد شده نامعتبر یا غیرفعال است.',
            -102 => 'آی‌پی سرور درخواست دهنده معتبر نمی‌باشد.',
            -103 => 'آدرس بازگشتی با آدرس درگاه پرداخت ثبت شده همخوانی ندارد.',
            -104 => 'مبلغ ارسال شده صحیح نمی‌باشد.',
            -105 => 'مجموع مبلغ تراکنش و کارمزد نباید بیش از 1,000,000,000 ریال باشد.',
            -106 => 'شماره کارت ارسالی معتبر نمی‌باشد.',
            -107 => 'وب‌سرویس مقصد جهت اتصال معتبر نمی‌باشد.',
            -108 => 'ایجاد تراکنش با خطای سیستمی مواجه شد.',
            -109 => 'مقدار authority معتبر نبوده و یا با کد پذیرنده مطابقت ندارد.',
            -110 => 'مبلغ ارسال شده برای تاییدیه با مبلغ تراکنش یکسان نیست.',
            -111 => 'تراکنش در انتظار پرداخت می‌باشد.',
            -112 => 'تراکنش ناموفق بوده و امکان تایید آن وجود ندارد.',
            -113 => 'مدت زمان تایید تراکنش به اتمام رسیده و مبلغ به حساب پرداخت کننده برگشت خواهد خورد.',
            -114 => 'تراکنش به حساب پرداخت کننده مرجوع شده است.',
            -115 => 'سشن ایجاد شده جهت پرداخت منقضی شده است.',
            -116 => 'وریفای تراکنش از سمت بانک رد شد.',
            -117 => 'ارسال شماره کارت جهت پرداخت الزامی می‌باشد.',
        ];

        if ($status !== null && isset($translations[(int) $status])) {
            return $translations[(int) $status];
        }

        return is_string($body['message'] ?? null) && $body['message'] !== ''
            ? $body['message']
            : 'خطای ناشناخته‌ای رخ داده است.';
    }
}
