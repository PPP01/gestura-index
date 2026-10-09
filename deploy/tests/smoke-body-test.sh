#!/usr/bin/env bash
# Testet die Body-Prüfung des Ping-Checks in deploy/smoke.sh (body_matches und
# die Muster aus deploy/common.sh) gegen echte und gefälschte Antworten.
# Aufruf: deploy/tests/smoke-body-test.sh        Exit 0 = alle Fälle grün.
set -euo pipefail
cd "$(dirname "$0")/../.."
# shellcheck source=deploy/common.sh
source deploy/common.sh

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
fail=0

# expect <accepted|rejected> <Muster> <Beschreibung> <Body per printf-Format>
expect() {
    local want="$1" pattern="$2" label="$3"; shift 3
    printf "$@" > "$tmp/body"
    local got=rejected
    body_matches "$tmp/body" "$pattern" && got=accepted
    if [ "$got" = "$want" ]; then echo "OK    $label ($got)"; else echo "FEHLT $label: erwartet $want, war $got"; fail=1; fi
}

# Echte Antworten des Servers (kompaktes JSON, mit und ohne Zeilenumbruch am Ende)
expect accepted "$PING_OK_PATTERN" "200: ein Feature" '{"status":"ok","features":["sync-meta"]}'
expect accepted "$PING_OK_PATTERN" "200: ein Feature mit Zeilenumbruch" '{"status":"ok","features":["sync-meta"]}\n'
expect accepted "$PING_OK_PATTERN" "200: zwei Features" '{"status":"ok","features":["sync-meta","other-feature"]}'
expect accepted "$PING_OK_PATTERN" "200: leere Feature-Liste" '{"status":"ok","features":[]}'
expect accepted "$PING_MAINTENANCE_PATTERN" "503: Wartung ohne until" '{"error":"maintenance"}'
expect accepted "$PING_MAINTENANCE_PATTERN" "503: Wartung mit until" '{"error":"maintenance","until":"2026-10-09T14:00:00+00:00"}'

# Gefälschte oder falsche Antworten dürfen nie als gesund bzw. als Wartung gelten
expect rejected "$PING_OK_PATTERN" "200: status ok ohne features" '{"status":"ok"}'
expect rejected "$PING_OK_PATTERN" "200: Feature mit Großbuchstaben" '{"status":"ok","features":["Sync"]}'
expect rejected "$PING_OK_PATTERN" "200: leerer Body" ''
expect rejected "$PING_OK_PATTERN" "200: Proxy-Fehlerseite plus gültige Zeile" '<html>Fehler</html>\n{"status":"ok","features":[]}\n'
expect rejected "$PING_OK_PATTERN" "200: gültige Zeile plus Müllzeile" '{"status":"ok","features":[]}\nmüll\n'
expect rejected "$PING_OK_PATTERN" "200: zwei Objekte ohne Zeilenumbruch am Ende" '{"status":"ok","features":[]}\n{"status":"ok","features":[]}'
expect rejected "$PING_MAINTENANCE_PATTERN" "503: unavailable mit maintenance als Text" '{"error":"unavailable","message":"maintenance"}'
expect rejected "$PING_MAINTENANCE_PATTERN" "503: unavailable" '{"error":"unavailable"}'
expect rejected "$PING_MAINTENANCE_PATTERN" "503: unavailable plus Wartungszeile" '{"error":"unavailable"}\n{"error":"maintenance"}\n'

if [ "$fail" -ne 0 ]; then echo "== smoke-body-test: FEHLGESCHLAGEN =="; exit 1; fi
echo "== smoke-body-test: alle Fälle grün =="
