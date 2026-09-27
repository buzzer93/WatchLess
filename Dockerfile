# syntax=docker/dockerfile:1
FROM php:8.3-fpm-bookworm AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libonig-dev libsqlite3-dev unzip \
    && docker-php-ext-install intl mbstring pdo_sqlite pcntl opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
ENV APP_ENV=prod APP_DEBUG=0
COPY docker/php.ini /usr/local/etc/php/conf.d/watchless.ini

FROM php-base AS app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && mkdir -p var && chown -R www-data:www-data var
CMD ["php-fpm"]

FROM node:22-bookworm-slim AS javascript-runtime

FROM php-base AS worker-runtime
USER root
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-venv ffmpeg \
    && rm -rf /var/lib/apt/lists/* \
    && python3 -m venv /opt/whisper
ENV PATH="/opt/whisper/bin:${PATH}" XDG_CACHE_HOME=/app/var/cache-models
COPY --from=javascript-runtime /usr/local/bin/node /usr/local/bin/node
COPY requirements-tools.txt ./
RUN pip install --no-cache-dir --upgrade pip \
    && pip install --no-cache-dir 'torch==2.14.0+cpu' --index-url https://download.pytorch.org/whl/cpu --extra-index-url https://pypi.org/simple \
    && pip install --no-cache-dir -r requirements-tools.txt

FROM worker-runtime AS worker
COPY --from=app /app /app
USER www-data
CMD ["php", "bin/console", "messenger:consume", "async", "--keepalive=60", "--time-limit=3600", "--memory-limit=256M", "-v"]

FROM app AS test
ENV APP_ENV=test APP_DEBUG=1
RUN composer install --no-interaction --prefer-dist --no-scripts
CMD ["php", "vendor/bin/phpunit"]

FROM caddy:2-alpine AS web
COPY public /app/public
COPY docker/Caddyfile /etc/caddy/Caddyfile
