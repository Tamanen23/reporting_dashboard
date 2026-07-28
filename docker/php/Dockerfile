FROM php:8.4-fpm-alpine

RUN apk add --no-cache icu-dev libpq-dev libzip-dev oniguruma-dev $PHPIZE_DEPS \
    && docker-php-ext-install intl mbstring pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
WORKDIR /var/www/html
