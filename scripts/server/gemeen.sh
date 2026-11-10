# shellcheck shell=bash
# Gedeelde functies voor uitrol.sh en onderhoud.sh. Wordt met `source` geladen, niet los gedraaid.
# RELEASE moet gezet zijn: de map van de release waar dit script uit komt.

BASIS=/opt/poetsploeg
ENV_BESTAND="$BASIS/.env"

log() { echo "==> $(date '+%F %T') $*"; }
fout() { echo "FOUT: $*" >&2; exit 1; }

# Vaste projectnaam: de volumes (database!) blijven zo dezelfde, welke release er ook draait.
dc() {
    docker compose -p poetsploeg -f "$RELEASE/infra/compose.yaml" --env-file "$ENV_BESTAND" "$@"
}

# Waarde uit .env lezen zonder het bestand te sourcen (het is geen shellscript).
env_waarde() {
    local waarde
    waarde=$(grep -E "^$1=" "$ENV_BESTAND" | tail -n 1 | cut -d= -f2-) || true
    waarde="${waarde%\"}"; waarde="${waarde#\"}"
    printf '%s' "$waarde"
}

# Haalt /gezond op via Caddy op deze machine (los van DNS, wel met het echte certificaat).
gezond() {
    local domein
    domein=$(env_waarde POETSPLOEG_DOMEIN)
    curl -fsS --max-time 5 --resolve "$domein:443:127.0.0.1" "https://$domein/gezond"
}
