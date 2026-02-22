<?php

namespace Shetabit\Multipay\Drivers\Digikala;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;

class Digify extends Driver
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $callbackUrl;

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice  = $invoice;
        $this->settings = $settings;

        $this->baseUrl     = rtrim($settings['base_url'], '/');
        $this->apiKey      = $settings['api_key'];
        $this->callbackUrl = $settings['callback_url'];
    }

    /**
     * Create Order
     */
    public function purchase()
    {
        $discountAmount = (int) $this->invoice->getDetail('discountAmount');
        $amount         = $this->normalizerAmount($this->invoice->getAmount());

        $payload = [
            'merchant_unique_id'     => $this->invoice->getUuid(),
            'merchant_order_id'      => $this->invoice->getUuid(),
            'main_amount'            => $amount + $discountAmount,
            'discount_amount'        => $discountAmount,
            'loyalty_amount'         => 0,
            'tax_amount'             => 0,
            'final_amount'           => $amount,
            'callback_url'           => $this->callbackUrl,
            'reservation_expired_at' => now()->addMinutes(20)->timestamp,
            'items'                  => $this->invoice->getDetail('items') ?? [],
        ];

        $response = $this->post('/orders/api/v1/create-order/', $payload);

        if ($response->failed()) {
            throw new PurchaseFailedException(
                $this->extractErrorMessage($response),
                $response->status()
            );
        }

        $data = $response->json();

        $this->invoice->transactionId($data['order_uuid']);
        $this->setPaymentUrl($data['order_start_url']);

        return $this->invoice->getTransactionId();
    }

    /**
     * Verify Payment
     */
    public function verify(): ReceiptInterface
    {
        $orderUuid = request('order_uuid');

        if (!$orderUuid) {
            throw new InvalidPaymentException('order_uuid ارسال نشده است');
        }

        $response = $this->post(
            "/orders/api/v1/manager/{$orderUuid}/verify/",
            [
                'merchant_unique_id' => request('merchant_order_id'),
            ]
        );

        if (!$response->ok()) {
            throw new InvalidPaymentException(
                $this->extractErrorMessage($response),
                $response->status()
            );
        }

        $data = $response->json();

        if (
            empty($data['is_paid']) ||
            !in_array($data['status'], [9], true)
        ) {
            throw new InvalidPaymentException(
                $data['status_display'] ?? 'پرداخت تایید نشد'
            );
        }

        return (new Receipt('digifay', $data['reference_code'] ?? $orderUuid))
            ->detail($data);
    }

    /**
     * Redirect to payment page
     */
    public function pay(): RedirectionForm
    {
        return $this->redirectWithForm(
            $this->getPaymentUrl(),
            [],
            'GET'
        );
    }

    /**
     * Send POST request
     */
    protected function post(string $uri, array $data): Response
    {
        return Http::withHeaders([
            'Authorization' => "Api-Key {$this->apiKey}",
            'Content-Type'  => 'application/json',
        ])->post($this->baseUrl . $uri, $data);
    }

    /**
     * Extract readable error message
     */
    protected function extractErrorMessage(Response $response): string
    {
        $body = $response->json();

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
        return $this->paymentUrl;
    }
}