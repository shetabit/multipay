<?php

namespace Shetabit\Multipay\Drivers\Asanpardakht;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Asanpardakht extends Driver
{
    const TokenURL = '/Token';
    const TimeURL = '/Time';
    const TranResultURL = '/TranResult';
    const CardHashURL = '/CardHash';
    const SettlementURL = '/Settlement';
    const VerifyURL = '/Verify';
    const CancelURL = '/Cancel';
    const ReverseURL = '/Reverse';

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
     * Merchant Config ID
     *
     */
    protected $merchantConfigID;

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
        $this->merchantConfigID = 35619;
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
            'referenceNo' => $result['RRN'],
            'transactionId' => $result['RefID'],
            'cardNo' => $result['CardNumber'],
        ]);

        return $receipt;
    }

    protected function callApi($method, $url, $data = [])
    {
        \Log::debug('PaymentTest:BeforeCallApiSection',[
            'url'=>$url,
            'data'=>$data
        ]);
        $client = new Client();
        $response = $client->request($method, $url, [
            "json" => $data,
            "headers" => [
                'Content-Type' => 'application/json',
                'usr' => $this->settings->username,
                'pwd' => $this->settings->password
            ],
            "http_errors" => false,
        ]);
        \Log::debug('PaymentTest:AfterCallApiSection',[
            'url'=>$url,
            'status_code' => $response->getStatusCode(),
            'content' => json_decode($response->getBody()->getContents(), true)
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
    protected function createReceipt($referenceId)
    {
        $receipt = new Receipt('asanpardakht', $referenceId);

        return $receipt;
    }

    public function token()
    {
        return $this->callApi('POST', $this->settings->apiRestPaymentUrl . self::TokenURL, [
            'serviceTypeId' => 1,
            'merchantConfigurationId' => $this->merchantConfigID,
            'localInvoiceId' => crc32($this->invoice->getUuid()),
            'amountInRials' => $this->invoice->getAmount(),
            'localDate' => $this->getTime()['content'],
            'callbackURL' => $this->settings->callbackUrl . "?" . http_build_query(['invoice' => crc32($this->invoice->getUuid())]),
            'paymentId' => "0",
            'additionalData' => '',
        ]);
    }

    public function reverse()
    {
        return $this->callApi('POST', $this->settings->apiRestPaymentUrl . self::ReverseURL, [
            'merchantConfigurationId' => $this->merchantConfigID,
            'payGateTranId' => $this->invoice->getUuid()
        ]);
    }

    public function cancel()
    {
        return $this->callApi('POST', $this->settings->apiRestPaymentUrl . self::CancelURL, [
            'merchantConfigurationId' => $this->merchantConfigID,
            'payGateTranId' => $this->payGateTransactionId
        ]);
    }

    public function verifyTransaction()
    {
        return $this->callApi('POST', $this->settings->apiRestPaymentUrl . self::VerifyURL, [
            'merchantConfigurationId' => $this->merchantConfigID,
            'payGateTranId' => $this->payGateTransactionId
        ]);
    }

    public function settlement()
    {
        return $this->callApi('POST', $this->settings->apiRestPaymentUrl . self::SettlementURL, [
            'merchantConfigurationId' => $this->merchantConfigID,
            'payGateTranId' => $this->payGateTransactionId
        ]);
    }

    public function cardHash()
    {
        return $this->callApi('GET', $this->settings->apiRestPaymentUrl . self::CardHashURL, [
            'merchantConfigurationId' => $this->merchantConfigID,
            'localInvoiceId' => $this->invoice->getUuid()
        ]);
    }

    public function transactionResult()
    {
        return $this->callApi('POST', $this->settings->apiRestPaymentUrl . self::SettlementURL, [
            'merchantConfigurationId' => $this->merchantConfigID,
            'localInvoiceId' => $this->invoice->getUuid()
        ]);
    }

    public function getTime()
    {
        return $this->callApi('GET', $this->settings->apiRestPaymentUrl . self::TimeURL);
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
