<?php

namespace Shetabit\Multipay\Drivers\Rayanpay;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Rayanpay extends Driver
{
    /**
     * Sadad Client.
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

    /**
     * Open Gate By Render Html
     * @var string $htmlPay
     */

    /**
     * Sadad constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client(
            [
                'base_uri' => $this->settings->apiPurchaseUrl,
                'verify' => false
            ]
        );
    }

    /**
     * @throws InvalidPaymentException
     */
    private function auth()
    {

        $data = [
            'clientId' => $this->settings->client_id,
            'userName' => $this->settings->username,
            'password' => $this->settings->password,
        ];

        $response = $this->client
            ->post($this->settings->apiTokenUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($data)
            ]);

        if ($response->getStatusCode() !== 200) {
            $this->notVerified($response->getStatusCode(), 'token');
        }
        return $response->getBody()->getContents();

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
        $details = $this->invoice->getDetails();

        if (!empty($details['mobile'])) {
            $mobile = $details['mobile'];
        }
        if (!empty($details['phone'])) {
            $mobile = $details['phone'];
        }

        if (empty($mobile)) {
            throw new PurchaseFailedException('شماره موبایل را وارد کنید.');
        }

        if (preg_match('/^(?:98)?9[0-9]{9}$/', $mobile) == false) {
            $mobile = '';
        }

        if ($this->invoice->getAmount() <= 1000) {
            throw new PurchaseFailedException('مقدار مبلغ ارسالی بزگتر از 1000 باشد.');
        }

        $referenceId = hexdec(uniqid());
        $amount = $this->invoice->getAmount();

        $callback = $this->settings->callbackUrl . "?referenceId=" . $referenceId . "&price=" . $amount . "&mobile=" . $mobile;

        $data = [
            'referenceId' => $referenceId,
            'amount' => $amount,
            'msisdn' => $mobile,
            'gatewayId' => 100,
            'callbackUrl' => $callback,
            'gateSwitchingAllowed' => true,
        ];

        $response = $this->client->post($this->settings->apiPayStart, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->auth(),
            ],
            'body' => json_encode($data)
        ]);

        if ($response->getStatusCode() !== 200) {
            $this->notVerified($response->getStatusCode(), $this->auth());
        }

        $body = json_decode($response->getBody()->getContents(), true);
        $transId = $body['paymentId'];



        $this->htmlPay = $body['bankRedirectHtml'];
        $this->invoice->transactionId($referenceId);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice render html redirect to getway
     *
     * @return String
     */
    public function pay() : String
    {
        echo $this->htmlPay;
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify() : ReceiptInterface
    {
        $data = json_encode([
            'referenceId' => (int) $this->getInvoice()->getTransactionId(),
            'header' => '',
            'content' => http_build_query($_POST),
        ]);

        $ch = curl_init($this->settings->apiPurchaseUrl . $this->settings->apiPayVerify);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $this->auth(),
            'Content-Type: application/json',
        ));
        $result = curl_exec($ch);
        $result = json_decode($result, true);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            $this->notVerified($http_code, 'payment_parse');
        }

        $receipt = $this->createReceipt($body->SystemTraceNo);

        $receipt->detail([
            'paymentId' => $result['paymentId'],
            'hashedBankCardNumber' => $result['hashedBankCardNumber'],
            'referenceId' =>  $this->getInvoice()->getTransactionId(),
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
    protected function createReceipt($referenceId)
    {
        $receipt = new Receipt('rayanpay', $referenceId);

        return $receipt;
    }


    /**
     * Trigger an exception
     *
     * @param $status
     * @param $method
     * @throws InvalidPaymentException
     */
    private function notVerified($status, $method)
    {
        $message = "";
        if ($method == 'token') {
            switch ($status) {

                case '400' :
                    $message = 'نقص در پارامترهای ارسالی';
                    break;

                case '401' :
                    $message = 'کد کاربری/رمز عبور /کلاینت/آی پی نامعتبر است';
                    break;

                case '500' :
                    $message = 'خطایی سمت سرور رخ داده است';
                    break;
            }
        } elseif ($method == 'payment_start') {
            switch ($status) {
                case '400' :
                    $message = 'شناسه ارسالی تکراری می باشد ';
                    break;
                case '401' :
                    $message = 'توکن نامعتبر';
                    break;

                case '601' :
                    $message = 'اتصال به درگاه خطا دارد (پرداخت ناموفق)';
                    break;

                case '500' :
                    $message = 'خطایی سمت سرور رخ داده است (احتمال تکراری بودن شماره ref شما یا اگر شماره موبایل دارید باید فرمت زیر باشد 989121112233 )';
                    break;
            }

        } elseif ($method == 'payment_status') {
            switch ($status) {
                case '401' :
                    $message = 'توکن نامعتبر است';
                    break;
                case '601' :
                    $message = 'پرداخت ناموفق';
                    break;

                case '600' :
                    $message = 'پرداخت در حالت Pending می باشد و باید متد fullfill برای تعیین وضعیت صدا زده شود';
                    break;
            }

        } elseif ($method == 'payment_parse') {
            switch ($status) {

                case '401' :
                    $message = 'توکن نامعتبر است';
                    break;

                case '500' :
                    $message = 'خطایی سمت سرور رخ داده است';
                    break;

                case '600' :
                    $message = 'وضعیت نامشخص';
                    break;

                case '601' :
                    $message = 'پرداخت ناموفق';
                    break;

                case '602' :
                    $message = 'پرداخت یافت نشد';
                    break;

                case '608' :
                    $message = 'قوانین پرداخت یافت نشد (برای پرداخت هایی که قوانین دارند)';
                    break;

                case '609' :
                    $message = 'وضعیت پرداخت نامعتبر میباشد';
                    break;
            }
        }
        if ($message) {
            throw new InvalidPaymentException($message);
        } else {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.' . $method);
        }
    }


}
