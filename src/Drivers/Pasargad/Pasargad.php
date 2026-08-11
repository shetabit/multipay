<?php

namespace Shetabit\Multipay\Drivers\Pasargad;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use DateTimeZone;
use DateTime;

class Pasargad extends Driver
{
    /**
     * Guzzle client
     */
    protected Client $client;

    /**
     * Prepared invoice's data
     */
    protected array $preparedData = [];

    /**
     * Pasargad(PEP) constructor.
     * Construct the class with the relevant settings.
     *
     * @param $settings
     */
    public function __construct(Invoice $invoice, array|object $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Purchase Invoice.
     *
     * @throws \DateMalformedStringException
     */
    public function purchase(): string
    {
        $invoiceData = $this->getPreparedInvoiceData();

        $this->invoice->transactionId($invoiceData['invoice']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @throws \DateMalformedStringException
     * @throws InvalidPaymentException|GuzzleException
     */
    public function pay() : RedirectionForm
    {
        $baseUrl = $this->settings->baseUrl;

        $paymentResult = $this->request($baseUrl . '/api/payment/purchase', $this->getPreparedInvoiceData());

        if ($paymentResult['resultCode'] !== 0 || empty($paymentResult['data']) || empty($paymentResult['data']['url'])) {
            throw new InvalidPaymentException($paymentResult['resultMsg'] ?? $this->getDefaultExceptionMessage());
        }

        $paymentUrl = $paymentResult['data']['url'];

        // redirect using the HTML form
        return $this->redirectWithForm($paymentUrl);
    }

    /**
     * Verify payment
     *
     *
     * @throws InvalidPaymentException
     * @throws GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $baseUrl = $this->settings->baseUrl;

        $invoiceInquiry = $this->request(
            $baseUrl . '/api/payment/payment-inquiry',
            [
                'invoiceId' => Request::input('invoiceId')
            ]
        );

        if ($invoiceInquiry['resultCode'] !== 0 || empty($invoiceInquiry['data'])) {
            throw new InvalidPaymentException($invoiceInquiry['resultMsg'] ?? $this->getDefaultExceptionMessage());
        }

        $invoiceDetails = $invoiceInquiry['data'];
        $invoiceInquiryStatus = $invoiceDetails['status'];

        $responseErrorStatusMessage = $this->getResponseErrorStatusMessage($invoiceInquiryStatus);
        if (!empty($responseErrorStatusMessage)) {
            throw new InvalidPaymentException($responseErrorStatusMessage);
        }

        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        if ($amount != $invoiceDetails['amount']) {
            throw new InvalidPaymentException('Invalid amount');
        }

        $paymentUrlId = $invoiceDetails['url'];

        $verifyResult = $this->request(
            $baseUrl . '/api/payment/confirm-transactions',
            [
                'invoice' => Request::input('invoiceId'),
                'urlId' => $paymentUrlId
            ]
        );

        return $this->createReceipt($verifyResult, $invoiceDetails);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $verifyResult
     * @param $invoiceDetails
     */
    protected function createReceipt(array $verifyResult, array $invoiceDetails): Receipt
    {
        $verifyResultData = $verifyResult['data'];
        $referenceId = $invoiceDetails['transactionId'];
        $trackId = $invoiceDetails['trackId'];
        $referenceNumber = $invoiceDetails['referenceNumber'];

        $receipt = new Receipt('Pasargad', $referenceId);

        $receipt->detail('TraceNumber', $trackId);
        $receipt->detail('ReferenceNumber', $referenceNumber);
        $receipt->detail('urlId', $invoiceDetails['url']);
        $receipt->detail('MaskedCardNumber', $verifyResultData['maskedCardNumber']);

        return $receipt;
    }

    /**
     * A default message for exceptions
     */
    protected function getDefaultExceptionMessage(): string
    {
        return 'مشکلی در دریافت اطلاعات از بانک به وجود آمده‌است.';
    }

    /**
     * Return response status message
     *
     * @param $status
     */
    protected function getResponseErrorStatusMessage(int|string|null $status): string
    {
        return match ($status) {
            13005 => 'عدم امکان دسترسی موقت به سرور پرداخت بانک',
            13016, 13018 => 'تراکنشی یافت نشد!',
            13021 => 'پرداخت از پیش لغو شده است!',
            13022 => 'تراکنش پرداخت نشده‌است!',
            13025 => 'تراکنش ناموفق است!',
            13030 => 'تراکنش تایید شده و ناموفق است!',
            default => ''
        };
    }

    /**
     * Retrieve prepared invoice's data
     *
     * @throws \DateMalformedStringException
     */
    protected function getPreparedInvoiceData(): array
    {
        if ($this->preparedData === []) {
            $this->preparedData = $this->prepareInvoiceData();
        }

        return $this->preparedData;
    }

    /**
     * Prepare invoice data
     *
     * @throws \DateMalformedStringException
     */
    protected function prepareInvoiceData(): array
    {
        $serviceCode = 8; // 8 : for PURCHASE request
        $terminalCode = $this->settings->terminalCode;
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        $redirectAddress = $this->settings->callbackUrl;
        $invoiceNumber = crc32($this->invoice->getUuid()) . random_int(0, time());

        $iranTime = new DateTime('now', new DateTimeZone('Asia/Tehran'));
        $invoiceDate = $iranTime->format("Y/m/d H:i:s");

        if (!empty($this->invoice->getDetails()['date'])) {
            $invoiceDate = $this->invoice->getDetails()['date'];
        }

        return [
            'invoice' => $invoiceNumber,
            'invoiceDate' => $invoiceDate,
            'amount' => $amount,
            'serviceCode' => $serviceCode,
            'serviceType' => 'PURCHASE',
            'terminalNumber' => $terminalCode,
            'callbackApi' => $redirectAddress,
        ];
    }

    /**
     * Get action token
     *
     * @throws InvalidPaymentException
     * @throws GuzzleException
     */
    protected function getToken() : string
    {
        $baseUrl = $this->settings->baseUrl;
        $userName = $this->settings->userName;
        $password = $this->settings->password;

        $response = $this->client->request(
            'POST',
            $baseUrl . '/token/getToken',
            [
                'body' => json_encode(['username' => $userName, 'password' => $password]),
                'headers' => [
                    'content-type' => 'application/json'
                ],
                'http_errors' => false,
            ]
        );

        $result = json_decode($response->getBody(), true);

        if ($result['resultCode'] !== 0 || empty($result['token'])) {
            throw new InvalidPaymentException($result['resultMsg'] ?? 'Invalid Authentication');
        }

        return $result['token'];
    }

    /**
     * Make request to Pasargad's Api
     *
     * @throws InvalidPaymentException
     * @throws GuzzleException
     */
    protected function request(string $url, array $body, string $method = 'POST'): array
    {
        $body = json_encode($body);
        $token = $this->getToken();

        $response = $this->client->request(
            'POST',
            $url,
            [
                'body' => $body,
                'headers' => [
                    'content-type' => 'application/json',
                    'Authorization' => "Bearer {$token}"
                ],
                'http_errors' => false,
            ]
        );

        $result = json_decode($response->getBody(), true);

        if ($result['resultCode'] !== 0) {
            $responseStatusMessage = $this->getResponseErrorStatusMessage($result['resultCode']);
            throw new InvalidPaymentException(
                empty($responseStatusMessage)
                    ? $result['resultMsg'] ?? $result['resultCode']
                    : ($responseStatusMessage)
            );
        }

        return $result;
    }
}
