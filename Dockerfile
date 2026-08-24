FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

# System packages needed to build the PHP extensions below, plus the ones the
# app actually uses at runtime (gd for image handling, zip for archives, intl
# for locale-aware formatting, mysqli/pdo_mysql for the database).
RUN apk add --no-cache \
        bash git curl unzip \
        libpng-dev libjpeg-turbo-dev freetype-dev \
        libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli gd zip intl exif bcmath opcache \
    && apk del --no-cache libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev icu-dev oniguruma-dev

# Redis extension (for the Redis-ready queue/cache config) - separate from the
# built-in extensions above since it comes from PECL, not the PHP source tree.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-cache .build-deps

COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

# --no-scripts: artisan isn't runnable yet (no .env/APP_KEY at build time) -
# `composer run-script` hooks that call into artisan are deferred to the
# entrypoint below, after the real .env is mounted in.
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
