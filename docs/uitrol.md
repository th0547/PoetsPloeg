# Uitrol en server

PoetsPloeg draait op een eigen Hetzner-server met Docker. Elke merge naar `main` wordt
automatisch uitgerold via GitHub Actions. De workflow is pas groen als `/gezond` precies de
uitgerolde commit teruggeeft.

| | |
|---|---|
| Server | Hetzner Cloud, `178.104.225.130` / `2a01:4f8:c0c:c820::1`, Ubuntu 26.04, hostnaam `poetsploeg` |
| Domein | voorlopig `e-rp.nl` (het oude ERP-domein, wijst al naar de server); later een eigen domein. Zie *Domein wisselen* |
| Inloggen | `ssh thomas@178.104.225.130` (alleen sleutel; root kan niet via SSH) |
| Noodingang | Hetzner-webconsole → *Reset root password* |
| Map | `/opt/poetsploeg` (eigenaar `uitrol`) |

> **Nooit *Rebuild* in Hetzner.** De server is aangemaakt met de cloud-init van het ERP, en
> Hetzner draait die bij een Rebuild opnieuw: dan komen de gebruiker `erp`, een oude sleutel
> en de hostnaam `erp-productie` terug. Moet de server opnieuw, maak dan een nieuwe server
> met [`infra/cloud-init.yaml`](../infra/cloud-init.yaml).

## Zo werkt een uitrol

```
merge naar main
  └─ .github/workflows/uitrol.yml   (environment "productie")
       ├─ ssh-klaarzetten: sleutel (tr -d '\r'), vastgelegde hostsleutel, StrictHostKeyChecking=yes
       ├─ git archive <sha> | ssh uitrol@server "uitrol <sha>"
       │    └─ /usr/local/sbin/poetsploeg-ontvang      (afgedwongen via command= in authorized_keys)
       │         ├─ pakt uit naar /opt/poetsploeg/releases/<sha>
       │         └─ start scripts/server/uitrol.sh UIT DIE COMMIT
       │              ├─ controleert .env (lege waarden → stoppen)
       │              ├─ docker compose build (commit in de image)
       │              ├─ database op, migraties, nieuwe containers
       │              ├─ /opt/poetsploeg/huidig → deze release
       │              ├─ /gezond moet <sha> teruggeven
       │              └─ opruimen: oude releases (5 blijven), images, bouwcache
       └─ health-check van buitenaf: /gezond moet <sha> teruggeven
```

Containers (`infra/compose.yaml`, projectnaam `poetsploeg`):

| Container | Wat |
|---|---|
| `web` | Caddy: TLS via Let's Encrypt, statische bestanden, PHP door naar `app` |
| `app` | PHP-FPM met de code |
| `scheduler` | `php artisan schedule:work` (planning aanvullen, pandkennis leren) |
| `db` | MySQL 8.4, volume `poetsploeg_dbdata`, geen poort naar buiten |

Volumes: `poetsploeg_dbdata` (database), `poetsploeg_bestanden` (`storage/app`) en
`poetsploeg_caddy_data` (certificaten). Logs gaan naar de containerlog
(`docker compose logs`, max. 5 × 10 MB per container).

## De uitrolsleutel

- Gebruiker `uitrol`, lid van `docker`. **Dat staat gelijk aan root.** Daarom mag de sleutel
  alleen `poetsploeg-ontvang` starten: `restrict,command="/usr/local/sbin/poetsploeg-ontvang"`
  in `/home/uitrol/.ssh/authorized_keys`. Geen shell, geen forwarding.
- Toegestane opdrachten: `ping`, `uitrol <sha>`, `onderhoud <taak>`.
- **Intrekken:** haal de regel weg uit `/home/uitrol/.ssh/authorized_keys`. De sleutel van
  Thomas blijft gewoon werken.
- **Vervangen:** maak een nieuwe sleutel (zie hieronder), zet de nieuwe regel in
  `authorized_keys` en de privésleutel in het secret `UITROL_SLEUTEL`.

Ingericht op 10-11-2026 met (SSH-sessie):

```bash
sudo useradd --system --create-home --home-dir /home/uitrol --shell /bin/bash uitrol
sudo usermod -aG docker uitrol
sudo install -d -o uitrol -g uitrol -m 750 /opt/poetsploeg /opt/poetsploeg/releases
sudo install -d -o uitrol -g uitrol -m 700 /home/uitrol/.ssh
# /usr/local/sbin/poetsploeg-ontvang = infra/server/poetsploeg-ontvang (root:root, 755)
```

De sleutel is op de pc van Thomas gemaakt (PowerShell, zonder passphrase):

```powershell
ssh-keygen -t ed25519 -C "github-actions-poetsploeg" -f "$env:USERPROFILE\.ssh\poetsploeg_uitrol"
```

Testen vanaf de pc (PowerShell), verwacht `ok poetsploeg`:

```powershell
ssh -i "$env:USERPROFILE\.ssh\poetsploeg_uitrol" -o IdentitiesOnly=yes uitrol@178.104.225.130 ping
```

Het ontvangstscript bijwerken na een wijziging in `infra/server/poetsploeg-ontvang`: dat
gebeurt **niet** automatisch (bewust: de sleutel mag zijn eigen beperking niet aanpassen).
Zet de nieuwe versie met de hand neer via een SSH-sessie.

## GitHub

Environment **`productie`**, alleen te gebruiken vanaf `main`. Secrets:

| Secret | Inhoud |
|---|---|
| `UITROL_HOST` | `178.104.225.130` |
| `UITROL_GEBRUIKER` | `uitrol` |
| `UITROL_HOSTSLEUTEL` | `178.104.225.130 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMktcSsbg2JvZL/3eXzW/zjrXFGLsMxs+Vg6V9XxPj7` (vingerafdruk `SHA256:7clVMsa0tyJhum8vbsCq24oEBtNsbvgFzlLTS/5rylE`, gecontroleerd op de server én vanaf de pc) |
| `UITROL_SLEUTEL` | privésleutel `poetsploeg_uitrol` |

Variabele (geen secret) in dezelfde environment:

| Variabele | Inhoud |
|---|---|
| `UITROL_URL` | `https://e-rp.nl` — waar de health-check van buitenaf naartoe gaat |

Workflows:

| Workflow | Wanneer |
|---|---|
| `ci.yml` | Elke PR en push naar `main`: Pint, tests, assets, `bash -n` + shellcheck, compose-config, Caddyfile, productie-images bouwen |
| `uitrol.yml` | Merge naar `main`, of met de hand (*Run workflow*) |
| `onderhoud.yml` | Met de hand, met een keuzelijst: `status`, `logs`, `herstart`, `opruimen` |

Branch protection op `main`: alleen via een PR, en de checks *Lint en tests* en
*Serverscripts en compose* moeten groen zijn.

## Eerste keer: `.env` op de server

Eenmalig, in een SSH-sessie. Zie [`infra/env.productie.voorbeeld`](../infra/env.productie.voorbeeld)
voor alle waarden.

```bash
sudo install -o uitrol -g uitrol -m 600 /dev/null /opt/poetsploeg/.env
sudo -u uitrol nano /opt/poetsploeg/.env
```

Na de eerste uitrol je eigen planner-account aanmaken (SSH-sessie; `thomas` zit in `docker`):

```bash
docker exec -it poetsploeg-app-1 php artisan poetsploeg:create-planner
```

Gebruik op productie **nooit** `--seed`: dat zet demodata en demo-accounts in de database.

## Domein wisselen

1. DNS: A- en AAAA-record van het nieuwe domein naar `178.104.225.130` / `2a01:4f8:c0c:c820::1`.
2. Server (SSH-sessie): `APP_URL` en `POETSPLOEG_DOMEIN` in `/opt/poetsploeg/.env` aanpassen.
3. GitHub: variabele `UITROL_URL` in environment `productie` aanpassen.
4. Actions → *Uitrol* → *Run workflow*. Caddy haalt dan zelf een nieuw certificaat.
5. Reverse DNS in Hetzner (*Networking*) naar het nieuwe domein.

## Problemen

| Symptoom | Kijk naar |
|---|---|
| Uitrol stopt bij "… is leeg in /opt/poetsploeg/.env" | Vul die waarde in `.env` in |
| Health-check faalt, certificaat | Wijst de DNS (A én AAAA) naar de server? Onderhoud → `logs`, zoek naar `web` |
| Health-check geeft een oude commit | Draaien de nieuwe containers? Onderhoud → `status` |
| `Host key verification failed` in Actions | Is de server vervangen? Dan `UITROL_HOSTSLEUTEL` bijwerken, na controle van de vingerafdruk |
| `Permission denied (publickey)` in Actions | `UITROL_SLEUTEL` of de regel in `authorized_keys` klopt niet |
| Schijf loopt vol | Onderhoud → `opruimen` |
