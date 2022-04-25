<?php

namespace Shetabit\Multipay\Drivers\Sadad;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use DateTimeZone;
use DateTime;

class Sadad extends Driver
{
    /**
     * Sadad Client.
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
     * Sadad constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
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
     *
     * @throws PurchaseFailedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $terminalId = $this->settings->terminalId;
        $orderId = crc32($this->invoice->getUuid());
        $amount = $this->invoice->getAmount() * 10; // convert to rial
        $key = $this->settings->key;

        $signData = $this->encrypt_pkcs7("$terminalId;$orderId;$amount", $key);
        $iranTime = new DateTime('now', new DateTimeZone('Asia/Tehran'));
        
        $data = array(
            'MerchantId' => $this->settings->merchantId,
            'ReturnUrl' => $this->settings->callbackUrl,
            'PaymentIdentity' => $this->settings->PaymentIdentity,
            'LocalDateTime' => $iranTime->format("m/d/Y g:i:s a"),
            'SignData' => $signData,
            'TerminalId' => $terminalId,
            'Amount' => $amount,
            'OrderId' => $orderId,
        );

        $response = $this
            ->client
            ->request(
                'POST',
                $this->getPaymentUrl(),
                [
                    "json" => $data,
                    "headers" => [
                        'Content-Type' => 'application/json',
                        'User-Agent' => '',
                    ],
                    "http_errors" => false,
                ]
            );

        $body = @json_decode($response->getBody()->getContents());

        if (empty($body)) {
            throw new PurchaseFailedException('دسترسی به صفحه مورد نظر امکان پذیر نمی باشد.');
        } elseif ($body->ResCode != 0) {
            throw new PurchaseFailedException($body->Description);
        }

        $this->invoice->transactionId($body->Token);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay() : RedirectionForm
    {
        $token = $this->invoice->getTransactionId();
        $payUrl = $this->getPurchaseUrl();

        return $this->redirectWithForm($payUrl, ['Token' => $token], 'GET');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $key = $this->settings->key;
        $token = $this->invoice->getTransactionId() ?? Request::input('token');
        $resCode = Request::input('ResCode');
        $message = 'تراکنش نا موفق بود در صورت کسر مبلغ از حساب شما حداکثر پس از 72 ساعت مبلغ به حسابتان برمیگردد.';

        if ($resCode != 0) {
            throw new InvalidPaymentException($message);
        }

        $data = array(
            'Token' => $token,
            'SignData' => $this->encrypt_pkcs7($token, $key)
        );

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiVerificationUrl,
                [
                    "json" => $data,
                    "headers" => [
                        'Content-Type' => 'application/json',
                        'User-Agent' => '',
                    ],
                    "http_errors" => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents());

        if ($body->ResCode != 0) {
            throw new InvalidPaymentException($message);
        }

        /**
         * شماره سفارش : $orderId = Request::input('OrderId')
         * شماره پیگیری : $body->SystemTraceNo
         * شماره مرجع : $body->RetrievalRefNo
         */

        $receipt = $this->createReceipt($body->SystemTraceNo);
        $receipt->detail([
            'orderId' => $body->OrderId,
            'traceNo' => $body->SystemTraceNo,
            'referenceNo' => $body->RetrivalRefNo,
            'description' => $body->Description,
        ]);

        return $receipt;
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
        $receipt = new Receipt('sadad', $referenceId);

        return $receipt;
    }

    /**
     * Create sign data(Tripledes(ECB,PKCS7))
     *
     * @param $str
     * @param $key
     *
     * @return string
     */
    protected function encrypt_pkcs7($str, $key)
    {
        $key = base64_decode($key);
        $ciphertext = OpenSSL_encrypt($str, "DES-EDE3", $key, OPENSSL_RAW_DATA);

        return base64_encode($ciphertext);
    }
    
    /**
     * Retrieve payment mode.
     *
     * @return string
     */
    protected function getMode() : string
    {
        return strtolower($this->settings->mode);
    }


    /**
     * Retrieve purchase url
     *
     * @return string
     */
    protected function getPurchaseUrl() : string
    {
        $mode = $this->getMode();

        switch ($mode) {
            case 'paymentbyidentity':
                $url = $this->settings->apiPurchaseUrl;
                break;
            default: // default: normal
                $url = $this->settings->apiPurchaseUrl;
                break;
        }

        return $url;
    }

    /**
     * Retrieve Payment url
     *
     * @return string
     */
    protected function getPaymentUrl() : string
    {
        $mode = $this->getMode();

        switch ($mode) {
            case 'paymentbyidentity':
                $url = $this->settings->apiPaymentByIdentityUrl;
                break;
            default: // default: normal
                $url = $this->settings->apiPaymentUrl;
                break;
        }

        return $url;
    }
}
