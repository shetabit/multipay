<?php

namespace Shetabit\Multipay\Drivers\Bajet;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Contracts\Refundable;
use Shetabit\Multipay\Contracts\Reversible;
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
class Bajet extends Driver implements Refundable, Reversible
{
    /**
     * The statuses of a payment the gateway has settled, compared in lower case.
     */
    private const SUCCESS_STATUSES = ['success', 'successful', 'completed'];

    /**
     * The operations whose answer may come without its "result" envelope.
     */
    private const UNWRAPPED_OPERATIONS = ['reverse', 'refund', 'refund-inquiry', self::REFUND_CHECK];

    /**
     * The operation that may answer without a "success" flag at all.
     */
    private const REFUND_CHECK = 'terminal/check-refund';

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
            $status = $result['status'] ?? $result['finalStatus'] ?? null;
            if (!is_string($status)) {
                throw new RuntimeException('Bajet returned an invalid payment status.');
            }
            $status = strtolower($status);
            if ($status === 'verified') {
                throw new PreviouslyVerifiedException('Bajet transaction was already verified.');
            }
            if (!in_array($status, self::SUCCESS_STATUSES, true)) {
                throw new RuntimeException('Bajet transaction was not successfully verified.');
            }
            $hasAmount = array_key_exists('amount', $result);
            $hasSplit = array_key_exists('creditAmount', $result) || array_key_exists('cashAmount', $result);
            if (!$hasAmount && !$hasSplit) {
                throw new RuntimeException('Bajet verification did not confirm the paid amount.');
            }
            if ($hasAmount && $this->integer($result['amount']) !== $amount) {
                throw new RuntimeException('Bajet transaction amount does not match the invoice.');
            }
            if ($hasSplit) {
                $credit = $this->integer(array_key_exists('creditAmount', $result) ? $result['creditAmount'] : 0);
                $cash = $this->integer(array_key_exists('cashAmount', $result) ? $result['cashAmount'] : 0);
                if ($credit > $amount || $cash !== $amount - $credit) {
                    throw new RuntimeException('Bajet paid amount does not match the invoice.');
                }
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
        $this->checkReference($result, $referenceId);
        $expectedOrder = $this->invoice->getDetail('orderId');
        if ($expectedOrder !== null && ($result['orderId'] ?? null) !== $this->text($expectedOrder)) {
            throw new RuntimeException('Bajet order does not match the invoice.');
        }
    }

    private function checkReference(array $result, string $referenceId): void
    {
        if (($result['referenceId'] ?? null) !== $referenceId) {
            throw new RuntimeException('Bajet transaction reference does not match the invoice.');
        }
    }

    public function checkAuthentication(): bool
    {
        try {
            $this->token();
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
            $result = $this->authedRequest(self::REFUND_CHECK, [], 'GET');
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
                $this->checkReference($result, $referenceId);
            }

            return $result;
        } catch (RuntimeException $exception) {
            throw new InvalidPaymentException($exception->getMessage(), $exception->getCode());
        }
    }

    private function token(): string
    {
        $result = $this->result('token', $this->send('token', [
            'username' => $this->text($this->settings->username ?? null),
            'password' => $this->text($this->settings->password ?? null),
            'terminalId' => $this->text($this->settings->terminalId ?? null),
        ]));

        return $this->text($result['token'] ?? null);
    }

    /**
     * Send an operation with a token of its own, and once more with a new one if the gateway did not accept it.
     *
     * Only a 401 is retried: the gateway refused the token there, not the operation, so even an operation that
     * moves money is safe to send again.
     */
    private function authedRequest(string $operation, array $payload, string $method = 'POST'): array
    {
        $response = $this->send($operation, $payload, $this->token(), $method);
        if ($response->getStatusCode() === 401) {
            $response = $this->send($operation, $payload, $this->token(), $method);
        }

        return $this->result($operation, $response);
    }

    private function send(
        string $operation,
        array $payload,
        ?string $token = null,
        string $method = 'POST'
    ): ResponseInterface {
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
            return $this->client->request($method, rtrim($baseUrl, '/').'/api/v1/jetpay/'.$operation, $options);
        } catch (GuzzleException $exception) {
            // Do not expose credentials, tokens, or request bodies in exception messages.
            throw new RuntimeException('Unable to contact Bajet. Check transaction status before retrying.');
        }
    }

    private function result(string $operation, ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            throw new RuntimeException('Bajet returned an invalid JSON response.', $response->getStatusCode());
        }
        if (!$this->isSuccessful($response, $body, $operation)) {
            $code = $body['result']['error']['code'] ?? $response->getStatusCode();
            throw new RuntimeException('Bajet '.$operation.' request failed.', is_int($code) ? $code : 0);
        }
        if (!array_key_exists('result', $body) && in_array($operation, self::UNWRAPPED_OPERATIONS, true)) {
            return $body;
        }
        if (!isset($body['result']) || !is_array($body['result'])) {
            throw new RuntimeException('Bajet returned an invalid result.');
        }

        return $body['result'];
    }

    private function isSuccessful(ResponseInterface $response, array $body, string $operation): bool
    {
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }
        if (isset($body['status']) && is_numeric($body['status']) && (int) $body['status'] >= 400) {
            return false;
        }
        if (array_key_exists('success', $body)) {
            return $body['success'] === true;
        }

        return $operation === self::REFUND_CHECK;
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
