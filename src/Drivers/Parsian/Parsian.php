<?php

namespace Shetabit\Multipay\Drivers\Parsian;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Parsian extends Driver
{
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
     * Parsian constructor.
     * Construct the class with the relevant settings.
     *
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
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
        $soap = new \SoapClient($this->settings->apiPurchaseUrl);
        $response = $soap->SalePaymentRequest(
            ['requestData' => $this->preparePurchaseData()]
        );

        // no response from bank
        if (empty($response->SalePaymentRequestResult)) {
            throw new PurchaseFailedException('bank gateway not response');
        }

        $result = $response->SalePaymentRequestResult;

        if (isset($result->Status) && $result->Status == 0 && !empty($result->Token)) {
            $this->invoice->transactionId($result->Token);
        } else {
            // an error has happened
            throw new PurchaseFailedException($result->Message);
        }

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     */
    public function pay() : RedirectionForm
    {
        $payUrl = sprintf(
            '%s?Token=%s',
            $this->settings->apiPaymentUrl,
            $this->invoice->getTransactionId()
        );

        return $this->redirectWithForm(
            $payUrl,
            ['Token' => $this->invoice->getTransactionId()],
            'GET'
        );
    }

    /**
     * Verify payment
     *
     *
     * @throws InvalidPaymentException
     * @throws \SoapFault
     */
    public function verify() : ReceiptInterface
    {
        $status = Request::input('status');
        $token = $this->invoice->getTransactionId() ?? Request::input('Token');

        if (empty($token)) {
            throw new InvalidPaymentException('تراکنش توسط کاربر کنسل شده است.', (int)$status);
        }

        $data = $this->prepareVerificationData();
        $soap = new \SoapClient($this->settings->apiVerificationUrl);

        $response = $soap->ConfirmPayment(['requestData' => $data]);
        if (empty($response->ConfirmPaymentResult)) {
            throw new InvalidPaymentException('از سمت بانک پاسخی دریافت نشد.');
        }
        $result = $response->ConfirmPaymentResult;

        $hasWrongStatus = (!isset($result->Status) || $result->Status != 0);
        $hasWrongRRN = (!isset($result->RRN) || $result->RRN <= 0);
        if ($hasWrongStatus || $hasWrongRRN) {
            $message = 'خطا از سمت بانک با کد '.$result->Status.' رخ داده است.';
            throw new InvalidPaymentException($message, (int)$result->Status);
        }

        return $this->createReceipt($result->RRN);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     */
    protected function createReceipt($referenceId): \Shetabit\Multipay\Receipt
    {
        return new Receipt('parsian', $referenceId);
    }

    /**
     * Prepare data for payment verification
     */
    protected function prepareVerificationData(): array
    {
        $transactionId = $this->invoice->getTransactionId() ?? Request::input('Token');

        return [
            'LoginAccount' => $this->settings->merchantId,
            'Token'        => $transactionId,
        ];
    }

    /**
     * Prepare data for purchasing invoice
     */
    protected function preparePurchaseData(): array
    {
        // The bank suggests that an English description is better
        if (empty($description = $this->invoice->getDetail('description'))) {
            $description = $this->settings->description;
        }

        $phone = $this->invoice->getDetail('phone')
            ?? $this->invoice->getDetail('cellphone')
            ?? $this->invoice->getDetail('mobile');


        return [
            'LoginAccount'   => $this->settings->merchantId,
            'Amount'         => $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
            'OrderId'        => crc32($this->invoice->getUuid()),
            'CallBackUrl'    => $this->settings->callbackUrl,
            'Originator'     => $phone,
            'AdditionalData' => $description,
        ];
    }
}
