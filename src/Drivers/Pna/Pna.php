<?php

namespace Shetabit\Multipay\Drivers\Pna;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Pna extends Driver
{
    /**
     * Pna Client.
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
     * payment token
     *
     * @var $token
     */
    protected $token;

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
        $this->client = new Client();
    }

    /**
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws GuzzleException
     */
    public function purchase()
    {
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }
        $data = [
            "CorporationPin" => $this->settings->CorporationPin,
            "Amount" => $amount,
            "OrderId" => intval(1, time()) . crc32($this->invoice->getUuid()),
            "CallBackUrl" => $this->settings->callbackUrl,
            "AdditionalData" => $description,
        ];
        if (!empty($this->invoice->getDetails()['mobile'])) {
            $data['Originator'] = $this->invoice->getDetails()['mobile'];
        }
        $response = $this->client->request(
            'POST',
            $this->settings->apiNormalSale,
            [
                'json' => $data,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'http_errors' => false,
            ]
        );
        $result = json_decode($response->getBody()->getContents(), true);
        if (isset($result['errors'])) {
            throw new PurchaseFailedException($result['title'] ?? 'اطلاعات وارد شده اشتباه می باشد.', $result['status'] ?? 400);
        }
        if (!isset($result['status']) || (string)$result['status'] !== '0') {
            throw new PurchaseFailedException($result['message'] ?? "خطای ناشناخته رخ داده است. در صورت کسر مبلغ از حساب حداکثر پس از 72 ساعت به حسابتان برمیگردد", $result['status'] ?? 400);
        }
        $this->invoice->transactionId($result['token']);

        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $transactionId = $this->invoice->getTransactionId();
        $paymentUrl = $this->settings->apiPaymentUrl;

        $payUrl = $paymentUrl . $transactionId;

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @throws InvalidPaymentException|GuzzleException
     */
    public function verify(): ReceiptInterface
    {
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('Token') ?? Request::input('token');
        $data = [
            "CorporationPin" => $this->settings->CorporationPin,
            "Token" => $transactionId
        ];
        $response = $this->client->request(
            'POST',
            $this->settings->apiConfirmationUrl,
            [
                'json' => $data,
                "headers" => [
                    'Content-Type' => 'application/json',
                ],
                'http_errors' => false,
            ]
        );
        $result = json_decode($response->getBody()->getContents(), true);
        if (!isset($result['status'])
            || ((string)$result['status'] !== '0'
                && (string)$result['status'] !== '2')
            || (string)$result['rrn'] === '0') {
            throw new InvalidPaymentException("خطای ناشناخته رخ داده است. در صورت کسر مبلغ از حساب حداکثر پس از 72 ساعت به حسابتان برمیگردد", $result['status'] ?? 400);
        }
        $refId = $result['rrn'];
        $receipt = new Receipt('pna', $refId);
        $receipt->detail([
            'cardNumberMasked' => $result['cardNumberMasked'],
            'token' => $result['token'],
        ]);
        return $receipt;
    }
}
