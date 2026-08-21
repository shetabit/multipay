<?php

namespace Shetabit\Multipay\Drivers\Parspal;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use chillerlan\SimpleCache\CacheOptions;
use chillerlan\SimpleCache\FileCache;
use Psr\SimpleCache\CacheInterface;

class Parspal extends Driver
{
    /**
     * HTTP Client.
     */
    protected Client $client;

    /**
     * Cache
     */
    protected CacheInterface $cache;

    protected const PAYMENT_PURCHASE_STATUS_OK = 'ACCEPTED';

    protected const PAYMENT_VERIFY_STATUS_OK = 'SUCCESSFUL';

    /**
     * Constructor.
     * Construct the class with the relevant settings.
     *
     * @param $settings
     */
    public function __construct(Invoice $invoice, array|object $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
        if (!is_dir((string) $this->settings->cachePath)) {
            mkdir((string) $this->settings->cachePath, 0755, true);
        }

        $this->cache = new FileCache(
            new CacheOptions([
                'cacheFilestorage' => $this->settings->cachePath,
            ])
        );
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails(string $name) : mixed
    {
        return empty($this->invoice->getDetails()[$name]) ? null : $this->invoice->getDetails()[$name];
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     */
    public function purchase(): string|int|null
    {
        $data = [
            'amount' => $this->getInvoiceAmount(),
            'return_url' => $this->settings->callbackUrl,
            'order_id' => $this->invoice->getUuid(),
            'description' => $this->extractDetails('description'),
            'payer' => [
                'name' => $this->extractDetails('name'),
                'mobile' => $this->extractDetails('mobile'),
                'email' => $this->extractDetails('email'),
            ],
        ];

        $response = $this->client->request(
            'POST',
            $this->getPurchaseUrl(),
            [
                'headers' => [
                    'ApiKey' => $this->settings->merchantId,
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
                'http_errors' => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($body['status'] !== self::PAYMENT_PURCHASE_STATUS_OK) {
            throw new PurchaseFailedException($body['message'], $body['error_code'] ?? 0);
        }

        $this->invoice->transactionId($body['payment_id']);

        $this->cache->set('payment_link_' . $body['payment_id'], $body['link'], $this->settings->cacheExpireTTL);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay(): RedirectionForm
    {
        $payUrl = $this->cache->get('payment_link_' . $this->invoice->getTransactionId());

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     *
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $paymentStatusCode = Request::input('status');
        $paymentReceiptNumber = Request::input('receipt_number');

        if ($paymentStatusCode != 100) {
            throw new InvalidPaymentException($this->translateStatus($paymentStatusCode), $paymentStatusCode);
        }

        $data = [
            'amount' => $this->getInvoiceAmount(),
            'receipt_number' => $paymentReceiptNumber,
        ];

        $response = $this->client->request(
            'POST',
            $this->getVerificationUrl(),
            [
                'headers' => [
                    'ApiKey' => $this->settings->merchantId,
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
                "http_errors" => false,
            ]
        );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($body['status'] !== self::PAYMENT_VERIFY_STATUS_OK) {
            throw new InvalidPaymentException($body['message'], $body['error_code'] ?? 0);
        }

        $receipt =  $this->createReceipt($paymentReceiptNumber);

        $receipt->detail([
            'id' => $body['id'],
            'paid_amount' => $body['paid_amount'],
            'message' => $body['message'],
        ]);

        return $receipt;
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     */
    public function createReceipt(string|int $referenceId): Receipt
    {
        return new Receipt('parspal', $referenceId);
    }

    /**
     * Retrieve invoice amount
     */
    protected function getInvoiceAmount(): int|float
    {
        return $this->invoice->getAmount() * (strtolower($this->settings->currency) === 't' ? 10 : 1); // convert to rial
    }

    /**
     * Retrieve purchase url
     */
    protected function getPurchaseUrl(): string
    {
        return $this->isSandboxMode()
            ? $this->settings->sandboxApiPurchaseUrl
            : $this->settings->apiPurchaseUrl;
    }

    /**
     * Retrieve verification url
     */
    protected function getVerificationUrl(): string
    {
        return $this->isSandboxMode()
            ? $this->settings->sandboxApiVerificationUrl
            : $this->settings->apiVerificationUrl;
    }

    /**
     * Retrieve payment in sandbox mode?
     */
    protected function isSandboxMode() : bool
    {
        return $this->settings->sandbox;
    }

    /**
     * Convert status to a readable message.
     *
     * @param $status
     */
    private function translateStatus(int|string|null $status): string
    {
        $translations = [
            '99' => 'انصراف کاربر از پرداخت',
            '88' => 'پرداخت ناموفق',
            '77' => 'لغو پرداخت توسط کاربر',
        ];

        $unknownError = 'خطای ناشناخته رخ داده است. در صورت کسر مبلغ از حساب حداکثر پس از 72 ساعت به حسابتان برمیگردد';

        return array_key_exists($status, $translations) ? $translations[$status] : $unknownError;
    }
}
