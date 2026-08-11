# Development image of the package.
#
# It only contains PHP, Composer and the tools needed to run the test suite and
# the quality checks. The package itself is mounted into /app at runtime, so the
# image does not have to be rebuilt while working on the code.
#
#   docker build --build-arg PHP_VERSION=8.4 --tag multipay-dev .
#   docker run --rm --volume "$PWD":/app multipay-dev composer install
#   docker run --rm --volume "$PWD":/app multipay-dev phpunit
#
# The Makefile that ships with the package wraps those commands.

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_NO_INTERACTION=1 \
    PATH=/app/vendor/bin:$PATH

WORKDIR /app

CMD ["php", "-v"]
