<?php


namespace Shetabit\Multipay\Drivers\Digipay;

use DateTime;
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

class Digipay extends Driver
{
    const VERSION = '2022-02-02';
    const OAUTH_URL = '/digipay/api/oauth/token';
    const PURCHASE_URL = '/digipay/api/tickets/business';
    const VERIFY_URL = '/digipay/api/purchases/verify/';
    const REVERSE_URL = '/digipay/api/reverse';
    const DELIVER_URL = '/digipay/api/purchases/deliver';
    const REFUNDS_CONFIG = '/digipay/api/refunds/config';
    const REFUNDS_REQUEST = '/digipay/api/refunds';

    /**
     * Digipay Client.
     *
     * @var Client
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
     * Digipay Oauth Token
     *
     * @var string
     */
    protected $oauthToken;

    /**
     * Digipay payment url
     *
     * @var string
     */
    protected $paymentUrl;

    /**
     * Digipay constructor.
     * Construct the class with the relevant settings.
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
        $this->oauthToken = $this->oauth();
    }

    /**
     * @throws PurchaseFailedException
     */
    public function purchase(): string
    {
        $phone = $this->invoice->getDetail('phone')
            ?? $this->invoice->getDetail('cellphone')
            ?? $this->invoice->getDetail('mobile');

        /**
         * @see https://docs.mydigipay.com/upg.html#_request_fields_2
         */
        $data = [
            'amount' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1),
            'cellNumber' => $phone,
            'providerId' => $this->invoice->getUuid(),
            'callbackUrl' => $this->settings->callbackUrl,
        ];

        if (!is_null($basketDetailsDto = $this->invoice->getDetail('basketDetailsDto'))) {
            $data['basketDetailsDto'] = $basketDetailsDto;
        }

        if (!is_null($preferredGateway = $this->invoice->getDetail('preferredGateway'))) {
            $data['preferredGateway'] = $preferredGateway;
        }

        if (!is_null($splitDetailsList = $this->invoice->getDetail('splitDetailsList'))) {
            $data['splitDetailsList'] = $splitDetailsList;
        }

        /**
         * @see https://docs.mydigipay.com/upg.html#_query_parameters_2
         */
        $digipayType = $this->invoice->getDetail('digipayType') ?? 11;

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPaymentUrl.self::PURCHASE_URL,
                [
                    RequestOptions::BODY => json_encode($data),
                    RequestOptions::QUERY => ['type' => $digipayType],
                    RequestOptions::HEADERS => [
                        'Agent' => $this->invoice->getDetail('agent') ?? 'WEB',
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer '.$this->oauthToken,
                        'Digipay-Version' => self::VERSION,
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

        $this->invoice->transactionId($body['ticket']);
        $this->setPaymentUrl($body['redirectUrl']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    public function pay(): RedirectionForm
    {
        return $this->redirectWithForm($this->getPaymentUrl(), [], 'GET');
    }

    /**
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        /**
         * @see https://docs.mydigipay.com/upg.html#ticket_type
         */
        $digipayTicketType = Request::input('type');
        $tracingId = Request::input('trackingCode');

        $response = $this->client->request(
            'POST',
            $this->settings->apiPaymentUrl.self::VERIFY_URL.$tracingId,
            [
                RequestOptions::QUERY => ['type' => $digipayTicketType],
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$this->oauthToken,
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200) {
            $message = $body['result']['message'] ?? 'تراکنش تایید نشد';
            throw new InvalidPaymentException($message, (int) $response->getStatusCode());
        }

        return (new Receipt('digipay', $body["trackingCode"]))->detail($body);
    }

    /**
     * @throws PurchaseFailedException
     */
    protected function oauth()
    {
        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPaymentUrl.self::OAUTH_URL,
                [
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Basic '.base64_encode("{$this->settings->client_id}:{$this->settings->client_secret}"),
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
            } else {
                throw new PurchaseFailedException('خطا در هنگام احراز هویت.');
            }
        }

        $body = json_decode($response->getBody()->getContents(), true);

        $this->oauthToken = $body['access_token'];

        return $body['access_token'];
    }

    /**
     * @see https://docs.mydigipay.com/upg.html#_purchase_reverse
     *
     * @throws PurchaseFailedException
     */
    public function reverse()
    {
        if (is_null($digipayTicketType = $this->invoice->getDetail('type'))) {
            throw new PurchaseFailedException('"type" is required for this method.');
        }

        if (is_null($trackingCode = $this->invoice->getDetail('trackingCode'))) {
            throw new PurchaseFailedException('"trackingCode" is required for this method.');
        }

        $data = [
            'trackingCode' => $trackingCode,
            'providerId' => $this->invoice->getTransactionId() ?? $this->invoice->getDetail('providerId') ?? $this->invoice->getUuid(),
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPaymentUrl.self::REVERSE_URL,
                [
                    RequestOptions::BODY => json_encode($data),
                    RequestOptions::QUERY => ['type' => $digipayTicketType],
                    RequestOptions::HEADERS => [
                        'Content-Type' => 'application/json;charset=UTF-8',
                        'Authorization' => 'Bearer '.$this->oauthToken,
                    ],
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200 || (isset($body['result']['code']) && $body['result']['code'] != 0)) {
            $message = $body['result']['message'] ?? 'خطا در هنگام درخواست برای برگشت وجه رخ داده است.';
            throw new InvalidPaymentException($message, (int) $response->getStatusCode());
        }

        return $body;
    }

    /**
     * @see https://docs.mydigipay.com/upg.html#_purchase_delivery
     *
     * @throws PurchaseFailedException
     */
    public function deliver()
    {
        if (empty($type = $this->invoice->getDetail('type'))) {
            throw new PurchaseFailedException('"type" is required for this method.');
        }

        if (!in_array($type, [5, 13])) {
            throw new PurchaseFailedException('This method is not supported for this type.');
        }

        if (empty($invoiceNumber = $this->invoice->getDetail('invoiceNumber'))) {
            throw new PurchaseFailedException('"invoiceNumber" is required for this method.');
        }

        if (empty($deliveryDate = $this->invoice->getDetail('deliveryDate'))) {
            throw new PurchaseFailedException('"deliveryDate" is required for this method.');
        }

        if (!DateTime::createFromFormat('Y-m-d', $deliveryDate)) {
            throw new PurchaseFailedException('"deliveryDate" must be a valid date with format Y-m-d.');
        }

        if (empty($trackingCode = $this->invoice->getDetail('trackingCode'))) {
            throw new PurchaseFailedException('"trackingCode" is required for this method.');
        }

        if (empty($products = $this->invoice->getDetail('products'))) {
            throw new PurchaseFailedException('"products" is required for this method.');
        }

        if (!is_array($products)) {
            throw new PurchaseFailedException('"products" must be an array.');
        }

        $data = [
            'invoiceNumber' => $invoiceNumber,
            'deliveryDate' => $deliveryDate,
            'trackingCode' => $trackingCode,
            'products' => $products,
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPaymentUrl.self::DELIVER_URL,
                [
                    RequestOptions::BODY => json_encode($data),
                    RequestOptions::QUERY => ['type' => $type],
                    RequestOptions::HEADERS => [
                        'Content-Type' => 'application/json;charset=UTF-8',
                        'Authorization' => 'Bearer '.$this->oauthToken,
                    ],
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200 || (isset($body['result']['code']) && $body['result']['code'] != 0)) {
            $message = $body['result']['message'] ?? 'خطا در هنگام درخواست برای تحویل کالا رخ داده است.';
            throw new InvalidPaymentException($message, (int) $response->getStatusCode());
        }

        return $body;
    }

    public function getRefundConfig()
    {
        $response = $this->client->request(
            'POST',
            $this->settings->apiPaymentUrl.self::REFUNDS_CONFIG,
            [
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$this->oauthToken,
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200 || (isset($body['result']['code']) && $body['result']['code'] != 0)) {
            $message = $body['result']['message'] ?? 'خطا در هنگام درخواست برای دریافت تنظیمات مرجوعی رخ داده است.';
            throw new InvalidPaymentException($message, (int) $response->getStatusCode());
        }

        $certFile = $response['certFile'];

        return $certFile;
    }

    public function refundTransaction()
    {
        if (empty($type = $this->invoice->getDetail('type'))) {
            throw new PurchaseFailedException('"type" is required for this method.');
        }

        if (empty($providerId = $this->invoice->getDetail('providerId'))) {
            throw new PurchaseFailedException('"providerId" is required for this method.');
        }

        if (empty($amount = $this->invoice->getDetail('amount'))) {
            throw new PurchaseFailedException('"amount" is required for this method.');
        }

        if (empty($saleTrackingCode = $this->invoice->getDetail('saleTrackingCode'))) {
            throw new PurchaseFailedException('"saleTrackingCode" is required for this method.');
        }

        $data = [
            'providerId' => $providerId,
            'amount' => $amount,
            'saleTrackingCode' => $saleTrackingCode,
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPaymentUrl.self::REFUNDS_REQUEST,
                [
                    RequestOptions::BODY => json_encode($data),
                    RequestOptions::QUERY => ['type' => $type],
                    RequestOptions::HEADERS => [
                        'Content-Type' => 'application/json;charset=UTF-8',
                        'Authorization' => 'Bearer '.$this->oauthToken,
                    ],
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200 || (isset($body['result']['code']) && $body['result']['code'] != 0)) {
            $message = $body['result']['message'] ?? 'خطا در هنگام درخواست مرجوعی تراکنش رخ داده است.';
            throw new InvalidPaymentException($message, (int) $response->getStatusCode());
        }

        return $body;
    }

    private function getPaymentUrl(): string
    {
        return $this->paymentUrl;
    }

    private function setPaymentUrl(string $paymentUrl): void
    {
        $this->paymentUrl = $paymentUrl;
    }
}
