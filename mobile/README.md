# PoetsPloeg-app voor planners

Een app voor iPhone en iPad (later ook Android) voor planners en teamleiders. Gebouwd met Expo (React Native) en praat met de server via `/api/v1` (zie `../docs/api.md`).

Wat de app kan:
- **Vandaag:** wie werkt er nu, wat komt er nog, open plekken, afwezigen en verlofverzoeken.
- **Rooster:** een week per dag, per pand of per medewerker.
- **Dienst:** wie er staat en de waarschuwingen; vervanger zoeken, inplannen of weghalen; wijzigen of annuleren.
- **Open diensten:** het overzicht met de reden (bijv. vaste kracht ziek).
- **Afwezig:** uitval of verlof melden, het vervangscherm per dienst, verzoeken afwijzen.
- **Inloggen:** zoals op de website, met wachtwoord en daarna de code uit de authenticator-app. Daarna ontgrendel je met Face ID of Touch ID.

Alles wat de app (nog) niet kan, zoals medewerkers, panden, taken en beleid, doe je op de website.

## Een nieuwe versie op je iPhone of iPad

Daarvoor is geen Mac nodig. Het bouwen gebeurt in de cloud van Expo.

1. Op GitHub: **Actions → App bouwen → Run workflow**. Kies platform `ios` en profiel `production`.
2. De build duurt ongeveer 15 tot 25 minuten en gaat daarna vanzelf naar TestFlight.
3. Apple controleert de build kort (meestal binnen een uur). Daarna staat de nieuwe versie in de TestFlight-app op je iPhone en iPad.

## Eenmalig instellen

Dit kan allemaal in de browser op de iPad. Voor stap 3 gebruik je GitHub Codespaces: een terminal in de browser.

1. **Expo-account:** maak een gratis account op [expo.dev](https://expo.dev). Ga naar **Account settings → Access tokens**, maak een token aan en zet het in GitHub als secret: repository → **Settings → Secrets and variables → Actions → New repository secret**, met de naam `EXPO_TOKEN`.
2. **App Store Connect:**
   - Maak de app aan via [appstoreconnect.apple.com](https://appstoreconnect.apple.com): **Apps → +**.
   - Bundle-ID: `nl.poetsploeg.planner`. Maak die eerst aan bij Certificates, Identifiers & Profiles als hij niet in de lijst staat.
   - Noteer daarna het **Apple ID** van de app (een getal) onder App-informatie.
3. **Project koppelen en certificaten** (één keer, in een Codespace):
   - Open de repository op GitHub, kies **Code → Codespaces → Create codespace** en voer in de terminal uit:
     ```bash
     cd mobile
     npm ci
     npx eas-cli@latest login
     npx eas-cli@latest init
     npx eas-cli@latest credentials --platform ios
     ```
   - `init` koppelt het project aan je Expo-account en zet een `projectId` in `app.json`. Commit die wijziging.
   - Kies bij `credentials` het profiel `production`. Log in met je Apple developer-account en laat Expo de **Distribution Certificate** en het **Provisioning Profile** aanmaken.
   - Kies daarna **App Store Connect: Manage your API Key** en **Set up your project to use an API Key for EAS Submit**, zodat het insturen naar TestFlight zonder jou kan.
4. **Apple ID invullen:** zet het Apple ID uit stap 2 in `eas.json` bij `submit.production.ios.ascAppId`. Je kunt het ook aan Claude geven, dan doet Claude het.
5. Installeer **TestFlight** op je iPhone en iPad, en start de workflow **App bouwen**.

Het server-adres van de app staat in `eas.json` (`EXPO_PUBLIC_API_URL`, nu `https://e-rp.nl`).

## Ontwikkelen

```bash
cd mobile
npm ci
npm run lint
npm run typecheck
npm test
```

- `npx expo start` start de ontwikkelserver. Het server-adres zet je in `.env.local` (zie `.env.example`).
- Face ID werkt alleen in een echte build, niet in Expo Go.
- `npx expo export --platform web` maakt een webversie. Die is alleen om schermen te testen, bijvoorbeeld met Playwright tegen `php artisan serve`; in de webversie staat het token in `sessionStorage`.
- De routes staan in `src/app/` (Expo Router) en de API-client in `src/api/`. Inloggen en Face ID zitten in `src/auth/`.
