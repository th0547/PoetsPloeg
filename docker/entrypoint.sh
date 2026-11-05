#!/usr/bin/env bash
# Eerste start: afhankelijkheden installeren, .env aanmaken en assets bouwen.
# vendor/ en node_modules/ staan in Docker-volumes (zie docker-compose.yml).
set -e
cd /var/www/poetsploeg

if [ "${POETSPLOEG_ROLE:-app}" = "app" ]; then
    [ -f .env ] || cp .env.docker .env

    # Controleer op een afgeronde installatie, niet alleen op de map: een afgebroken
    # installatie wordt zo bij de volgende start gewoon afgemaakt.
    if [ ! -f vendor/autoload.php ]; then
        echo "PoetsPloeg: PHP-pakketten installeren (eerste keer, duurt een paar minuten)…"
        composer install --no-interaction --prefer-dist --no-progress
    fi

    grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

    if [ ! -f node_modules/.package-lock.json ]; then
        echo "PoetsPloeg: JavaScript-pakketten installeren…"
        npm ci --no-audit --no-fund
    fi
    if [ ! -f public/build/manifest.json ]; then
        echo "PoetsPloeg: opmaak bouwen…"
        npm run build
    fi

    chown -R www-data:www-data storage bootstrap/cache
    echo "PoetsPloeg: klaar. Eerste keer? Draai: docker compose exec app php artisan migrate --seed"
else
    # Andere containers (scheduler) wachten tot de app-container klaar is.
    until [ -f vendor/autoload.php ] && grep -q '^APP_KEY=base64' .env 2>/dev/null; do sleep 2; done
fi

exec "$@"
