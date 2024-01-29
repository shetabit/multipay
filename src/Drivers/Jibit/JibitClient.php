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
     * API key
     *
     * @var string
     */
    private $apiKey;

    /**
     * Secret key
     *
     * @var string
     */
    private $secretKey;

    /**
     * Refresh token
     * @var string
     */
    private $refreshToken;

    /**
     * Cache
     *
     * @var FileCache
     */
    private $cache;

    /**
     * Base URL
     *
     * @var string
     */
    public $baseUrl;


    /**
     * @throws CacheException
     */
    public function __construct($apiKey, $secretKey, $baseUrl, $cachePath)
    {
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
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
     * @param null $description
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
    public function getOrderById($id)
    {
        return  $this->callCurl('/purchases?purchaseId=' . $id, [], true, 0, 'GET');
    }

    /**
     * Generate token
     *
     * @param bool $isForce
     * @return string
     * @throws PurchaseFailedException
     * @throws InvalidArgumentException
     */
    private function generateToken($isForce = false)
    {
        if ($isForce === false && $this->cache->has('accessToken')) {
            return $this->setAccessToken($this->cache->get('accessToken'));
        } elseif ($this->cache->has('refreshToken')) {
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
    private function refreshTokens()
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
     * @param bool $haveAuth
     * @param int $try
     * @param string $method
     * @return bool|mixed|string
     * @throws PurchaseFailedException
     */
    private function callCurl($url, $arrayData, $haveAuth = false, $try = 0, $method = 'POST')
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

        if ($err) {
            throw new PurchaseFailedException('cURL Error #:' . $err);
        }

        if (empty($result['errors'])) {
            return $result;
        }

        if ($haveAuth === true && $result['errors'][0]['code'] === 'security.auth_required') {
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
    public function setAccessToken($accessToken)
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
        $this->refreshToken = $refreshToken;
    }

    /**
     * Generate new token
     *
     * @return string
     * @throws PurchaseFailedException
     * @throws InvalidArgumentException
     */
    private function generateNewToken()
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
     * @param string $purchaseId
     * @return bool|mixed|string
     * @throws PurchaseFailedException
     */
    public function paymentVerify($purchaseId)
    {
        $this->generateToken();
        $data = [];

        return $this->callCurl('/purchases/' . $purchaseId . '/verify', $data, true, 0, 'GET');
    }
}
