# PoetsPloeg op de VPS zetten (naast getbolster.nl)

PoetsPloeg draait als losse app naast getbolster.nl. De twee delen alleen nginx, PHP 8.3 en de MySQL-server. PoetsPloeg krijgt een eigen gebruiker, map, PHP-FPM-pool, database en certificaat. **getbolster.nl wordt nergens aangeraakt.**

Alle commando's voer je uit via SSH op de VPS (23.88.99.253).

## 1. DNS

Maak bij de DNS van thomasklaassen.nl een **A-record** aan: `poetsploeg` → `23.88.99.253`. Wacht tot `dig +short poetsploeg.thomasklaassen.nl` het IP teruggeeft. Dit moet in orde zijn vóór het certificaat in stap 7.

## 2. Gebruiker en code

```bash
sudo adduser --system --group --home /var/www/poetsploeg --shell /bin/bash poetsploeg
sudo chown poetsploeg:poetsploeg /var/www/poetsploeg
sudo -u poetsploeg git clone https://github.com/th0547/PoetsPloeg.git /var/www/poetsploeg
# Privé-repo? Maak een deploy key: sudo -u poetsploeg ssh-keygen -t ed25519 en zet de .pub bij GitHub → Settings → Deploy keys.
```

Werk je (nog) niet op `main`? Zet dan straks `DEPLOY_BRANCH` in `.env` en gebruik `BRANCH=<naam>` bij het deployscript.

## 3. Database

```bash
sudo mysql
```
```sql
CREATE DATABASE poetsploeg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'poetsploeg'@'localhost' IDENTIFIED BY 'KIES-EEN-STERK-WACHTWOORD';
GRANT ALL PRIVILEGES ON poetsploeg.* TO 'poetsploeg'@'localhost';
FLUSH PRIVILEGES;
```

## 4. .env

```bash
sudo -u poetsploeg cp /var/www/poetsploeg/.env.example /var/www/poetsploeg/.env
sudo -u poetsploeg nano /var/www/poetsploeg/.env
```

Pas minimaal deze waarden aan:

```ini
APP_NAME=PoetsPloeg
APP_ENV=production
APP_DEBUG=false
APP_URL=https://poetsploeg.thomasklaassen.nl
APP_LOCALE=nl
APP_FALLBACK_LOCALE=nl

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=poetsploeg
DB_USERNAME=poetsploeg
DB_PASSWORD=KIES-EEN-STERK-WACHTWOORD

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database

# Optioneel: automatisch uitrollen via GitHub (stap 9)
DEPLOY_WEBHOOK_SECRET=
DEPLOY_BRANCH=main
```

```bash
sudo chmod 600 /var/www/poetsploeg/.env
```

## 5. Eerste installatie

```bash
cd /var/www/poetsploeg
sudo -u poetsploeg composer install --no-dev --optimize-autoloader
sudo -u poetsploeg npm ci && sudo -u poetsploeg npm run build
sudo -u poetsploeg php artisan key:generate
sudo -u poetsploeg php artisan migrate --force
sudo -u poetsploeg php artisan poetsploeg:create-planner        # je eigen inlog
sudo -u poetsploeg php artisan config:cache && sudo -u poetsploeg php artisan route:cache && sudo -u poetsploeg php artisan view:cache
```

Gebruik op productie **geen** `--seed`, want dat zet demodata en demo-accounts in de database.

## 6. PHP-FPM-pool en nginx

```bash
sudo cp /var/www/poetsploeg/deploy/php-fpm-poetsploeg.conf /etc/php/8.3/fpm/pool.d/poetsploeg.conf
sudo php-fpm8.3 -t && sudo systemctl reload php8.3-fpm
ls -l /run/php/poetsploeg.sock            # moet bestaan

sudo cp /var/www/poetsploeg/deploy/nginx-poetsploeg.conf /etc/nginx/sites-available/poetsploeg
sudo ln -s /etc/nginx/sites-available/poetsploeg /etc/nginx/sites-enabled/poetsploeg
sudo nginx -t && sudo systemctl reload nginx
```

Nginx (user `www-data`) moet de map `public/` kunnen lezen:

```bash
sudo chmod 755 /var/www/poetsploeg
sudo chmod -R o+rX /var/www/poetsploeg/public
```

`.env`, `storage/` en de broncode blijven alleen leesbaar voor `poetsploeg`. PHP draait via de eigen pool als `poetsploeg`.

## 7. HTTPS

```bash
sudo certbot --nginx -d poetsploeg.thomasklaassen.nl
```

De bestaande `certbot.timer` verlengt dit certificaat automatisch.

## 8. Scheduler (cron)

Elke nacht vult PoetsPloeg de planning aan tot 12 weken vooruit:

```bash
sudo crontab -u poetsploeg -e
```
```
* * * * * cd /var/www/poetsploeg && php artisan schedule:run >> /dev/null 2>&1
```

## 9. Updates uitrollen

**Handmatig:**

```bash
sudo -u poetsploeg /var/www/poetsploeg/deploy/deploy.sh            # of: BRANCH=andere-branch ...
```

`deploy.sh` doet het volgende:
- `git reset` naar de remote branch
- `composer install`
- `npm build`
- migrations
- caches

Om na de deploy php-fpm te mogen herladen (opcache verversen), voeg je deze sudo-regel toe met `sudo visudo -f /etc/sudoers.d/poetsploeg`:

```
poetsploeg ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm
```

**Automatisch bij een push (zelfde patroon als bolster, maar los ervan):**

1. Zet een lange willekeurige waarde in `.env`: `DEPLOY_WEBHOOK_SECRET=$(openssl rand -hex 32)`. Draai daarna `sudo -u poetsploeg php artisan config:cache`.
2. Installeer de systemd-units:
   ```bash
   sudo cp /var/www/poetsploeg/deploy/poetsploeg-deploy.service /var/www/poetsploeg/deploy/poetsploeg-deploy.path /etc/systemd/system/
   sudo systemctl daemon-reload && sudo systemctl enable --now poetsploeg-deploy.path
   ```
3. Ga in GitHub naar **Settings → Webhooks → Add webhook** van `th0547/PoetsPloeg` en vul in:
   - Payload URL: `https://poetsploeg.thomasklaassen.nl/deploy/webhook`
   - Content type: `application/json`
   - Secret: dezelfde waarde als `DEPLOY_WEBHOOK_SECRET`
   - Events: *Just the push event*

Een push naar `DEPLOY_BRANCH` zet het bestand `storage/app/deploy.trigger` neer. Systemd start daarop `deploy.sh`. De log staat in `storage/logs/deploy.log`.

## Controle

- `https://poetsploeg.thomasklaassen.nl/up` geeft "Application up".
- Je kunt inloggen met het account uit stap 5.
- `getbolster.nl` werkt nog zoals voorheen.

## Problemen?

| Symptoom | Oplossing |
|---|---|
| 502 Bad Gateway | Draait de pool? Controleer `ls /run/php/poetsploeg.sock` en `sudo journalctl -u php8.3-fpm`. |
| 500-fout | Kijk in `storage/logs/laravel.log`. Mag `poetsploeg` schrijven in `storage/` en `bootstrap/cache/`? |
| Opmaak ontbreekt | `npm run build` is niet gedraaid, of `public/build` is niet leesbaar voor www-data. |
| Wijziging in `.env` werkt niet | Draai `sudo -u poetsploeg php artisan config:cache`. |
