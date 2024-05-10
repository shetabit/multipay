<?php

namespace Shetabit\Multipay\Drivers\Aqayepardakht;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Aqayepardakht extends Driver
{
    /**
     * Aqayepardakht Client.
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

    const PAYMENT_STATUS_FAILED = 'error';
    const PAYMENT_STATUS_OK = 'success';

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Shetabit\Multipay\Exceptions\PurchaseFailedException
     */
    public function purchase()
    {
        $data = [
            'pin' => $this->settings->mode === "normal" ? $this->settings->pin : "sandbox",
            'amount' => $this->invoice->getAmount() / ($this->settings->currency == 'T' ? 1 : 10), // convert to toman
            'callback' => $this->settings->callbackUrl,
            'invoice_id' => $this->settings->invoice_id,
            'mobile' => $this->settings->mobile,
            'email' => $this->settings->email,
        ];

        $response = $this->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    'json' => $data,
                    'headers' => [
                        "Accept" => "application/json",
                    ],
                    'http_errors' => false,
                ]
            );

        $responseBody = json_decode($response->getBody()->getContents(), true);

        if ($responseBody['status'] !== self::PAYMENT_STATUS_OK) {
            throw new PurchaseFailedException($this->getErrorMessage($responseBody['code']));
        }

        $this->invoice->transactionId($responseBody['transid']);

        return $this->invoice->getTransactionId();
    }

    /**
     * @return \Shetabit\Multipay\RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $url = $this->settings->mode === "normal" ? $this->settings->apiPaymentUrl : $this->settings->apiPaymentUrlSandbox;
        $url = $url . $this->invoice->getTransactionId();

        return $this->redirectWithForm($url, [], 'GET');
    }

    /**
     * @return ReceiptInterface
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Shetabit\Multipay\Exceptions\InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $tracking_number = Request::post("tracking_number");
        $transid = Request::post("transid");
        if ($tracking_number === null || $tracking_number === ""|| $transid === ""|| $transid === null) {
            $this->notVerified('پرداخت ناموفق.');
        }
        $data = [
            'pin' => $this->settings->pin,
            'amount' => $this->invoice->getAmount() / ($this->settings->currency == 'T' ? 1 : 10), // convert to toman
            'transid' => $transid
        ];
        $response = $this->client
            ->request(
                'POST',
                $this->settings->apiVerificationUrl,
                [
                    'json' => $data,
                    'headers' => [
                        "Accept" => "application/json",
                    ],
                    'http_errors' => false,
                ]
            );

        $responseBody = json_decode($response->getBody()->getContents(), true);

        if ($responseBody['status'] !== self::PAYMENT_STATUS_OK) {
            if (isset($responseBody['code'])) {
                $message = $this->getErrorMessage($responseBody["code"]);
            }

            $this->notVerified($message ?? '', $responseBody["code"]);
        }
        return $this->createReceipt($tracking_number);
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
        $receipt = new Receipt('aqayepardakht', $referenceId);

        return $receipt;
    }

    /**
     * @param $message
     * @throws \Shetabit\Multipay\Exceptions\InvalidPaymentException
     */
    protected function notVerified($message, $status = 0)
    {
        if (empty($message)) {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.', (int)$status);
        } else {
            throw new InvalidPaymentException($message, (int)$status);
        }
    }

    /**
     * @param $code
     * @return  string
     */
    protected function getErrorMessage($code)
    {
        $code = (int)$code;
        switch ($code) {
            case -1: return "مبلغ نباید خالی باشد.";
            case -2: return "کد پین درگاه نمیتواند خالی باشد.";
            case -3: return "آدرس بازگشت نمیتواند خالی باشد.";
            case -4: return "مبلغ وارد شده اشتباه است.";
            case -5: return "مبلع باید بین 100 تومان تا 50 میلیون تومان باشد.";
            case -6: return "کد پین وارد شده اشتباه است.";
            case -7: return "کد تراکنش نمیتواند خالی باشد.";
            case -8: return "تراکنش مورد نظر وجود ندارد.";
            case -9: return "کد پین درگاه با درگاه تراکنش مطابقت ندارد.";
            case -10: return "مبلغ با مبلغ تراکنش مطابقت ندارد.";
            case -11: return "درگاه در انتظار تایید و یا غیرفعال است.";
            case -12: return "امکان ارسال درخواست برای این پذیرنده وجود ندارد.";
            case -13: return "شماره کارت باید 16 رقم چسبیده بهم باشد.";
            case 0: return "پرداخت انجام نشد.";
            case 1: return "پرداخت با موفقیت انجام شد.";
            case 2: return "تراکنش قبلا وریفای شده است.";
            default: return "خطای نامشخص.";
        }
    }
}
