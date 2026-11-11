# Lokale testomgeving die de VPS nabootst: PHP 8.3-FPM met Composer en Node 26.
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libpng-dev libonig-dev curl ca-certificates \
    && docker-php-ext-install pdo_mysql intl zip gd opcache bcmath \
    && rm -rf /var/lib/apt/lists/*

# Node 26 + npm uit het officiële Node-image (geen extern installatiescript nodig).
COPY --from=node:26-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:26-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node --version && npm --version

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/poetsploeg.ini
COPY docker/entrypoint.sh /usr/local/bin/poetsploeg-entrypoint
RUN chmod +x /usr/local/bin/poetsploeg-entrypoint

WORKDIR /var/www/poetsploeg
ENTRYPOINT ["poetsploeg-entrypoint"]
CMD ["php-fpm"]
