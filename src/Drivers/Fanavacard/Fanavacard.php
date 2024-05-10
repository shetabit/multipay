<?php

namespace Shetabit\Multipay\Drivers\Fanavacard;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;
use const CURLOPT_SSL_CIPHER_LIST;

class Fanavacard extends Driver
{
    /**
     * client
     *
     * @var Client
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
     * Etebarino constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
        $this->httpClientInit();
    }

    /**
     * Purchase Invoice
     *
     * @return string
     *
     * @throws PurchaseFailedException
     */
    public function purchase()
    {
        $this->invoice->uuid(crc32($this->invoice->getUuid()));
        $token  = $this->getToken();
        $this->invoice->transactionId($token['Token']);

        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $url = rtrim($this->settings->baseUri, '/')."/{$this->settings->apiPaymentUrl}";

        return $this->redirectWithForm($url, [
            'token' => $this->invoice->getTransactionId(),
            'language' => 'fa'
        ], 'POST');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws PurchaseFailedException
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $transaction_amount = Request::input('transactionAmount');
        $amount = $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1); // convert to rial

        if ($amount == $transaction_amount) {
            $param = ['Token'=>Request::input('token'), 'RefNum'=>Request::input('RefNum')];
            $response = $this->client->post($this->settings->apiVerificationUrl, [
                'json'=> array_merge(
                    ['WSContext'=> $this->getWsContext()],
                    $param
                )]);

            $response_data = json_decode($response->getBody()->getContents());
            if ($response_data->Result != 'erSucceed') {
                throw new InvalidPaymentException($response_data->Result);
            } elseif ($amount != $response_data->Amount) {
                $this->client->post(
                    $this->settings->apiReverseAmountUrl,
                    [
                        'json'=> [
                            'WSContext'=> $this->getWsContext(),
                            $param
                        ]
                    ]
                );
                throw new InvalidPaymentException('مبلغ تراکنش برگشت داده شد');
            }
        }

        return $this->createReceipt(Request::input('ResNum'));
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
        $receipt = new Receipt('fanavacard', $referenceId);
        $receipt->detail([
                             'ResNum'=>Request::input('ResNum'),
                             'RefNum'=>Request::input('RefNum'),
                             'token'=>Request::input('token'),
                             'CustomerRefNum'=>Request::input('CustomerRefNum'),
                             'CardMaskPan'=>Request::input('CardMaskPan'),
                             'transactionAmount'=>Request::input('transactionAmount'),
                             'emailAddress'=>Request::input('emailAddress'),
                             'mobileNo'=>Request::input('mobileNo'),
                         ]);
        return $receipt;
    }

    /**
     * call create token request
     *
     * @return array
     * @throws PurchaseFailedException
     */
    public function getToken(): array
    {
        $response = $this->client->request('POST', $this->settings->apiPurchaseUrl, [
            'json'=>[
                'WSContext'=> $this->getWsContext(),
                'TransType'=>'EN_GOODS',
                'ReserveNum'=>$this->invoice->getDetail('invoice_number') ?? crc32($this->invoice->getUuid()),
                'Amount'=> $this->invoice->getAmount() * ($this->settings->currency == 'T' ? 10 : 1), // convert to rial
                'RedirectUrl'=>$this->settings->callbackUrl,
            ]]);

        if ($response->getStatusCode() != 200) {
            throw new PurchaseFailedException(
                "cant get token |  {$response->getBody()->getContents()}",
                $response->getStatusCode()
            );
        }

        $response_data = json_decode($response->getBody()->getContents());
        if ($response_data->Result != 'erSucceed') {
            throw new PurchaseFailedException(
                "cant get token |  {$response->getBody()->getContents()}",
                $response->getStatusCode()
            );
        }

        return (array) $response_data;
    }

    private function httpClientInit(): void
    {
        $this->client = new Client([
                                       'curl'=>[CURLOPT_SSL_CIPHER_LIST=>'DEFAULT@SECLEVEL=1',],
                                       'verify' => false,
                                       'base_uri' => $this->settings->baseUri,
                                       'headers' => ['Content-Type' => 'application/json',],
                                   ]);
    }

    private function getWsContext(): array
    {
        return ['UserId' => $this->settings->username, 'Password' => $this->settings->password];
    }
}
