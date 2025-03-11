<?php

use Shetabit\Multipay\Drivers\Aqayepardakht\Aqayepardakht;
use Shetabit\Multipay\Drivers\Asanpardakht\Asanpardakht;
use Shetabit\Multipay\Drivers\Atipay\Atipay;
use Shetabit\Multipay\Drivers\Azki\Azki;
use Shetabit\Multipay\Drivers\Behpardakht\Behpardakht;
use Shetabit\Multipay\Drivers\Bitpay\Bitpay;
use Shetabit\Multipay\Drivers\Digipay\Digipay;
use Shetabit\Multipay\Drivers\Etebarino\Etebarino;
use Shetabit\Multipay\Drivers\Fanavacard\Fanavacard;
use Shetabit\Multipay\Drivers\Gooyapay\Gooyapay;
use Shetabit\Multipay\Drivers\IranDargah\IranDargah;
use Shetabit\Multipay\Drivers\Irankish\Irankish;
use Shetabit\Multipay\Drivers\Jibit\Jibit;
use Shetabit\Multipay\Drivers\Local\Local;
use Shetabit\Multipay\Drivers\Minipay\Minipay;
use Shetabit\Multipay\Drivers\Nextpay\Nextpay;
use Shetabit\Multipay\Drivers\Omidpay\Omidpay;
use Shetabit\Multipay\Drivers\Parsian\Parsian;
use Shetabit\Multipay\Drivers\Parspal\Parspal;
use Shetabit\Multipay\Drivers\Pasargad\Pasargad;
use Shetabit\Multipay\Drivers\Payfa\Payfa;
use Shetabit\Multipay\Drivers\Paypal\Paypal;
use Shetabit\Multipay\Drivers\Payping\Payping;
use Shetabit\Multipay\Drivers\Paystar\Paystar;
use Shetabit\Multipay\Drivers\Pna\Pna;
use Shetabit\Multipay\Drivers\Poolam\Poolam;
use Shetabit\Multipay\Drivers\Rayanpay\Rayanpay;
use Shetabit\Multipay\Drivers\Sadad\Sadad;
use Shetabit\Multipay\Drivers\Saman\Saman;
use Shetabit\Multipay\Drivers\SEP\SEP;
use Shetabit\Multipay\Drivers\Sepehr\Sepehr;
use Shetabit\Multipay\Drivers\Sepordeh\Sepordeh;
use Shetabit\Multipay\Drivers\Shepa\Shepa;
use Shetabit\Multipay\Drivers\Sizpay\Sizpay;
use Shetabit\Multipay\Drivers\SnappPay\SnappPay;
use Shetabit\Multipay\Drivers\Tara\Tara;
use Shetabit\Multipay\Drivers\Toman\Toman;
use Shetabit\Multipay\Drivers\Vandar\Vandar;
use Shetabit\Multipay\Drivers\Yekpay\Yekpay;
use Shetabit\Multipay\Drivers\Zarinpal\Zarinpal;
use Shetabit\Multipay\Drivers\Zibal\Zibal;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | This value determines which of the following gateway to use.
    | You can switch to a different driver at runtime.
    |
    */
    'default' => 'zarinpal',

    /*
    |--------------------------------------------------------------------------
    | List of Drivers
    |--------------------------------------------------------------------------
    |
    | These are the list of drivers to use for this package.
    | You can change the name. Then you'll have to change
    | it in the map array too.
    |
    */
    'drivers' => [
        'local' => [
            'callbackUrl' => '/callback',
            'title' => 'درگاه پرداخت تست',
            'description' => 'این درگاه *صرفا* برای تست صحت روند پرداخت و لغو پرداخت میباشد',
            'orderLabel' => 'شماره سفارش',
            'amountLabel' => 'مبلغ قابل پرداخت',
            'payButton' => 'پرداخت موفق',
            'cancelButton' => 'پرداخت ناموفق',
        ],
        'gooyapay' => [
            'apiPurchaseUrl' => 'https://gooyapay.ir/webservice/rest/PaymentRequest',
            'apiVerificationUrl' => 'https://gooyapay.ir/webservice/rest/PaymentVerification',
            'apiPaymentUrl' => 'https://gooyapay.ir/startPay/',
            'merchantId' => 'XXXX-XXXX-XXXX-XXXXXXXXXXXXXXXXXXXXX',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'fanavacard' => [
            'baseUri' => 'https://fcp.shaparak.ir',
            'apiPaymentUrl' => '_ipgw_//payment/',
            'apiPurchaseUrl' => 'ref-payment/RestServices/mts/generateTokenWithNoSign/',
            'apiVerificationUrl' => 'ref-payment/RestServices/mts/verifyMerchantTrans/',
            'apiReverseAmountUrl' => 'ref-payment/RestServices/mts/reverseMerchantTrans/',
            'username' => 'xxxxxxx',
            'password' => 'xxxxxxx',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'atipay' => [
            'atipayTokenUrl' => 'https://mipg.atipay.net/v1/get-token',
            'atipayRedirectGatewayUrl' => 'https://mipg.atipay.net/v1/redirect-to-gateway',
            'atipayVerifyUrl' => 'https://mipg.atipay.net/v1/verify-payment',
            'apikey' => '',
            'currency' => 'R', //Can be R, T (Rial, Toman)
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using Atipay',
        ],
        'asanpardakht' => [
            'apiPaymentUrl' => 'https://asan.shaparak.ir',
            'apiRestPaymentUrl' => 'https://ipgrest.asanpardakht.ir/v1/',
            'username' => '',
            'password' => '',
            'merchantConfigID' => '',
            'currency' => 'T', //Can be R, T (Rial, Toman)
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using asanpardakht',
        ],
        'behpardakht' => [
            'apiPurchaseUrl' => 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl',
            'apiPaymentUrl' => 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat',
            'apiVerificationUrl' => 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl',
            'terminalId' => '',
            'username' => '',
            'password' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using behpardakht',
            'currency' => 'T', //Can be R, T (Rial, Toman)
            'cumulativeDynamicPayStatus' => false,
        ],
        'digipay' => [
            'apiPaymentUrl' => 'https://api.mydigipay.com', // with out '/' at the end
            'username' => 'username',
            'password' => 'password',
            'client_id' => '',
            'client_secret' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'currency' => 'R', //Can be R, T (Rial, Toman)
        ],
        'etebarino' => [
            'apiPurchaseUrl' => 'https://api.etebarino.com/public/merchant/request-payment',
            'apiPaymentUrl' => 'https://panel.etebarino.com/gateway/public/ipg',
            'apiVerificationUrl' => 'https://api.etebarino.com/public/merchant/verify-payment',
            'merchantId' => '',
            'terminalId' => '',
            'username' => '',
            'password' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using etebarino',
        ],
        'irandargah' => [
            /* Normal api */
            'apiPurchaseUrl' => 'https://dargaah.com/payment',
            'apiPaymentUrl' => 'https://dargaah.com/ird/startpay/',
            'apiVerificationUrl' => 'https://dargaah.com/verification',

            /* Sandbox api */
            'sandboxApiPurchaseUrl' => ' https://dargaah.com/sandbox/payment',
            'sandboxApiPaymentUrl' => 'https://dargaah.com/sandbox/ird/startpay/',
            'sandboxApiVerificationUrl' => 'https://dargaah.com/sandbox/verification',

            'sandbox' => false, // Set it to true for test environments
            'merchantId' => '', // Set `TEST` for test environments (sandbox)
            'callbackUrl' => '',
            'currency' => 'R', //Can be R, T (Rial, Toman)
        ],
        'irankish' => [
            'apiPurchaseUrl' => 'https://ikc.shaparak.ir/api/v3/tokenization/make',
            'apiPaymentUrl' => 'https://ikc.shaparak.ir/iuiv3/IPG/Index/',
            'apiVerificationUrl' => 'https://ikc.shaparak.ir/api/v3/confirmation/purchase',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using irankish',
            'terminalId' => '',
            'password' => '',
            'acceptorId' => '',
            'pubKey' => '',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'jibit' => [
            'apiPaymentUrl' => 'https://napi.jibit.ir/ppg/v3',
            'apiKey' => '',
            'apiSecret' => '',
            // You can change the token storage path in Laravel like this
            // 'tokenStoragePath' => function_exists('storage_path') ? storage_path('jibit/') : 'jibit/'
            'tokenStoragePath' => 'jibit/',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using jibit',
            'currency' => 'T', // Can be R, T (Rial, Toman)
        ],
        'nextpay' => [
            'apiPurchaseUrl' => 'https://nextpay.org/nx/gateway/token',
            'apiPaymentUrl' => 'https://nextpay.org/nx/gateway/payment/',
            'apiVerificationUrl' => 'https://nextpay.org/nx/gateway/verify',
            'merchantId' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using nextpay',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'omidpay' => [
            'apiGenerateTokenUrl' => 'https://ref.sayancard.ir/ref-payment/RestServices/mts/generateTokenWithNoSign/',
            'apiPaymentUrl' => 'https://say.shaparak.ir/_ipgw_/MainTemplate/payment/',
            'apiVerificationUrl' => 'https://ref.sayancard.ir/ref-payment/RestServices/mts/verifyMerchantTrans/',
            'username' => '',
            'merchantId' => '',
            'password' => '',
            'callbackUrl' => '',
            'description' => 'payment using omidpay',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'parsian' => [
            'apiPurchaseUrl' => 'https://pec.shaparak.ir/NewIPGServices/Sale/SaleService.asmx?wsdl',
            'apiPaymentUrl' => 'https://pec.shaparak.ir/NewIPG/',
            'apiVerificationUrl' => 'https://pec.shaparak.ir/NewIPGServices/Confirm/ConfirmService.asmx?wsdl',
            'merchantId' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using parsian',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'parspal' => [
            /* Normal api */
            'apiPurchaseUrl' => 'https://api.parspal.com/v1/payment/request',
            'apiVerificationUrl' => 'https://api.parspal.com/v1/payment/verify',

            /* Sandbox api */
            'sandboxApiPurchaseUrl' => ' https://sandbox.api.parspal.com/v1/payment/request',
            'sandboxApiVerificationUrl' => 'https://sandbox.api.parspal.com/v1/payment/verify',

            // You can change the cache path in Laravel like this
            // 'cachePath' => function_exists('storage_path') ? storage_path('parspal/') : 'parspal/'
            'cachePath' => 'parspal/',
            'cacheExpireTTL' => 3600, // Cache expire time in seconds

            'sandbox' => false, // Set it to true for test environments
            'merchantId' => '', // Set `00000000aaaabbbbcccc000000000000` for test environments (sandbox)
            'callbackUrl' => '',
            'currency' => 'T', // Can be R, T (Rial, Toman)
        ],
        'pasargad' => [
            'baseUrl' => 'https://pep.shaparak.ir/dorsa1',
            'userName' => '',
            'password' => '',
            'merchantId' => '',
            'terminalCode' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'paypal' => [
            /* normal api */
            'apiPurchaseUrl' => 'https://www.paypal.com/cgi-bin/webscr',
            'apiPaymentUrl' => 'https://www.zarinpal.com/pg/StartPay/',
            'apiVerificationUrl' => 'https://ir.zarinpal.com/pg/services/WebGate/wsdl',

            /* sandbox api */
            'sandboxApiPurchaseUrl' => 'https://www.sandbox.paypal.com/cgi-bin/webscr',
            'sandboxApiPaymentUrl' => 'https://sandbox.zarinpal.com/pg/StartPay/',
            'sandboxApiVerificationUrl' => 'https://sandbox.zarinpal.com/pg/services/WebGate/wsdl',

            'mode' => 'normal', // can be normal, sandbox
            'id' => '', // Specify the email of the PayPal Business account
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using paypal',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'payping' => [
            'apiPurchaseUrl' => 'https://api.payping.ir/v3/pay/',
            'apiPaymentUrl' => 'https://api.payping.ir/v3/pay/start/',
            'apiVerificationUrl' => 'https://api.payping.ir/v3/pay/verify/',
            'merchantId' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using payping',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'paystar' => [
            'apiPurchaseUrl' => 'https://core.paystar.ir/api/pardakht/create/',
            'apiPaymentUrl' => 'https://core.paystar.ir/api/pardakht/payment/',
            'apiVerificationUrl' => 'https://core.paystar.ir/api/pardakht/verify/',
            'gatewayId' => '', // your gateway id
            'signKey' => '', // sign key of your gateway
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using paystar',
            'currency' => 'R', //Can be R, T (Rial, Toman)
        ],
        'poolam' => [
            'apiPurchaseUrl' => 'https://poolam.ir/invoice/request/',
            'apiPaymentUrl' => 'https://poolam.ir/invoice/pay/',
            'apiVerificationUrl' => 'https://poolam.ir/invoice/check/',
            'merchantId' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using poolam',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'pna' => [
            'apiNormalSale' => 'https://pna.shaparak.ir/mhipg/api/Payment/NormalSale',
            'apiPaymentUrl' => 'https://pna.shaparak.ir/mhui/home/index/',
            'apiConfirmationUrl' => 'https://pna.shaparak.ir/mhipg/api/Payment/confirm',
            'CorporationPin' => '',
            'currency' => 'R',//Can be R, T (Rial, Toman)
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using pna',
        ],
        'sadad' => [
            'apiPaymentByMultiIdentityUrl' => 'https://sadad.shaparak.ir/VPG/api/v0/PaymentByMultiIdentityRequest',
            'apiPaymentByIdentityUrl' => 'https://sadad.shaparak.ir/api/v0/PaymentByIdentity/PaymentRequest',
            'apiPaymentUrl' => 'https://sadad.shaparak.ir/api/v0/Request/PaymentRequest',
            'apiPurchaseUrl' => 'https://sadad.shaparak.ir/Purchase',
            'apiVerificationUrl' => 'https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify',
            'key' => '',
            'merchantId' => '',
            'terminalId' => '',
            'callbackUrl' => '',
            'currency' => 'T', //Can be R, T (Rial, Toman)
            'mode' => 'normal', // can be normal, PaymentByIdentity, PaymentByMultiIdentity,
            'PaymentIdentity' => '',
            'MultiIdentityRows' => [
                [
                    "IbanNumber" => '', // Sheba number (with IR)
                    "Amount" => 0,
                    "PaymentIdentity" => '',
                ],
            ],
            'description' => 'payment using sadad',
        ],
        'saman' => [
            'apiPurchaseUrl' => 'https://sep.shaparak.ir/Payments/InitPayment.asmx?WSDL',
            'apiPaymentUrl' => 'https://sep.shaparak.ir/payment.aspx',
            'apiVerificationUrl' => 'https://sep.shaparak.ir/payments/referencepayment.asmx?WSDL',
            'merchantId' => '',
            'password' => '',
            'callbackUrl' => '',
            'description' => 'payment using saman',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'sep' => [
            'apiGetToken' => 'https://sep.shaparak.ir/onlinepg/onlinepg',
            'apiPaymentUrl' => 'https://sep.shaparak.ir/OnlinePG/OnlinePG',
            'apiVerificationUrl' => 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction',
            'terminalId' => '',
            'callbackUrl' => '',
            'description' => 'Saman Electronic Payment for Saderat & Keshavarzi',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'sepehr' => [
            'apiGetToken' => 'https://sepehr.shaparak.ir:8081/V1/PeymentApi/GetToken',
            'apiPaymentUrl' => 'https://sepehr.shaparak.ir:8080/Pay',
            'apiVerificationUrl' => 'https://sepehr.shaparak.ir:8081/V1/PeymentApi/Advice',
            'terminalId' => '',
            'callbackUrl' => '',
            'description' => 'payment using sepehr(saderat)',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'yekpay' => [
            'apiPurchaseUrl' => 'https://gate.yekpay.com/api/payment/server?wsdl',
            'apiPaymentUrl' => 'https://gate.yekpay.com/api/payment/start/',
            'apiVerificationUrl' => 'https://gate.yekpay.com/api/payment/server?wsdl',
            'fromCurrencyCode' => 978,
            'toCurrencyCode' => 364,
            'merchantId' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using yekpay',
        ],
        'zarinpal' => [
            /* normal api */
            'apiPurchaseUrl' => 'https://api.zarinpal.com/pg/v4/payment/request.json',
            'apiPaymentUrl' => 'https://www.zarinpal.com/pg/StartPay/',
            'apiVerificationUrl' => 'https://api.zarinpal.com/pg/v4/payment/verify.json',

            /* sandbox api */
            'sandboxApiPurchaseUrl' => 'https://sandbox.zarinpal.com/pg/v4/payment/request.json',
            'sandboxApiPaymentUrl' => 'https://sandbox.zarinpal.com/pg/StartPay/',
            'sandboxApiVerificationUrl' => 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json',

            /* zarinGate api */
            'zaringateApiPurchaseUrl' => 'https://ir.zarinpal.com/pg/services/WebGate/wsdl',
            'zaringateApiPaymentUrl' => 'https://www.zarinpal.com/pg/StartPay/:authority/ZarinGate',
            'zaringateApiVerificationUrl' => 'https://ir.zarinpal.com/pg/services/WebGate/wsdl',

            'mode' => 'normal', // can be normal, sandbox, zaringate
            'merchantId' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using zarinpal',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'zibal' => [
            /* normal api */
            'apiPurchaseUrl' => 'https://gateway.zibal.ir/v1/request',
            'apiPaymentUrl' => 'https://gateway.zibal.ir/start/',
            'apiVerificationUrl' => 'https://gateway.zibal.ir/v1/verify',

            'mode' => 'normal', // can be normal, direct

            'merchantId' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using zibal',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'sepordeh' => [
            'apiPurchaseUrl' => 'https://sepordeh.com/merchant/invoices/add',
            'apiPaymentUrl' => 'https://sepordeh.com/merchant/invoices/pay/id:',
            'apiDirectPaymentUrl' => 'https://sepordeh.com/merchant/invoices/pay/automatic:true/id:',
            'apiVerificationUrl' => 'https://sepordeh.com/merchant/invoices/verify',
            'mode' => 'normal', // can be normal, direct
            'merchantId' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using sepordeh',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'rayanpay' => [
            'apiPurchaseUrl' => 'https://bpm.shaparak.ir/pgwchannel/startpay.mellat',
            'apiTokenUrl' => 'https://pms.rayanpay.com/api/v1/auth/token/generate',
            'apiPayStart' => 'https://pms.rayanpay.com/api/v1/ipg/payment/start',
            'apiPayVerify' => 'https://pms.rayanpay.com/api/v1/ipg/payment/response/parse',
            'username' => '',
            'client_id' => '',
            'password' => '',
            'callbackUrl' => '',
            'currency' => 'R', //Can be R, T (Rial, Toman)
        ],
        'shepa' => [
            /* Normal api */
            'apiPurchaseUrl' => 'https://merchant.shepa.com/api/v1/token',
            'apiPaymentUrl' => 'https://merchant.shepa.com/v1/',
            'apiVerificationUrl' => 'https://merchant.shepa.com/api/v1/verify',

            /* Sandbox api */
            'sandboxApiPurchaseUrl' => 'https://sandbox.shepa.com/api/v1/token',
            'sandboxApiPaymentUrl' => 'https://sandbox.shepa.com/v1/',
            'sandboxApiVerificationUrl' => 'https://sandbox.shepa.com/api/v1/verify',

            'sandbox' => false, // Set it to true for test environments
            'merchantId' => '', // Set `sandbox` for test environments (sandbox)
            'callbackUrl' => '',
            'currency' => 'R', //Can be R, T (Rial, Toman)
        ],
        'sizpay' => [
            'apiPurchaseUrl' => 'https://rt.sizpay.ir/KimiaIPGRouteService.asmx?WSDL',
            'apiPaymentUrl' => 'https://rt.sizpay.ir/Route/Payment',
            'apiVerificationUrl' => 'https://rt.sizpay.ir/KimiaIPGRouteService.asmx?WSDL',
            'merchantId' => '',
            'terminal' => '',
            'username' => '',
            'password' => '',
            'SignData' => '',
            'callbackUrl' => '',
            'currency' => 'R', //Can be R, T (Rial, Toman)
        ],
        'vandar' => [
            'apiPurchaseUrl' => 'https://ipg.vandar.io/api/v3/send',
            'apiPaymentUrl' => 'https://ipg.vandar.io/v3/',
            'apiVerificationUrl' => 'https://ipg.vandar.io/api/v3/verify',
            'callbackUrl' => '',
            'merchantId' => '',
            'description' => 'payment using Vandar',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'aqayepardakht' => [
            'apiPurchaseUrl' => 'https://panel.aqayepardakht.ir/api/v2/create',
            'apiPaymentUrl' => 'https://panel.aqayepardakht.ir/startpay/',
            'apiPaymentUrlSandbox' => 'https://panel.aqayepardakht.ir/startpay/sandbox/',
            'apiVerificationUrl' => 'https://panel.aqayepardakht.ir/api/v2/verify',
            'mode' => 'normal', //normal | sandbox
            'callbackUrl' => '',
            'pin' => '',
            'invoice_id' => '',
            'mobile' => '',
            'email' => '',
            'description' => 'payment using Aqayepardakht',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'azki' => [
            'apiPaymentUrl' => 'https://api.azkivam.com',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'fallbackUrl' => 'http://yoursite.com/path/to',
            'merchantId' => '',
            'key' => '',
            'currency' => 'T', //Can be R, T (Rial, Toman)
            'description' => 'payment using azki',
        ],
        'payfa' => [
            'apiPurchaseUrl' => 'https://payment.payfa.com/v2/api/Transaction/Request',
            'apiPaymentUrl' => 'https://payment.payfa.ir/v2/api/Transaction/Pay/',
            'apiVerificationUrl' => 'https://payment.payfa.com/v2/api/Transaction/Verify/',
            'callbackUrl' => '',
            'apiKey' => '',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'toman' => [
            'base_url' => 'https://escrow-api.toman.ir/api/v1',
            'shop_slug' => '',
            'auth_code' => '',
            'data' => ''
        ],
        'bitpay' => [
            'apiPurchaseUrl' => 'https://bitpay.ir/payment/gateway-send',
            'apiPaymentUrl' => 'https://bitpay.ir/payment/gateway-{id_get}-get',
            'apiVerificationUrl' => 'https://bitpay.ir/payment/gateway-result-second',
            'callbackUrl' => '',
            'api_token' => '',
            'description' => 'payment using Bitpay',
            'currency' => 'R', //Can be R, T (Rial, Toman)
        ],
        'minipay' => [
            'apiPurchaseUrl' => 'https://v1.minipay.me/api/pg/request/',
            'apiPaymentUrl' => 'https://ipg.minipay.me/',
            'apiVerificationUrl' => 'https://v1.minipay.me/api/pg/verify/',
            'merchantId' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using Minipay.',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'snapppay' => [
            'apiPaymentUrl' => 'https://fms-gateway-staging.apps.public.teh-1.snappcloud.io',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'username' => 'username',
            'password' => 'password',
            'client_id' => '',
            'client_secret' => '',
            'description' => 'payment using Snapp Pay.',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
        'daracard' => [
            'apiPurchaseUrl' => 'https://ipg.daracard.co/api/v0/Request/PaymentRequest/',
            'getAction' => 'https://ipg.daracard.co/#/purchase/',
            'apiPaymentUrl' => 'https://ipg.daracard.co/#/purchase/',
            'apiVerificationUrl' => 'https://ipg.daracard.co/api/v0/Advice/Verify/',
            'merchantId' => '',
            'terminalId' => '',
            'username' => '',
            'password' => '',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'description' => 'payment using daracard',
        ],
        'tara' => [
            'apiPaymentUrl' => 'https://pay.tara360.ir/pay/',
            'callbackUrl' => 'http://yoursite.com/path/to',
            'username' => '',
            'password' => '',
            'serviceId' => '101',
            'group' => '1',
            'groupTitle' => 'category',
            'description' => 'payment using Tara 360.',
            'currency' => 'T', //Can be R, T (Rial, Toman)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Class Maps
    |--------------------------------------------------------------------------
    |
    | This is the array of Classes that maps to Drivers above.
    | You can create your own driver if you like and add the
    | config in the drivers array and the class to use for
    | here with the same name. You will have to extend
    | Shetabit\Multipay\Abstracts\Driver in your driver.
    |
    */
    'map' => [
        'local' => Local::class,
        'gooyapay' => Gooyapay::class,
        'fanavacard' => Fanavacard::class,
        'asanpardakht' => Asanpardakht::class,
        'atipay' => Atipay::class,
        'behpardakht' => Behpardakht::class,
        'digipay' => Digipay::class,
        'etebarino' => Etebarino::class,
        'irandargah' => IranDargah::class,
        'irankish' => Irankish::class,
        'jibit' => Jibit::class,
        'nextpay' => Nextpay::class,
        'omidpay' => Omidpay::class,
        'parsian' => Parsian::class,
        'parspal' => Parspal::class,
        'pasargad' => Pasargad::class,
        'paypal' => Paypal::class,
        'payping' => Payping::class,
        'paystar' => Paystar::class,
        'poolam' => Poolam::class,
        'sadad' => Sadad::class,
        'saman' => Saman::class,
        'sep' => SEP::class,
        'sepehr' => Sepehr::class,
        'yekpay' => Yekpay::class,
        'zarinpal' => Zarinpal::class,
        'zibal' => Zibal::class,
        'sepordeh' => Sepordeh::class,
        'rayanpay' => Rayanpay::class,
        'shepa' => Shepa::class,
        'sizpay' => Sizpay::class,
        'vandar' => Vandar::class,
        'aqayepardakht' => Aqayepardakht::class,
        'azki' => Azki::class,
        'payfa' => Payfa::class,
        'tara' => Tara::class,
        'toman' => Toman::class,
        'bitpay' => Bitpay::class,
        'minipay' => Minipay::class,
        'snapppay' => SnappPay::class,
        'pna' => Pna::class
    ]
];
