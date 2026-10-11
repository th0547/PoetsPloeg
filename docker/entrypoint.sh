#!/usr/bin/env bash
# Eerste start: afhankelijkheden installeren, .env aanmaken en assets bouwen.
set -e
cd /var/www/novon

if [ "${NOVON_ROLE:-app}" = "app" ]; then
    [ -f .env ] || cp .env.docker .env
    [ -d vendor ] || composer install --no-interaction --prefer-dist
    grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
    [ -f public/build/manifest.json ] || { npm ci --no-audit --no-fund && npm run build; }
    chown -R www-data:www-data storage bootstrap/cache
else
    # Andere containers (scheduler) wachten tot de app-container klaar is.
    until [ -f vendor/autoload.php ] && grep -q '^APP_KEY=base64' .env 2>/dev/null; do sleep 2; done
fi

exec "$@"
