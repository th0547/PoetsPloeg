#!/usr/bin/env bash
# Uitrollen van PoetsPloeg op de VPS. Draai als gebruiker "poetsploeg":
#   sudo -u poetsploeg /var/www/poetsploeg/deploy/deploy.sh
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/poetsploeg}"
BRANCH="${BRANCH:-main}"

cd "$APP_DIR"
echo "==> $(date '+%F %T') deploy van branch $BRANCH"

git fetch --prune origin "$BRANCH"
git reset --hard "origin/$BRANCH"

php artisan down --retry=15 || true
trap 'php artisan up' EXIT

composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
npm ci --no-audit --no-fund
npm run build

php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Opcache verversen: herlaad alleen de poetsploeg-pool (sudo-regel staat in DEPLOY.md).
sudo -n /usr/bin/systemctl reload php8.3-fpm 2>/dev/null || echo "Let op: php-fpm niet herladen (geen sudo-recht); opcache ververst vanzelf."

echo "==> klaar"
