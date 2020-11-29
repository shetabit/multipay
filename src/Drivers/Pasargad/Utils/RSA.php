<?php

namespace Shetabit\Multipay\Drivers\Pasargad\Utils;

class RSA
{
    public const BCCOMP_LARGER = 1;

    public static function rsaEncrypt($message, $public_key, $modulus, $keylength)
    {
        $padded = RSA::addPKCS1Padding($message, true, $keylength / 8);
        $number = RSA::binaryToNumber($padded);
        $encrypted = RSA::powMod($number, $public_key, $modulus);
        $result = RSA::numberToBinary($encrypted, $keylength / 8);
        return $result;
    }

    public static function rsaDecrypt($message, $private_key, $modulus, $keylength)
    {
        $number = RSA::binaryToNumber($message);
        $decrypted = RSA::powMod($number, $private_key, $modulus);
        $result = RSA::numberToBinary($decrypted, $keylength / 8);
        return RSA::removePKCS1Padding($result, $keylength / 8);
    }

    public static function rsaSign($message, $private_key, $modulus, $keylength)
    {
        $padded = RSA::addPKCS1Padding($message, false, $keylength / 8);
        $number = RSA::binaryToNumber($padded);
        $signed = RSA::powMod($number, $private_key, $modulus);
        $result = RSA::numberToBinary($signed, $keylength / 8);
        return $result;
    }

    public static function rsaVerify($message, $public_key, $modulus, $keylength)
    {
        return RSA::rsaDecrypt($message, $public_key, $modulus, $keylength);
    }

    public static function rsaKypVerify($message, $public_key, $modulus, $keylength)
    {
        $number = RSA::binaryToNumber($message);
        $decrypted = RSA::powMod($number, $public_key, $modulus);
        $result = RSA::numberToBinary($decrypted, $keylength / 8);
        return RSA::removeKYPPadding($result, $keylength / 8);
    }

    public static function powMod($p, $q, $r)
    {
        $factors = array();
        $div = $q;
        $power_of_two = 0;
        while (bccomp($div, "0") == self::BCCOMP_LARGER) {
            $rem = bcmod($div, 2);
            $div = bcdiv($div, 2);
            if ($rem) {
                array_push($factors, $power_of_two);
            }
            $power_of_two++;
        }
        $partial_results = array();
        $part_res = $p;
        $idx = 0;
        foreach ($factors as $factor) {
            while ($idx < $factor) {
                $part_res = bcpow($part_res, "2");
                $part_res = bcmod($part_res, $r);
                $idx++;
            }
            array_push($partial_results, $part_res);
        }
        $result = "1";
        foreach ($partial_results as $part_res) {
            $result = bcmul($result, $part_res);
            $result = bcmod($result, $r);
        }
        return $result;
    }

    public static function addPKCS1Padding($data, $isPublicKey, $blocksize)
    {
        $pad_length = $blocksize - 3 - strlen($data);
        if ($isPublicKey) {
            $block_type = "\x02";
            $padding = "";
            for ($i = 0; $i < $pad_length; $i++) {
                $rnd = mt_rand(1, 255);
                $padding .= chr($rnd);
            }
        } else {
            $block_type = "\x01";
            $padding = str_repeat("\xFF", $pad_length);
        }
        return "\x00" . $block_type . $padding . "\x00" . $data;
    }

    public static function removePKCS1Padding($data, $blocksize)
    {
        assert(strlen($data) == $blocksize);
        $data = substr($data, 1);
        if ($data[0] == '\0') {
            die("Block type 0 not implemented.");
        }

        assert(($data[0] == "\x01") || ($data[0] == "\x02"));
        $offset = strpos($data, "\0", 1);
        return substr($data, $offset + 1);
    }

    public static function removeKYPPadding($data, $blocksize)
    {
        assert(strlen($data) == $blocksize);
        $offset = strpos($data, "\0");
        return substr($data, 0, $offset);
    }

    public static function binaryToNumber($data)
    {
        $base = "256";
        $radix = "1";
        $result = "0";
        for ($i = strlen($data) - 1; $i >= 0; $i--) {
            $digit = ord($data[$i]);
            $part_res = bcmul($digit, $radix);
            $result = bcadd($result, $part_res);
            $radix = bcmul($radix, $base);
        }
        return $result;
    }

    public static function numberToBinary($number, $blocksize)
    {
        $base = "256";
        $result = "";
        $div = $number;
        while ($div > 0) {
            $mod = bcmod($div, $base);
            $div = bcdiv($div, $base);
            $result = chr($mod) . $result;
        }
        return str_pad($result, $blocksize, "\x00", STR_PAD_LEFT);
    }
}
