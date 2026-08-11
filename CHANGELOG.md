# Changelog

All Notable changes to `multipay` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](http://keepachangelog.com/) principles.

## Unreleased

### Added
- A unit test suite covering the payment manager, the invoice, the receipt, the redirection form, the event emitter,
  the request helper, the abstract driver, the driver configuration and the local driver.
- GitHub Actions workflows running the test suite (PHP 8.4 and 8.5, lowest and highest dependencies), the coding style
  check and the static analysis on every pull request and on every push to `master`.
- A `Dockerfile` and a `Makefile` to run the test suite and every check inside a container.
- A `phpcs.xml.dist` ruleset, so that ``$ composer check-style`` fails on coding style errors.
- A PHPStan baseline (`phpstan-baseline.neon`), so that ``$ composer analyse`` fails on newly introduced errors.
- The `analyse`, `rector`, `test-coverage` and `ci` composer scripts.

### Changed
- **Breaking:** PHP 8.4 is now the minimum required version (was PHP 8.0).
- **Breaking:** the dependencies were narrowed down to their latest major versions: `guzzlehttp/guzzle: ^8.0`,
  `nesbot/carbon: ^3.13`, `chillerlan/php-cache: ^5.1` and `ramsey/uuid: ^4.9`.
- The development dependencies were updated: PHPUnit 13, PHP_CodeSniffer 4, PHPStan 2.2 and Rector 2.6.
- `phpunit.xml` was migrated to the current PHPUnit schema and made strict about risky tests, warnings, notices and
  deprecations.

### Removed
- The Travis CI configuration (`.travis.yml`), replaced by GitHub Actions.
- The StyleCI configuration (`.styleci.yml`), replaced by the PHP_CodeSniffer workflow.
- The unused and broken `Shetabit\Multipay\Tests\Traits\DriverCommon` test trait.
- The StyleCI and Scrutinizer badges from the readme files.

### Fixed
- A coding style error in the Digify driver.

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
