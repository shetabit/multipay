<?php

namespace Shetabit\Multipay\Drivers\Pasargad;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Shetabit\Multipay\Drivers\Pasargad\PasargadHolder\PasargadHolder;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\RedirectionForm;
use DateTimeZone;
use DateTime;

class Pasargad extends Driver
{
    /**
     * Guzzle client
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
     * Prepared invoice's data
     *
     * @var array
     */
    protected $preparedData = array();

    /**
     * Pasardad Holder
     * @var string
     */
    protected $holder;

    /**
     * Pasargad(PEP) constructor.
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
        $this->holder = new PasargadHolder();
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     * @throws InvalidPaymentException
     * @throws GuzzleException
     * @throws \Exception
     */
    public function purchase(): string
    {
        $invoiceData = $this->getPreparedInvoiceData();

        $this->invoice->transactionId($invoiceData['invoice']);

        $response = $this->request(
            $this->settings->apiPaymentUrl,
            $invoiceData,
            'POST',
            $this->createToken()
        );

        if ($response['data']['urlId']) {
            $this->holder->urlId($response['data']['urlId']);
        } else {
            throw new InvalidPaymentException("urlId is not set.");
        }

        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $paymentUrl = $this->settings->apiBaseUrl . $this->holder->getUrlId();

        // redirect using HTML form
        return $this->redirectWithForm($paymentUrl, ['Token' => $this->holder->getUrlId()]);
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \Exception
     * @throws GuzzleException
     */
    public function verify(): ReceiptInterface
    {

        $transactionId = $this->invoice->getTransactionId();

        $payment_inquiry = $this->request(
            $this->settings->paymentInquiry,
            ['invoiceId' => $transactionId],
            'POST',
            $this->createToken()
        );

        if ($payment_inquiry['resultCode'] != 0) {
            throw new InvalidPaymentException("This transaction is fail.");
        }

        $verifyResult = $this->request(
            $this->settings->verifyPayment,
            [
                'invoice' => $transactionId,
                'urlId' => $payment_inquiry['data']['url']
            ],
            'POST',
            $this->createToken()
        );

        if (!$verifyResult['data']['referenceNumber']) {
            throw new InvalidPaymentException("This transaction is fail.");
        }

        $receipt =  $this->createReceipt($$verifyResult['data']['referenceNumber']);

        $receipt->detail([
            'resultCode' => $verifyResult['resultCode'],
            'resultMsg' => $verifyResult['resultMsg'] ?? null,
            'hashedCardNumber' => $verifyResult['data']['hashedCardNumber'] ?? null,
            'maskedCardNumber' => $verifyResult['data']['maskedCardNumber'] ?? null,
            'invoiceId' => $transactionId,
            'referenceNumber' =>  $verifyResult['data']['referenceNumber'] ?? null,
            'trackId' =>  $verifyResult['data']['trackId'] ?? null,
            'amount' =>  $verifyResult['data']['amount'] ?? null,
            'requestDate' => $verifyResult['data']['requestDate'] ?? null,
        ]);

        return $receipt;
    }


    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     * @return Receipt
     */
    protected function createReceipt($referenceId): Receipt
    {
        return new Receipt('pasargad', $referenceId);
    }

    /**
     * Retrieve prepared invoice's data
     *
     * @return array
     * @throws \Exception
     */
    protected function getPreparedInvoiceData(): array
    {
        if (empty($this->preparedData)) {
            $this->preparedData = $this->prepareInvoiceData();
        }

        return $this->preparedData;
    }

    /**
     * Prepare invoice data
     *
     * @return array
     * @throws \Exception
     */
    protected function prepareInvoiceData(): array
    {
        $action = 8; // 8 : for buy request (bank standard)
        $terminalCode = $this->settings->terminalCode;
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1);
        $redirectAddress = $this->settings->callbackUrl;
        $invoiceNumber = crc32($this->invoice->getUuid()) . rand(0, time());

        $iranTime = new DateTime('now', new DateTimeZone('Asia/Tehran'));
        $invoiceDate = $iranTime->format("Y/m/d H:i:s");

        if (!empty($this->invoice->getDetails()['date'])) {
            $invoiceDate = $this->invoice->getDetails()['date'];
        }

        return [
            'invoice' => $invoiceNumber,
            'invoiceDate' => $invoiceDate,
            'amount' => $amount,
            'terminalNumber' => $terminalCode,
            'callbackApi' => $redirectAddress,
            'serviceCode' => $action,
            'nationalCode' => "",
            'serviceType' => "PURCHASE",
            'mobileNumber' => ""
        ];
    }

    /**
     * Make request to pasargad's Api
     *
     * @param string $url
     * @param array $body
     * @param string $method
     * @param string|null $token
     * @return array
     * @throws GuzzleException
     * @throws InvalidPaymentException
     */
    protected function request(string $url, array $body, string $method = 'POST', string $token = null): array
    {
        $body = json_encode($body);
        $token = $token != null ? 'Bearer '.$token : null;

        $response = $this->client->request(
            'POST',
            $url,
            [
                'body' => $body,
                'headers' => [
                    'content-type' => 'application/json',
                    'Authorization' => $token
                ],
                "http_errors" => false,
            ]
        );

        $result = json_decode($response->getBody(), true);

        if ($result['resultMsg'] !== 'Successful') {
            throw new InvalidPaymentException($result['resultMsg']);
        }

        return $result;
    }

    /**
     * * make token with username and password
     * @return string
     * @throws InvalidPaymentException
     */
    protected function createToken(): string
    {
        $data = [
            "username" => $this->settings->username,
            "password" => $this->settings->password
        ];

        $getTokenUrl = $this->settings->apiGetToken;

        try {
            return $this->request(
                $getTokenUrl,
                $data
            )['token'];
        } catch (GuzzleException|InvalidPaymentException $e) {
            throw new InvalidPaymentException($e->getMessage());
        }
    }
}
