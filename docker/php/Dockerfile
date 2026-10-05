FROM php:8.4-fpm-alpine

RUN apk add --no-cache --virtual .build-deps libpq-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
 && apk add --no-cache libpq \
 && apk del .build-deps

COPY php.ini $PHP_INI_DIR/conf.d/app.ini

WORKDIR /app

# Cache dlaždic je pojmenovaný volume, musí na ni smět zapisovat php-fpm.
RUN mkdir -p /app/cache && chown www-data:www-data /app/cache
