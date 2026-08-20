<?php
namespace Shetabit\Multipay\Drivers\Jibit;

use Psr\SimpleCache\CacheException;
use Psr\SimpleCache\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use chillerlan\SimpleCache\CacheOptions;
use chillerlan\SimpleCache\FileCache;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;

class JibitClient
{
    /**
     * Access token
     */
    public string|null $accessToken = null;

    /**
     * Cache
     */
    private readonly CacheInterface $cache;


    /**
     * @throws CacheException
     */
    public function __construct(/**
         * API key
         */
        private readonly string $apiKey, /**
         * Secret key
         */
        private readonly string $secretKey, /**
         * Base URL
         */
        public string $baseUrl,
        string $cachePath
    ) {
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        $this->cache = new FileCache(
            new CacheOptions([
                'cacheFilestorage' => $cachePath,
            ])
        );
    }

    /**
     * Request payment
     *
     * @param int $amount
     * @param string $referenceNumber
     * @param string $userIdentifier
     * @param $callbackUrl
     * @param string $currency
     * @param array $payload
     * @return bool|mixed|string
     * @throws PurchaseFailedException
     */
    public function paymentRequest(
        int    $amount,
        string $referenceNumber,
        string $userIdentifier,
        $callbackUrl,
        string $currency = 'IRR',
        array $payload = []
    ): mixed
    {
        $this->generateToken();

        $data = [
            'amount' => $amount,
            'currency' => $currency,
            'clientReferenceNumber' => (string) $referenceNumber,
            'callbackUrl' => $callbackUrl,
            'userIdentifier' => $userIdentifier,
        ];

        if (is_array($payload)) {
            $payload = array_filter($payload, static fn ($value) => $value !== null);

            if (isset($payload['additionalData']) && is_array($payload['additionalData'])) {
                $payload['additionalData'] = json_encode($payload['additionalData'], JSON_UNESCAPED_UNICODE);
            }

            $data = array_merge($data, $payload);
        }

        return $this->callCurl('/purchases', $data, true);
    }

    /**
     *
     * Get order by ID
     * @param $id
     * @return bool|mixed|string
     * @throws PurchaseFailedException
     */
    public function getOrderById(string $id) : mixed
    {
        return  $this->callCurl('/purchases?purchaseId=' . $id, [], true, 0, 'GET');
    }

    /**
     * Generate token
     *
     * @throws PurchaseFailedException
     * @throws InvalidArgumentException
     */
    private function generateToken(bool $isForce = false) : string
    {
        if ($isForce === false && $this->cache->has('accessToken')) {
            $accessToken = $this->cache->get('accessToken');

            $this->setAccessToken($accessToken);

            return $accessToken;
        }
        if ($this->cache->has('refreshToken')) {
            $refreshToken = $this->refreshTokens();
            if ($refreshToken !== 'ok') {
                return $this->generateNewToken();
            }
        } else {
            return $this->generateNewToken();
        }

        throw new PurchaseFailedException('Token generation encountered an error.');
    }

    /**
     * Refresh tokens
     * @throws PurchaseFailedException
     * @throws InvalidArgumentException
     */
    private function refreshTokens(): string
    {
        $data = [
            'accessToken' => str_replace('Bearer ', '', $this->cache->get('accessToken')),
            'refreshToken' => $this->cache->get('refreshToken'),
        ];

        $result = $this->callCurl('/tokens/refresh', $data, false);

        if (empty($result['accessToken'])) {
            throw new PurchaseFailedException('Refresh token encountered an error.');
        }

        if (!empty($result['accessToken'])) {
            $this->cache->set('accessToken', 'Bearer ' . $result['accessToken'], 24 * 60 * 60 - 60);
            $this->cache->set('refreshToken', $result['refreshToken'], 48 * 60 * 60 - 60);

            $this->setAccessToken('Bearer ' . $result['accessToken']);
            $this->setRefreshToken($result['refreshToken']);

            return 'ok';
        }

        throw new PurchaseFailedException('Refresh token encountered an error.');
    }

    /**
     * Call curl
     *
     * @param $url
     * @param $arrayData
     * @return bool|mixed|string
     * @throws PurchaseFailedException
     */
    private function callCurl(string $url, array $arrayData, bool $haveAuth = false, int $try = 0, string $method = 'POST') : mixed
    {
        $data = $arrayData;
        $jsonData = json_encode($data);
        $accessToken = '';

        if ($haveAuth) {
            $accessToken = $this->getAccessToken();
        }

        $ch = curl_init($this->baseUrl . $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Jibit.class Rest Api');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ' . $accessToken,
            'Content-Length: ' . strlen($jsonData)
        ]);

        $result = curl_exec($ch);
        $err = curl_error($ch);
        $result = json_decode($result, true);

        if ($err !== '' && $err !== '0') {
            throw new PurchaseFailedException('cURL Error #:' . $err);
        }

        if (empty($result['errors'])) {
            return $result;
        }

        if ($haveAuth && $result['errors'][0]['code'] === 'security.auth_required') {
            $this->generateToken(true);

            if ($try === 0) {
                return $this->callCurl($url, $arrayData, $haveAuth, 1, $method);
            }

            throw new PurchaseFailedException('Authentication encountered an error.');
        }

        return $result;
    }

    /**
     * Get access token
     */
    public function getAccessToken() : string|null
    {
        return $this->accessToken;
    }

    /**
     * Set access token
     */
    public function setAccessToken(string|null $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Set refresh token
     */
    public function setRefreshToken(mixed $refreshToken) : void
    {
    }

    /**
     * Generate new token
     *
     * @throws PurchaseFailedException
     * @throws InvalidArgumentException
     */
    private function generateNewToken(): string
    {
        $data = [
            'apiKey' => $this->apiKey,
            'secretKey' => $this->secretKey,
        ];

        $result = $this->callCurl('/tokens', $data);

        if (empty($result['accessToken'])) {
            throw new PurchaseFailedException('Token generation encoutered an error.');
        }

        if (! empty($result['accessToken'])) {
            $this->cache->set('accessToken', 'Bearer ' . $result['accessToken'], 24 * 60 * 60 - 60);
            $this->cache->set('refreshToken', $result['refreshToken'], 48 * 60 * 60 - 60);

            $this->setAccessToken('Bearer ' . $result['accessToken']);
            $this->setRefreshToken($result['refreshToken']);

            return 'ok';
        }

        throw new PurchaseFailedException('Token generation encoutered an error.');
    }

    /**
     * Verify payment
     *
     * @return bool|mixed|string
     * @throws PurchaseFailedException
     */
    public function paymentVerify(string $purchaseId) : mixed
    {
        $this->generateToken();
        $data = [];

        return $this->callCurl('/purchases/' . $purchaseId . '/verify', $data, true, 0, 'GET');
    }
}
