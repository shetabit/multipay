<?php

namespace Shetabit\Multipay\Drivers\Omidpay;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Omidpay extends Driver
{
    /**
     * Sadad Client.
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
     * Sadad constructor.
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase(): string
    {
        $data = array(
            'WSContext' => [
                'UserId' => $this->settings->username,
                'Password' => $this->settings->password,
            ],
            'TransType' => 'EN_GOODS',
            'ReserveNum' => $this->invoice->getUuid(),
            'MerchantId' => $this->settings->merchantId,
            'Amount' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'RedirectUrl' => $this->settings->callbackUrl,
        );

        $response = $this->client->request(
            "POST",
            $this->settings->apiGenerateTokenUrl,
            [
                'json' => $data,
                "headers" => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => '',
                ],
            ]
        );

        $responseStatus = $response->getStatusCode();

        if ($responseStatus != 200) {
            throw new PurchaseFailedException($this->translateStatus("unknown_error"));
        }

        $jsonBody = $response->getBody()->getContents();
        $responseData = json_decode($jsonBody, true);

        $result = $responseData['Result'];
        if (!$this->isSucceed($result)) {
            throw new PurchaseFailedException($this->translateStatus($result));
        }

        // set transaction id
        $this->invoice->transactionId($responseData['Token']);

        // return the transaction’s id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $token = $this->invoice->getTransactionId();
        $payUrl = $this->settings->apiPaymentUrl;

        return $this->redirectWithForm($payUrl, ['token' => $token, 'language' => 'fa']);
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify(): ReceiptInterface
    {
        $token = $this->invoice->getTransactionId() ?? Request::input('token');
        $refNum = Request::input('RefNum');

        $response = $this->client->request(
            "POST",
            $this->settings->apiVerificationUrl,
            [
                "json" => [
                    'WSContext' => [
                        'UserId' => $this->settings->username,
                        'Password' => $this->settings->password,
                    ],
                    'Token' => $token,
                    'RefNum' => $refNum
                ],
                "headers" => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => '',
                ],
            ]
        );

        $body = json_decode($response->getBody()->getContents());

        $result = $body->Result;
        if (!$this->isSucceed($result)) {
            throw new InvalidPaymentException($this->translateStatus($result));
        }

        return $this->createReceipt($body->RefNum);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId): Receipt
    {
        return new Receipt('omidpay', $referenceId);
    }

    /**
     * @param string $status
     * @return bool
     */
    private function isSucceed(string $status): bool
    {
        return $status == "erSucceed";
    }

    /**
     * Convert status to a readable message.
     *
     * @param $status
     *
     * @return mixed|string
     */
    private function translateStatus($status): string
    {
        $translations = [
            'erSucceed' => 'سرویس با موفقیت اجراء شد.',
            'erAAS_UseridOrPassIsRequired' => 'کد کاربری و رمز الزامی هست.',
            'erAAS_InvalidUseridOrPass' => 'کد کاربری یا رمز صحیح نمی باشد.',
            'erAAS_InvalidUserType' => 'نوع کاربر صحیح نمی‌باشد.',
            'erAAS_UserExpired' => 'کاربر منقضی شده است.',
            'erAAS_UserNotActive' => 'کاربر غیر فعال هست.',
            'erAAS_UserTemporaryInActive' => 'کاربر موقتا غیر فعال شده است.',
            'erAAS_UserSessionGenerateError' => 'خطا در تولید شناسه لاگین',
            'erAAS_UserPassMinLengthError' => 'حداقل طول رمز رعایت نشده است.',
            'erAAS_UserPassMaxLengthError' => 'حداکثر طول رمز رعایت نشده است.',
            'erAAS_InvalidUserCertificate' => 'برای کاربر فایل سرتیفکیت تعریف نشده است.',
            'erAAS_InvalidPasswordChars' => 'کاراکترهای غیر مجاز در رمز',
            'erAAS_InvalidSession' => 'شناسه لاگین معتبر نمی‌باشد ',
            'erAAS_InvalidChannelId' => 'کانال معتبر نمی‌باشد.',
            'erAAS_InvalidParam' => 'پارامترها معتبر نمی‌باشد.',
            'erAAS_NotAllowedToService' => 'کاربر مجوز سرویس را ندارد.',
            'erAAS_SessionIsExpired' => 'شناسه الگین معتبر نمی‌باشد.',
            'erAAS_InvalidData' => 'داده‌ها معتبر نمی‌باشد.',
            'erAAS_InvalidSignature' => 'امضاء دیتا درست نمی‌باشد.',
            'erAAS_InvalidToken' => 'توکن معتبر نمی‌باشد.',
            'erAAS_InvalidSourceIp' => 'آدرس آی پی معتبر نمی‌باشد.',

            'erMts_ParamIsNull' => 'پارمترهای ورودی خالی می‌باشد.',
            'erMts_UnknownError' => 'خطای ناشناخته',
            'erMts_InvalidAmount' => 'مبلغ معتبر نمی‌باشد.',
            'erMts_InvalidBillId' => 'شناسه قبض معتبر نمی‌باشد.',
            'erMts_InvalidPayId' => 'شناسه پرداخت معتبر نمی‌باشد.',
            'erMts_InvalidEmailAddLen' => 'طول ایمیل معتبر نمی‌باشد.',
            'erMts_InvalidGoodsReferenceIdLen' => 'طول شناسه خرید معتبر نمی‌باشد.',
            'erMts_InvalidMerchantGoodsReferenceIdLen' => 'طول شناسه خرید پذیرنده معتبر نمی‌باشد.',
            'erMts_InvalidMobileNo' => 'فرمت شماره موبایل معتبر نمی‌باشد.',
            'erMts_InvalidPorductId' => 'طول یا فرمت کد محصول معتبر نمی‌باشد.',
            'erMts_InvalidRedirectUrl' => 'طول یا فرمت آدرس صفحه رجوع معتبر نمی‌باشد.',
            'erMts_InvalidReferenceNum' => 'طول یا فرمت شماره رفرنس معتبر نمی‌باشد.',
            'erMts_InvalidRequestParam' => 'پارامترهای درخواست معتبر نمی‌باشد.',
            'erMts_InvalidReserveNum' => 'طول یا فرمت شماره رزرو معتبر نمی‌باشد.',
            'erMts_InvalidSessionId' => 'شناسه الگین معتبر نمی‌باشد.',
            'erMts_InvalidSignature' => 'طول یا فرمت امضاء دیتا معتبر نمی‌باشد.',
            'erMts_InvalidTerminal' => 'کد ترمینال معتبر نمی‌باشد.',
            'erMts_InvalidToken' => 'توکن معتبر نمی‌باشد.',
            'erMts_InvalidTransType' => 'نوع تراکنش معتبر نمی‌باشد.',
            'erMts_InvalidUniqueId' => 'کد یکتا معتبر نمی‌باشد.',
            'erMts_InvalidUseridOrPass' => 'رمز یا کد کاربری معتبر نمی باشد.',
            'erMts_RepeatedBillId' => 'پرداخت قبض تکراری می باشد.',
            'erMts_AASError' => 'کد کاربری و رمز الزامی هست.',
            'erMts_SCMError' => 'خطای سرور مدیریت کانال',
        ];

        $unknownError = 'خطای ناشناخته رخ داده است. در صورت کسر مبلغ از حساب حداکثر پس از 72 ساعت به حسابتان برمیگردد.';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }
}
