<?php


namespace Shetabit\Multipay\Drivers\Snapppay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Snapppay extends Driver
{
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
     * snapppay Oauth Token
     *
     * @var string
     */
    protected $oauthToken;

    /**
     * Snapppay payment url
     *
     * @var string
     */
    protected $paymentUrl;
    protected $paymentToken;

    /**
     * Snapppay constructor.
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
        $this->oauthToken = $this->oauth();
    }

    /**
     * @throws PurchaseFailedException
     */
    public function purchase(): string
    {
        $phone = $this->invoice->getDetail('phone') ?? $this->invoice->getDetail('mobile');
        $phoneNumber = preg_replace('/\D/', '', $phone);

        // Check if the number starts with '0' and is 11 digits long
        if (strlen($phoneNumber) === 11 && $phoneNumber[0] === '0') {
            // Replace the leading '0' with '+98'
            $phoneNumber = '+98' . substr($phoneNumber, 1);
        }
        $data = [
            "amount" => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1),
            "cartList" => [
                [
                    "cartId" => 1,
                    "cartItems" => [
                        [
                            "amount" => 0,
                            "category" => "string",
                            "count" => 1,
                            "id" => 0,
                            "name" => "string",
                            "commissionType" => 0
                        ]
                    ],
                    "isShipmentIncluded" => false,
                    "isTaxIncluded" => false,
                    "shippingAmount" => 0,
                    "taxAmount" => 0,
                    "totalAmount" => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1),
                ]
            ],


            "discountAmount" => 0,
            "externalSourceAmount" => 0,
            "mobile" => $phoneNumber,
            "paymentMethodTypeDto" => "INSTALLMENT",
            "returnURL" => $this->settings->callbackUrl,
            "transactionId" => $this->invoice->getUuid()
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiPurchaseUrl,
            [
                RequestOptions::BODY => json_encode($data),
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
        parse_str(parse_url($this->getPaymentUrl(), PHP_URL_QUERY), $formData);
        return $this->redirectWithForm($this->getPaymentUrl(), $formData, 'GET');
    }

    /**
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                RequestOptions::BODY => json_encode(['paymentToken' => $this->invoice->getTransactionId()]),
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->oauthToken,
                ],
                RequestOptions::HTTP_ERRORS => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents(), true);
        if ($response->getStatusCode() != 200) {
            $message = $body['result']['message'] ?? 'تراکنش تایید نشد';
            throw new InvalidPaymentException($message, (int)$response->getStatusCode());
        }

        return (new Receipt('snapppay', $body["response"]['transactionId']))->detail($body);
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
                $this->settings->apiOauthUrl,
                [
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Basic ' . base64_encode("{$this->settings->client_id}:{$this->settings->client_secret}"),
                    ],
                    RequestOptions::MULTIPART => [
                        [
                            "name" => "username",
                            "contents" => $this->settings->username,
                        ],
                        [
                            "name" => "password",
                            "contents" => $this->settings->password,
                        ],
                        [
                            "name" => "grant_type",
                            "contents" => 'password',
                        ],
                    ],
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );


        if ($response->getStatusCode() != 200) {
            if ($response->getStatusCode() == 401) {
                throw new PurchaseFailedException("خطا نام کاربری یا رمز عبور شما اشتباه می باشد.");
            } else {
                throw new PurchaseFailedException("خطا در هنگام احراز هویت.");
            }
        }


        $body = json_decode($response->getBody()->getContents(), true);
        $this->oauthToken = $body['access_token'];
        return $body['access_token'];
    }

    /**
     * @return string
     */
    public function getPaymentUrl(): string
    {
        return $this->paymentUrl;
    }

    public function getPaymentToken(): string
    {
        return $this->paymentToken;
    }

    /**
     * @param string $paymentUrl
     */
    public function setPaymentUrl(string $paymentUrl): void
    {
        $this->paymentUrl = $paymentUrl;
    }

    public function setPaymentToken(string $paymentToken): void
    {
        $this->paymentToken = $paymentToken;
    }
}
