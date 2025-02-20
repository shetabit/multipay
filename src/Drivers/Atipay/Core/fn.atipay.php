<?php

define('ATIPAY_URL', 'https://mipg.atipay.net/v1/');
define('ATIPAY_TOKEN_URL', ATIPAY_URL . 'get-token');
define('ATIPAY_REDIRECT_TO_PSP_URL', ATIPAY_URL . 'redirect-to-gateway');
define('ATIPAY_VERIFY_URL', ATIPAY_URL . 'verify-payment');

/**
 * @return mixed[]
 */
function fn_atipay_get_token($params): array
{
    $r = wsRequestPost(ATIPAY_TOKEN_URL, $params);
    $return = [];
    if ($r) {
        if (isset($r['status']) && !empty($r['status'])) {
            $status = $r['status'];
            if ($status == 1) {
                $return['success']=1;
                $return['token']=$r['token'];
            } else {
                $return['success']=0;
                $return['errorMessage']=$r['errorDescription'];
            }
        } else {
            $return['success']=0;
            if (isset($r['faMessage']) && !empty($r['faMessage'])) {
                $return['errorMessage'] = $r['faMessage'];
            } else {
                $return['errorMessage'] = "خطا در دریافت توکن پرداخت";
            }
        }
    } else {
        $return['success']=0;
        $return['errorMessage'] = "خطا در دریافت اطلاعات توکن پرداخت";
    }

    return $return;
}


function fn_atipay_redirect_to_psp_form($token): string
{
    return _fn_generate_redirect_form($token);
}

function _fn_generate_redirect_form(string $token): string
{
    $form = '<form action="'.ATIPAY_REDIRECT_TO_PSP_URL.'" method="POST" align="center" name="atipay_psp_form" id="atipay_psp_form">';
    $form .= '<input type="hidden" value="'.$token.'" name="token" >';
    $form .= "<input type='submit' value='' class='d-none'/>";

    return $form . '</form><script>document.getElementById("atipay_psp_form").submit(); </script>';
}

function fn_atipay_get_token_form($params, $submit_text, $action): string
{
    return _fn_generate_get_token_form($params, $submit_text, $action);
}

function _fn_generate_get_token_form($params, $submit_text, $action): string
{
    $form ="<form action='$action' method='POST' align='center' name='atipay_payment_form_token' id='atipay_payment_form_token' >";
    foreach ($params as $k => $v) {
        $form .= "<input type='hidden' value='$v' name='$k' >";
    }

    $form .= "<input type='submit' value='$submit_text' name='submit' >";

    return $form . "</form>";
}

function fn_check_callback_data($params): array
{
    $result = [];
    if (isset($params['state']) && !empty($params['state'])) {
        $state = $params['state'];
        if ($state == 'OK') {
            $result['success']=1;
            $result['error']="";
        } else {
            $result['success']=0;
            $result['error']= _fn_return_state_text($state);
        }
    } else {
        $result['success']=0;
        $result['error']="خطای نامشخص در پرداخت. در صورتیکه مبلغی از شما کسر شده باشد، برگشت داده می شود.";
    }

    return $result;
}

/**
 * @return int[]|'خطا در تایید مبلغ پرداخت.در صورتیکه مبلغی از شما کسر شده باشد، برگشت داده می شود.'[]|'خطا در تایید اطلاعات پرداخت. در صورتیکه مبلغی از شما کسر شده باشد، برگشت داده می شود.'[]|'خطا در تایید نهایی پرداخت. در صورتیکه مبلغی از شما کسر شده باشد، برگشت داده می شود.'[]|''[]
 */
function fn_atipay_verify_payment($params, $amount): array
{
    $r = wsRequestPost(ATIPAY_VERIFY_URL, $params);
    $return = [];
    if ($r) {
        if (isset($r['amount']) && !empty($r['amount'])) {
            if ($r['amount'] == $amount) {
                $return['success']=1;
                $return['errorMessage']="";
            } else {
                $return['success']=0;
                $return['errorMessage']="خطا در تایید مبلغ پرداخت.در صورتیکه مبلغی از شما کسر شده باشد، برگشت داده می شود.";
            }
        } else {
            $return['success']=0;
            $return['errorMessage']="خطا در تایید اطلاعات پرداخت. در صورتیکه مبلغی از شما کسر شده باشد، برگشت داده می شود.";
        }
    } else {
        $return['success']=0;
        $return['errorMessage'] = "خطا در تایید نهایی پرداخت. در صورتیکه مبلغی از شما کسر شده باشد، برگشت داده می شود.";
    }

    return $return;
}

function _fn_return_state_text($state): string
{
    return match ($state) {
        "CanceledByUser" => "پرداخت توسط شما لغو شده است.",
        "Failed" => "پرداخت انجام نشد.",
        "SessionIsNull" => "کاربر در بازه زمانی تعیین شده پاسخی ارسال نکرده است",
        "InvalidParameters" => "پارامترهاي ارسالی نامعتبر است",
        "MerchantIpAddressIsInvalid" => "آدرس سرور پذیرنده نامعتبر است",
        "TokenNotFound" => "توکن ارسال شده یافت نشد",
        "TokenRequired" => "با این شماره ترمینال فقط تراکنش هاي توکنی قابل پرداخت هستند",
        "TerminalNotFound" => "شماره ترمینال ارسال شده یافت نشد",
        default => "خطای نامشخص در عملیات پرداخت",
    };
}


function fn_check_callback_params($params): bool
{
    return !(!isset($params['state']) || !isset($params['status']) || !isset($params['reservationNumber']) || !isset($params['referenceNumber']) || !isset($params['terminalId']) || !isset($params['traceNumber']));
}





function wsRequestGet($url): bool|string
{
    set_time_limit(30);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); //timeout in seconds
    $json = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode == "200") {
        //nothing YET
    } else {
        $json= json_encode(['error'=>'Y']);
    }

    return $json;
}

function wsRequestPost($url, $params)
{
    set_time_limit(30);
    $ch = curl_init($url);
    $postFields = json_encode($params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); //timeout in seconds
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json;"]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    $json = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode == "200") {
        return json_decode($json, true);
    }

    return ['error'=>'Y','jsonError'=>$httpcode,'message'=>$httpcode];
}
