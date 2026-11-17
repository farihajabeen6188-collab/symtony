# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip acl file gettext \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
        @composer \
        apcu \
        intl \
        opcache \
        zip \
        pdo_pgsql \
        bcmath

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

COPY docker/php/app.ini $PHP_INI_DIR/app.conf.d/10-app.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

# ---- dev image: source is bind-mounted, nothing copied ----
FROM base AS dev
ENV APP_ENV=dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
