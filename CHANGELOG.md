# Changelog

All Notable changes to `multipay` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](http://keepachangelog.com/) principles.

## Unreleased

### Added
- A test suite of 509 tests covering the payment manager, the invoice, the receipt, the redirection form, the event
  emitter, the request helper, the abstract driver, the driver configuration and **every driver of the package**.
  The drivers are tested without touching the network: their HTTP calls are answered from a queue of prepared
  responses, and the requests they send are checked along the way.
- A stub HTTP server (`tests/Support/StubServer.php`) for the drivers whose HTTP client can not be replaced from the
  outside: those that build it inside the method that uses it, those that use cURL and those that already talk to
  their gateway while they are being constructed.
- GitHub Actions workflows running the test suite (PHP 8.4 and 8.5, lowest and highest dependencies), the coding style
  check, the static analysis and the code coverage on every pull request and on every push to `master`. The coverage
  has to stay above 75%.
- A `Dockerfile` (with pcov, for coverage) and a `Makefile` to run the test suite and every check inside a container.
- A `phpcs.xml.dist` ruleset, so that ``$ composer check-style`` fails on coding style errors.
- The `analyse`, `rector`, `test-coverage` and `ci` composer scripts.
- The Daracard and the Digify driver are registered in the driver map of `config/payment.php`, and Digify received the
  configuration section it never had. Both were unreachable before.

### Changed
- **Breaking:** PHP 8.4 is now the minimum required version (was PHP 8.0).
- The package was modernized for PHP 8.4. Every parameter, return value and property of `src/` declares a type now
  (365 parameters, 466 return values and 103 properties, none left untyped), promoted constructor properties and
  `readonly` are used where a value never changes, methods that only ever throw are typed `never`,
  `new Foo()->bar()` replaces `(new Foo())->bar()` and `array_find()` replaces a hand written search loop.
  Nullable types are spelled `T|null` instead of `?T`, so that they read like every other union in the package, and
  class names are imported with `use` instead of being written out in full inside the code.
- **Breaking:** `DriverInterface::purchase()` and `Driver::purchase()` declare `string|int|null`, and
  `DriverInterface::amount()` / `detail()` declare `static`. A custom driver has to declare compatible types, and the
  driver example of the readme files shows them.
- **Breaking:** `Shetabit\Multipay\Abstracts\Driver` now declares `protected Invoice $invoice` and
  `protected object $settings` with types. A custom driver must **remove** its own `protected $invoice;` and
  `protected $settings;` declarations, otherwise PHP refuses to load it ("Type of ...::$invoice must be
  Shetabit\Multipay\Invoice"). The bundled drivers no longer redeclare them either.
- **Breaking:** `Shetabit\Multipay\Contracts\DriverInterface` declares `getInvoice(): Invoice`, which the payment
  manager and the payment events have always relied on. Drivers that extend the abstract `Driver` already have it.
- **Breaking:** `Shetabit\Multipay\Contracts\ReceiptInterface::getDetail()` now declares `string $name` and a `mixed`
  return type.
- **Breaking:** `Payment::validateInvoice()` and its calls were removed: with a typed `Invoice` property, an invoice
  always exists, so `InvoiceNotFoundException` could no longer be thrown from there. The exception class stays.
- The static analysis was raised from PHPStan level 1 to level 5, and passes without a baseline.
- `rector.php` no longer crashes: `strictBooleans` was removed from Rector 2's `withPreparedSets()`. Adding
  `declare(strict_types=1)` and rewriting `!empty($string)` are skipped on purpose, see the comments in the config.
- **Breaking:** the dependencies were narrowed down to their latest major versions: `guzzlehttp/guzzle: ^8.0`,
  `nesbot/carbon: ^3.13`, `chillerlan/php-cache: ^5.1` and `ramsey/uuid: ^4.9`.
- The development dependencies were updated: PHPUnit 13, PHP_CodeSniffer 4, PHPStan 2.2 and Rector 2.6.
- `phpunit.xml` was migrated to the current PHPUnit schema and made strict about risky tests, warnings, notices and
  deprecations.

### Removed
- The Travis CI configuration (`.travis.yml`), replaced by GitHub Actions.
- The StyleCI configuration (`.styleci.yml`), replaced by the PHP_CodeSniffer workflow.
- The unused and broken `Shetabit\Multipay\Tests\Traits\DriverCommon` test trait.
- The StyleCI, Scrutinizer and Code Climate badges from the readme files, replaced by the workflow badges and a code
  coverage badge.

### Fixed
- `chillerlan/php-settings-container` below 3.2 is declared as a conflict: 3.1.x declares implicitly nullable
  parameters, which PHP 8.4 deprecated, and it was what the lowest supported dependencies resolved to (3.2.0 fixed it
  upstream). Composer now refuses to install those versions. Any deprecation - ours or a dependency's - fails the build.
- **cURL drivers:** `curl_close()` is deprecated as of PHP 8.5 and was removed from Atipay, Bitpay, Irankish, Jibit,
  Rayanpay and Sepehr, where it had no effect anyway.
- **Bitpay:** a non numeric response body was passed as the exception code of `PurchaseFailedException`, which throws a
  `TypeError` instead of the intended exception.
- **Rayanpay:** the purchase read an attribute off the gateway's HTML without checking that the element is there.
- **Irankish:** `json_decode()` received the `JSON_OBJECT_AS_ARRAY` flag as its `$associative` argument, and
  `str_pad()` received the amount as a number.
- **Zibal:** the messages of failed purchases and verifications had a fallback that could never be reached.
- **Azki, SnappPay:** dropped two checks that could never be true (a response's status code and an invoice's amount are
  never `null`).
- **Jibit, Parspal:** the cache directory of the driver (`tokenStoragePath` / `cachePath`) was ignored, because the
  option was still passed under its name of `chillerlan/php-cache` v4. Access tokens and payment links were written
  relative to the current working directory instead - inside the document root of the application in a typical setup.
  The directory is now created if it does not exist yet.
- **Payping:** the purchase never found the payment code of the gateway, because the driver lower cased the whole
  response body - keys included - before reading `paymentCode` out of it. The response is now read case insensitively,
  and a purchase without a payment code fails instead of returning no transaction id.
- **Sepordeh:** the verification passed `http_errors` inside the `headers` option, which Guzzle 8 rejects outright.
- **Xendit:** the driver declared its own `getInvoice()`, which overrode `Driver::getInvoice()` - the method the
  payment manager and the payment events use to read the invoice of a driver. Reading the invoice of the driver called
  the gateway and returned an array instead of the invoice. **Breaking:** the method that fetches an invoice from the
  gateway is now called `fetchInvoice()`.
- **Pasargad:** the exception messages of a failed payment and of a failed verification read an undefined variable, so
  the message of the gateway was always replaced by the generic one.
- **Etebarino:** a purchase without `items` in the invoice details triggered a PHP warning.
- **Daracard, Digify:** both drivers used Laravel helpers (`now()`, `request()`, the `Http` facade) that are no
  dependencies of this package, so they could not work outside of Laravel. Digify additionally declared a `Digikala`
  class in a namespace that did not match its file, which made it impossible to autoload.
- **IranDargah, Parspal:** the sandbox purchase url of the shipped configuration started with a space, which made the
  sandbox mode fail with an invalid url.
- **Local:** the card number of the receipt was only read from the `cartNo` detail, `cardNo` is now accepted as well.
- A coding style error in the Digify driver, and a redundant loop in `Driver::detail()`.

## Date - 2019-01-09

### Fixed
- Nothing

### Added
- Nothing

### Deprecated
- Nothing

### Fixed
- Nothing

### Removed
- Nothing

### Security
- Nothing
