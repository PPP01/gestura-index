#!/usr/bin/env bash
# Smoke-Check gegen eine Origin (Default https://gestura.eu). Prüft genau das,
# was der Vertrag mit der Extension und das gemeinsame Docroot verlangen.
# Aufruf: deploy/smoke.sh [origin]      Exit 0 = alles grün.
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=deploy/common.sh
source deploy/common.sh

ORIGIN="${1:-$SITE_ORIGIN}"
ORIGIN="${ORIGIN%/}"
fail=0
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# request <name> <curl-args…>  → setzt STATUS, HEADERS (Datei), BODY (Datei).
# Kein -L: curl folgt nie einer Umleitung, jedes 3xx bleibt als Status stehen.
request() {
    local name="$1"; shift
    STATUS=$(curl -sS --max-time 20 -o "$tmp/$name.body" -D "$tmp/$name.headers" -w '%{http_code}' "$@") || STATUS=000
    HEADERS="$tmp/$name.headers"; BODY="$tmp/$name.body"
}
header() { # header <Name> → Wert (case-insensitiv, ohne CR)
    grep -i "^$1:" "$HEADERS" | head -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'
}
ok()   { echo "OK    $*"; }
bad()  { echo "FEHLT $*"; fail=1; }

echo "== Smoke-Check gegen $ORIGIN =="

request preflight -X OPTIONS \
    -H 'Origin: moz-extension://smoke-check' \
    -H 'Access-Control-Request-Method: POST' \
    -H 'Access-Control-Request-Headers: content-type' \
    "$ORIGIN/api/v1/updates"
if [ "$STATUS" = 204 ] \
   && [ "$(header Access-Control-Allow-Origin)" = '*' ] \
   && header Access-Control-Allow-Methods | grep -q POST \
   && header Access-Control-Allow-Headers | grep -qi content-type; then
    ok "OPTIONS /api/v1/updates: 204 mit offenem CORS"
else
    bad "OPTIONS /api/v1/updates: Status $STATUS, ACAO »$(header Access-Control-Allow-Origin)«"
fi

request updates -X POST -H 'Content-Type: application/json' \
    --data '{"apiLevel":3,"entries":[]}' "$ORIGIN/api/v1/updates"
# apiLevel als Zahl, nicht fest 2: das R3-Paket hebt den Wert auf 3, die Form bleibt.
# [[ =~ ]] statt grep -E: bash ankert ^/$ an den GESAMTEN String, nicht an
# Zeilen – so fallen vor-/nachgestellte Ausgaben (z. B. PHP-Deprecations vor
# oder nach dem JSON) durch, die eine zeilenweise Prüfung übersehen würde.
if [ "$STATUS" = 200 ] && [[ "$(cat "$BODY")" =~ ^\{\"apiLevel\":[0-9]+,\"updates\":\[\]\}$ ]]; then
    ok "POST /api/v1/updates: 200 mit leerer Vertragsantwort ($(cat "$BODY"))"
elif [ "$STATUS" = 200 ] && header Content-Type | grep -qi text/html; then
    bad "POST /api/v1/updates liefert HTML – das KAS-Docroot von ${ORIGIN#https://} zeigt noch nicht auf current/backend/public"
else
    bad "POST /api/v1/updates: Status $STATUS, Body: $(head -c 200 "$BODY")"
fi

request entries "$ORIGIN/api/v1/entries"
if [ "$STATUS" = 200 ] && grep -q '"items"' "$BODY"; then
    ok "GET /api/v1/entries: JSON mit items"
else
    bad "GET /api/v1/entries: Status $STATUS, Content-Type »$(header Content-Type)«"
fi

request de "$ORIGIN/de"
if [ "$STATUS" = 200 ] && header Content-Type | grep -qi text/html; then
    ok "GET /de: 200 text/html (Frontend aus dem gemeinsamen Docroot)"
else
    bad "GET /de: Status $STATUS, Content-Type »$(header Content-Type)«"
fi

request vergleich "$ORIGIN/de/vergleich"
if { [ "$STATUS" = 200 ] || [ "$STATUS" = 404 ]; } && header Content-Type | grep -qi text/html; then
    ok "GET /de/vergleich: $STATUS text/html (Marketing-Interceptor erreicht Symfony)"
else
    bad "GET /de/vergleich: Status $STATUS, Content-Type »$(header Content-Type)« – erwartet 200/404 als HTML"
fi

if [ "$fail" -ne 0 ]; then
    echo "== Smoke-Check FEHLGESCHLAGEN =="; exit 1
fi
echo "== Smoke-Check erfolgreich =="
