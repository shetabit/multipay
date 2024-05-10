<?php


namespace Shetabit\Multipay\Drivers\Digipay;

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

class Digipay extends Driver
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
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings= (object) $settings;
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
            'amount'      => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1),
            'cellNumber'  => $phone,
            'providerId'  => $this->invoice->getUuid(),
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
                $this->settings->apiPurchaseUrl,
                [
                    RequestOptions::BODY  => json_encode($data),
                    RequestOptions::QUERY  => ['type' => $digipayType],
                    RequestOptions::HEADERS   => [
                        'Agent' => $this->invoice->getDetail('agent') ?? 'WEB',
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $this->oauthToken,
                        'Digipay-Version' => '2022-02-02',
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
            $this->settings->apiVerificationUrl . $tracingId,
            [
                RequestOptions::QUERY      => ['type' => $digipayTicketType],
                RequestOptions::HEADERS    => [
                    "Accept"        => "application/json",
                    "Authorization" => "Bearer " . $this->oauthToken,
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
                $this->settings->apiOauthUrl,
                [
                    RequestOptions::HEADERS   => [
                        'Authorization' => 'Basic ' . base64_encode("{$this->settings->client_id}:{$this->settings->client_secret}"),
                    ],
                    RequestOptions::MULTIPART   => [
                        [
                            "name"     => "username",
                            "contents" => $this->settings->username,
                        ],
                        [
                            "name"     => "password",
                            "contents" => $this->settings->password,
                        ],
                        [
                            "name"     => "grant_type",
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

    /**
     * @param string $paymentUrl
     */
    public function setPaymentUrl(string $paymentUrl): void
    {
        $this->paymentUrl = $paymentUrl;
    }
}
