<?php

namespace Shetabit\Multipay\Drivers\Digify;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Digify extends Driver
{
    /**
     * HTTP Client.
     */
    protected Client $client;

    protected string $baseUrl;

    protected string $apiKey;

    protected string $callbackUrl;

    /**
     * Url of the payment page, given by the gateway during the purchase.
     */
    protected string|null $paymentUrl = null;

    /**
     * Digify constructor.
     *
     * @param $settings
     */
    public function __construct(Invoice $invoice, array|object $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();

        $this->baseUrl = rtrim($this->settings->baseUrl, '/');
        $this->apiKey = $this->settings->apiKey;
        $this->callbackUrl = $this->settings->callbackUrl;
    }

    /**
     * Create the order.
     *
     * @throws PurchaseFailedException
     */
    public function purchase(): string|int|null
    {
        $discountAmount = (int) $this->invoice->getDetail('discountAmount');
        $amount = $this->normalizerAmount($this->invoice->getAmount());

        $payload = [
            'merchant_unique_id' => $this->invoice->getUuid(),
            'merchant_order_id' => $this->invoice->getUuid(),
            'main_amount' => $amount + $discountAmount,
            'discount_amount' => $discountAmount,
            'loyalty_amount' => 0,
            'tax_amount' => 0,
            'final_amount' => $amount,
            'callback_url' => $this->callbackUrl,
            'reservation_expired_at' => Carbon::now()->addMinutes(20)->timestamp,
            'items' => $this->invoice->getDetail('items') ?? [],
        ];

        [$status, $body] = $this->post('/orders/api/v1/create-order/', $payload);

        if ($status >= 400) {
            throw new PurchaseFailedException($this->extractErrorMessage($body), $status);
        }

        $this->invoice->transactionId($body['order_uuid']);
        $this->setPaymentUrl($body['order_start_url']);

        return $this->invoice->getTransactionId();
    }

    /**
     * Redirect to the payment page.
     */
    public function pay(): RedirectionForm
    {
        return $this->redirectWithForm($this->getPaymentUrl(), [], 'GET');
    }

    /**
     * Verify the payment.
     *
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $orderUuid = Request::input('order_uuid');

        if (!$orderUuid) {
            throw new InvalidPaymentException('order_uuid ارسال نشده است');
        }

        [$status, $body] = $this->post(
            "/orders/api/v1/manager/{$orderUuid}/verify/",
            ['merchant_unique_id' => Request::input('merchant_order_id')]
        );

        if ($status !== 200) {
            throw new InvalidPaymentException($this->extractErrorMessage($body), $status);
        }

        if (empty($body['is_paid']) || $body['status'] !== 9) {
            throw new InvalidPaymentException($body['status_display'] ?? 'پرداخت تایید نشد');
        }

        return new Receipt('digify', $body['reference_code'] ?? $orderUuid)->detail($body);
    }

    /**
     * Send a POST request to the gateway.
     *
     * @return array{0: int, 1: array} the status code and the decoded body
     */
    protected function post(string $uri, array $data): array
    {
        $response = $this->client->request(
            'POST',
            $this->baseUrl.$uri,
            [
                RequestOptions::JSON => $data,
                RequestOptions::HEADERS => [
                    'Authorization' => "Api-Key {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        return [$response->getStatusCode(), is_array($body) ? $body : []];
    }

    /**
     * Extract a readable error message out of the gateway's response.
     */
    protected function extractErrorMessage(array $body): string
    {
        return $body['non_field_errors'][0]
            ?? $body['detail']
            ?? $body['message']
            ?? 'خطا در ارتباط با سرویس دیجیفای';
    }

    private function normalizerAmount(int $amount): int
    {
        return $amount;
    }

    private function setPaymentUrl(string $url): void
    {
        $this->paymentUrl = $url;
    }

    private function getPaymentUrl(): string
    {
        if ($this->paymentUrl === null) {
            throw new InvalidPaymentException('صفحه پرداخت یافت نشد، ابتدا صورتحساب را ثبت کنید.');
        }

        return $this->paymentUrl;
    }
}
