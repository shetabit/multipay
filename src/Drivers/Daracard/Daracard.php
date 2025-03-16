<?php

namespace Shetabit\Multipay\Drivers\Daracard;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;

class Daracard extends Driver
{
    protected $invoice;

    protected $settings;

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
    }

    public function purchase()
    {
        $this->invoice->uuid(crc32($this->invoice->getUuid()));

        $result = $this->token();

        if (!isset($result['status_code']) or $result['status_code'] != 200) {
            $this->purchaseFailed($result['content']);
        }

        $this->invoice->transactionId($result['content']);

        return $this->invoice->getTransactionId();
    }

    public function pay(): RedirectionForm
    {
        $result = json_decode($this->invoice->getTransactionId(), true);

        return $this->redirectWithForm($this->settings->apiPaymentUrl, [
            'token' => $result['ResultData']['PurchaseToken']?? $this->invoice->getTransactionId()
        ], 'GET');
    }

    public function verify(): ReceiptInterface
    {
        $result = $this->verifyTransaction();
        $resultArray = json_decode($result['content'], true);

        if (!isset($resultArray['resultCode']) or $resultArray['resultCode'] != 0) {
            $this->purchaseFailed($resultArray['resultMessage']);
        }
       
        $receipt = $this->createReceipt($this->invoice->getDetail('referenceCode'));
        $receipt->detail([
            'referenceNo' => "IssuerRRN: {$resultArray['IssuerRRN']}, AcquirerRRN: {$resultArray['AcquirerRRN']}",
        ]);

        return $receipt;
    }

    protected function callApi($method, $url, $data = []): array
    {
        $client = new Client();

        $response = $client->request($method, $url, [
            "json" => $data,
            "headers" => [
                'Content-Type' => 'application/json',
            ],
            "http_errors" => false,
        ]);

        return [
            'status_code' => $response->getStatusCode(),
            'content' => $response->getBody()->getContents()
        ];
    }

    protected function createReceipt($referenceId): Receipt
    {
        $receipt = new Receipt('daracard', $referenceId);

        return $receipt;
    }

    public function token(): array
    {
        $amount = $this->invoice->getAmount()*10;
        $orderId = $this->settings->orderId;

        $signData = $this->generateSignData($orderId, $amount);
        return $this->callApi('POST', $this->settings->apiPurchaseUrl, [
            'TerminalId'      => $this->settings->terminalId,
            'MerchantId'      => $this->settings->merchantId,
            "description"     => $this->invoice->getDetail('description'),
            "Amount"          => $amount,
            "OrderId"         => $orderId,
            "LocalDateTime"   => now()->toDateTimeString(),
            "SignData"        => $signData,
            "ReturnUrl"       => $this->settings->callbackUrl,
        ]);
    }

    private function generateSignData($orderId, $amount): string
    {
        $data = "{$this->settings->terminalId};{$orderId};{$amount}";
        return $this->encryptPkcs7($data, $this->settings->merchantId);
    }

    private function encryptPkcs7($str, $key)
    {
        $key = base64_decode($key);
        $cipherText = openSSL_encrypt($str, "DES-EDE3", $key, 0);
        return base64_encode($cipherText);
    }

    public function verifyTransaction(): array
    {
        return $this->callApi('POST', $this->settings->apiVerificationUrl, [
            'Token' => request('Token')
        ]);
    }

    protected function purchaseFailed($message)
    {
        throw new PurchaseFailedException($message);
    }
}
