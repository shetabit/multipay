<?php

namespace Shetabit\Multipay\Drivers\Paystar;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Paystar extends Driver
{
    /**
     * Paystar Client.
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
     * payment token
     *
     * @var $token
     */
    protected $token;

    /**
     * Paystar constructor.
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
    public function purchase()
    {
        $details = $this->invoice->getDetails();
        $order_id = $this->invoice->getUuid();
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        $callback = $this->settings->callbackUrl;

        $data = [
            'amount' => $amount,
            'order_id' => $order_id,
            'mail' => $details['email'] ?? null,
            'phone' => $details['mobile'] ?? $details['phone'] ?? null,
            'description' => $details['description'] ?? $this->settings->description,
            'callback' => $callback,
            'sign' =>
                hash_hmac(
                    'SHA512',
                    $amount . '#' . $order_id . '#' . $callback,
                    $this->settings->signKey
                ),
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->settings->gatewayId,
                    ],
                    'body' => json_encode($data),
                ]
            );

        $body = json_decode($response->getBody()->getContents());

        if ($body->status !== 1) {
            // some error has happened
            throw new PurchaseFailedException($this->translateStatus($body->status));
        }

        $this->invoice->transactionId($body->data->ref_num);
        $this->token = $body->data->token;

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
        return $this->redirectWithForm(
            $this->settings->apiPaymentUrl,
            [
                'token' => $this->token,
            ],
            'POST'
        );
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
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        $refNum = Request::post('ref_num');
        $cardNumber = Request::post('card_number');
        $trackingCode = Request::post('tracking_code');

        if (!$trackingCode) {
            throw new InvalidPaymentException($this->translateStatus(-1), -1);
        }

        $data = [
            'amount' => $amount,
            'ref_num' => $refNum,
            'tracking_code' => $trackingCode,
            'sign' =>
                hash_hmac(
                    'SHA512',
                    $amount . '#' . $refNum . '#' . $cardNumber . '#' . $trackingCode,
                    $this->settings->signKey
                ),
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->settings->gatewayId,
                ],
                'body' => json_encode($data),
            ]
        );

        $body = json_decode($response->getBody()->getContents());

        if ($body->status !== 1) {
            throw new InvalidPaymentException($this->translateStatus($body->status), (int)$body->status);
        }

        return $this->createReceipt($refNum);
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
        $receipt = new Receipt('paystar', $referenceId);

        return $receipt;
    }

    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @return mixed|string
     */
    private function translateStatus($status)
    {
        $status = (string) $status;

        $translations = [
            '1' => 'موفق',
            '-1' => 'درخواست نامعتبر (خطا در پارامترهای ورودی)',
            '-2' => 'درگاه فعال نیست',
            '-3' => 'توکن تکراری است',
            '-4' => 'مبلغ بیشتر از سقف مجاز درگاه است',
            '-5' => 'شناسه ref_num معتبر نیست',
            '-6' => 'تراکنش قبلا وریفای شده است',
            '-7' => 'پارامترهای ارسال شده نامعتبر است',
            '-8' => 'تراکنش را نمیتوان وریفای کرد',
            '-9' => 'تراکنش وریفای نشد',
            '-98' => 'تراکنش ناموفق',
            '-99' => 'خطای سامانه'
        ];

        $unknownError = 'خطای ناشناخته رخ داده است.';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }
}
