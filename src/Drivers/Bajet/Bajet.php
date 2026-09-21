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
 * Bajet credit payments, following the provider's WooCommerce API flow.
 */
class Bajet extends Driver
{
    protected Client $client;

    private ?string $cachedToken = null;

    private int $tokenExpiresAt = 0;

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
            ];
            if (!empty($this->settings->callbackUrl)) {
                $payload['returnUrl'] = $this->text($this->settings->callbackUrl);
            }
            foreach (['mobile', 'nationalId', 'basketItems'] as $key) {
                $value = $this->invoice->getDetail($key);
                if ($value !== null) {
                    $payload[$key] = $key === 'basketItems' ? $this->basket($value) : $this->text($value);
                }
            }

            $result = $this->authedRequest('order', $payload);
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
            $result = $this->authedRequest('verify', ['referenceId' => $referenceId]);
            $this->checkIdentity($result, $referenceId);
            $status = $result['status'] ?? $result['finalStatus'] ?? '';
            if (!is_string($status)) {
                throw new RuntimeException('Bajet returned an invalid payment status.');
            }
            $status = strtolower($status);
            if ($status === 'verified') {
                throw new PreviouslyVerifiedException('Bajet transaction was already verified.');
            }
            if ($status !== '' && !in_array($status, ['success', 'successful', 'completed'], true)) {
                throw new RuntimeException('Bajet transaction was not successfully verified.');
            }
            if (array_key_exists('amount', $result) && $this->integer($result['amount']) !== $amount) {
                throw new RuntimeException('Bajet transaction amount does not match the invoice.');
            }
            $hasSplit = array_key_exists('creditAmount', $result) || array_key_exists('cashAmount', $result);
            if ($hasSplit) {
                $credit = $this->integer(array_key_exists('creditAmount', $result) ? $result['creditAmount'] : 0);
                $cash = $this->integer(array_key_exists('cashAmount', $result) ? $result['cashAmount'] : 0);
                if ($credit > $amount || $cash !== $amount - $credit) {
                    throw new RuntimeException('Bajet paid amount does not match the invoice.');
                }
            } elseif ($status === '') {
                throw new RuntimeException('Bajet verification did not confirm payment.');
            }

            $details = array_intersect_key($result, array_flip([
                'orderId', 'status', 'finalStatus', 'amount', 'creditAmount', 'cashAmount',
            ]));
            $details['apiCurrency'] = $this->settings->apiCurrency;
            return (new Receipt('bajet', $referenceId))->detail($details);
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
            $result = $this->authedRequest('inquiry', ['referenceId' => $referenceId]);
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
        $expectedOrder = $this->invoice->getDetail('orderId');
        if (array_key_exists('orderId', $result) && $expectedOrder !== null
            && $this->text($result['orderId']) !== $this->text($expectedOrder)) {
            throw new RuntimeException('Bajet order does not match the invoice.');
        }
    }

    public function checkAuthentication(): bool
    {
        try {
            $this->token(true);
            return true;
        } catch (RuntimeException $exception) {
            throw new PurchaseFailedException($exception->getMessage(), $exception->getCode());
        }
    }

    public function reverse(): array
    {
        return $this->transactionOperation('reverse');
    }

    /** Amount is in invoice currency; reuse the same trackId when reconciling a refund. */
    public function refund(?int $amount = null, ?string $trackId = null): array
    {
        try {
            $payload = [
                'trackId' => $this->text($trackId ?? $this->invoice->getDetail('trackId')),
                'amount' => (string) $this->apiAmount($amount),
            ];
            return $this->transactionOperation('refund', $payload);
        } catch (RuntimeException $exception) {
            throw new InvalidPaymentException($exception->getMessage(), $exception->getCode());
        }
    }

    public function refundInquiry(?string $trackId = null): array
    {
        try {
            return $this->transactionOperation('refund-inquiry', [
                'trackId' => $this->text($trackId ?? $this->invoice->getDetail('trackId')),
            ]);
        } catch (RuntimeException $exception) {
            throw new InvalidPaymentException($exception->getMessage(), $exception->getCode());
        }
    }

    public function isRefundEnabled(): bool
    {
        try {
            $result = $this->authedRequest('terminal/check-refund', [], 'GET');
            return ($result['refundEnabled'] ?? false) === true;
        } catch (RuntimeException $exception) {
            throw new InvalidPaymentException($exception->getMessage(), $exception->getCode());
        }
    }

    private function transactionOperation(string $operation, array $payload = []): array
    {
        try {
            $referenceId = $this->text($this->invoice->getTransactionId());
            $result = $this->authedRequest($operation, ['referenceId' => $referenceId] + $payload);
            if (array_key_exists('referenceId', $result)) {
                $this->checkIdentity($result, $referenceId);
            }

            return $result;
        } catch (RuntimeException $exception) {
            throw new InvalidPaymentException($exception->getMessage(), $exception->getCode());
        }
    }

    private function token(bool $forceRefresh = false): string
    {
        if (!$forceRefresh && $this->cachedToken !== null && time() < $this->tokenExpiresAt) {
            return $this->cachedToken;
        }
        $this->cachedToken = null;
        $this->tokenExpiresAt = 0;
        $result = $this->request('token', [
            'username' => $this->text($this->settings->username ?? null),
            'password' => $this->text($this->settings->password ?? null),
            'terminalId' => $this->text($this->settings->terminalId ?? null),
        ]);

        $token = $this->text($result['token'] ?? null);
        $ttl = min(840, $this->integer($result['expiresIn'] ?? 840));
        $this->cachedToken = $token;
        $this->tokenExpiresAt = time() + $ttl;
        return $token;
    }

    private function authedRequest(string $operation, array $payload, string $method = 'POST'): array
    {
        return $this->request($operation, $payload, $this->token(), $method, true);
    }

    private function request(
        string $operation,
        array $payload,
        ?string $token = null,
        string $method = 'POST',
        bool $refreshOnUnauthorized = false
    ): array {
        $baseUrl = $this->httpsUrl($this->settings->apiUrl ?? null);
        if (parse_url($baseUrl, PHP_URL_QUERY) !== null || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('Bajet apiUrl must not contain a query or fragment.');
        }
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        $options = [
            RequestOptions::HEADERS => $headers,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 80,
        ];
        if ($method !== 'GET') {
            $options[RequestOptions::JSON] = $payload;
        }
        try {
            $response = $this->client->request($method, rtrim($baseUrl, '/').'/api/v1/jetpay/'.$operation, $options);
        } catch (GuzzleException $exception) {
            // Do not expose credentials, tokens, or request bodies in exception messages.
            throw new RuntimeException('Unable to contact Bajet. Check transaction status before retrying.');
        }

        if ($refreshOnUnauthorized && in_array($response->getStatusCode(), [401, 403], true)) {
            return $this->request($operation, $payload, $this->token(true), $method);
        }
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            throw new RuntimeException('Bajet returned an invalid JSON response.', $response->getStatusCode());
        }
        $refundCheck = $operation === 'terminal/check-refund';
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300
            || (isset($body['status']) && is_numeric($body['status']) && (int) $body['status'] >= 400)
            || (array_key_exists('success', $body) ? $body['success'] !== true : !$refundCheck)) {
            $code = $body['result']['error']['code'] ?? $response->getStatusCode();
            throw new RuntimeException('Bajet '.$operation.' request failed.', is_int($code) ? $code : 0);
        }
        if (!array_key_exists('result', $body) && in_array($operation, [
            'reverse', 'refund', 'refund-inquiry', 'terminal/check-refund',
        ], true)) {
            return $body;
        }
        if (!isset($body['result']) || !is_array($body['result'])) {
            throw new RuntimeException('Bajet returned an invalid result.');
        }

        return $body['result'];
    }

    private function apiAmount(?int $requestedAmount = null): int
    {
        $amount = $this->integer($requestedAmount ?? $this->invoice->getAmount());
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
