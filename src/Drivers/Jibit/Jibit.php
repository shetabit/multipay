<?php
namespace Shetabit\Multipay\Drivers\Jibit;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Receipt;

class Jibit extends Driver
{
    protected $invoice; // Invoice.

    protected $settings; // Driver settings.

    protected $payment_url;
    /**
     * @var JibitBase
     */
    protected $jibit; // Driver settings.

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice); // Set the invoice.
        $this->settings = (object) $settings; // Set settings.
        /** @var JibitBase $jibit */
        $this->jibit = new JibitBase($this->settings->merchantId, $this->settings->apiSecret, $this->settings->apiPaymentUrl, $this->settings->tokenStoragePath);
    }


    public function purchase()
    {
        $requestResult = $this->jibit->paymentRequest($this->invoice->getAmount(), $this->invoice->getUuid(true), $this->invoice->getDetail('mobile'), $this->settings->callbackUrl);


        if (!empty($requestResult['pspSwitchingUrl'])) {
            $this->payment_url = $requestResult['pspSwitchingUrl'];
        }
        if (!empty($requestResult['errors'])) {
            //fail result and show the error
            $errMsgs = array_map(function ($err) {
                return $err['message'];
            }, $requestResult['errors']);
            throw new PurchaseFailedException(implode('\n', $errMsgs));
        }

        $transId = $requestResult['orderIdentifier'];
        $referenceNumber = $requestResult['referenceNumber'];
        $this->invoice->detail('referenceNumber', $referenceNumber);

        $this->invoice->transactionId($transId);

        return $transId;
    }

    // Redirect into bank using transactionId, to complete the payment.
    public function pay() : RedirectionForm
    {

        // Redirect to the bank.
        $url = $this->payment_url;
        $inputs = [];
        $method = 'GET';

        return $this->redirectWithForm($url, $inputs, $method);
    }

    public function verify(): ReceiptInterface
    {

        // $verifyUrl = $this->settings->verifyApiUrl;

        $refNum = $this->invoice->getTransactionId();
        // Making payment verify
        $requestResult = $this->jibit->paymentVerify($refNum);

        if (!empty($requestResult['status']) && $requestResult['status'] === 'Successful') {
            //successful result

            //show session detail
            $order = $this->jibit->getOrderById($refNum);

            return (new Receipt('jibit', $refNum))->detail('payerCard', $order['payerCard'] ?? '');
        }

        throw new InvalidPaymentException('Payment failed.');
    }
}
