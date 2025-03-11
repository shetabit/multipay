<?php

namespace Shetabit\Multipay\Drivers\Tara;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Exceptions\TimeoutException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;

class Tara extends Driver
{
    const VERSION = '4.0';
    const RELEASE_DATE = '2023-01-12';
    const OAUTH_URL = 'api/v2/authenticate';
    const CLUB_GROUP = 'api/clubGroups';
    const TOKEN_URL = 'api/getToken';
    const PURCHASE_URL = 'api/ipgPurchase';
    const VERIFY_URL = 'api/purchaseVerify';
    const INQUIRY_URL = 'api/purchaseInquiry';

    /**
     * Tara Client.
     */
    protected Client $client;

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
     * Tara Oauth Data
     *
     * @var string
     */
    protected mixed $oauthToken;

    /**
     * Tara payment url
     *
     * @var string
     */
    protected string $paymentUrl;

    /**
     * Tara constructor.
     * Construct the class with the relevant settings.
     * @throws PurchaseFailedException|GuzzleException
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
        $this->client = new Client();
        $this->oauthToken = $this->oauth();
    }

    /**
     * @throws PurchaseFailedException|GuzzleException
     */
    protected function oauth()
    {
        $data = [
            'username' => $this->settings->username,
            'password' => $this->settings->password,
        ];

        $response = $this->client->post($this->settings->apiPaymentUrl . self::OAUTH_URL, [
            RequestOptions::BODY => json_encode($data),
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => 10,
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
            ],
        ]);
        if ($response->getStatusCode() != 200) {
            throw new PurchaseFailedException('خطا در هنگام احراز هویت.');
        }

        $body = json_decode($response->getBody()->getContents(), true);

        return $body['accessToken'];
    }

    /**
     * @throws PurchaseFailedException|GuzzleException
     */
    public function purchase(): string
    {
        $phone = $this->invoice->getDetail('phone') ?? $this->invoice->getDetail('cellphone') ?? $this->invoice->getDetail('mobile');

        $data = [
            'ip' => $this->getClientIpAddress(),
            'amount' => $this->normalizerAmount($this->invoice->getAmount()),
            'mobile' => $phone,
            'orderId' => $this->invoice->getUuid(),
            'callBackUrl' => $this->settings->callbackUrl,
            'vat' => $this->invoice->getDetail('vat') ?? '',
            'additionalData' => 'Oder number #' . $this->invoice->getUuid(),
        ];

        if (empty($this->invoice->getDetail('serviceAmountList'))) {
            $data['serviceAmountList'][0]['serviceId'] = $this->settings->serviceId ?? 101;
            $data['serviceAmountList'][0]['amount'] = $this->normalizerAmount($this->invoice->getAmount());
        }

        if (is_null($this->invoice->getDetail('taraInvoiceItemList'))) {
            throw new PurchaseFailedException('"taraInvoiceItemList" is required for this driver');
        }

        $data['taraInvoiceItemList'] = $this->invoice->getDetail('taraInvoiceItemList');

        $this->normalizerCartList($data);

        $response = $this->client->post($this->settings->apiPaymentUrl . self::TOKEN_URL, [
            RequestOptions::BODY => json_encode($data),
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => 10,
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->oauthToken,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200 || $body['result'] != 0) {
            // error has happened
            $message = $body['description'] ?? 'خطا در هنگام درخواست برای پرداخت رخ داده است.';
            throw new PurchaseFailedException($message);
        }

        $this->invoice->transactionId($body['token']);
        $query = http_build_query([
            'token' => $body['token'],
            'username' => $this->settings->username,
        ]);
        $this->setPaymentUrl($this->settings->apiPaymentUrl . self::PURCHASE_URL . '?' . $query);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    private function getClientIpAddress(): string
    {
        if (getenv('HTTP_CLIENT_IP')) {
            $ipaddress = getenv('HTTP_CLIENT_IP');
        } elseif (getenv('HTTP_X_FORWARDED_FOR')) {
            $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
        } elseif (getenv('HTTP_X_FORWARDED')) {
            $ipaddress = getenv('HTTP_X_FORWARDED');
        } elseif (getenv('HTTP_FORWARDED_FOR')) {
            $ipaddress = getenv('HTTP_FORWARDED_FOR');
        } elseif (getenv('HTTP_FORWARDED')) {
            $ipaddress = getenv('HTTP_FORWARDED');
        } elseif (getenv('REMOTE_ADDR')) {
            $ipaddress = getenv('REMOTE_ADDR');
        } else {
            $ipaddress = 'UNKNOWN';
        }

        return $ipaddress;
    }

    private function normalizerAmount(int $amount): int
    {
        return $amount * ($this->settings->currency == 'T' ? 10 : 1);
    }

    private function normalizerCartList(array &$data): void
    {
        foreach ($data['taraInvoiceItemList'] as &$item) {
            if (isset($item['fee'])) {
                $item['fee'] = $this->normalizerAmount($item['fee']);
            }
        }
    }

    private function setPaymentUrl(string $paymentUrl): void
    {
        $this->paymentUrl = $paymentUrl;
    }

    public function pay(): RedirectionForm
    {
        parse_str(parse_url($this->paymentUrl, PHP_URL_QUERY), $formData);

        return $this->redirectWithForm($this->paymentUrl, $formData, 'GET');
    }

    /**
     * @throws TimeoutException
     * @throws PurchaseFailedException|GuzzleException
     */
    public function verify(): ReceiptInterface
    {
        $data = [
            'ip' => $this->getClientIpAddress(),
            'token' => $this->invoice->getTransactionId(),
        ];

        try {
            $response = $this->client->post($this->settings->apiPaymentUrl . self::VERIFY_URL, [
                RequestOptions::BODY => json_encode($data),
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => 60,
                RequestOptions::HEADERS => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->oauthToken,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if ($response->getStatusCode() != 200 || $body['result'] != 0) {
                // error has happened
                $message = $body['description'] ?? 'خطا در هنگام تایید تراکنش';
                throw new PurchaseFailedException($message);
            }

            return (new Receipt('Tara', $body['rrn']))->detail($body);
        } catch (ConnectException) {
            $inquiry_response = $this->inquiry();

            if (isset($inquiry_response['result']) && $inquiry_response['result'] == 0) {
                return (new Receipt('Tara', $inquiry_response['rrn']))->detail($inquiry_response);
            }

            throw new TimeoutException('پاسخی از درگاه دریافت نشد.');
        }
    }

    /**
     * @throws PurchaseFailedException|GuzzleException
     */
    public function inquiry()
    {
        $data = [
            'ip' => $this->getClientIpAddress(),
            'token' => $this->invoice->getTransactionId(),
        ];

        $response = $this->client->post($this->settings->apiPaymentUrl . self::INQUIRY_URL, [
            RequestOptions::BODY => json_encode($data),
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => 10,
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->oauthToken,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200 || $body['trackPurchaseList']['result'] != 0) {
            // error has happened
            $message = $body['description'] ?? 'خطا در status تراکنش';
            throw new PurchaseFailedException($message);
        }

        return $body['trackPurchaseList'];
    }
}
