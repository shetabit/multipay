<?php
namespace Shetabit\Multipay\Drivers\Jibit;

class JibitBase
{
    public $accessToken;
    private $apiKey;
    private $secretKey;
    private $refreshToken;
    private $cache;
    public $base_url;

    public function __construct($apiKey, $secretKey, $base_url, $cachePath)
    {
        $this->base_url = $base_url;
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
        $this->cache = new JibitCache(['name'=>'jibit', 'path'=>$cachePath, 'extension'=>'.cache']);
    }

    /**
     * @param int $amount
     * @param string $referenceNumber
     * @param string $userIdentifier
     * @param string $callbackUrl
     * @param string $currency
     * @param null $description
     * @param $additionalData
     * @return bool|mixed|string
     * @throws Exception
     */
    public function paymentRequest($amount, $referenceNumber, $userIdentifier, $callbackUrl, $currency = 'RIALS', $description = null, $additionalData = null)
    {
        $this->generateToken();
        $data = [
            'merchantCode' => $this->apiKey,
            'password' => $this->secretKey,
            'amount' => $amount * 10, //convert to toman
            'referenceNumber' => $referenceNumber,
            'userIdentifier' => $userIdentifier,
            'callbackUrl' => $callbackUrl,
            'currency' => $currency,
            'description' => $description,
            'additionalData' => $additionalData,
        ];
        return $this->callCurl('/orders', $data, true);
    }

    /**
     * @param $id
     * @return bool|mixed|string
     * @throws Exception
     */
    public function getOrderById($id)
    {
        return  $this->callCurl('/orders/' .$id, [], true, 0, 'GET');
    }

    /**
     * @param bool $isForce
     * @return string
     * @throws Exception
     */
    private function generateToken($isForce = false)
    {
        $this->cache->eraseExpired();

        if ($isForce === false && $this->cache->isCached('accessToken')) {
            return $this->setAccessToken($this->cache->retrieve('accessToken'));
        } elseif ($this->cache->isCached('refreshToken')) {
            $refreshToken = $this->refreshTokens();
            if ($refreshToken !== 'ok') {
                return $this->generateNewToken();
            }
        } else {
            return $this->generateNewToken();
        }

        throw new \Shetabit\Multipay\Exceptions\PurchaseFailedException('unExcepted Err in generateToken.');
    }

    private function refreshTokens()
    {
        echo 'refreshing';
        $data = [
            'accessToken' => str_replace('Bearer ', '', $this->cache->retrieve('accessToken')),
            'refreshToken' => $this->cache->retrieve('refreshToken'),
        ];
        $result = $this->callCurl('/tokens/refresh', $data, false);
        if (empty($result['accessToken'])) {
            throw new \Shetabit\Multipay\Exceptions\PurchaseFailedException('Err in refresh token.');
        }
        if (!empty($result['accessToken'])) {
            $this->cache->store('accessToken', 'Bearer ' . $result['accessToken'], 24 * 60 * 60 - 60);
            $this->cache->store('refreshToken', $result['refreshToken'], 48 * 60 * 60 - 60);
            $this->setAccessToken('Bearer ' . $result['accessToken']);
            $this->setRefreshToken($result['refreshToken']);
            return 'ok';
        }
        throw new \Shetabit\Multipay\Exceptions\PurchaseFailedException('unExcepted Err in refreshToken.');
    }

    /**
     * @param $url
     * @param $arrayData
     * @param bool $haveAuth
     * @param int $try
     * @param string $method
     * @return bool|mixed|string
     * @throws Exception
     */
    private function callCurl($url, $arrayData, $haveAuth = false, $try = 0, $method = 'POST')
    {
        $data = $arrayData;
        $jsonData = json_encode($data);
        $accessToken = '';
        if ($haveAuth) {
            $accessToken = $this->getAccessToken();
        }
        $ch = curl_init($this->base_url . $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Jibit.class Rest Api');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: ' . $accessToken,
            'Content-Length: ' . strlen($jsonData)
        ));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        $result = json_decode($result, true);
        curl_close($ch);

        if ($err) {
            throw new \Shetabit\Multipay\Exceptions\PurchaseFailedException('cURL Error #:' . $err);
        }
        if (empty($result['errors'])) {
            return $result;
        }
        if ($haveAuth === true && $result['errors'][0]['code'] === 'security.auth_required') {
            $this->generateToken(true);
            if ($try === 0) {
                return $this->callCurl($url, $arrayData, $haveAuth, 1, $method);
            }
            throw new \Shetabit\Multipay\Exceptions\PurchaseFailedException('Err in auth.');
        }

        return $result;
    }

    /**
     * @return mixed
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * @param mixed $accessToken
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * @param mixed $refreshToken
     */
    public function setRefreshToken($refreshToken)
    {
        $this->refreshToken = $refreshToken;
    }

    private function generateNewToken()
    {
        $data = [
            'merchantCode' => $this->apiKey,
            'password' => $this->secretKey,
        ];
        $result = $this->callCurl('/tokens/generate', $data);

        if (empty($result['accessToken'])) {
            throw new \Shetabit\Multipay\Exceptions\PurchaseFailedException('Err in generate new token.');
        }
        if (!empty($result['accessToken'])) {
            $this->cache->store('accessToken', 'Bearer ' . $result['accessToken'], 24 * 60 * 60 - 60);
            $this->cache->store('refreshToken', $result['refreshToken'], 48 * 60 * 60 - 60);
            $this->setAccessToken('Bearer ' . $result['accessToken']);
            $this->setRefreshToken($result['refreshToken']);
            return 'ok';
        }
        throw new \Shetabit\Multipay\Exceptions\PurchaseFailedException('unExcepted Err in generateNewToken.');
    }

    /**
     * @param string $refNum
     * @return bool|mixed|string
     * @throws Exception
     */
    public function paymentVerify($refNum)
    {
        $this->generateToken();
        $data = [
        ];
        return $this->callCurl('/orders/' . $refNum . '/verify', $data, true, 0, 'GET');
    }
}
