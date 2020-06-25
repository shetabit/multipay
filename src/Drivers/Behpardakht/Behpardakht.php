<?php

namespace Shetabit\Multipay\Drivers\Behpardakht;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\Request;
use Carbon\Carbon;
use Shetabit\Multipay\RedirectionForm;

class Behpardakht extends Driver
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
     * Behpardakht constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
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
        $response = $soap->bpPayRequest($this->preparePurchaseData());

        // fault has happened in bank gateway
        if ($response->return == 21) {
            throw new PurchaseFailedException('پذیرنده معتبر نیست.');
        }

        $data = explode(',', $response->return);

        // purchase was not successful
        if ($data[0] != "0") {
            throw new PurchaseFailedException('خرید موفقیت‌آمیز نبود.');
        }

        $this->invoice->transactionId($data[1]);

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
        $payUrl = $this->settings->apiPaymentUrl;

        return $this->redirectWithForm(
            $payUrl,
            [
                'RefId' => $this->invoice->getTransactionId(),
            ],
            'POST'
        );
    }

    /**
     * Verify payment
     *
     * @return mixed|Receipt
     *
     * @throws InvalidPaymentException
     * @throws \SoapFault
     */
    public function verify() : ReceiptInterface
    {
        $resCode = Request::input('ResCode');
        if ($resCode != '0') {
            $message = $resCode ?? 'تراکنش نا موفق بوده است.';
            throw new InvalidPaymentException($message);
        }

        $data = $this->prepareVerificationData();
        $soap = new \SoapClient($this->settings->apiVerificationUrl);

        // step1: verify request
        $verifyResponse = (int)$soap->bpVerifyRequest($data)->return;
        if ($verifyResponse != 0) {
            // rollback money and throw exception
            $soap->bpReversalRequest($data);
            throw new InvalidPaymentException($verifyResponse ?? "خطا در عملیات وریفای تراکنش");
        }

        // step2: settle request
        $settleResponse = $soap->bpSettleRequest($data)->return;
        if ($settleResponse != 0) {
            // rollback money and throw exception
            $soap->bpReversalRequest($data);
            throw new InvalidPaymentException($settleResponse ?? "خطا در ثبت درخواست واریز وجه");
        }

        return $this->createReceipt($data['saleReferenceId']);
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
        $receipt = new Receipt('behpardakht', $referenceId);

        return $receipt;
    }

    /**
     * Prepare data for payment verification
     *
     * @return array
     */
    protected function prepareVerificationData()
    {
        $orderId = Request::input('SaleOrderId');
        $verifySaleOrderId = Request::input('SaleOrderId');
        $verifySaleReferenceId = Request::input('SaleReferenceId');

        return array(
            'terminalId'        => $this->settings->terminalId,
            'userName'          => $this->settings->username,
            'userPassword'      => $this->settings->password,
            'orderId'           => $orderId,
            'saleOrderId'       => $verifySaleOrderId,
            'saleReferenceId'   => $verifySaleReferenceId
        );
    }

    /**
     * Prepare data for purchasing invoice
     *
     * @return array
     */
    protected function preparePurchaseData()
    {
        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }

        $payerId = $this->invoice->getDetails()['payerId'] ?? 0;

        return array(
            'terminalId'        => $this->settings->terminalId,
            'userName'          => $this->settings->username,
            'userPassword'      => $this->settings->password,
            'callBackUrl'       => $this->settings->callbackUrl,
            'amount'            => $this->invoice->getAmount() * 10, // convert to rial
            'localDate'         => Carbon::now()->format('Ymd'),
            'localTime'         => Carbon::now()->format('Gis'),
            'orderId'           => crc32($this->invoice->getUuid()),
            'additionalData'    => $description,
            'payerId'           => $payerId
        );
    }
}
