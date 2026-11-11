#!/usr/bin/env bash
# Benoemde onderhoudstaken, gestart via .github/workflows/onderhoud.yml (een keuzelijst,
# nooit een vrij commandoveld) en /usr/local/sbin/poetsploeg-ontvang. Draait vanuit de
# huidige release (/opt/poetsploeg/huidig) als gebruiker uitrol.
#
# Een nieuwe taak toevoegen: hier een case-tak én in de keuzelijst van onderhoud.yml.
set -euo pipefail

TAAK="${1:?gebruik: onderhoud.sh <taak>}"
RELEASE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=scripts/server/gemeen.sh
source "$RELEASE/scripts/server/gemeen.sh"

case "$TAAK" in
    status)
        log "containers"; dc ps
        log "gezond"; gezond || echo "(geen antwoord)"; echo
        log "schijf"; df -h /
        log "docker"; docker system df
        log "releases"; ls -1t "$BASIS/releases"
        ;;
    logs)
        dc logs --tail 300 --no-color --timestamps
        ;;
    herstart)
        dc restart app scheduler web
        dc ps
        ;;
    opruimen)
        docker image prune -af
        docker builder prune -af
        docker system df
        ;;
    *)
        fout "onbekende taak: $TAAK"
        ;;
esac
