<?php

namespace Shetabit\Multipay\Drivers\Pasargad;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Util\Exception;
use Shetabit\Multipay\Drivers\Pasargad\PasargadReducer\PasargadReducer;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Drivers\Pasargad\Utils\RSAProcessor;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
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
     * Pasardad Reducer
     * @var string
     */
    protected $reducer;

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
        $this->reducer = new PasargadReducer();
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     * @throws InvalidPaymentException
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

        $response['data']['urlId'] ? $this->reducer->urlId($response['data']['urlId']) :
            throw  new InvalidPaymentException("urlId is not set.");


        return $this->reducer->getUrlId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $paymentUrl = $this->settings->apiBaseUrl . $this->reducer->getUrlId();
        // redirect using HTML form
        return $this->redirectWithForm($paymentUrl, ['Token' => $this->reducer->getUrlId()], "POST");
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \Exception
     */
    public function verify(): ReceiptInterface
    {
        $invoiceDetails = $this->request(
            $this->settings->confirmTransactions,
            [
                'invoice' => $this->invoice->getTransactionId(),
                'urlId' => $this->reducer->getUrlId()
            ]
        );

        if ( $this->invoice->getAmount() != $invoiceDetails['amount']) {
            throw new InvalidPaymentException('Invalid amount');
        }

        $verifyResult = $this->request($this->settings->verifyPayment, [
            'invoice' => $this->invoice->getTransactionId(),
            'urlId' => $this->reducer->getUrlId()
        ]);

        return $this->createReceipt($verifyResult, $invoiceDetails);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $verifyResult
     * @param $invoiceDetails
     * @return Receipt
     */
    protected function createReceipt($verifyResult, $invoiceDetails): Receipt
    {
        $referenceId = $this->reducer->getUrlId();
        $traceNumber = $invoiceDetails['trackId'];
        $referenceNumber = $invoiceDetails['referenceNumber'];

        $reciept = new Receipt('Pasargad', $referenceId);

        $reciept->detail('TraceNumber', $traceNumber);
        $reciept->detail('ReferenceNumber', $referenceNumber);
        $reciept->detail('MaskedCardNumber', $verifyResult['maskedCardNumber']);
        $reciept->detail('ShaparakRefNumber', $verifyResult['referenceNumber']);

        return $reciept;
    }

    /**
     * Retrieve prepared invoice's data
     *
     * @return array
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
        $amount = $this->invoice->getAmount();
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
            throw new InvalidPaymentException($result['Message']);
        }

        return $result;
    }

    /**
     * * make token with username and password
     * @return string
     */
    protected function createToken(): string
    {
        $data = [
            "username" => $this->settings->username,
            "password" => $this->settings->password
        ];

        $getTokenUrl = $this->settings->apiGetToken;

        return $this->request(
            $getTokenUrl,
            $data
        )['token'];
    }
}
