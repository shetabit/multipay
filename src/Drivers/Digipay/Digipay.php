<?php


namespace Shetabit\Multipay\Drivers\Digipay;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Digipay extends Driver
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
     * Digipay AccessToken
     *
     * @var string
     */
    protected $accessToken;

    /**
     * Digipay constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->setUpHttpClient($settings->apiBaseUrl);
        $this->settings=$settings;
        $this->accessToken = $this->getAccessToken();
    }

    /**
     * @return string
     */
    public function purchase(): string
    {
        $details = $this->invoice->getDetails();
        $phone = $details['phone']??$details['mobile']??null;
        $data = [
            'amount' => $this->invoice->getAmount(),
            'phone' => $phone,
            'providerId' => $this->invoice->getUuid(),
            'redirectUrl' => $this->settings->callbackUrl,
            'type' => 0,
            'userType' => is_null($phone) ? 2 : 0
        ];
        $response = $this->client->post($this->settings->apiPurchaseUrl, $data, [
            'Authorization' => 'Bearer '.$this->accessToken
        ]);
        $response->throwError(PurchaseFailedException::class, 'عملیات پرداخت با خطا مواجه شد');
        $this->invoice->transactionId($response['ticket']);
        return $this->invoice->getTransactionId();
    }

    /**
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $payUrl = $this->settings->apiPaymentUrl.$this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * @return ReceiptInterface
     */
    public function verify(): ReceiptInterface
    {
        $tracingId=Request::input("trackingCode");
        $header =[
            "Accept" => "application/json",
            "Authorization" => "Bearer ".$this->accessToken
        ];
        $response = $this->client->request('POST', $this->settings->apiVerificationUrl.$tracingId, [], $header);


        $response->throwError(InvalidPaymentException::class, 'پرداخت تایید نشد');

        return new Receipt('digipay', $response["trackingCode"]);
    }


    /**
     * @return string
     */
    protected function getAccessToken() : string
    {
        $data = [
            "username" => $this->settings->username,
            "password" => $this->settings->password,
            "grant_type" => 'password',
        ];
        $header = [
            'Content-Type' => 'multipart/form-data',
            'Authorization' => 'Basic '.base64_encode("{$this->settings->client_id}:{$this->settings->client_secret}")
        ];
        $response = $this->client->request('POST', $this->settings->apiOauthUrl, $data, $header);
        $response->setStatusMessages(401, 'خطا نام کاربری یا رمز عبور شما اشتباه می باشد.');
        $response->throwError(PurchaseFailedException::class);
        return $response['access_token'];
    }
}
