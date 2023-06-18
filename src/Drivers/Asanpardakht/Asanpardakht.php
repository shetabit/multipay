<?php

namespace Shetabit\Multipay\Drivers\Asanpardakht;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;

class Asanpardakht extends Driver
{
    const TokenURL = 'Token';
    const TimeURL = 'Time';
    const TranResultURL = 'TranResult';
    const CardHashURL = 'CardHash';
    const SettlementURL = 'Settlement';
    const VerifyURL = 'Verify';
    const CancelURL = 'Cancel';
    const ReverseURL = 'Reverse';

    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Response
     *
     * @var object
     */
    protected $response;

    /**
     * PayGateTransactionId
     *
     */
    protected $payGateTransactionId;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Asanpardakht constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     */
    public function purchase()
    {
        $this->invoice->uuid(crc32($this->invoice->getUuid()));

        $result = $this->token();

        if (!isset($result['status_code']) or $result['status_code'] != 200) {
            $this->purchaseFailed($result['status_code']);
        }

        $this->invoice->transactionId($result['content']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $data = [
            'RefID' => $this->invoice->getTransactionId()
        ];

        //set mobileap for get user cards
        if (!empty($this->invoice->getDetails()['mobile'])) {
            $data['mobileap'] = $this->invoice->getDetails()['mobile'];
        }

        return $this->redirectWithForm($this->settings->apiPaymentUrl, $data, 'POST');
    }

    /**
     * Verify payment
     *
     * @return mixed|Receipt
     *
     * @throws PurchaseFailedException
     */
    public function verify(): ReceiptInterface
    {
        $result = $this->transactionResult();

        if (!isset($result['status_code']) or $result['status_code'] != 200) {
            $this->purchaseFailed($result['status_code']);
        }

        $this->payGateTransactionId = $result['content']['payGateTranID'];

        //step1: verify
        $verify_result = $this->verifyTransaction();

        if (!isset($verify_result['status_code']) or $verify_result['status_code'] != 200) {
            $this->purchaseFailed($verify_result['status_code']);
        }

        //step2: settlement
        $this->settlement();

        $receipt = $this->createReceipt($this->payGateTransactionId);
        $receipt->detail([
            'traceNo' => $this->payGateTransactionId,
            'referenceNo' => $result['content']['rrn'],
            'transactionId' => $result['content']['refID'],
            'cardNo' => $result['content']['cardNumber'],
        ]);

        return $receipt;
    }

    /**
     * send request to Asanpardakht
     *
     * @param $method
     * @param $url
     * @param array $data
     * @return array
     */
    protected function callApi($method, $url, $data = []): array
    {
        $client = new Client(['base_uri' => $this->settings->apiRestPaymentUrl]);

        $response = $client->request($method, $url, [
            "json" => $data,
            "headers" => [
                'Content-Type' => 'application/json',
                'usr' => $this->settings->username,
                'pwd' => $this->settings->password
            ],
            "http_errors" => false,
        ]);

        return [
            'status_code' => $response->getStatusCode(),
            'content' => json_decode($response->getBody()->getContents(), true)
        ];
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId): Receipt
    {
        $receipt = new Receipt('asanpardakht', $referenceId);

        return $receipt;
    }

    /**
     * call create token request
     *
     * @return array
     */
    public function token(): array
    {
        if (strpos($this->settings->callbackUrl, '?') !== false) {
            $query = '&' . http_build_query(['invoice' => $this->invoice->getUuid()]);
        } else {
            $query = '?' . http_build_query(['invoice' => $this->invoice->getUuid()]);
        }

        return $this->callApi('POST', self::TokenURL, [
            'serviceTypeId' => 1,
            'merchantConfigurationId' => $this->settings->merchantConfigID,
            'localInvoiceId' => $this->invoice->getUuid(),
            'amountInRials' => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'localDate' => $this->getTime()['content'],
            'callbackURL' => $this->settings->callbackUrl . $query,
            'paymentId' => "0",
            'additionalData' => '',
        ]);
    }

    /**
     * call reserve request
     *
     * @return array
     */
    public function reverse(): array
    {
        return $this->callApi('POST', self::ReverseURL, [
            'merchantConfigurationId' => (int)$this->settings->merchantConfigID,
            'payGateTranId' => (int)$this->invoice->getUuid()
        ]);
    }

    /**
     * send cancel request
     *
     * @return array
     */
    public function cancel(): array
    {
        return $this->callApi('POST', self::CancelURL, [
            'merchantConfigurationId' => (int)$this->settings->merchantConfigID,
            'payGateTranId' => (int)$this->payGateTransactionId
        ]);
    }

    /**
     * send verify request
     *
     * @return array
     */
    public function verifyTransaction(): array
    {
        return $this->callApi('POST', self::VerifyURL, [
            'merchantConfigurationId' => (int)$this->settings->merchantConfigID,
            'payGateTranId' => (int)$this->payGateTransactionId
        ]);
    }

    /**
     * send settlement request
     *
     * @return array
     */
    public function settlement(): array
    {
        return $this->callApi('POST', self::SettlementURL, [
            'merchantConfigurationId' => (int)$this->settings->merchantConfigID,
            'payGateTranId' => (int)$this->payGateTransactionId
        ]);
    }

    /**
     * get card hash request
     *
     * @return array
     */
    public function cardHash(): array
    {
        return $this->callApi('GET', self::CardHashURL . '?merchantConfigurationId=' . $this->settings->merchantConfigID . '&localInvoiceId=' . $this->invoice->getTransactionId(), []);
    }

    /**
     * get transaction result
     *
     * @return array
     */
    public function transactionResult(): array
    {
        return $this->callApi('GET', self::TranResultURL . '?merchantConfigurationId=' . $this->settings->merchantConfigID . '&localInvoiceId=' . $this->invoice->getTransactionId(), []);
    }

    /**
     * get Asanpardakht server time
     *
     * @return array
     */
    public function getTime(): array
    {
        return $this->callApi('GET', self::TimeURL);
    }

    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @throws PurchaseFailedException
     */
    protected function purchaseFailed($status)
    {
        $translations = [
            400 => "bad request",
            401 => "unauthorized. probably wrong or unsent header(s)",
            471 => "identity not trusted to proceed",
            472 => "no records found",
            473 => "invalid merchant username or password",
            474 => "invalid incoming request machine ip. check response body to see your actual public IP address",
            475 => "invoice identifier is not a number",
            476 => "request amount is not a number",
            477 => "request local date length is invalid",
            478 => "request local date is not in valid format",
            479 => "invalid service type id",
            480 => "invalid payer id",
            481 => "incorrect settlement description format",
            482 => "settlement slices does not match total amount",
            483 => "unregistered iban",
            484 => "internal error for other reasons",
            485 => "invalid local date",
            486 => "amount not in range",
            487 => "service not found or not available for merchant",
            488 => "invalid default callback",
            489 => "duplicate local invoice id",
            490 => "merchant disabled or misconfigured",
            491 => "too many settlement destinations",
            492 => "unprocessable request",
            493 => "error processing special request for other reasons like business restrictions",
            494 => "invalid payment_id for governmental payment",
            495 => "invalid referenceId in additionalData",
            496 => "invalid json in additionalData",
            497 => "invalid payment_id location",
            571 => "misconfiguration OR not yet processed",
            572 => "misconfiguration OR transaction status undetermined",
            573 => "misconfiguraed valid ips for configuration OR unable to request for verification due to an internal error",
            574 => "internal error in uthorization",
            575 => "no valid ibans found for merchant",
            576 => "internal error",
            577 => "internal error",
            578 => "no default sharing is defined for merchant",
            579 => "cant submit ibans with default sharing endpoint",
            580 => "error processing special request"
        ];

        if (array_key_exists($status, $translations)) {
            throw new PurchaseFailedException($translations[$status]);
        } else {
            throw new PurchaseFailedException('خطای ناشناخته ای رخ داده است.');
        }
    }
}
