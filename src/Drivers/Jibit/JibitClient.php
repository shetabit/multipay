<?php
namespace Shetabit\Multipay\Drivers\Jibit;

use chillerlan\SimpleCache\CacheException;
use chillerlan\SimpleCache\CacheOptions;
use chillerlan\SimpleCache\FileCache;
use Psr\SimpleCache\InvalidArgumentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;

class JibitClient
{
    /**
     * Access token
     * @var string
     */
    public $accessToken;

    /**
     * Cache
     */
    private \chillerlan\SimpleCache\FileCache $cache;


    /**
     * @throws CacheException
     * @param string $apiKey
     * @param string $secretKey
     * @param string $baseUrl
     */
    public function __construct(/**
         * API key
         */
        private $apiKey, /**
         * Secret key
         */
        private $secretKey, /**
         * Base URL
         */
        public $baseUrl,
        $cachePath
    ) {
        $this->cache = new FileCache(
            new CacheOptions([
                'filestorage' => $cachePath,
            ])
        );
    }

    /**
     * Request payment
     *
     * @param int $amount
     * @param string $referenceNumber
     * @param string $userIdentifier
     * @param string $callbackUrl
     * @param string $currency
     * @param $additionalData
     * @return bool|mixed|string
     * @throws PurchaseFailedException
     */
    public function paymentRequest($amount, $referenceNumber, $userIdentifier, $callbackUrl, $currency = 'IRR', $description = null, $additionalData = null)
    {
        $this->generateToken();

        $data = [
            'additionalData' => $additionalData,
            'amount' => $amount,
            'callbackUrl' => $callbackUrl,
            'clientReferenceNumber' => $referenceNumber,
            'currency' => $currency,
            'userIdentifier' => $userIdentifier,
            'description' => $description,
        ];

        return $this->callCurl('/purchases', $data, true);
    }

    /**
     *
     * Get order by ID
     * @param $id
     * @return bool|mixed|string
     * @throws PurchaseFailedException
     */
    public function getOrderById(string $id)
    {
        return  $this->callCurl('/purchases?purchaseId=' . $id, [], true, 0, 'GET');
    }

    /**
     * Generate token
     *
     * @return string
     * @throws PurchaseFailedException
     * @throws InvalidArgumentException
     */
    private function generateToken(bool $isForce = false)
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
     * @param string $method
     * @return bool|mixed|string
     * @throws PurchaseFailedException
     */
    private function callCurl(string $url, $arrayData, bool $haveAuth = false, int $try = 0, $method = 'POST')
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
        curl_close($ch);

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
     *
     * @return mixed
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * Set access token
     *
     * @param mixed $accessToken
     */
    public function setAccessToken($accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Set refresh token
     *
     * @param mixed $refreshToken
     */
    public function setRefreshToken($refreshToken)
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
    public function paymentVerify(string $purchaseId)
    {
        $this->generateToken();
        $data = [];

        return $this->callCurl('/purchases/' . $purchaseId . '/verify', $data, true, 0, 'GET');
    }
}
