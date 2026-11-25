# API voor de app (api/v1)

De app voor planners praat met de server via `/api/v1/...`. Inloggen gaat met een token (Laravel Sanctum). De sessie-cookie van de website werkt hier niet, en een token werkt niet op de website.

- **Antwoorden** zijn altijd JSON, ook bij fouten: `{ "message": "...", "errors": { "veld": ["..."] } }`.
- **Statuscodes:**
  - `401`: geen of een ongeldig token;
  - `403`: geen rechten, of eerst tweestapsverificatie instellen;
  - `422`: invoer klopt niet.
- **Limiet:** 180 verzoeken per minuut per gebruiker. Inloggen: 10 per minuut, plus de limieten van de website (5 keer een fout wachtwoord, 5 keer een foute code).

## Inloggen

Alleen planners en teamleiders kunnen inloggen in de app. Is tweestapsverificatie verplicht, dan moet die eerst op de website zijn ingesteld (Mijn profiel).

1. `POST /api/v1/auth/login` met `{ email, password, device_name }`. Je krijgt een van twee antwoorden:
   - `{ two_factor: true, challenge }`: vul de code in (stap 2). De challenge is 5 minuten geldig.
   - `{ token, expires_at, user }`: geen tweestapsverificatie ingesteld, en dat is ook niet verplicht.
2. `POST /api/v1/auth/two-factor` met `{ challenge, code }` of `{ challenge, recovery_code }`. Antwoord: `{ token, expires_at, user, recovery_codes_left }`. Het laatste veld is alleen gevuld als je met een herstelcode inlogde.

Stuur het token mee als `Authorization: Bearer <token>`.

- Een token verloopt na 60 dagen (`SANCTUM_EXPIRATION`, in minuten).
- Het vervalt meteen als het wachtwoord, de rol of de tweestapsverificatie van de gebruiker verandert, of als het account wordt verwijderd.
- Verlopen tokens worden elke nacht opgeruimd.

| Methode | Pad | Wat |
|---|---|---|
| `POST` | `auth/logout` | Dit token intrekken |
| `GET` | `me` | De ingelogde gebruiker en zijn rechten |

Inloggen en uitloggen in de app komen in het logboek (`auth.app_login`, `auth.app_logout`, `auth.2fa_failed`).

## Planning

| Methode | Pad | Wat |
|---|---|---|
| `GET` | `today?date=JJJJ-MM-DD` | Vandaag (of een andere dag). De diensten staan verdeeld in `current`, `later` en `finished`, met daarnaast `open_shifts` (komende 14 dagen), `absent`, `pending_requests` en `counts`. Dezelfde gegevens als de startpagina (`App\Services\TodayOverview`). |
| `GET` | `open-shifts?weeks=1\|2\|4\|8&building_id=` | Diensten met open plekken, met `fixed_missing`: wat er met de vaste kracht is. |
| `GET` | `planning?from=&to=` | Alles voor het rooster in een periode van hoogstens 2 maanden: diensten, medewerkers, panden, afwezigheid, feestdagen. Zelfde antwoord als het roosterbord op de website. |
| `POST` | `planning/move` | Een dienst of medewerker verplaatsen (zoals slepen op het roosterbord). |
| `GET` | `shifts/{id}` | Een dienst met details: pand, contact, toegang, vaardigheden, medewerkers en waarschuwingen. Wordt gelogd als inzage van persoonsgegevens. |
| `PATCH` | `shifts/{id}` | Een dienst wijzigen: `title`, `date`, `start_time`, `end_time`, `required_staff`, `notes`, `skills[]`. |
| `DELETE` | `shifts/{id}` | Een dienst annuleren. |
| `GET` | `shifts/{id}/candidates` | Geschikte vervangers, met score en redenen. |
| `POST` | `shifts/{id}/assignments` | Inplannen: `employee_id`, eventueel `replace_employee_id` en `confirm`. Zijn er waarschuwingen en is `confirm` niet `true`, dan komt `{ needs_confirmation: true, warnings }` terug en wordt er nog niets opgeslagen. |
| `DELETE` | `shifts/{id}/assignments/{employee}` | Een medewerker van een dienst halen. |
| `GET` | `search?q=` | Zoeken naar medewerkers en panden. |
| `GET` | `employees/{id}?datum=` | Waar is een medewerker nu, plus zijn dag en week. |

## Vormen

Een **dienst in een lijst** (`today`, `open-shifts`):

```json
{
  "id": 12, "title": "Kantoor", "date": "2026-10-12",
  "starts_at": "2026-10-12T11:00:00+02:00", "ends_at": "2026-10-12T13:00:00+02:00",
  "start_time": "11:00", "end_time": "13:00",
  "required_staff": 1, "open_slots": 0, "cancelled": false, "state": "now",
  "building": { "id": 3, "name": "ReggeIT", "short_name": "Regge", "color": "#0f766e", "ink": "#ffffff" },
  "assignments": [
    { "employee": { "id": 5, "name": "Anna Jansen", "color": "#...", "ink": "#...", "initials": "AJ" },
      "source": "fixed", "warnings": [ { "type": "...", "level": "warning", "message": "..." } ] }
  ]
}
```

Een **afwezigheid**: `id`, `employee`, `type`, `type_label`, `status` (`approved`, `pending` of `rejected`), `status_label`, `title`, `starts_at`, `ends_at`, `period` (als tekst), `note` en `shift_count` (alleen bij verzoeken).
