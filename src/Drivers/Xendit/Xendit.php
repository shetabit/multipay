<?php

namespace Shetabit\Multipay\Drivers\Xendit;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Xendit extends Driver
{
    protected Client $client;
    protected $invoice;
    protected $settings;
    protected string $invoiceId;
    protected string $invoiceUrl;

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;

        $this->client = new Client([
            'base_uri' => $this->settings->apiUrl ?? 'https://api.xendit.co/',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->settings->secretKey . ':'),
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function purchase(): string
    {
        $data = [
            'external_id' => $this->invoice->getUuid(),
            'amount' => $this->invoice->getAmount(),
            'currency' => $this->settings->currency ?? 'IDR',
            'description' => $this->settings->description ?? 'Payment via Xendit',
            'invoice_duration' => $this->settings->invoiceDuration ?? 86400, // 24 hours default
        ];

        // Add optional parameters
        if (!empty($this->settings->payerEmail)) {
            $data['payer_email'] = $this->settings->payerEmail;
        }

        if (!empty($this->settings->customerName)) {
            $data['customer'] = [
                'given_names' => $this->settings->customerName,
            ];
        }

        if (!empty($this->settings->notificationEmail)) {
            $data['customer_notification_preference'] = [
                'invoice_created' => [$this->settings->notificationEmail],
                'invoice_reminder' => [$this->settings->notificationEmail],
                'invoice_paid' => [$this->settings->notificationEmail],
                'invoice_expired' => [$this->settings->notificationEmail],
            ];
        }

        // Add success and failure redirect URLs
        if (!empty($this->settings->successReturnUrl)) {
            $data['success_redirect_url'] = $this->settings->successReturnUrl;
        }
        
        if (!empty($this->settings->failureReturnUrl)) {
            $data['failure_redirect_url'] = $this->settings->failureReturnUrl;
        }

        // Add available payment methods
        if (!empty($this->settings->paymentMethods)) {
            $data['payment_methods'] = $this->settings->paymentMethods;
        }

        // Add invoice metadata
        if ($this->invoice->getDetails()) {
            $data['metadata'] = $this->invoice->getDetails();
        }

        try {
            $response = $this->client->post('v2/invoices', [
                'json' => $data,
            ]);

            $result = json_decode((string) $response->getBody(), true);

            if (!isset($result['id']) || !isset($result['invoice_url'])) {
                $errorMessage = 'Failed to create invoice: ' . ($result['error_code'] ?? 'Unknown error');
                throw new PurchaseFailedException($errorMessage);
            }

            $this->invoiceId = $result['id'];
            $this->invoiceUrl = $result['invoice_url'];
            $this->invoice->transactionId($result['id']);

            return $result['id'];
        } catch (\Exception $e) {
            if ($e instanceof PurchaseFailedException) {
                throw $e;
            }
            throw new PurchaseFailedException('Xendit invoice creation failed: ' . $e->getMessage());
        }
    }

    public function pay(): RedirectionForm
    {
        if (empty($this->invoiceUrl)) {
            throw new InvalidPaymentException('Invoice URL not found. Call purchase() first.');
        }

        return new RedirectionForm($this->invoiceUrl, [], 'GET');
    }

    public function verify(): ReceiptInterface
    {
        $invoiceId = $this->invoice->getTransactionId();
        
        if (empty($invoiceId)) {
            // Try to get from request parameters
            $invoiceId = Request::input('invoice_id')
                       ?? Request::input('id')
                       ?? Request::input('external_id');
        }

        if (empty($invoiceId)) {
            throw new InvalidPaymentException('Invoice ID not found for verification.');
        }

        try {
            $response = $this->client->get("v2/invoices/{$invoiceId}");
            $result = json_decode((string) $response->getBody(), true);

            if (!isset($result['id'])) {
                throw new InvalidPaymentException('Invalid invoice response.');
            }

            // Check invoice status
            if ($result['status'] !== 'SETTLED') {
                throw new InvalidPaymentException(
                    'Invoice not paid. Status: ' . ($result['status'] ?? 'Unknown')
                );
            }

            // Verify amount matches
            if ($result['amount'] != $this->invoice->getAmount()) {
                throw new InvalidPaymentException('Invoice amount mismatch.');
            }

            return $this->createReceipt($result);
        } catch (\Exception $e) {
            if ($e instanceof InvalidPaymentException) {
                throw $e;
            }
            throw new InvalidPaymentException('Xendit invoice verification failed: ' . $e->getMessage());
        }
    }

    protected function createReceipt(array $invoiceData): Receipt
    {
        $receipt = new Receipt('xendit', $invoiceData['id']);
        
        // Add additional invoice details if available
        if (isset($invoiceData['external_id'])) {
            $receipt->detail('external_id', $invoiceData['external_id']);
        }

        if (isset($invoiceData['payment_method'])) {
            $receipt->detail('payment_method', $invoiceData['payment_method']);
        }

        if (isset($invoiceData['payment_channel'])) {
            $receipt->detail('payment_channel', $invoiceData['payment_channel']);
        }

        if (isset($invoiceData['payment_destination'])) {
            $receipt->detail('payment_destination', $invoiceData['payment_destination']);
        }

        if (isset($invoiceData['paid_at'])) {
            $receipt->detail('paid_at', $invoiceData['paid_at']);
        }

        if (isset($invoiceData['payment_id'])) {
            $receipt->detail('payment_id', $invoiceData['payment_id']);
        }

        return $receipt;
    }

    /**
     * Get invoice details
     */
    public function getInvoice(string $invoiceId = null): array
    {
        try {
            $response = $this->client->get("v2/invoices/{$invoiceId}");
            return json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            throw new InvalidPaymentException('Failed to get invoice: ' . $e->getMessage());
        }
    }

    /**
     * Expire an invoice
     */
    public function expireInvoice(string $invoiceId): bool
    {
        try {
            $response = $this->client->post("v2/invoices/{$invoiceId}/expire!");
            $result = json_decode((string) $response->getBody(), true);
            return isset($result['status']) && $result['status'] === 'EXPIRED';
        } catch (\Exception $e) {
            return false;
        }
    }
}
