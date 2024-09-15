<p align="center"><img src="resources/images/payment.png?raw=true"></p>

# PHP Payment Gateway

[![Software License][ico-license]](LICENSE.md)
[![Latest Version on Packagist][ico-version]][link-packagist]
[![Total Downloads on Packagist][ico-download]][link-packagist]
[![StyleCI](https://github.styleci.io/repos/268039684/shield?branch=master)](https://github.styleci.io/repos/268039684)
[![Maintainability](https://api.codeclimate.com/v1/badges/3aa790c544c9f2132b16/maintainability)](https://codeclimate.com/github/shetabit/multipay/maintainability)
[![Quality Score][ico-code-quality]][link-code-quality]

This is a PHP Package for Payment Gateway Integration. This package supports `PHP 7.2+`.

[Donate me](https://yekpay.me/mahdikhanzadi) if you like this package :sunglasses: :bowtie:

For **Laravel** integration you can use [shetabit/payment](https://github.com/shetabit/payment) package.

> This package works with multiple drivers, and you can create custom drivers if you can't find them in the [current drivers list](#list-of-available-drivers) (below list).

- [داکیومنت فارسی][link-fa]
- [English documents][link-en]
- [中文文档][link-zh]

# List of contents

- [PHP Payment Gateway](#php-payment-gateway)
- [List of contents](#list-of-contents)
- [List of available drivers](#list-of-available-drivers)
  - [Install](#install)
  - [Configure](#configure)
  - [How to use](#how-to-use)
    - [Working with invoices](#working-with-invoices)
    - [Purchase invoice](#purchase-invoice)
    - [Pay invoice](#pay-invoice)
    - [Verify payment](#verify-payment)
    - [Useful methods](#useful-methods)
    - [Create custom drivers:](#create-custom-drivers)
    - [Events](#events)
  - [Local driver (for development)](#local-driver)
  - [Change log](#change-log)
  - [Contributing](#contributing)
  - [Security](#security)
  - [Credits](#credits)
  - [License](#license)

# List of available drivers

- [aqayepardakht](https://aqayepardakht.ir/) :heavy_check_mark:
- [asanpardakht](https://asanpardakht.ir/) :heavy_check_mark:
- [atipay](https://www.atipay.net/) :heavy_check_mark:
- [azkiVam (Installment payment)](https://www.azkivam.com/) :heavy_check_mark:
- [behpardakht (mellat)](http://www.behpardakht.com/) :heavy_check_mark:
- [bitpay](https://bitpay.ir/) :heavy_check_mark:
- [digipay](https://www.mydigipay.com/) :heavy_check_mark:
- [etebarino (Installment payment)](https://etebarino.com/) :heavy_check_mark:
- [fanavacard](https://www.fanava.com/) :heavy_check_mark:
- [gooyapay](https://gooyapay.ir/) :heavy_check_mark:
- [idpay](https://idpay.ir/) :heavy_check_mark:
- [irandargah](https://irandargah.com/) :heavy_check_mark:
- [irankish](http://irankish.com/) :heavy_check_mark:
- [jibit](https://jibit.ir/) :heavy_check_mark:
- [local](#local-driver) :heavy_check_mark:
- [minipay](https://minipay.me/) :heavy_check_mark:
- [nextpay](https://nextpay.ir/) :heavy_check_mark:
- [omidpay](https://omidpayment.ir/) :heavy_check_mark:
- [parsian](https://www.pec.ir/) :heavy_check_mark:
- [parspal](https://parspal.com/) :heavy_check_mark:
- [pasargad](https://bpi.ir/) :heavy_check_mark:
- [payfa](https://payfa.com/) :heavy_check_mark:
- [payir](https://pay.ir/) :heavy_check_mark:
- [paypal](http://www.paypal.com/) (will be added soon in next version)
- [payping](https://www.payping.ir/) :heavy_check_mark:
- [paystar](http://paystar.ir/) :heavy_check_mark:
- [poolam](https://poolam.ir/) :heavy_check_mark:
- [pna](https://www.pna.co.ir/) :heavy_check_mark:
- [rayanpay](https://rayanpay.com/) :heavy_check_mark:
- [sadad (melli)](https://sadadpsp.ir/) :heavy_check_mark:
- [saman](https://www.sep.ir) :heavy_check_mark:
- [sep (saman electronic payment) Keshavarzi & Saderat](https://www.sep.ir) :heavy_check_mark:
- [sepehr (saderat)](https://www.sepehrpay.com/) :heavy_check_mark:
- [sepordeh](https://sepordeh.com/) :heavy_check_mark:
- [shepa](https://shepa.com/) :heavy_check_mark:
- [sizpay](https://www.sizpay.ir/) :heavy_check_mark:
- [snapppay](https://snapppay.ir/) :heavy_check_mark:
- [toman](https://tomanpay.net/) :heavy_check_mark:
- [vandar](https://vandar.io/) :heavy_check_mark:
- [walleta (Installment payment)](https://walleta.ir/) :heavy_check_mark:
- [yekpay](https://yekpay.com/) :heavy_check_mark:
- [zarinpal](https://www.zarinpal.com/) :heavy_check_mark:
- [zibal](https://www.zibal.ir/) :heavy_check_mark:
- [novinopay](https://novinopay.com/) :heavy_check_mark:
- Others are under way.

**Help me to add the gateways below by creating `pull requests`**

- stripe
- authorize
- 2checkout
- braintree
- skrill
- payU
- amazon payments
- wepay
- payoneer
- paysimple

> you can create your own custom drivers if it doesn't exist in the list, read the `Create custom drivers` section.

## Install

Via Composer

```bash
composer require shetabit/multipay
```

## Configure

a. Copy `config/payment.php` into somewhere in your project. (you can also find it in `vendor/shetabit/multipay/config/payment.php` path).

b. In the config file you can set the `default driver` to be used for all your payments and you can also change the driver at runtime.

Choose what gateway you would like to use in your application. Then make that as default driver so that you don't have to specify that everywhere. But, you can also use multiple gateways in a project.

```php
// Eg. if you want to use zarinpal.
'default' => 'zarinpal',
```

Then fill the credentials for that gateway in the drivers array.

```php
'drivers' => [
    'zarinpal' => [
        // Fill in the credentials here.
        'apiPurchaseUrl' => 'https://www.zarinpal.com/pg/rest/WebGate/PaymentRequest.json',
        'apiPaymentUrl' => 'https://www.zarinpal.com/pg/StartPay/',
        'apiVerificationUrl' => 'https://www.zarinpal.com/pg/rest/WebGate/PaymentVerification.json',
        'merchantId' => '',
        'callbackUrl' => 'http://yoursite.com/path/to',
        'description' => 'payment in '.config('app.name'),
    ],
    ...
]
```

c. Instantiate the `Payment` class and **pass configs to it** like the below:

```php
    use Shetabit\Multipay\Payment;

    // load the config file from your project
    $paymentConfig = require('path/to/payment.php');

    $payment = new Payment($paymentConfig);
```

## How to use

your `Invoice` holds your payment details, so initially we'll talk about `Invoice` class.

#### Working with invoices

before doing any thing you need to use `Invoice` class to create an invoice.

In your code, use it like the below:

```php
// At the top of the file.
use Shetabit\Multipay\Invoice;
...

// Create new invoice.
$invoice = new Invoice;

// Set invoice amount.
$invoice->amount(1000);

// Add invoice details: There are 4 syntax available for this.
// 1
$invoice->detail(['detailName' => 'your detail goes here']);
// 2 
$invoice->detail('detailName','your detail goes here');
// 3
$invoice->detail(['name1' => 'detail1','name2' => 'detail2']);
// 4
$invoice->detail('detailName1','your detail1 goes here')
        ->detail('detailName2','your detail2 goes here');
```

Available methods:

- `uuid`: set the invoice unique id
- `getUuid`: retrieve the invoice current unique id
- `detail`: attach some custom details into invoice
- `getDetails`: retrieve all custom details
- `amount`: set the invoice amount
- `getAmount`: retrieve invoice amount
- `transactionId`: set invoice payment transaction id
- `getTransactionId`: retrieve payment transaction id
- `via`: set a driver we use to pay the invoice
- `getDriver`: retrieve the driver

#### Purchase invoice

In order to pay the invoice, we need the payment transactionId.
We purchase the invoice to retrieve transaction id:

```php
// At the top of the file.
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Payment;
...

// load the config file from your project
$paymentConfig = require('path/to/payment.php');

$payment = new Payment($paymentConfig);


// Create new invoice.
$invoice = (new Invoice)->amount(1000);

// Purchase the given invoice.
$payment->purchase($invoice,function($driver, $transactionId) {
	// We can store $transactionId in database.
});

// Purchase method accepts a callback function.
$payment->purchase($invoice, function($driver, $transactionId) {
    // We can store $transactionId in database.
});

// You can specify callbackUrl
$payment->callbackUrl('http://yoursite.com/verify')->purchase(
    $invoice,
    function($driver, $transactionId) {
    	// We can store $transactionId in database.
	}
);
```

#### Pay invoice

After purchasing the invoice, we can redirect the user to the bank payment page:

```php
// At the top of the file.
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Payment;
...

// load the config file from your project
$paymentConfig = require('path/to/payment.php');

$payment = new Payment($paymentConfig);


// Create new invoice.
$invoice = (new Invoice)->amount(1000);

// Purchase and pay the given invoice.
// You should use return statement to redirect user to the bank page.
return $payment->purchase($invoice, function($driver, $transactionId) {
    // Store transactionId in database as we need it to verify payment in the future.
})->pay()->render();

// Do all things together in a single line.
return $payment->purchase(
    (new Invoice)->amount(1000), 
    function($driver, $transactionId) {
    	// Store transactionId in database.
        // We need the transactionId to verify payment in the future.
	}
)->pay()->render();

// Retrieve json format of Redirection (in this case you can handle redirection to bank gateway)
return $payment->purchase(
    (new Invoice)->amount(1000), 
    function($driver, $transactionId) {
    	// Store transactionId in database.
        // We need the transactionId to verify payment in the future.
	}
)->pay()->toJson();
```

#### Verify payment

When user has completed the payment, the bank redirects them to your website, then you need to **verify your payment** in order to ensure the `invoice` has been **paid**.

```php
// At the top of the file.
use Shetabit\Multipay\Payment;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
...

// load the config file from your project
$paymentConfig = require('path/to/payment.php');

$payment = new Payment($paymentConfig);


// You need to verify the payment to ensure the invoice has been paid successfully.
// We use transaction id to verify payments
// It is a good practice to add invoice amount as well.
try {
	$receipt = $payment->amount(1000)->transactionId($transaction_id)->verify();

    // You can show payment referenceId to the user.
    echo $receipt->getReferenceId();

    ...
} catch (InvalidPaymentException $exception) {
    /**
    	when payment is not verified, it will throw an exception.
    	We can catch the exception to handle invalid payments.
    	getMessage method, returns a suitable message that can be used in user interface.
    **/
    echo $exception->getMessage();
}
```

#### Useful methods

- ###### `callbackUrl`: can be used to change callbackUrl on the runtime.


  ```php
  // At the top of the file.
  use Shetabit\Multipay\Invoice;
  use Shetabit\Multipay\Payment;
  ...

  // load the config file from your project
  $paymentConfig = require('path/to/payment.php');

  $payment = new Payment($paymentConfig);


  // Create new invoice.
  $invoice = (new Invoice)->amount(1000);

  // Purchase the given invoice.
  $payment->callbackUrl($url)->purchase(
      $invoice, 
      function($driver, $transactionId) {
      // We can store $transactionId in database.
  	}
  );
  ```
- ###### `amount`: you can set the invoice amount directly


  ```php
  // At the top of the file.
  use Shetabit\Multipay\Invoice;
  use Shetabit\Multipay\Payment;
  ...

  // load the config file from your project
  $paymentConfig = require('path/to/payment.php');

  $payment = new Payment($paymentConfig);


  // Purchase (we set invoice to null).
  $payment->callbackUrl($url)->amount(1000)->purchase(
      null,
      function($driver, $transactionId) {
      // We can store $transactionId in database.
  	}
  );
  ```
- ###### `via`: change driver on the fly


  ```php
  // At the top of the file.
  use Shetabit\Multipay\Invoice;
  use Shetabit\Multipay\Payment;
  ...

  // load the config file from your project
  $paymentConfig = require('path/to/payment.php');

  $payment = new Payment($paymentConfig);


  // Create new invoice.
  $invoice = (new Invoice)->amount(1000);

  // Purchase the given invoice.
  $payment->via('driverName')->purchase(
      $invoice, 
      function($driver, $transactionId) {
      // We can store $transactionId in database.
  	}
  );
  ```
- ###### `config`: set driver configs on the fly


  ```php
  // At the top of the file.
  use Shetabit\Multipay\Invoice;
  use Shetabit\Multipay\Payment;
  ...

  // load the config file from your project
  $paymentConfig = require('path/to/payment.php');

  $payment = new Payment($paymentConfig);


  // Create new invoice.
  $invoice = (new Invoice)->amount(1000);

  // Purchase the given invoice with custom driver configs.
  $payment->config('mechandId', 'your mechand id')->purchase(
      $invoice,
      function($driver, $transactionId) {
      // We can store $transactionId in database.
  	}
  );

  // Also we can change multiple configs at the same time.
  $payment->config(['key1' => 'value1', 'key2' => 'value2'])->purchase(
      $invoice,
      function($driver, $transactionId) {
      // We can store $transactionId in database.
  	}
  );
  ```
- `custom fileds`: Use custom fields of gateway (Not all gateways support this feature)
  SEP gateway support up to 4 custom fields and you can set the value to a string up to 50 characters.
  These custom fields are shown only when viewing reports in the user's panel.

  ```php
  // At the top of the file.
  use Shetabit\Multipay\Invoice;
  ...


  // Create new invoice.
  $invoice = (new Invoice)->amount(1000);

  // Use invoice bag to store custom field values.
  $invoice->detail([
              'ResNum1' => $order->orderId,
              'ResNum2' => $customer->verifiedCode,
              'ResNum3' => $someValue,
              'ResNum4' => $someOtherValue,
              ]);
  ```

#### Create custom drivers:

First you have to add the name of your driver, in the drivers array and also you can specify any config parameters you want.

```php
'drivers' => [
    'zarinpal' => [...],
    'my_driver' => [
        ... // Your Config Params here.
    ]
]
```

Now you have to create a Driver Map Class that will be used to pay invoices.
In your driver, You just have to extend `Shetabit\Multipay\Abstracts\Driver`.

Eg. You created a class: `App\Packages\Multipay\Driver\MyDriver`.

```php
namespace App\Packages\Multipay\Driver;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\{Contracts\ReceiptInterface, Invoice, RedirectionForm, Receipt};

class MyDriver extends Driver
{
    protected $invoice; // Invoice.

    protected $settings; // Driver settings.

    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice); // Set the invoice.
        $this->settings = (object) $settings; // Set settings.
    }

    // Purchase the invoice, save its transactionId and finaly return it.
    public function purchase() {
        // Request for a payment transaction id.
        ...

        $this->invoice->transactionId($transId);

        return $transId;
    }

    // Redirect into bank using transactionId, to complete the payment.
    public function pay() : RedirectionForm {
        // It is better to set bankApiUrl in config/payment.php and retrieve it here:
        $bankUrl = $this->settings->bankApiUrl; // bankApiUrl is the config name.

        // Prepare payment url.
        $payUrl = $bankUrl.$this->invoice->getTransactionId();

        // Redirect to the bank.
        $url = $payUrl;
        $inputs = [];
        $method = 'GET';

        return $this->redirectWithForm($url, $inputs, $method);
    }
  
    // Verify the payment (we must verify to ensure that user has paid the invoice).
    public function verify(): ReceiptInterface {
        $verifyPayment = $this->settings->verifyApiUrl;
  
        $verifyUrl = $verifyPayment.$this->invoice->getTransactionId();
  
        ...
  
        /**
			Then we send a request to $verifyUrl and if payment is not valid we throw an InvalidPaymentException with a suitable message.
        **/
        throw new InvalidPaymentException('a suitable message');
  
        /**
        	We create a receipt for this payment if everything goes normally.
        **/
        return new Receipt('driverName', 'payment_receipt_number');
    }
}
```

Once you create that class you have to specify it in the `payment.php` config file `map` section.

```php
'map' => [
    ...
    'my_driver' => App\Packages\Multipay\Driver\MyDriver::class,
]
```

**Note:** You have to make sure that the key of the `map` array is identical to the key of the `drivers` array.

#### Events:

**Notice 1:** event listeners will be registered globaly for all payments.

**Notice 2:** if you want your listeners work correctly, you **must** subcribe them before the target event dispatches.

> Its better to subcribe events in your app's entry point or main service provider, so events will be subcribed before any events dispatches.

---

You can listen for 3 events:

1. **purchase**
2. **pay**
3. **verify**.

- **purchase**: Occurs when an invoice is purchased (after purchasing invoice is done successfully).

```php
// add purchase event listener
Payment::addPurchaseListener(function($driver, $invoice) {
    echo $driver;
    echo $invoice;
});
```

- **pay**: Occurs when an invoice is prepared to pay.

```php
// add pay event listener
Payment::addPayListener(function($driver, $invoice) {
    echo 'first listener';
});

// we can add multiple listeners
Payment::addPayListener(function($driver, $invoice) {
    echo 'second listener';
});
```

- **verify**: Occurs when an invoice is verified successfully.

```php
// we can add multiple listeners and also remove them!!!

$firstListener = function($driver, $invoice) {
    echo 'first listener';
};

$secondListener = function($driver, $invoice) {
    echo 'second listener';
};

Payment::addVerifyListener($firstListener);
Payment::addVerifyListener($secondListener);

// remove first listener
Payment::removeVerifyListener($firstListener);

// if we call remove listener without any arguments, it will remove all listeners
Payment::removeVerifyListener(); // remove all verify listeners :D
```

## Local driver

`Local` driver can simulate payment flow of a real gateway for development purpose.

Payment can be initiated like any other driver

```php
$invoice = (new Invoice)->amount(10000);
$payment->via('local')->purchase($invoice, function($driver, $transactionId) {
    // a fake transaction ID is generated and returned.
})->pay()->render();
```

<p align="center"><img src="resources/images/local-form.png?raw=true"></p>

Calling `render()` method will render a `HTML` form with **Accept** and  **Cancel** buttons, which simulate corresponding action of real payment gateway. and redirects to the specified callback url.
`transactionId` parameter will allways be available in the returned query url.

Payment can be verified after receiving the callback request.

```php
$receipt = $payment->via('local')->verify();
```

In case of succesful payment, `$receipt` will contains the following parameters

```php
[
'orderId' => // fake order number 
'traceNo' => // fake trace number (this should be stored in databse)
'referenceNo' => // generated transaction ID in `purchase` method callback
'cardNo' => // fake last four digits of card 
]
```

In case of canceled payment, `PurchaseFailedException` will be thrown to simulate the failed verification of gateway.

Driver functionalities can be configured via `Invoice` detail bag.

- ###### `available parameters`

```php
$invoice->detail([
    // setting this value will cause `purchase` method to throw an `PurchaseFailedException` 
    // to simulate when a gateway can not initialize the payment.
        'failedPurchase' => 'custom message to decribe the error',

    // Setting this parameter will be shown in payment form.
        'orderId' => 4444,
]);
```

- ###### `appearance`

Appearance of payment form can be customized via config parameter of `local` driver in `payment.php` file.

```php
'local' => [
    // default callback url of the driver
    'callbackUrl' => '/callback',

    // main title of the form
    'title' => 'Test gateway',
  
    // a description to show under the title for more clarification
    'description' => 'This gateway is for using in development environments only.',
  
    // custom label to show as order No.
    'orderLabel' => 'Order No.',
  
    // custom label to show as payable amount
    'amountLabel' => 'Payable amount',
  
    // custom label of successful payment button
    'payButton' => 'Successful Payment',
  
    // custom label of cancel payment button
    'cancelButton' => 'Cancel Payment',
],
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has been changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CONDUCT](CONDUCT.md) for details.

## Security

If you discover any security related issues, please email khanzadimahdi@gmail.com instead of using the issue tracker.

## Credits

- [Mahdi khanzadi][link-author]
- [All Contributors][link-contributors]

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/shetabit/multipay.svg?style=flat-square
[ico-download]: https://img.shields.io/packagist/dt/shetabit/multipay.svg?color=%23F18&style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/shetabit/multipay.svg?label=Code%20Quality&style=flat-square
[link-fa]: README-FA.md
[link-en]: README.md
[link-zh]: README-ZH.md
[link-packagist]: https://packagist.org/packages/shetabit/multipay
[link-code-quality]: https://scrutinizer-ci.com/g/shetabit/multipay
[link-author]: https://github.com/khanzadimahdi
[link-contributors]: ../../contributors
