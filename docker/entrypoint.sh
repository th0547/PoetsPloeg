#!/usr/bin/env bash
# Lokale testomgeving: zorgt bij elke start dat pakketten, opmaak en database bij de code passen.
# Daardoor is na een branchwissel of `git pull` alleen `docker compose up -d` (of
# `docker compose restart app`) nodig.
# vendor/ en node_modules/ staan in Docker-volumes (zie docker-compose.yml).
set -e
cd /var/www/poetsploeg

# Hash van de inhoud van bestanden en mappen. Verandert die, dan werken we dat onderdeel bij.
hash_of() {
    find "$@" -type f 2>/dev/null | LC_ALL=C sort | xargs -r cat | sha256sum | cut -d' ' -f1
}

# changed <stempelbestand> <hash>: waar als het stempel ontbreekt of een andere hash bevat.
changed() {
    [ ! -f "$1" ] || [ "$(cat "$1")" != "$2" ]
}

db_ready() {
    php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        try { Illuminate\Support\Facades\DB::connection()->getPdo(); } catch (Throwable $e) { exit(1); }' >/dev/null 2>&1
}

if [ "${POETSPLOEG_ROLE:-app}" = "app" ]; then
    [ -f .env ] || cp .env.docker .env

    # Controleer op een afgeronde installatie, niet alleen op de map: een afgebroken
    # installatie wordt zo bij de volgende start gewoon afgemaakt.
    composer_hash=$(hash_of composer.lock)
    if [ ! -f vendor/autoload.php ] || changed vendor/.poetsploeg-lock "$composer_hash"; then
        echo "PoetsPloeg: PHP-pakketten installeren of bijwerken (de eerste keer duurt dit een paar minuten)…"
        composer install --no-interaction --prefer-dist --no-progress
        echo "$composer_hash" > vendor/.poetsploeg-lock
    fi

    grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

    npm_hash=$(hash_of package-lock.json)
    if [ ! -f node_modules/.package-lock.json ] || changed node_modules/.poetsploeg-lock "$npm_hash"; then
        echo "PoetsPloeg: JavaScript-pakketten installeren of bijwerken…"
        npm ci --no-audit --no-fund
        echo "$npm_hash" > node_modules/.poetsploeg-lock
    fi

    # De opmaak hangt af van de scripts en stijlen, maar ook van de pagina's (Tailwind leest de klassen daaruit).
    build_hash=$(hash_of resources app/Models app/Support package-lock.json vite.config.js tailwind.config.js postcss.config.js)
    if [ ! -f public/build/manifest.json ] || changed public/build/.poetsploeg-bron "$build_hash"; then
        echo "PoetsPloeg: opmaak bouwen…"
        npm run build
        echo "$build_hash" > public/build/.poetsploeg-bron
    fi

    chown -R www-data:www-data storage bootstrap/cache
    php artisan view:clear >/dev/null 2>&1 || true

    # Database: wachten tot hij bereikbaar is, dan nieuwe migraties draaien (bestaande gegevens blijven).
    # Een lege database krijgt meteen de demodata.
    echo "PoetsPloeg: database bijwerken…"
    for _ in $(seq 1 30); do
        db_ready && break
        sleep 2
    done
    if php artisan migrate:status >/dev/null 2>&1; then
        php artisan migrate --force
    else
        echo "PoetsPloeg: lege database, demodata laden…"
        php artisan migrate --force --seed
    fi

    echo "PoetsPloeg: klaar. Open http://localhost:${POETSPLOEG_PORT:-8080} en log in met planner@poetsploeg.test / password"
else
    # Andere containers (scheduler) wachten tot de app-container klaar is.
    until [ -f vendor/autoload.php ] && grep -q '^APP_KEY=base64' .env 2>/dev/null; do sleep 2; done
fi

exec "$@"
