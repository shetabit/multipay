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
    /**
     * Jibit client
     */
    protected JibitClient $jibit;

    /**
     * Payment URL
     */
    protected string|null $paymentUrl = null;

    public function __construct(Invoice $invoice, array|object $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->jibit = new JibitClient(
            $this->settings->apiKey,
            $this->settings->apiSecret,
            $this->settings->apiPaymentUrl,
            $this->settings->tokenStoragePath
        );
    }

    /**
     * Purchase invoice
     *
     * @return int|string|null
     * @throws PurchaseFailedException
     */
        public function purchase(): int|null|string
        {
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // Convert to Rial

        $payerMobileNumber = $this->invoice->getDetail('payerMobileNumber')
            ?? $this->invoice->getDetail('mobile');

        $payload = [
            'wage' => $this->invoice->getDetail('wage'),
            'payerMobileNumber' => $payerMobileNumber,
            'checkPayerMobileNumber' => $this->invoice->getDetail('checkPayerMobileNumber'),
            'payerCardNumber' => $this->invoice->getDetail('payerCardNumber'),
            'payerCardNumbers' => $this->invoice->getDetail('payerCardNumbers'),
            'payerNationalCode' => $this->invoice->getDetail('payerNationalCode'),
            'description' => $this->invoice->getDetail('description'),
            'switching' => $this->invoice->getDetail('switching'),
            'additionalData' => $this->invoice->getDetail('additionalData'),
            'userIdentifier' => $this->invoice->getDetail('userIdentifier'),
        ];

        $requestResult = $this->jibit->paymentRequest(
            $amount,
            $this->invoice->getUuid(),
            $payerMobileNumber,
            $this->settings->callbackUrl,
            $this->settings->currency ?? 'IRR',
            $payload
        );

        if (! empty($requestResult['pspSwitchingUrl'])) {
            $this->paymentUrl = $requestResult['pspSwitchingUrl'];
        }

        if (! empty($requestResult['errors'])) {
            $errMsgs = array_map(fn (array $err) => $err['code'], $requestResult['errors']);
            throw new PurchaseFailedException(implode("\n", $errMsgs));
        }

        $purchaseId = $requestResult['purchaseId'];
        $referenceNumber = $requestResult['clientReferenceNumber'];

        $this->invoice->detail('referenceNumber', $referenceNumber);
        $this->invoice->transactionId($purchaseId);

        return $purchaseId;
    }

    /**
     * Pay invoice
     */
    public function pay() : RedirectionForm
    {
        $url = $this->paymentUrl;

        return $this->redirectWithForm($url, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @throws InvalidPaymentException
     * @throws PurchaseFailedException
     */
    public function verify(): ReceiptInterface
    {
        $purchaseId = $this->invoice->getTransactionId();

        $requestResult = $this->jibit->paymentVerify($purchaseId);

        if (! empty($requestResult['status']) && $requestResult['status'] === 'SUCCESSFUL') {
            $order = $this->jibit->getOrderById($purchaseId);

            $receipt = new Receipt('jibit', $purchaseId);
            return $receipt->detail('payerCard', $order['elements']['payerCardNumber'] ?? '');
        }

        throw new InvalidPaymentException('Payment encountered an issue.');
    }
}
