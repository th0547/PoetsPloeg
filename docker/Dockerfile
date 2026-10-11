# Lokale testomgeving die de VPS nabootst: PHP 8.3-FPM met Composer en Node 22.
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libpng-dev libonig-dev curl ca-certificates gnupg \
    && docker-php-ext-install pdo_mysql intl zip gd opcache bcmath \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/novon.ini
COPY docker/entrypoint.sh /usr/local/bin/novon-entrypoint
RUN chmod +x /usr/local/bin/novon-entrypoint

WORKDIR /var/www/novon
ENTRYPOINT ["novon-entrypoint"]
CMD ["php-fpm"]
