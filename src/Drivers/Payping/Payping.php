<?php

namespace Shetabit\Multipay\Drivers\Payping;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Payping extends Driver
{
    /**
     * Payping Client.
     */
    protected Client $client;

    /**
     * Payping constructor.
     * Construct the class with the relevant settings.
     *
     * @param $settings
     * @throws InvalidPaymentException
     */
    public function __construct(Invoice $invoice, array|object $settings)
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
    private function extractDetails(string $name) : mixed
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
    }

    /**
     * Retrieve a value from the gateway's response, no matter how it is cased.
     */
    private function extractResponseValue(mixed $body, string $name) : mixed
    {
        if (!is_array($body)) {
            return null;
        }

        $key = array_find(
            array_keys($body),
            static fn ($key): bool => strcasecmp((string) $key, $name) === 0
        );

        return $key === null ? null : $body[$key];
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase(): string|int|null
    {
        $name = $this->extractDetails('name');
        $mobile = $this->extractDetails('mobile');
        $email = $this->extractDetails('email');
        $description = $this->extractDetails('description');

        $data = [
            "amount" => $this->invoice->getAmount() / ($this->settings->currency == 'T' ? 1 : 10), // convert to toman
            "returnUrl" => $this->settings->callbackUrl,
            "payerIdentity" => $mobile ?: $email,
            "payerName" => $name,
            "description" => $description,
            "clientRefId" => $this->invoice->getUuid(),
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "json" => $data,
                    "headers" => [
                        "Accept" => "application/json",
                        "Authorization" => "bearer ".$this->settings->merchantId,
                    ],
                    "http_errors" => false,
                ]
            );

        $body = @json_decode($response->getBody()->getContents(), true);
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            // some error has happened
            $message = is_array($body) ? array_pop($body) : $this->convertStatusCodeToMessage($statusCode);

            throw new PurchaseFailedException($message);
        }

        $paymentCode = $this->extractResponseValue($body, 'paymentCode');

        if (empty($paymentCode)) {
            throw new PurchaseFailedException($this->convertStatusCodeToMessage($statusCode));
        }

        $this->invoice->transactionId($paymentCode);


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
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $refId = Request::input('refid');
        $data = [
                'paymentRefId' => $refId
        ];



        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                'json' => $data,
                "headers" => [
                    "Accept" => "application/json",
                    "Authorization" => "bearer ".$this->settings->merchantId,
                ],
                "http_errors" => false,
            ]
        );

        $body = @json_decode($response->getBody()->getContents(), true);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $message = is_array($body) ? array_pop($body) : $this->convertStatusCodeToMessage($statusCode);

            $this->notVerified($message, $statusCode);
        }

        $receipt = $this->createReceipt($refId);

        $receipt->detail([
            "cardNumber" => $this->extractResponseValue($body, 'cardNumber'),
        ]);


        return $receipt;
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     */
    protected function createReceipt(string|int $referenceId): Receipt
    {
        return new Receipt('payping', $referenceId);
    }

    /**
     * Trigger an exception
     *
     * @param $message
     *
     * @throws InvalidPaymentException
     */
    private function notVerified(string|null $message, int $status): never
    {
        throw new InvalidPaymentException($message, $status);
    }

    /**
     * Retrieve related message to given status code
     *
     * @param $statusCode
     */
    private function convertStatusCodeToMessage(int $statusCode) : string
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
}
