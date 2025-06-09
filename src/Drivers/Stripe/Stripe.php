<?php

namespace Shetabit\Multipay\Drivers\Stripe;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Stripe extends Driver
{
    protected Client $client;
    protected $invoice;
    protected $settings;
    protected string $checkoutUrl;
    protected string $checkoutSessionId;

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;

        $this->client = new Client([
            'base_uri' => 'https://api.stripe.com/v1/',
            'auth' => [$this->settings->secret, ''],
        ]);
    }

    public function purchase(): string
    {
        $response = $this->client->post('checkout/sessions', [
            'form_params' => [
                'payment_method_types[]' => 'card',
                'line_items[0][price_data][currency]' => $this->settings->currency ?? 'usd',
                'line_items[0][price_data][unit_amount]' => $this->invoice->getAmount(),
                'line_items[0][price_data][product_data][name]' => 'Order #' . uniqid(),
                'line_items[0][quantity]' => 1,
                'mode' => 'payment',
                'success_url' => $this->settings->success_url . "?session_id={CHECKOUT_SESSION_ID}",
                'cancel_url' => $this->settings->cancel_url . "?session_id={CHECKOUT_SESSION_ID}",
            ],
        ]);

        $data = json_decode((string) $response->getBody());

        if (!isset($data->id) || !isset($data->url)) {
            throw new PurchaseFailedException('خطا در دریافت اطلاعات پرداخت Stripe');
        }

        $this->invoice->transactionId($data->id);
        $this->checkoutUrl = $data->url;
        $this->checkoutSessionId = $data->id;

        return $data->id;
    }

    public function pay(): RedirectionForm
    {
        return new RedirectionForm($this->checkoutUrl, [], 'GET');
    }

    public function verify(): ReceiptInterface
    {
        $sessionId = Request::input('session_id');

        if (empty($sessionId)) {
            throw new InvalidPaymentException('Session ID یافت نشد.');
        }

        $response = $this->client->get("checkout/sessions/{$sessionId}");
        $session = json_decode((string) $response->getBody());

        if (($session->payment_status ?? '') !== 'paid') {
            throw new InvalidPaymentException('پرداخت موفق نبود.');
        }

        $refId = $session->payment_intent ?? $session->id;

        return $this->createReceipt($refId);
    }

    protected function createReceipt($refId): Receipt
    {
        return new Receipt('stripe', $refId);
    }
}
