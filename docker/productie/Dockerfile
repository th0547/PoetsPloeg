# Productie-image van PoetsPloeg. Wordt op de server gebouwd door scripts/server/uitrol.sh
# (geen register: geen registertoken op de machine en één bouwpad, les uit het vorige project).
#
# Doelen:
#   app  - PHP-FPM met de code, vendor en gebouwde assets (ook gebruikt door de scheduler)
#   web  - Caddy met alleen public/ (statische bestanden + TLS), stuurt PHP door naar app:9000

ARG PHP_VERSION=8.3

# --- bron: code + PHP-afhankelijkheden zonder dev-pakketten ---------------------------------
FROM composer:2 AS vendor
WORKDIR /bron
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY . .
# Het ontvangstscript pakt de tar uit met umask 027; zonder dit kan www-data de code niet lezen.
RUN chmod -R u=rwX,go=rX . \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
    && rm -rf .env tests node_modules

# --- assets: Vite-build (Tailwind leest ook de pagination-views uit vendor) ------------------
FROM node:26-bookworm-slim AS assets
WORKDIR /bron
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /bron ./
COPY resources ./resources
RUN npm run build

# --- app: PHP-FPM ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd opcache bcmath \
    && rm -rf /var/lib/apt/lists/*
COPY docker/productie/php.ini /usr/local/etc/php/conf.d/zz-poetsploeg.ini
COPY docker/productie/fpm.conf /usr/local/etc/php-fpm.d/zz-poetsploeg.conf
COPY docker/productie/entrypoint.sh /usr/local/bin/poetsploeg-entrypoint

WORKDIR /var/www/poetsploeg
COPY --from=vendor /bron ./
COPY --from=assets /bron/public/build ./public/build
# Alleen storage en bootstrap/cache zijn schrijfbaar; de code zelf is van root.
# storage/app is in compose een volume (geüploade bestanden); de rest van storage begint
# bij elke uitrol schoon, zodat er geen gecompileerde views van een oude versie blijven.
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod 755 /usr/local/bin/poetsploeg-entrypoint

# De commit zit in de image, zodat /gezond kan laten zien welke versie er echt draait.
ARG POETSPLOEG_COMMIT=onbekend
ENV POETSPLOEG_COMMIT=${POETSPLOEG_COMMIT}

USER www-data
ENTRYPOINT ["poetsploeg-entrypoint"]
CMD ["php-fpm"]

# --- web: Caddy -----------------------------------------------------------------------------
FROM caddy:2-alpine AS web
COPY docker/productie/Caddyfile /etc/caddy/Caddyfile
COPY --from=app /var/www/poetsploeg/public /var/www/poetsploeg/public
