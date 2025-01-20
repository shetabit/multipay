<?php

namespace Shetabit\Multipay\Drivers\Pasargad;

use GuzzleHttp\Client;
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
     * Prepared invoice's data
     *
     * @var array
     */
    protected $preparedData = [];

    /**
     * Pasargad(PEP) constructor.
     * Construct the class with the relevant settings.
     *
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     */
    public function purchase()
    {
        $invoiceData = $this->getPreparedInvoiceData();

        $this->invoice->transactionId($invoiceData['InvoiceNumber']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay() : RedirectionForm
    {
        $paymentUrl = $this->settings->apiPaymentUrl;
        $getTokenUrl = $this->settings->apiGetToken;
        $tokenData = $this->request($getTokenUrl, $this->getPreparedInvoiceData());

        // redirect using HTML form
        return $this->redirectWithForm($paymentUrl, $tokenData, 'POST');
    }

    /**
     * Verify payment
     *
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $invoiceDetails = $this->request(
            $this->settings->apiCheckTransactionUrl,
            [
                'TransactionReferenceID' => Request::input('tref')
            ]
        );
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        if ($amount != $invoiceDetails['Amount']) {
            throw new InvalidPaymentException('Invalid amount');
        }
        $iranTime = new DateTime('now', new DateTimeZone('Asia/Tehran'));
        $fields = [
            'MerchantCode' => $invoiceDetails['MerchantCode'],
            'TerminalCode' => $invoiceDetails['TerminalCode'],
            'InvoiceNumber' => $invoiceDetails['InvoiceNumber'],
            'InvoiceDate' => $invoiceDetails['InvoiceDate'],
            'Amount' => $invoiceDetails['Amount'],
            'Timestamp' => $iranTime->format("Y/m/d H:i:s"),
        ];

        $verifyResult = $this->request($this->settings->apiVerificationUrl, $fields);

        return $this->createReceipt($verifyResult, $invoiceDetails);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     */
    protected function createReceipt(array $verifyResult, array $invoiceDetails): \Shetabit\Multipay\Receipt
    {
        $referenceId = $invoiceDetails['TransactionReferenceID'];
        $traceNumber = $invoiceDetails['TraceNumber'];
        $referenceNumber = $invoiceDetails['ReferenceNumber'];

        $reciept = new Receipt('Pasargad', $referenceId);

        $reciept->detail('TraceNumber', $traceNumber);
        $reciept->detail('ReferenceNumber', $referenceNumber);
        $reciept->detail('MaskedCardNumber', $verifyResult['MaskedCardNumber']);
        $reciept->detail('ShaparakRefNumber', $verifyResult['ShaparakRefNumber']);

        return $reciept;
    }

    /**
     * A default message for exceptions
     */
    protected function getDefaultExceptionMessage(): string
    {
        return 'مشکلی در دریافت اطلاعات از بانک به وجود آمده است';
    }

    /**
     * Sign given data.
     *
     * @param string $data
     *
     * @return string
     */
    public function sign($data)
    {
        $certificate = $this->settings->certificate;
        $certificateType = $this->settings->certificateType;

        $processor = new RSAProcessor($certificate, $certificateType);

        return $processor->sign($data);
    }

    /**
     * Retrieve prepared invoice's data
     *
     * @return array
     */
    protected function getPreparedInvoiceData()
    {
        if ($this->preparedData === []) {
            $this->preparedData = $this->prepareInvoiceData();
        }

        return $this->preparedData;
    }

    /**
     * Prepare invoice data
     */
    protected function prepareInvoiceData(): array
    {
        $action = 1003; // 1003 : for buy request (bank standard)
        $merchantCode = $this->settings->merchantId;
        $terminalCode = $this->settings->terminalCode;
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        $redirectAddress = $this->settings->callbackUrl;
        $invoiceNumber = crc32($this->invoice->getUuid()) . random_int(0, time());

        $iranTime = new DateTime('now', new DateTimeZone('Asia/Tehran'));
        $timeStamp = $iranTime->format("Y/m/d H:i:s");
        $invoiceDate = $iranTime->format("Y/m/d H:i:s");

        if (!empty($this->invoice->getDetails()['date'])) {
            $invoiceDate = $this->invoice->getDetails()['date'];
        }

        return [
            'InvoiceNumber' => $invoiceNumber,
            'InvoiceDate' => $invoiceDate,
            'Amount' => $amount,
            'TerminalCode' => $terminalCode,
            'MerchantCode' => $merchantCode,
            'RedirectAddress' => $redirectAddress,
            'Timestamp' => $timeStamp,
            'Action' => $action,
        ];
    }

    /**
     * Prepare signature based on Pasargad document
     */
    public function prepareSignature(string $data): string
    {
        return base64_encode($this->sign(sha1($data, true)));
    }

    /**
     * Make request to pasargad's Api
     *
     * @param string $method
     */
    protected function request(string $url, array $body, $method = 'POST'): array
    {
        $body = json_encode($body);
        $sign = $this->prepareSignature($body);

        $response = $this->client->request(
            'POST',
            $url,
            [
                'body' => $body,
                'headers' => [
                    'content-type' => 'application/json',
                    'Sign' => $sign
                ],
                "http_errors" => false,
            ]
        );

        $result = json_decode($response->getBody(), true);

        if ($result['IsSuccess'] === false) {
            throw new InvalidPaymentException($result['Message']);
        }

        return $result;
    }
}
