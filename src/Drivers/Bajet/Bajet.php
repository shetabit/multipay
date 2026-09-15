<?php

namespace Shetabit\Multipay\Drivers\Bajet;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use RuntimeException;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use UnitEnum;

/**
 * Bajet credit payments (JETPAY API v1.3.2).
 */
class Bajet extends Driver
{
    protected Client $client;

    public function __construct(Invoice $invoice, mixed $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    public function purchase(): string
    {
        try {
            $amount = $this->apiAmount();
            $orderId = $this->text($this->invoice->getDetail('orderId') ?? (string) $this->invoice->getUuid());
            $payload = [
                'orderId' => $orderId,
                'amount' => $amount,
                'mobile' => $this->text($this->invoice->getDetail('mobile')),
            ];
            if (!empty($this->settings->callbackUrl)) {
                $payload['returnUrl'] = $this->text($this->settings->callbackUrl);
            }
            foreach (['nationalId', 'basketItems'] as $key) {
                $value = $this->invoice->getDetail($key);
                if ($value !== null) {
                    $payload[$key] = $key === 'nationalId' ? $this->text($value) : $this->basket($value);
                }
            }

            $result = $this->post('order', $payload, $this->token());
            $referenceId = $this->text($result['referenceId'] ?? null);
            $referUrl = $this->httpsUrl($result['referUrl'] ?? null);
            $this->invoice->transactionId($referenceId);
            $this->invoice->detail(['orderId' => $orderId, 'bajetReferUrl' => $referUrl]);

            return $referenceId;
        } catch (RuntimeException $exception) {
            throw new PurchaseFailedException($exception->getMessage(), $exception->getCode());
        }
    }

    public function pay(): RedirectionForm
    {
        try {
            $this->text($this->invoice->getTransactionId());
            $url = $this->httpsUrl($this->invoice->getDetail('bajetReferUrl'));
            $inputs = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $inputs);
            foreach ($inputs as $value) {
                if (!is_string($value)) {
                    throw new RuntimeException('Bajet returned an invalid payment URL.');
                }
            }

            // GET forms replace the action query. Preserve the gateway query as inputs.
            return $this->redirectWithForm($url, $inputs, 'GET');
        } catch (RuntimeException $exception) {
            throw new PurchaseFailedException($exception->getMessage(), $exception->getCode());
        }
    }

    public function verify(): ReceiptInterface
    {
        try {
            $amount = $this->apiAmount();
            $referenceId = $this->text($this->invoice->getTransactionId());
            $token = $this->token();
            $payload = ['referenceId' => $referenceId];
            $inquiry = $this->post('inquiry', $payload, $token);
            $this->checkIdentity($inquiry, $referenceId);
            if ($this->integer($inquiry['amount'] ?? null) !== $amount) {
                throw new RuntimeException('Bajet transaction amount does not match the invoice.');
            }
            if (($inquiry['finalStatus'] ?? null) === 'VERIFIED') {
                throw new PreviouslyVerifiedException('Bajet transaction was already verified.');
            }
            if (($inquiry['finalStatus'] ?? null) !== 'SUCCESS') {
                throw new RuntimeException('Bajet transaction is not ready for verification.');
            }

            $result = $this->post('verify', $payload, $token);
            $this->checkIdentity($result, $referenceId);
            if ($result['orderId'] !== $inquiry['orderId']) {
                throw new RuntimeException('Bajet order does not match the inquiry.');
            }
            $credit = $this->integer($result['creditAmount'] ?? null);
            $cash = $this->integer($result['cashAmount'] ?? null);
            if ($credit > $amount || $cash !== $amount - $credit) {
                throw new RuntimeException('Bajet paid amount does not match the invoice.');
            }

            return (new Receipt('bajet', $referenceId))->detail([
                'orderId' => $result['orderId'],
                'creditAmount' => $credit,
                'cashAmount' => $cash,
                'apiCurrency' => $this->settings->apiCurrency,
            ]);
        } catch (RuntimeException $exception) {
            throw new InvalidPaymentException($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * Read the gateway status using the stored reference, without settling a payment.
     */
    public function inquiry(): array
    {
        try {
            $referenceId = $this->text($this->invoice->getTransactionId());
            $result = $this->post('inquiry', ['referenceId' => $referenceId], $this->token());
            $this->checkIdentity($result, $referenceId);

            return $result;
        } catch (RuntimeException $exception) {
            throw new InvalidPaymentException($exception->getMessage(), $exception->getCode());
        }
    }

    private function checkIdentity(array $result, string $referenceId): void
    {
        if (($result['referenceId'] ?? null) !== $referenceId) {
            throw new RuntimeException('Bajet transaction reference does not match the invoice.');
        }
        $orderId = $this->text($result['orderId'] ?? null);
        $expectedOrder = $this->invoice->getDetail('orderId');
        if ($expectedOrder !== null && $orderId !== $this->text($expectedOrder)) {
            throw new RuntimeException('Bajet order does not match the invoice.');
        }
    }

    private function token(): string
    {
        $result = $this->post('token', [
            'username' => $this->text($this->settings->username ?? null),
            'password' => $this->text($this->settings->password ?? null),
            'terminalId' => $this->text($this->settings->terminalId ?? null),
        ]);

        return $this->text($result['token'] ?? null);
    }

    private function post(string $operation, array $payload, ?string $token = null): array
    {
        $baseUrl = $this->httpsUrl($this->settings->apiUrl ?? null);
        if (parse_url($baseUrl, PHP_URL_QUERY) !== null || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('Bajet apiUrl must not contain a query or fragment.');
        }
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        try {
            $response = $this->client->request('POST', rtrim($baseUrl, '/').'/api/v1/jetpay/'.$operation, [
                RequestOptions::JSON => $payload,
                RequestOptions::HEADERS => $headers,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::CONNECT_TIMEOUT => 10,
                RequestOptions::TIMEOUT => 30,
            ]);
        } catch (GuzzleException $exception) {
            // Do not expose credentials, tokens, or request bodies in exception messages.
            throw new RuntimeException('Unable to contact Bajet. Check transaction status before retrying.');
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            throw new RuntimeException('Bajet returned an invalid JSON response.');
        }
        if ($response->getStatusCode() !== 200 || ($body['status'] ?? null) !== 200
            || ($body['success'] ?? null) !== true) {
            $code = $body['result']['error']['code'] ?? $response->getStatusCode();
            throw new RuntimeException('Bajet '.$operation.' request failed.', is_int($code) ? $code : 0);
        }
        if (!isset($body['result']) || !is_array($body['result'])) {
            throw new RuntimeException('Bajet returned an invalid result.');
        }

        return $body['result'];
    }

    private function apiAmount(): int
    {
        $amount = $this->integer($this->invoice->getAmount());
        $source = $this->currencyRatio($this->settings->currency ?? null);
        $target = $this->currencyRatio($this->settings->apiCurrency ?? null);
        if ($amount === 0 || $amount > intdiv(PHP_INT_MAX, $source)) {
            throw new RuntimeException('Bajet requires a positive, supported invoice amount.');
        }
        $rial = $amount * $source;
        if ($rial % $target !== 0) {
            throw new RuntimeException('Bajet amount conversion must not discard fractional units.');
        }

        return intdiv($rial, $target);
    }

    private function currencyRatio(mixed $currency): int
    {
        $value = $currency instanceof UnitEnum ? $currency->name : $currency;

        return match ($value) {
            'RIAL', 'R' => 1,
            'TOMAN', 'T' => 10,
            default => throw new RuntimeException('Configure Bajet currency and apiCurrency explicitly (R or T).'),
        };
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new RuntimeException('Bajet returned or received an invalid amount.');
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($number === false) {
            throw new RuntimeException('Bajet requires non-negative integer amounts.');
        }

        return $number;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('A required Bajet field is missing or invalid.');
        }

        return $value;
    }

    private function httpsUrl(mixed $value): string
    {
        $url = $this->text($value);
        if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new RuntimeException('Bajet requires a valid HTTPS URL without credentials.');
        }

        return $url;
    }

    private function basket(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items)) {
            throw new RuntimeException('Bajet basketItems must be a list.');
        }
        $basket = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Invalid Bajet basket item.');
            }
            $count = $this->integer($item['count'] ?? null);
            if ($count === 0) {
                throw new RuntimeException('Bajet basket item count must be positive.');
            }
            $basket[] = [
                'brand' => $this->text($item['brand'] ?? null),
                'productType' => $this->integer($item['productType'] ?? null),
                'count' => $count,
            ];
        }

        return $basket;
    }
}
