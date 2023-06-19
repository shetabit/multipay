<?php

namespace Shetabit\Multipay\Drivers\Sepordeh;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Sepordeh extends Driver
{
    /**
     * Sepordeh Client.
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
     * Sepordeh constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
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
        $orderId = $this->extractDetails('orderId');
        $phone = $this->extractDetails('phone');
        $description = $this->extractDetails('description') ?: $this->settings->description;

        $data = [
            "merchant" => $this->settings->merchantId,
            "amount" => $this->invoice->getAmount() / ($this->settings->currency == 'T' ? 1 : 10), // convert to toman
            "phone" => $phone,
            "orderId" => $orderId,
            "callback" => $this->settings->callbackUrl,
            "description" => $description,
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "form_params" => $data,
                    "http_errors" => false,
                    'verify' => false,
                ]
            );

        $responseBody = mb_strtolower($response->getBody()->getContents());
        $body = @json_decode($responseBody, true);
        $statusCode = (int)$body['status'];

        if ($statusCode !== 200) {
            // some error has happened
            $message = $body['message'] ?? $this->convertStatusCodeToMessage($statusCode);

            throw new PurchaseFailedException($message);
        }

        $this->invoice->transactionId($body['information']['invoice_id']);

        return $this->invoice->getTransactionId();
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
     * Retrieve related message to given status code
     *
     * @param $statusCode
     *
     * @return string
     */
    private function convertStatusCodeToMessage(int $statusCode): string
    {
        $messages = [
            400 => 'مشکلی در ارسال درخواست وجود دارد',
            401 => 'عدم دسترسی',
            403 => 'دسترسی غیر مجاز',
            404 => 'آیتم درخواستی مورد نظر موجود نمی باشد',
            500 => 'مشکلی در سرور درگاه پرداخت رخ داده است',
            503 => 'سرور درگاه پرداخت در حال حاضر قادر به پاسخگویی نمی باشد',
        ];

        $unknown = 'خطای ناشناخته ای در درگاه پرداخت رخ داده است';

        return $messages[$statusCode] ?? $unknown;
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $basePayUrl = $this->settings->mode == 'normal' ? $this->settings->apiPaymentUrl
            : $this->settings->apiDirectPaymentUrl;
        $payUrl =  $basePayUrl . $this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
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
        $authority = $this->invoice->getTransactionId() ?? Request::input('authority');
        $data = [
            'merchant' => $this->settings->merchantId,
            'authority' => $authority,
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                'form_params' => $data,
                "headers" => [
                    "http_errors" => false,
                ],
                'verify' => false,
            ]
        );

        $responseBody = mb_strtolower($response->getBody()->getContents());
        $body = @json_decode($responseBody, true);
        $statusCode = (int)$body['status'];

        if ($statusCode !== 200) {
            $message = $body['message'] ?? $this->convertStatusCodeToMessage($statusCode);

            $this->notVerified($message, $statusCode);
        }

        $refId = $body['information']['invoice_id'];
        $detail = [
            'card' => $body['information']['card'],
            'orderId' => Request::input('orderId')
        ];

        return $this->createReceipt($refId, $detail);
    }

    /**
     * Trigger an exception
     *
     * @param $message
     *
     * @throws InvalidPaymentException
     */
    private function notVerified($message, $status)
    {
        throw new InvalidPaymentException($message, (int)$status);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId, $detail = [])
    {
        $receipt = new Receipt('sepordeh', $referenceId);
        $receipt->detail($detail);

        return $receipt;
    }
}
