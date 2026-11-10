#!/bin/sh
# Bij elke start de Laravel-caches opbouwen. Dat kan pas hier, omdat .env pas bij het
# starten van de container binnenkomt (env_file in compose), niet bij het bouwen.
set -e
cd /var/www/poetsploeg
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
exec "$@"
