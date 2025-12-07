<?php

namespace Shetabit\Multipay\Drivers\Panapal;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Panapal extends Driver
{
    /**
     * Panapal Client.
     *
     * @var object
     */
    protected \GuzzleHttp\Client $client;

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
     * Panapal constructor.
     * Construct the class with the relevant settings.
     *
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $details = $this->invoice->getDetails();

        $name = '';
        if (!empty($details['name'])) {
            $name = $details['name'];
        }

        $mobile = '';
        if (!empty($details['mobile'])) {
            $mobile = $details['mobile'];
        } elseif (!empty($details['phone'])) {
            $mobile = $details['phone'];
        }

        $email = '';
        if (!empty($details['mail'])) {
            $email = $details['mail'];
        } elseif (!empty($details['email'])) {
            $email = $details['email'];
        }

        $desc = '';
        if (!empty($details['desc'])) {
            $desc = $details['desc'];
        } elseif (!empty($details['description'])) {
            $desc = $details['description'];
        }

        $amount = $this->invoice->getAmount();
        if ($this->settings->currency != 'T') {
            $amount /= 10;
        }
        $amount = intval(ceil($amount));

        $uuid = str_replace('-', '', $this->invoice->getUuid());
        $hash = unpack('J', hash('sha256', $uuid, true))[1];
        $random = random_int(1000, 9999);

        $orderId = abs($hash) . $random;

        if (!empty($details['orderId'])) {
            $orderId = $details['orderId'];
        } elseif (!empty($details['order_id'])) {
            $orderId = $details['order_id'];
        }

        $data = [
            "MerchantID"    => $this->settings->merchantId,
            "Amount"        => $amount,
            "InvoiceID"     => $orderId,
            "Description"   => $desc,
            "FullName"      => $name,
            "Email"         => $email,
            "Mobile"        => $mobile,
            "CallbackURL"   => $this->settings->callbackUrl,
        ];

        $response = $this->client->request('POST', $this->settings->apiPurchaseUrl, ["json" => $data, "http_errors" => false]);

        $body = json_decode($response->getBody()->getContents(), true);

        $Status = $body['Status'];
        if ($Status != 100) {
            throw new PurchaseFailedException($this->translateStatusCode($Status), $Status);
        }

        $this->invoice->transactionId($body['Authority']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay() : RedirectionForm
    {
        $payUrl = $this->settings->apiPaymentUrl.$this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return mixed|void
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $PaymentStatus = Request::input('PaymentStatus');
        if ($PaymentStatus != 'OK') {
            throw new InvalidPaymentException('تراکنش از سوی کاربر لغو شد');
        }

        $Authority = Request::input('Authority');
        $InvoiceID = Request::input('InvoiceID');

        if ($Authority != $this->invoice->getTransactionId()) {
            throw new InvalidPaymentException('اطلاعات تراکنش دریافتی با صورتحساب همخوانی ندارد');
        }

        $amount = $this->invoice->getAmount();
        if ($this->settings->currency != 'T') {
            $amount /= 10;
        }
        $amount = intval(ceil($amount));

        //start verfication
        $data = [
            "MerchantID"    => $this->settings->merchantId,
            "Authority"     => $Authority,
            "Amount"        => $amount,
        ];

        $response = $this->client->request('POST', $this->settings->apiVerificationUrl, ["json" => $data, "http_errors" => false]);

        $body = json_decode($response->getBody()->getContents(), true);

        $Status = $body['Status'];
        if ($Status != 100) {
            throw new InvalidPaymentException($this->translateStatusCode($Status), $Status);
        }

        $receipt = new Receipt('panapal', $body['RefID']);

        $receipt->detail([
            'Authority'     => $data['Authority'],
            'InvoiceID1'    => $InvoiceID,
            'InvoiceID2'    => $body['InvoiceID'],
            'Amount1'       => $data['Amount'],
            'Amount2'       => $body['Amount'],
            'CardNumber'    => $body['MaskCardNumber'],
            'PaymentTime'   => $body['PaymentTime'],
            'PaymenterIP'   => $body['BuyerIP']
        ]);

        return $receipt;
    }


    /**
     * Convert status to a readable message.
     *
     * @param $code
     */
    private function translateStatusCode($code): string
    {
        $translations = [
            -1 => 'درخواست باید از طریق وب سرویس ارسال شود',
            -2 => 'مقداری برای MerchantID ارسال نشده است',
            -3 => 'مقداری برای Amount ارسال نشده است',
            -4 => 'مقداری برای CallbackURL ارسال نشده است',
            -5 => 'حداقل مبلغ قابل پرداخت 2,000 تومان می‌باشد',
            -6 => 'MerchantID وارد شده در سیستم یافت نشد',
            -7 => 'MerchantID وارد شده فعال نیست',
            -8 => 'سطح اکانت شما بنفش نیست، لذا امکان استفاده از وبسرویس را ندارید',
            -9 => 'IP معتبر نیست',
            -10 => 'آدرس بازگشتی با آدرس درگاه پرداخت ثبت شده همخوانی ندارد',
            -11 => 'خطا در وب سرویس - ایجاد تراکنش با خطا مواجه شد',
            -12 => 'مقدار Authority ارسالی معتبر نیست - تراکنش یافت نشد',
            -13 => 'مقداری برای Authority ارسال نشده است',
            -14 => 'اطلاعات تراکنش یافت نشد، مقدار Authority را بررسی کنید',
            -15 => 'تراکنش پرداخت نشده است',
            -16 => 'مبلغ ارسال شده با مبلغ تراکنش یکسان نیست',
            -17 => 'دسترسی شما به این تراکنش رد شد (Authority این تراکنش برای MerchantID شما نیست)',
            -18 => 'پرداخت موفق بوده، اما بروزرسانی کیف پول پذیرنده با مشکل مواجه شد',
            -19 => 'وضعیت تراکنش نامشخص است',
            -20 => 'شماره کارت ارسال شده معتبر نیست',
            -21 => 'شماره کارت پرداخت‌کننده با کارت مجاز به پرداخت مطابقت ندارد',
            100 => 'عملیات موفقیت‌آمیز',
            101 => 'عملیات پرداخت موفق بوده و قبلا PaymentVerification انجام شده است',
            102 => 'اتصال به وب سرویس انجام نشد',
        ];

        $unknownError = 'خطای ناشناخته رخ داده است. لطفا مجدد تلاش کنید';

        return $translations[$code] ?? $unknownError;
    }
}
