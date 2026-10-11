# Novon – planningstool voor schoonmaak

Webapplicatie om schoonmaakdiensten voor meerdere panden te plannen, met vaste en losse taken, meerdere medewerkers en een slimme vervangerszoeker bij uitval.

## Wat kan het?

- **Panden** met pandinfo (adres, contact, toegangsinstructies) en **vaste taken**: wekelijks, elke N weken, maandelijks op de N-de weekdag of op een vaste dag. Elke taak heeft tijden, het aantal personen, vereiste vaardigheden en een vaste bezetting.
- **Diensten worden automatisch ingepland** tot 12 weken vooruit. Feestdagen worden desgewenst overgeslagen. Handmatig aangepaste diensten blijven altijd staan.
- **Medewerkers** met vaardigheden, pandkennis (ingewerkt op), een vast weekpatroon van beschikbaarheid en contracturen (min/max per week).
- **Verlof, ziekte en uitval**: bij registratie wordt de medewerker automatisch van zijn diensten gehaald. Die diensten komen in "Open diensten" met de knop **Zoek vervanger**.
- **Vervangerszoeker**: rangschikt geschikte collega's op beschikbaarheid, vaardigheden, pandkennis en uren. Wie afwezig of al ingepland is, valt af. Bij elke kandidaat staat de reden ("✓ kent pand, ✗ 2 u boven contract").
- **Gantt-planning** per medewerker of per pand (dag, week of maand), met slepen en neerzetten. Elke wijziging wordt gecontroleerd op dubbel ingepland, afwezigheid, beschikbaarheid, vaardigheden, pandkennis en contracturen.
- **Maandkalender** en een **dagoverzicht** met open plekken en waarschuwingen.
- Rollen: **planner** en **teamleider**, met dezelfde rechten. Een login voor medewerkers volgt later.

Techniek: Laravel 12 (PHP 8.3), MySQL 8, Blade + Alpine.js + Tailwind, vis-timeline (Gantt) en FullCalendar.

## Lokaal testen met Docker

Vereist: Docker Desktop.

```bash
git clone git@github.com:th0547/Novon.git && cd Novon
docker compose up -d                                   # eerste keer duurt een paar minuten
docker compose exec app php artisan migrate --seed     # database + demodata
```

Open <http://localhost:8080> en log in met **planner@novon.test** / **password**. De demo bevat 15 panden, 50 medewerkers, vaste taken, verlof en een zieke medewerker. Uitgaande e-mail kun je bekijken op <http://localhost:8025> (Mailpit).

- Opnieuw beginnen met verse demodata: `docker compose exec app php artisan migrate:fresh --seed`
- Na wijzigingen in JS/CSS: `docker compose exec app npm run build`
- Stoppen: `docker compose down` (met `-v` gooi je ook de database weg)

### In GitHub Codespaces

Dezelfde commando's werken ook in een Codespace (daar zit Docker al in). Open daarna onder **Poorten** de doorgestuurde poort 8080. Dankzij `TRUSTED_PROXIES=*` in `.env.docker` laadt de opmaak dan via https. Gebruik je al een `.env` van vóór deze instelling? Voeg dan `TRUSTED_PROXIES=*` zelf toe.

## Lokaal zonder Docker

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
php artisan serve
```

## Tests

```bash
php artisan test
```

## Op de server zetten

Zie [DEPLOY.md](DEPLOY.md) voor de installatie op de VPS naast getbolster.nl.

## Handige commando's

| Commando | Wat |
|---|---|
| `php artisan novon:create-planner` | Planner- of teamleideraccount aanmaken (`--role=teamleider`) |
| `php artisan novon:generate-shifts` | Diensten aanvullen tot 12 weken vooruit (draait ook elke nacht) |
