<?php
namespace Shetabit\Multipay\Drivers\Atipay;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

require_once 'Core/fn.atipay.php';

class Atipay extends Driver
{
    /**
     * Atipay Client.
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

    public $tokenId;

    /**
     * Atipay constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
        $this->tokenId = null;
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
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        $order_id = $this->invoice->getUuid();
        $mobile = $this->extractDetails('mobile');
        $description = $this->extractDetails('description');
        $apikey = $this->settings->apikey;
        $redirectUrl = $this->settings->callbackUrl;

        $token_params = array('apiKey'=>$apikey,
            'redirectUrl'=>$redirectUrl,
            'invoiceNumber'=>$order_id,
            'amount'=>$amount,
            'cellNumber'=>$mobile,
        );

        $r = fn_atipay_get_token($token_params);
        if ($r['success'] == 1) {
            $token = $r['token'];
            $this->tokenId = $token;
            $this->invoice->transactionId($order_id);
            return $this->invoice->getTransactionId();
        } else {
            $error_message = $r['errorMessage'];
            throw new PurchaseFailedException($error_message);
        }
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        //$token = $this->invoice->getTransactionId();
        $token = $this->tokenId;
        $payUrl = $this->settings->atipayRedirectGatewayUrl;
        return $this->redirectWithForm($payUrl, ['token'=>$token], 'POST');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify(): ReceiptInterface
    {
        $params = $_POST;
        $result = fn_check_callback_data($params);
        $payment_id = $params['reservationNumber'];
        if ($result['success'] == 1) { //will verify here
            $apiKey = $this->settings->apikey;
            $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial
            $verify_params = array('apiKey' => $apiKey,
                'referenceNumber' => $params['referenceNumber']
            );

            $r = fn_atipay_verify_payment($verify_params, $amount);
            if ($r['success'] == 0) { //veriy failed
                $error_message = $r['errorMessage'];
                throw new InvalidPaymentException($error_message);
            } else { //success
                $receipt =  $this->createReceipt($params['referenceNumber']);
                $receipt->detail([
                    'referenceNo' => $params['referenceNumber'],
                    'rrn' => Request::input('rrn'),
                    'pan' => $params['maskedPan']
                ]);

                return $receipt;
            }
        } else {
            $error_message = $result['error'];
            throw new InvalidPaymentException($error_message, (int)$result['success']);
        }


        return $this->createReceipt($body['transId']);
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
        $receipt = new Receipt('Atipay', $referenceId);

        return $receipt;
    }
}
