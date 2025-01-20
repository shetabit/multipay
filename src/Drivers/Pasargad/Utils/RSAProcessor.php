<?php

namespace Shetabit\Multipay\Drivers\Pasargad\Utils;

use Shetabit\Multipay\Drivers\Pasargad\Utils\RSA;

class RSAProcessor
{
    public const KEY_TYPE_XML_FILE = 'xml_file';
    public const KEY_TYPE_XML_STRING = 'xml_string';

    private string $publicKey;
    private string $privateKey;
    private string $modulus;
    private int $keyLength;

    public function __construct($key, $keyType = null)
    {
        $xmlObject = null;
        $keyType = is_null($keyType) ? null : strtolower($keyType);

        if ($keyType == null || $keyType === self::KEY_TYPE_XML_STRING) {
            $xmlObject = simplexml_load_string($key);
        } elseif ($keyType === self::KEY_TYPE_XML_FILE) {
            $xmlObject = simplexml_load_file($key);
        }

        $this->modulus = RSA::binaryToNumber(base64_decode($xmlObject->Modulus));
        $this->publicKey = RSA::binaryToNumber(base64_decode($xmlObject->Exponent));
        $this->privateKey = RSA::binaryToNumber(base64_decode($xmlObject->D));
        $this->keyLength = strlen(base64_decode($xmlObject->Modulus)) * 8;
    }

    /**
     * Retrieve public key
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Retrieve private key
     */
    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    /**
     * Retrieve key length
     */
    public function getKeyLength(): int
    {
        return $this->keyLength;
    }

    /**
     * Retrieve modulus
     */
    public function getModulus(): string
    {
        return $this->modulus;
    }

    /**
     * Encrypt given data
     */
    public function encrypt(string $data): string
    {
        return base64_encode(RSA::rsaEncrypt($data, $this->publicKey, $this->modulus, $this->keyLength));
    }

    /**
     * Decrypt given data
     *
     * @param $data
     */
    public function decrypt($data): string
    {
        return RSA::rsaDecrypt($data, $this->privateKey, $this->modulus, $this->keyLength);
    }

    /**
     * Sign given data
     *
     *
     */
    public function sign(string $data): string
    {
        return RSA::rsaSign($data, $this->privateKey, $this->modulus, $this->keyLength);
    }

    /**
     * Verify RSA data
     *
     * @param string $data
     */
    public function verify($data): string
    {
        return RSA::rsaVerify($data, $this->publicKey, $this->modulus, $this->keyLength);
    }
}
