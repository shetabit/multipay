<?php

namespace Shetabit\Multipay\Drivers\TorobPay;

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

class TorobPay extends Driver
{
    private const OAUTH_URL = '/api/online/v1/oauth/token';
    const PURCHASE_URL = '/api/online/payment/v1/token';
    const VERIFY_URL = '/api/online/payment/v1/verify';
    const REVERT_URL = '/api/online/payment/v1/revert';
    const STATUS_URL = '/api/online/payment/v1/status?paymentToken=';
    const CANCEL = '/api/online/payment/v1/cancel';

    /**
     * Digipay Client.
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
     * Torobpay payment url
     *
     * @var string
     */
    protected $apiUrl;

    /**
     * Torobpay Oauth Token
     *
     * @var string
     */
    protected $oauthToken;

    /**
     * Torobpay payment url
     *
     * @var string
     */
    protected $paymentUrl;


    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
        $this->client = new Client();
        $this->oauthToken = $this->getJwtToken();
    }

    /**
     * @throws PurchaseFailedException
     */
    public function purchase()
    {
        $phone = $this->invoice->getDetail('phone')
            ?? $this->invoice->getDetail('cellphone')
            ?? $this->invoice->getDetail('mobile');


        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1);
        $transactionId = $this->invoice->getUuid();
        $returnUrl = $this->settings->callbackUrl;


        $cartList = $this->generateCartList();

        $payload = [
            'amount' => $amount,
            'paymentMethodTypeDto' => 'ONLINE_CREDIT',
            'returnURL' => $returnUrl,
            'transactionId' => $transactionId,
            'cartList' => $cartList,
        ];

        if ($phone) {
            $payload['mobile'] = $phone;
        }

        $response = $this->client->request(
            'POST',
            $this->settings->api_url . self::PURCHASE_URL,
            [
                RequestOptions::BODY => json_encode($payload),
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->oauthToken,
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);
        if ($response->getStatusCode() != 200) {
            // error has happened
            $message = $body['result']['message'] ?? 'خطا در هنگام درخواست برای پرداخت رخ داده است.';
            throw new PurchaseFailedException($message);
        }


        $this->invoice->transactionId($body['response']['paymentToken']);
        $this->setPaymentUrl($body['response']['paymentPageUrl']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }


    public function pay(): RedirectionForm
    {
        return $this->redirectWithForm($this->paymentUrl, ['payment_token' => $this->invoice->getTransactionId()], 'GET');
    }

    /**
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $tracingId = $this->invoice->getTransactionId() ?? Request::input('transactionId');
        $response = $this->client->request(
            'POST',
            $this->settings->api_url . self::VERIFY_URL,
            [
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->oauthToken,
                ],
                RequestOptions::JSON => [
                    'paymentToken' => $tracingId,
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() !== 200 || !($body['successful'] ?? false)) {
            $message = $body['error']['message'] ?? 'تراکنش تایید نشد';
            throw new InvalidPaymentException($message, (int)$response->getStatusCode());
        }


        return (new Receipt('torobpay', $body['response']['transactionId']))
            ->detail($body);
    }

    public function revert(string $paymentToken): bool
    {
        $response = $this->client->request(
            'POST',
            $this->settings->api_url . self::REVERT_URL,
            [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $this->oauthToken,
                    'Content-Type' => 'application/json',
                ],
                RequestOptions::JSON => [
                    'paymentToken' => $paymentToken,
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        return $response->getStatusCode() === 200 && ($body['successful'] ?? false);
    }

    public function status(string $paymentToken): string
    {
        $response = $this->client->request(
            'GET',
            $this->settings->api_url . self::STATUS_URL . $paymentToken,
            [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $this->oauthToken,
                    'Accept' => 'application/json',
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() !== 200 || !($body['successful'] ?? false)) {
            return 'UNKNOWN';
        }

        return $body['response']['status'] ?? 'UNKNOWN';
    }


    public function cancel(string $paymentToken): bool
    {
        $response = $this->client->request(
            'POST',
            $this->settings->api_url . self::CANCEL,
            [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $this->oauthToken,
                    'Content-Type' => 'application/json',
                ],
                RequestOptions::JSON => [
                    'paymentToken' => $paymentToken,
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        return $response->getStatusCode() === 200 && ($body['successful'] ?? false);
    }


    protected function getJwtToken()
    {
        $response = $this->client->request(
            'POST',
            $this->settings->api_url . self::OAUTH_URL,
            [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Basic ' . base64_encode("{$this->settings->client_id}:{$this->settings->client_secret}"),
                ],
                RequestOptions::MULTIPART => [
                    [
                        'name' => 'username',
                        'contents' => $this->settings->username,
                    ],
                    [
                        'name' => 'password',
                        'contents' => $this->settings->password,
                    ],
                    [
                        'name' => 'grant_type',
                        'contents' => 'password',
                    ],
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );

        if ($response->getStatusCode() != 200) {
            if ($response->getStatusCode() == 401) {
                throw new PurchaseFailedException('خطا نام کاربری یا رمز عبور شما اشتباه می‌باشد.');
            }
            throw new PurchaseFailedException('خطا در هنگام احراز هویت.');
        }

        $body = json_decode($response->getBody()->getContents(), true);

        $this->oauthToken = $body['access_token'];

        return $body['access_token'];
    }

    protected function generateCartList()
    {
        $items = $this->invoice->getDetails()['cartItems'] ?? [];


        return [
            [
                'cartId' => $this->invoice->getUuid(),
                'totalAmount' => $this->invoice->getAmount(),
                'tax_amount' => $this->invoice->getDetail('tax_amount') ?? 0,
                'shipping_amount' => $this->invoice->getDetail('shipping_amount') ?? 0,
                'is_tax_included' => $this->invoice->getDetail('is_tax_included') ?? false,
                'is_shipment_included' => $this->invoice->getDetail('is_shipment_included') ?? false,
                'cartItems' => array_map(function ($item) {
                    $cartItem = [
                        'id' => (string)$item['id'],
                        'name' => $item['name'] ?? $item['title'],
                        'count' => $item['count'] ?? $item['quantity'],
                        'amount' => $item['amount'] ?? $item['price'],
                        'category' => $item['category'],
                    ];

                    if (isset($item['comission_type']) && $item['comission_type'] !== '') {
                        $cartItem['comission_type'] = (int)$item['comission_type'];
                    }

                    return $cartItem;
                }, $items),
            ]
        ];
    }


    private function setPaymentUrl(string $paymentUrl): void
    {
        $this->paymentUrl = $paymentUrl;
    }
}
