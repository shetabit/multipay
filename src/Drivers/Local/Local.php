<?php

namespace Shetabit\Multipay\Drivers\Local;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Local extends Driver
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
     * Local constructor.
     * Construct the class with the relevant settings.
     *
     * @param  Invoice  $invoice
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
     */
    public function purchase(): string
    {
        // throw PurchaseFailedException if set in invoice message
        if ($message = $this->invoice->getDetail('failedPurchase')) {
            throw new PurchaseFailedException($message);
        }

        // set a randomly grenerated transactionId
        $transactionId = mt_rand(1000000, 9999999);
        $this->invoice->transactionId($transactionId);

        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        RedirectionForm::setViewPath(dirname(__DIR__).'/../../resources/views/local-form.php');

        return new RedirectionForm('', $this->getFormData(), 'POST');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $data = array(
            'transactionId' => Request::input('transactionId'),
            'cancel' => Request::input('cancel')
        );

        $success = $data['transactionId'] && !$data['cancel'];

        if (!$success) {
            $this->notVerified(0);
        }

        $receipt = $this->createReceipt($data['transactionId']);
        $receipt->detail([
            'orderId' => $this->invoice->getDetail('orderId') ?? mt_rand(1111, 9999),
            'traceNo' => $this->invoice->getDetail('traceNo') ?? mt_rand(11111, 99999),
            'referenceNo' => $data['transactionId'],
            'cardNo' => $this->invoice->getDetail('cartNo') ?? mt_rand(1111, 9999),
        ]);

        return $receipt;
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId): Receipt
    {
        return new Receipt('local', $referenceId);
    }

    /**
     * Populate payment form data
     *
     *
     * @return array
     */
    protected function getFormData(): array
    {
        return [
            'orderId' => $this->invoice->getDetail('orderId'),
            'price' => number_format($this->invoice->getAmount()),
            'successUrl' => $this->addUrlQuery($this->settings->callbackUrl, [
                'transactionId' => $this->invoice->getTransactionId(),
                ]),
            'cancelUrl' => $this->addUrlQuery($this->settings->callbackUrl, [
                'transactionId' => $this->invoice->getTransactionId(),
                'cancel' => 'true',
                ]),
            'title' => $this->settings->title,
            'description' => $this->settings->description,
            'orderLabel' => $this->settings->orderLabel,
            'amountLabel' => $this->settings->amountLabel,
            'payButton' => $this->settings->payButton,
            'cancelButton' => $this->settings->cancelButton,
        ];
    }


    /**
     * Add array parameters as url query
     *
     * @param $url
     * @param $params
     *
     * @return string
     */
    protected function addUrlQuery($url, $params): string
    {
        $urlWithQuery = $url;
        foreach ($params as $key => $value) {
            $urlWithQuery .= (parse_url($urlWithQuery, PHP_URL_QUERY) ? '&' : '?') . "{$key}={$value}";
        }
        return $urlWithQuery;
    }


    /**
     * Trigger an exception
     *
     * @param $status
     *
     * @throws InvalidPaymentException
     */
    private function notVerified($status)
    {
        $translations = array(
            0 => 'تراکنش توسط خریدار لغو شده است.',
        );

        if (array_key_exists($status, $translations)) {
            throw new InvalidPaymentException($translations[$status], (int)$status);
        } else {
            throw new InvalidPaymentException('تراکنش با خطا مواجه شد.', (int)$status);
        }
    }
}
