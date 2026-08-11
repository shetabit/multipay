<?php

namespace Shetabit\Multipay\Drivers\Daracard;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Daracard extends Driver
{
    public function __construct(Invoice $invoice, array|object $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
    }

    public function purchase(): string|int|null
    {
        $this->invoice->uuid(crc32($this->invoice->getUuid()));

        $result = $this->token();

        if (!isset($result['status_code']) || $result['status_code'] != 200) {
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

        if (!isset($resultArray['resultCode']) || $resultArray['resultCode'] != 0) {
            $this->purchaseFailed($resultArray['resultMessage']);
        }
       
        $receipt = $this->createReceipt($this->invoice->getDetail('referenceCode'));
        $receipt->detail([
            'referenceNo' => "IssuerRRN: {$resultArray['IssuerRRN']}, AcquirerRRN: {$resultArray['AcquirerRRN']}",
        ]);

        return $receipt;
    }

    protected function callApi(string $method, string $url, array $data = []): array
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

    protected function createReceipt(string|int $referenceId): Receipt
    {
        return new Receipt('daracard', $referenceId);
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
            "LocalDateTime"   => Carbon::now()->toDateTimeString(),
            "SignData"        => $signData,
            "ReturnUrl"       => $this->settings->callbackUrl,
        ]);
    }

    private function generateSignData(string|int $orderId, int|float $amount): string
    {
        $data = "{$this->settings->terminalId};{$orderId};{$amount}";
        return $this->encryptPkcs7($data, $this->settings->merchantId);
    }

    private function encryptPkcs7(string $str, string $key): string
    {
        $key = base64_decode($key);
        $cipherText = openSSL_encrypt($str, "DES-EDE3", $key, 0);
        return base64_encode($cipherText);
    }

    public function verifyTransaction(): array
    {
        return $this->callApi('POST', $this->settings->apiVerificationUrl, [
            'Token' => Request::input('Token')
        ]);
    }

    protected function purchaseFailed(string|null $message): never
    {
        throw new PurchaseFailedException($message);
    }
}
