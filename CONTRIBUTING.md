# Contributing

Contributions are **welcome** and will be fully **credited**.

We accept contributions via Pull Requests on [Github](https://github.com/shetabit/multipay).

## Running the checks

Every pull request is checked by GitHub Actions. You can run the very same checks before pushing:

```bash
composer ci          # coding style, static analysis and the test suite
```

The single checks are available as ``$ composer test``, ``$ composer check-style`` and ``$ composer analyse``.

If you do not have PHP installed on your machine, the shipped `Dockerfile` and `Makefile` run everything inside a
container instead: ``$ make ci`` (see ``$ make help`` for every available target).

## Pull Requests

- **[PSR-2 Coding Standard](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md)** - Check the code style with ``$ composer check-style`` and fix it with ``$ composer fix-style``.

- **Add tests!** - Your patch won't be accepted if it doesn't have tests.

- **Keep the static analysis green** - Run ``$ composer analyse``. PHPStan has to report no errors at all.

- **Keep the coverage up** - The code coverage of the test suite has to stay above 75%, which the CI pipeline checks. ``$ composer test-coverage`` reports it locally.

- **Do not talk to a gateway in a test** - The driver tests answer the HTTP calls of a driver from a queue of prepared responses, see `tests/Drivers/DriverTestCase.php`. Drivers that build their client inside the method that uses it, use cURL or talk to their gateway while they are being constructed are pointed at the stub HTTP server of `tests/Support/StubServer.php` instead.

- **Document any change in behaviour** - Make sure the `README.md` and any other relevant documentation are kept up-to-date.

- **Consider our release cycle** - We try to follow [SemVer v2.0.0](http://semver.org/). Randomly breaking public APIs is not an option.

- **Create feature branches** - Don't ask us to pull from your master branch.

- **One pull request per feature** - If you want to do more than one thing, send multiple pull requests.

- **Send coherent history** - Make sure each individual commit in your pull request is meaningful. If you had to make multiple intermediate commits while developing, please [squash them](http://www.git-scm.com/book/en/v2/Git-Tools-Rewriting-History#Changing-Multiple-Commit-Messages) before submitting.


**Happy coding**!
