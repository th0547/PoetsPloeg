# PoetsPloeg – planningstool voor schoonmaak

Webapplicatie om schoonmaakdiensten voor meerdere panden te plannen, met vaste en losse taken, meerdere medewerkers en een slimme vervangerszoeker bij uitval.

## Wat kan het?

- **Panden** met pandinfo (adres, contact, toegangsinstructies) en **vaste taken**: wekelijks, om de week of minder vaak, maandelijks op een weekdag (bijv. de eerste maandag) of op een vaste datum. Elke taak heeft tijden, het aantal personen, vereiste vaardigheden en een vaste bezetting. Een taak kan **meerdere patronen** hebben (bijv. de ene week donderdag, de week erop maandag), elk met een eigen startpunt en eventueel eigen tijden. Gevorderde gebruikers kiezen **Aangepast (code)** en schrijven het patroon als iCalendar-RRULE; uitleg staat op de hulppagina `/hulp/herhaling`. Daar kun je ook in gewone taal beschrijven wat je wilt en Claude of ChatGPT de code laten schrijven.
- **Diensten worden automatisch ingepland** tot 12 weken vooruit. Feestdagen worden desgewenst overgeslagen. Handmatig aangepaste diensten blijven altijd staan.
- **Medewerkers** met vaardigheden, pandkennis (ingewerkt op), beschikbaarheid (een weekpatroon, per tijdvak eventueel voor een periode zoals een schoolblok, plus **afwijkende dagen** die die dag het patroon vervangen, handig bij een wisselend schoolrooster); met **Test nieuwe invoer** op die pagina probeer je een rooster waarin je sleept (proef, zelfde gegevens, terugschakelen kan altijd) en contracturen (min/max per week).
- **Verlof, ziekte en uitval**: bij registratie wordt de medewerker automatisch van zijn diensten gehaald. Die diensten komen in "Open diensten" met de knop **Zoek vervanger**. Verlof telt als de uren die iemand normaal zou werken: geplande diensten, anders vaste taken, anders contracturen per werkdag (de planner kan de uren aanpassen). Ziek tijdens vakantie telt als ziekte. Medewerkers geven vrije dagen door als **Niet beschikbaar** (telt niet als verlof). **Verloftellers** per soort (uren en dagen, goedgekeurd en aangevraagd) met een indicatief vakantiesaldo staan bij de medewerker en onder Mijn verlof; via Afwezigheid → Export een CSV voor de loonadministratie.
- **Vervangerszoeker**: rangschikt geschikte collega's op beschikbaarheid, vaardigheden, pandkennis en uren. Wie afwezig of al ingepland is, valt af. Bij elke kandidaat staat de reden ("✓ kent pand, ✗ 2 u boven contract").
- **Beschikbaarheid bevestigen**: medewerkers krijgen elke week een mail om hun beschikbaarheid te bevestigen voor de week die als volgende geroosterd wordt (standaard 5 weken vooruit, altijd vóór de roosterdeadline). Onder de contracturen vragen we een toelichting; er wordt niets geblokkeerd. Planners zien per medewerker per week de status (menu → Beschikbaarheid bevestigd), weken onder de contracturen, ingepland onder het contract en het meer-uren-signaal per kwartaal (cao art. 13).
- **Gantt-planning** per medewerker of per pand (dag, week of maand), met slepen en neerzetten. Elke wijziging wordt gecontroleerd op dubbel ingepland, afwezigheid, beschikbaarheid, vaardigheden, pandkennis en contracturen.
- **Maandkalender** en een **dagoverzicht** met open plekken en waarschuwingen.
- Rollen: **planner** en **teamleider**, met dezelfde rechten, en **medewerker**.
- **Beleid** (menu → Beleid): alle regels voor verlof, vakantie-uren, rooster, rusttijden, ziekte en beschikbaarheid, standaard volgens de cao Schoonmaak 2026–2028 en de wet (bronnen in `docs/bronnen/`). Elke waarde is te overschrijven, met versies en een ingangsdatum; afwijken ten nadele van de werknemer vraagt een toelichting. Verlofsoorten zijn aan te passen of toe te voegen. Medewerkers lezen de regels onder **Regels**. De planning waarschuwt voor te korte rusttijden en te lange diensten.
- **Tweestapsverificatie (2FA)** met een authenticator-app: verplicht voor planners en teamleiders, vrijwillig voor medewerkers (Mijn profiel → Tweestapsverificatie). Herstelcodes bij verlies van de telefoon; een teamleider kan 2FA van een ander resetten, en op de server kan het met `php artisan poetsploeg:reset-2fa <e-mail>`.
- **Logboek** (alleen teamleiders, menu → Logboek): wie wanneer persoonsgegevens heeft ingezien (telefoonnummers, toegangsinstructies, verlofgeschiedenis), in- en uitloggen, mislukte inlogpogingen en beheer van gebruikers en inlogs. Regels worden na 12 maanden opgeruimd (`POETSPLOEG_AUDIT_RETENTION_MONTHS`).
- **Medewerker-accounts**: bij een medewerker vink je *Uitnodiging sturen om in te loggen* aan. De medewerker krijgt een e-mail met een link (7 dagen geldig) om zelf een wachtwoord in te stellen en krijgt de rol Medewerker. Na het inloggen ziet een medewerker alleen **Mijn planning** (eigen diensten, met adres en collega's), **Verlof** (aanvragen als verzoek, openstaande aanvragen intrekken) en **Beschikbaarheid** (eigen weekpatroon en afwijkende dagen). Een inactieve medewerker kan niet inloggen. Voor de uitnodigingsmail moet de mailer (`MAIL_*` in `.env`) zijn ingesteld.

Techniek: Laravel 12 (PHP 8.3), MySQL 8, Blade + Alpine.js + Tailwind, vis-timeline (Gantt) en FullCalendar.

## Lokaal testen met Docker

Vereist: Docker Desktop.

```bash
git clone git@github.com:th0547/PoetsPloeg.git && cd PoetsPloeg
docker compose up -d                                   # eerste keer duurt een paar minuten
docker compose exec app php artisan migrate --seed     # database + demodata
```

Open <http://localhost:8080> en log in met **planner@poetsploeg.test** / **password**. De demo bevat 15 panden, 50 medewerkers, vaste taken, verlof en een zieke medewerker. Uitgaande e-mail kun je bekijken op <http://localhost:8025> (Mailpit).

- Opnieuw beginnen met verse demodata: `docker compose exec app php artisan migrate:fresh --seed`
- Na wijzigingen in JS/CSS: `docker compose exec app npm run build`
- Stoppen: `docker compose down` (met `-v` gooi je ook de database weg)

### Windows (Docker Desktop)

- De PHP- en JavaScript-pakketten (`vendor`, `node_modules`) staan in Docker-volumes, niet in je projectmap. Daardoor is de eerste start veel sneller.
- Nog sneller: zet de projectmap in WSL (bijv. `\\wsl$\Ubuntu\home\<naam>\PoetsPloeg`) in plaats van op `C:\`.
- Bleef de eerste start eerder hangen op een time-out (`exceeded the timeout of 300 seconds`)? Haal de nieuwste versie op en begin schoon:
  ```bash
  git pull
  docker compose down -v
  docker compose up -d --build
  docker compose logs -f app      # wacht op "ready to handle connections", dan Ctrl+C
  docker compose exec app php artisan migrate --seed
  ```

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

Zie [docs/uitrol.md](docs/uitrol.md): Docker op de Hetzner-server, uitrol via GitHub Actions bij een merge naar `main`.

## Handige commando's

| Commando | Wat |
|---|---|
| `php artisan poetsploeg:create-planner` | Planner- of teamleideraccount aanmaken (`--role=teamleider`) |
| `php artisan poetsploeg:reset-2fa <e-mail>` | Noodroute: tweestapsverificatie van een account uitzetten |
| `php artisan poetsploeg:generate-shifts` | Diensten aanvullen tot 12 weken vooruit (draait ook elke nacht) |
| `php artisan poetsploeg:release-unavailable` | Eenmalig opschonen: medewerkers van toekomstige diensten buiten hun beschikbaarheid halen |
| `php artisan poetsploeg:learn-buildings` | Wie 2 diensten op een pand heeft gedraaid, kent dat pand (draait ook elk uur; aantal via `POETSPLOEG_LEARN_BUILDING_AFTER`) |
