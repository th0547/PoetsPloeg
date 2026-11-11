#!/usr/bin/env bash
# Rolt één commit uit. Wordt gestart door /usr/local/sbin/poetsploeg-ontvang (bron:
# infra/server/poetsploeg-ontvang) als gebruiker uitrol, vanuit de release van die commit:
#   /opt/poetsploeg/releases/<sha>/scripts/server/uitrol.sh <sha>
# Dit script komt dus altijd uit de commit die uitgerold wordt; bij het vorige project
# draaide de server de vorige versie van zijn eigen script.
set -euo pipefail

SHA="${1:?gebruik: uitrol.sh <commit-sha>}"
RELEASE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=scripts/server/gemeen.sh
source "$RELEASE/scripts/server/gemeen.sh"

log "uitrol van $SHA"

# 1. Controleer .env vooraf. Een lege variabele in de Caddyfile liet Caddy bij het vorige
#    project eindeloos herstarten; liever hier stoppen met een duidelijke melding.
[ -f "$ENV_BESTAND" ] || fout "$ENV_BESTAND ontbreekt (zie docs/uitrol.md)"
for naam in APP_KEY DB_PASSWORD DB_ROOT_PASSWORD; do
    [ -n "$(env_waarde "$naam")" ] || fout "$naam is leeg in $ENV_BESTAND"
done
for naam in APP_URL POETSPLOEG_DOMEIN DB_DATABASE DB_USERNAME; do
    [ -n "$(env_waarde "$naam")" ] || fout "$naam is leeg in infra/productie.env"
done
if [ "$(env_waarde MAIL_MAILER)" = smtp ]; then
    for naam in MAIL_HOST MAIL_USERNAME MAIL_PASSWORD; do
        [ -n "$(env_waarde "$naam")" ] || fout "MAIL_MAILER=smtp, maar $naam is leeg (zie infra/env.productie.voorbeeld)"
    done
fi

# 2. Bouwen op de server, met de commit in de image (voor /gezond).
export POETSPLOEG_COMMIT="$SHA"
log "images bouwen"
dc build --pull app web

# 3. Database starten en migreren vóór de nieuwe app live gaat. Mislukt de migratie, dan
#    blijft de vorige versie draaien.
log "database starten"
dc up -d --wait --wait-timeout 180 db
log "migraties"
dc run --rm --no-deps app php artisan migrate --force

# 4. Nieuwe containers starten.
log "containers starten"
dc up -d --remove-orphans --wait --wait-timeout 180
ln -sfn "$RELEASE" "$BASIS/huidig"

# 5. Controleren dat de juiste versie antwoordt. Het eerste certificaat kan even duren.
log "health-check"
gelukt=""
for _ in $(seq 1 45); do
    if antwoord=$(gezond "$SHA" 2>/dev/null) && grep -q '"versie":"juist"' <<<"$antwoord"; then
        gelukt=1
        break
    fi
    sleep 2
done
if [ -z "$gelukt" ]; then
    echo "laatste antwoord: ${antwoord:-geen}" >&2
    dc ps >&2 || true
    dc logs --tail 60 --no-color >&2 || true
    fout "/gezond bevestigt commit $SHA niet"
fi
echo "$antwoord"

# 6. Opruimen, anders loopt de schijf vol met oude images, bouwcache en releases.
log "opruimen"
find "$BASIS/releases" -mindepth 1 -maxdepth 1 -type d -name '.ontvang.*' -mmin +60 -exec rm -rf {} +
# Releasemappen heten naar hun commit-sha (alleen hex), dus ls is hier veilig.
# shellcheck disable=SC2012
ls -1dt "$BASIS"/releases/*/ | tail -n +6 | while read -r oud; do
    [ "$(readlink -f "$oud")" = "$(readlink -f "$RELEASE")" ] || rm -rf "$oud"
done
docker image prune -f >/dev/null
docker builder prune -f --filter until=168h >/dev/null

log "UITROL OK $SHA"
