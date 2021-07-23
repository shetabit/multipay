<?php
namespace Shetabit\Multipay\Drivers\Jibit;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\{Contracts\ReceiptInterface,
    Exceptions\PurchaseFailedException,
    Invoice,
    RedirectionForm,
    Receipt};

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
        $this->jibit = new JibitBase($this->settings->merchantId, $this->settings->apiSecret,$this->settings->apiPaymentUrl);

    }

    // Purchase the invoice, save its transactionId and finaly return it.
    public function purchase() {
        // Request for a payment transaction id.

        // Making payment request
        // you should save the order details in DB, you need if for verify
        $requestResult = $this->jibit->paymentRequest($this->invoice->getAmount(), $this->invoice->getDetail('order_id'), $this->invoice->getDetail('mobile'),$this->settings->callbackUrl);


        if (!empty($requestResult['pspSwitchingUrl'])) {
            //successful result and redirect to PG
            $this->payment_url = $requestResult['pspSwitchingUrl'];

        }
        if (!empty($requestResult['errors'])) {
            //fail result and show the error
            $errMsgs = array_map(function ($err){ return $err['message']; }, $requestResult['errors']);
            throw new PurchaseFailedException(implode('\n', $errMsgs));
        }

        $transId = $requestResult['orderIdentifier'];
        $referenceNumber = $requestResult['referenceNumber'];

        $this->invoice->transactionId($transId);

        return $transId;
    }

    // Redirect into bank using transactionId, to complete the payment.
    public function pay() : RedirectionForm {

        // Redirect to the bank.
        $url = $this->payment_url;
        $inputs = [];
        $method = 'GET';

        return $this->redirectWithForm($url, $inputs, $method);
    }

    // Verify the payment (we must verify to ensure that user has paid the invoice).
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
