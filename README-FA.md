<p align="center"><img src="resources/images/payment.png?raw=true"></p>

<div dir=rtl>

# پکیج درگاه پرداخت برای پی اچ پی


[![Software License][ico-license]](LICENSE.md)
[![Latest Version on Packagist][ico-version]][link-packagist]
[![Total Downloads on Packagist][ico-download]][link-packagist]
[![StyleCI](https://github.styleci.io/repos/268039684/shield?branch=master)](https://github.styleci.io/repos/268039684)
[![Maintainability](https://api.codeclimate.com/v1/badges/3aa790c544c9f2132b16/maintainability)](https://codeclimate.com/github/shetabit/multipay/maintainability)
[![Quality Score][ico-code-quality]][link-code-quality]

این پکیج برای پرداخت آنلاین توسط درگاه‌های مختلف در پی اچ پی ایجاد شده است.


> این پکیج با درگاه‌های پرداخت مختلفی کار میکنه. در صورتی که درگاه مورد نظرتون رو در لیست درایورهای موجود پیدا نکردید می‌تونید برای درگاهی که استفاده می‌کنید درایور مورد نظرتون رو بسازید.


- [داکیومنت فارسی][link-fa]
- [english documents][link-en]
- [中文文档][link-zh]


در صورتی که از این پکیج خوشتون آمده و ازش استفاده می‌کنید می‌تونید با پرداخت مبلغ اندکی من رو حمایت کنید تا این پکیج رو بیشتر توسعه بدم و درگاه‌های جدیدتری بهش اضافه کنم.

[به منظور کمک مالی کلیک کنید](https://zarinp.al/@mahdikhanzadi) :sunglasses: :bowtie:

درصورتی که از Laravel استفاده میکنید میتونید از پکیج [shetabit/payment](https://github.com/shetabit/payment) استفاده کنید.

# لیست محتوا

- [درایور های موجود](#درایورهای-موجود)
- [نصب](#نصب)
- [تنظیمات](#تنظیمات)
- [طریقه استفاده](#طریقه-استفاده)
    - [کار با صورتحساب ها](#کار-با-صورتحساب-ها)
    - [ثبت درخواست برای پرداخت صورتحساب](#ثبت-درخواست-برای-پرداخت-صورتحساب)
    - [پرداخت صورتحساب](#پرداخت-صورتحساب)
    - [اعتبار سنجی پرداخت](#اعتبار-سنجی-پرداخت)
    - [ایجاد درایور دلخواه](#ایجاد-درایور-دلخواه)
    - [متدهای سودمند](#متدهای-سودمند)
- [درایور آفلاین (برای تست)](#درایور-آفلاین)
- [تغییرات](#تغییرات)
- [مشارکت کننده ها](#مشارکت-کننده-ها)
- [امنیت](#امنیت)
- [توسعه دهندگان](#توسعه-دهندگان)
- [لایسنس](#لایسنس)

# درایورهای موجود

- [آقای پرداخت](https://aqayepardakht.ir/) :heavy_check_mark:
- [آسان‌پرداخت](https://asanpardakht.ir/) :heavy_check_mark:
- [آتی‌پی](https://www.atipay.net/) :heavy_check_mark:
- [ازکی‌وام (پرداخت اقساطی)](https://www.azkivam.com/) :heavy_check_mark:
- [به‌پرداخت (ملت)](http://www.behpardakht.com/) :heavy_check_mark:
- [بیت‌پی](https://bitpay.ir/) :heavy_check_mark:
- [دیجی‌پی](https://www.mydigipay.com/) :heavy_check_mark:
- [اعتبارینو (پرداخت اقساطی)](https://etebarino.com/) :heavy_check_mark:
- [فن‌آوا‌کارت](https://www.fanava.com/) :heavy_check_mark:
- [گویاپـــی](https://gooyapay.ir/) :heavy_check_mark:
- [آی‌دی‌پی](https://idpay.ir/) :heavy_check_mark:
- [ایران‌کیش](http://irankish.com/) :heavy_check_mark:
- [جیبیت](https://jibit.ir/) :heavy_check_mark:
- [لوکال](#local-driver) :heavy_check_mark:
- [مینی پی](https://minipay.me/) :heavy_check_mark:
- [نکست‌پی](https://nextpay.ir/) :heavy_check_mark:
- [امیدپی](https://sayancard.ir/) :heavy_check_mark:
- [پارسیان](https://www.pec.ir/) :heavy_check_mark:
- [پاسارگاد](https://bpi.ir/) :heavy_check_mark:
- [پی‌فا](https://payfa.com/) :heavy_check_mark:
- [پی‌آی‌آر](https://pay.ir/) :heavy_check_mark:
- [پی‌پال](http://www.paypal.com/) (به زودی در ورژن بعدی اضافه می‌شود)
- [پی‌پینگ](https://www.payping.ir/) :heavy_check_mark:
- [پی‌استار](http://paystar.ir/) :heavy_check_mark:
- [پولام](https://poolam.ir/) :heavy_check_mark:
- [پرداخت نوین](https://www.pna.co.ir/) :heavy_check_mark:
- [رایان‌پی](https://rayanpay.com/) :heavy_check_mark:
- [سداد (ملی)](https://sadadpsp.ir/) :heavy_check_mark:
- [سامان](https://www.sep.ir) :heavy_check_mark:
- [سپ (درگاه الکترونیک سامان) کشاورزی و صادرات](https://www.sep.ir) :heavy_check_mark:
- [سپهر (صادرات)](https://www.sepehrpay.com/) :heavy_check_mark:
- [سپرده](https://sepordeh.com/) :heavy_check_mark:
- [سیزپی](https://www.sizpay.ir/) :heavy_check_mark:
- [اسنپ‌پی](https://snapppay.ir/) :heavy_check_mark:
- [تومن](https://tomanpay.net/) :heavy_check_mark:
- [وندار](https://vandar.io/) :heavy_check_mark:
- [والتا](https://walleta.ir/) :heavy_check_mark:
- [یک‌پی](https://yekpay.com/) :heavy_check_mark:
- [زرین‌پال](https://www.zarinpal.com/) :heavy_check_mark:
- [زیبال](https://www.zibal.ir/) :heavy_check_mark:

- درایورهای دیگر ساخته خواهند شد یا اینکه بسازید و درخواست `merge` بدید.

> در صورتی که درایور مورد نظرتون موجود نیست, می‌تونید برای درگاه پرداخت موردنظرتون درایور بسازید.

## نصب

نصب با استفاده از کامپوزر

</div>

``` bash
composer require shetabit/multipay
```

<div dir="rtl">

## تنظیمات

a. ابتدا فایل حاوی تنظیمات را از مسیر `config/payment.php` به درون پروژه خود کپی کنید. (فایل تنظیمات رو میتونید از مسیر `vendor/shetabit/multipay/config/payment.php` نیز پیدا کرده و کپی کنید)

b. درون فایل تنظیمات در قسمت `default driver` می‌توانید درایوری که قصد استفاده از ان را دارید قرار دهید تا تمامی پرداخت ها از آن طریق انجام شود.


</div>

```php
// Eg. if you want to use zarinpal.
'default' => 'zarinpal',
```

<div dir="rtl">

سپس تنظیمات مرتبط با درایوری که قصد استفاده از ان را دارید انجام دهید

</div>

```php
'drivers' => [
    'zarinpal' => [
        // Fill all the credentials here.
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

<div dir="rtl">

c. سپس از روی کلاس `Payment` یک نمونه ایجاد کنید و تنظیمات را به درون آن ارسال کنید:

</div>

```php
    use Shetabit\Multipay\Payment;

    // load the config file from your project
    $paymentConfig = require('path/to/payment.php');

    $payment = new Payment($paymentConfig);
```

<div dir="rtl">

## طریقه استفاده

در تمامی پرداخت ها اطلاعات پرداخت درون صورتحساب شما نگهداری میشود. برای استفاده از پکیج ابتدا نحوه ی استفاده از کلاس `Invoice` به منظور کار با صورتحساب ها را توضیح میدهیم.

#### کار با صورتحساب ها

قبل از انجام هرکاری نیاز به ایجاد یک صورتحساب دارید. برای ایجاد صورتحساب می‌توانید از کلاس `Invoice` استفاده کنید.

درون کد خودتون به شکل زیر عمل کنید:

</div>

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

<div dir="rtl">

متدهای موجود برای کار با صورتحساب ها:

- `uuid`: یک ایدی یونیک برای صورتحساب تنظیم می‌کند
- `getUuid`: ایدی یونیک صورتحساب را برمی‌گرداند
- `detail`: توضیحات یا مواردی که مرتبط به صورتحساب است را به صورتحساب اضافه می‌کند
- `getDetails`: تمامی موارد مرتبطی که به صورتحساب افزوده شده است را برمی‌گرداند
- `amount`: مقدار هزینه‌ای که باید پرداخت شود را مشخص می‌کند
- `getAmount`: هزینه‌ی صورتحساب را برمی‌گرداند
- `transactionId`: شماره تراکنش صورتحساب را مشخص می‌کند
- `getTransactionId`: شماره تراکنش صورتحساب را برمی‌گرداند
- `via`: درایوری که قصد پرداخت صورتحساب با آن را داریم مشخص می‌کند
- `getDriver`: درایور انتخاب شده را برمی‌گرداند

#### ثبت درخواست برای پرداخت صورتحساب
به منظور پرداخت تمامی صورتحساب ها به یک شماره تراکنش بانکی یا `transactionId` نیاز خواهیم داشت.
با ثبت درخواست به منظور پرداخت میتوان شماره تراکنش بانکی را دریافت کرد:

</div>

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

<div dir="rtl">

#### پرداخت صورتحساب

با استفاده از شماره تراکنش یا `transactionId` میتوانیم کاربر را به صفحه ی پرداخت بانک هدایت کنیم:

</div>

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
})->pay();

// Do all things together in a single line.
return $payment->purchase(
    (new Invoice)->amount(1000), 
    function($driver, $transactionId) {
    	// Store transactionId in database.
        // We need the transactionId to verify payment in the future.
	}
)->pay();
```

<div dir="rtl">


#### اعتبار سنجی پرداخت

بعد از پرداخت شدن صورتحساب توسط کاربر, بانک کاربر را به یکی از صفحات سایت ما برمیگردونه و ما با اعتبار سنجی میتونیم متوجه بشیم کاربر پرداخت رو انجام داده یا نه!

</div>

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
	$receipt = Payment::amount(1000)->transactionId($transaction_id)->verify();

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

<div dir="rtl">


در صورتی که پرداخت توسط کاربر به درستی انجام نشده باشه یک استثنا از نوع `InvalidPaymentException` ایجاد میشود که حاوی پیام متناسب با پرداخت انجام شده است.

#### ایجاد درایور دلخواه:

برای ایجاد درایور جدید ابتدا نام (اسم) درایوری که قراره بسازید رو به لیست درایور ها اضافه کنید و لیست تنظیات مورد نیاز را نیز مشخص کنید.

</div>

```php
'drivers' => [
    'zarinpal' => [...],
    'my_driver' => [
        ... // Your Config Params here.
    ]
]
```

<div dir="rtl">


کلاس درایوری که قصد ساختنش رو دارید باید کلاس `Shetabit\Multipay\Abstracts\Driver` رو به ارث ببره.

به عنوان مثال:

</div>

```php
namespace App\Packages\PaymentDriver;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\{Contracts\ReceiptInterface, Invoice, Receipt};

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

<div dir="rtl">


بعد از اینکه کلاس درایور خودتون رو ایجاد کردید به فایل `Config/payment.php` برید و درایور خودتون رو در قسمت `map` اضافه کنید.

</div>

```php
'map' => [
    ...
    'my_driver' => App\Packages\PaymentDriver\MyDriver::class,
]
```

<div dir="rtl">


**نکته:** دقت کنید کلیدی که قسمت `map` قرار میدهید باید همنام با نامی باشد که در قسمت `drivers` قرار داده اید.

#### متدهای سودمند

- `callbackUrl`: با استفاده از این متد به صورت داینامیک می‌توانید ادرس صفحه ای که بعد از پرداخت آنلاین کاربر به ان هدایت میشود را مشخص کنید

</div>

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

<div dir="rtl">

- `amount`: به کمک این متد می‌توانید به صورت مستقیم هزینه صورتحساب را مشخص کنید

</div>

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

<div dir="rtl">

- `via`: به منظور تغییر درایور در هنگام اجرای برنامه مورد استفاده قرار میگیرد

</div>

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

<div dir="rtl">

- ###### `config`: به منظور تغییر تنظیمات در هنگام اجرای برنامه مورد استفاده قرار میگیرد

</div>

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

<div dir="rtl">

- ###### `فیلدهای اضافی (دلخواه)`: در نظر داشته باشید که تمامی درگاه‌ها از این امکان پشتیبانی نمیکنند.
درگاه **پرداخت الکترونیک سامان** تا ۴ فیلد اضافه را پشتبانی میکند و هرکدام از فیلدها تا ۵۰ کاراکتر اطلاعات را میتوانند در خود نگهداری کنند.

اطلاعات این فیلدها در هنگام گزارش گیری در پنل پذیرنده نمایش داده میشوند. 

شما میتوانید اطلاعاتی را که منجر به تسریع عملیات گزارش گیری و مغایرت گیری کمک میکند را در این فیلدها ذخیره و هنگام پرداخت به بانک ارسال نمایید.

</div>

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

<div dir="rtl">

#### رویدادها:

**نکته اول:** تمامی listener ها به صورت global تنظیم خواهند شد و برای تمامی پرداخت ها اعمال میشوند.

**نکته دوم:** اگه میخواید listener های شما به درستی کار کنند باید حتما آنها را تا قبل از فراخوانی شدن رویدادها تنظیم کرده باشید.

> بهتر است listener ها را در جایی از برنامه قرار دهید که همیشه قبل از کار کردن با درگاه های پرداخت اجرا شوند و تنظیم شوند.

---

سه مورد از رویداد وجود دارند که میتوانید برای آنها listener تنظیم کنید:

1. **purchase**
2. **pay**
3. **verify**.

- **purchase**: این رویداد بعد از purchase شدن صورتحساب فراخوانی میشود.

</div>

```php
// add purchase event listener
Payment::addPurchaseListener(function($driver, $invoice) {
    echo $driver;
    echo $invoice;
});
```

<div dir="rtl">

- **pay**: این رویداد بعد از اینکه متد pay فراخوانی شود اتفاق می افتد. در این حالت صورتحساب اماده ی پرداخت می باشد.
</div>

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

<div dir="rtl">

- **verify**: این رویداد هنگامی که صورتحساب موفقیت آمیز verify شود فراخوانی میشود.
</div>


```php
// شما میتوانید چندین لیستنر داشته باشید و همچنین آنها را حذف کنید

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

<div dir="rtl">

## درایور آفلاین
 (Local driver)


این درایور برای شبیه سازی روند خرید از درگاه اینترنتی استفاده میشود.

شروع روند پرداخت مانند بقیه درایورها است.

</div>

```php
$invoice = (new Invoice)->amount(10000);
$payment->via('local')->purchase($invoice, function($driver, $transactionId) {
    // یک شناسه پرداخت به صورت اتفاقی تولید و برگردانده میشود
})->pay()->render();
```
<p align="center"><img src="resources/images/local-form-fa.png?raw=true"></p>

<div dir="rtl">

بعد از صدا زدن متد `render` یک فرم `HTML‍` با دکمه های **پرداخت موفق** و **پرداخت ناموفق** نمایش داده میشود. این دکمه‌ها یک پرداخت موفق یا ناموفق درگاه بانکی واقعی را شبیه سازی میکنند و پس از آن مسیر را به callbackUrl انتقال میدهند.

در هر دو حالت پارامتر `trasactionId` به انتهای مسیر callbackUrl اضافه شده و قابل دسترسی است.

بعد از انتقال به callbackUrl امکان اعتبارسنجی تراکنش وجود دارد. 
</div>

```php
$receipt = $payment->via('local')->verify();
```

<div dir="rtl">
در صورت پرداخت موفق، رسید پرداخت با مشخصات ساختگی تولید میشود.

</div>

```php
[
'orderId' => // شماره سفارش (ساختگی) 
'traceNo' => // شماره پیگیری (ساختگی) (جهت ذخیره در دیتابیس)
'referenceNo' => // شماره تراکنش که در مرحله قبل تولید شده بود (transactionId)
'cardNo' => // چهار رقم آخر کارت (ساختگی)
]
```

<div dir="rtl">
در صورتی که پرداخت ناموفق (یا لغو تراکنش)، یک استثنا از نوع `InvalidPaymentException` ایجاد میشود که حاوی پیام لغو تراکنش توسط کاربر است.

تعدادی از امکانات درایور توسط مقدارهایی که به `invoice` داده میشوند، قابل تنظیم است.
</div>


- ###### `پارامترهای قابل تنظیم`

```php
$invoice->detail([
    // setting this value will cause `purchase` method to throw an `PurchaseFailedException` 
    // to simulate when a gateway can not initialize the payment.
        'failedPurchase' => 'custom message to decribe the error',

    // Setting this parameter will be shown in payment form.
        'orderId' => 4444,
]);
```

- ###### `ظاهر فرم`

<div dir="rtl">
 بعضی از مشخصات ظاهری فرم پرداخت نمایش داده شده از طریق پارامترهای درایور `local` در فایل `payment.php` قابل تغییر هستند.

</div>

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






<div dir="rtl">

## تغییرات

برای مشاهده آخرین تغییرات انجام شده در پکیج [قسمت تغییرات](CHANGELOG.md) را بررسی کنید.

## مشارکت کننده ها

برای مشاهده لیست مشارکت کننده ها [CONTRIBUTING](CONTRIBUTING.md) and [CONDUCT](CONDUCT.md) را بررسی کنید.

## امنیت

در صورتی که مشکل امنیتی در پکیج پیدا کردید به منظور رفع مشکل با ایمیل khanzadimahdi@gmail.com در ارتباط باشید.

## توسعه دهندگان

- [Mahdi khanzadi][link-author]
- [All Contributors][link-contributors]

## لایسنس

توسعه و تولید تحت لایسنس MIT است. برای اطلاعات بیشتر [فایل لایسنس](LICENSE.md) را مطالعه کنید.

</div>

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
