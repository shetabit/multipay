<?php

namespace Shetabit\Multipay\Drivers\Minipay;

use GuzzleHttp\Client;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\Request;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;

class Minipay extends Driver
{
    /**
     * HTTP Client.
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
     * Minipay constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->client = new Client();
        $this->settings = (object)$settings;
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \SoapFault
     */
    public function purchase()
    {
        $metadata = [];

        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }

        $data = [
            "merchant_id" => $this->settings->merchantId,
            "amount" => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            "callback_url" => $this->settings->callbackUrl,
            "description" => $description,
            "metadata" => array_merge($this->invoice->getDetails() ?? [], $metadata),
        ];

        $response = $this
            ->client
            ->request(
                'POST',
                $this->settings->apiPurchaseUrl,
                [
                    "json" => $data,
                    "headers" => [
                        'Content-Type' => 'application/json',
                    ],
                    "http_errors" => false,
                ]
            );

        $result = json_decode($response->getBody()->getContents(), true);
        if ($response->getStatusCode() != 200) {
            $message = $result['messages'][0]['text'] ?? 'خطا در هنگام درخواست برای پرداخت رخ داده است.';
            throw new PurchaseFailedException($message, $response->getStatusCode());
        }

        $this->invoice->transactionId($result['data']["authority"]);

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
        $payUrl = $this->settings->apiPaymentUrl . '?authority=' . $this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return mixed|void
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify(): ReceiptInterface
    {
        $authority = $this->invoice->getTransactionId() ?? Request::input('Authority');
        $data = [
            "merchant_id" => $this->settings->merchantId,
            "authority" => $authority,
            "amount" => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
        ];

        $response = $this->client->request(
            'POST',
            $this->settings->apiVerificationUrl,
            [
                'json' => $data,
                "headers" => [
                    'Content-Type' => 'application/json',
                ],
                "http_errors" => false,
            ]
        );

        $result = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200) {
            $message = $result['messages'][0]['text'] ?? 'تراکنش تایید نشد.';
            throw new InvalidPaymentException($message, $response->getStatusCode());
        }

        $refId = $result['data']['id'];

        $receipt = $this->createReceipt($refId);
        $receipt->detail([
            'ref_id' => $refId,
            'installments_count' => $result['data']['installments_count'] ?? null,
            'price' => $result['data']['price'] ?? null,
            'credit_price' => $result['data']['credit_price'] ?? null,
        ]);

        return $receipt;
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return ReceiptInterface
     */
    protected function createReceipt($referenceId): ReceiptInterface
    {
        $receipt = new Receipt('minipay', $referenceId);

        return $receipt;
    }
}
