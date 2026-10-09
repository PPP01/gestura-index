#!/usr/bin/env bash
# Gemeinsame Konstanten und Helfer der Deploy-Skripte. Wird per »source« geladen.
# Layout auf dem Server (Spec 2026-09-03, Abschnitt 4.3):
#   $DEPLOY_PATH/releases/<tag>/{backend,schema,RELEASE}
#   $DEPLOY_PATH/shared/{.env.local,media,log,state}
#   $DEPLOY_PATH/current -> releases/<tag>
# Docroot beider Domains: $DEPLOY_PATH/current/backend/public

DEPLOY_HOST="ssh-w00d7b19@85.13.135.147"
DEPLOY_PATH="/www/htdocs/w00d7b19/gestura.eu"
RELEASES_DIR="$DEPLOY_PATH/releases"
SHARED_DIR="$DEPLOY_PATH/shared"
CURRENT_LINK="$DEPLOY_PATH/current"
SITE_ORIGIN="https://gestura.eu"

die()  { echo "FEHLER – $*" >&2; exit 1; }
step() { echo; echo "== $* =="; }
# Führt einen Befehl auf dem Server aus. Bei mehrzeiligen Skripten:
#   remote 'bash -s' <<'REMOTE' … REMOTE   (Variablen vorher als VAR=… voranstellen)
remote() { ssh -o BatchMode=yes "$DEPLOY_HOST" "$@"; }

# Erlaubte Bodies von GET /api/v1/sync/ping (smoke.sh, deploy/tests/smoke-body-test.sh).
# JsonResponse liefert kompaktes JSON ohne Whitespace; die Muster gelten für
# den GANZEN Body, nicht für einzelne Zeilen (siehe body_matches).
PING_OK_PATTERN='^\{"status":"ok","features":\[("[a-z-]+"(,"[a-z-]+")*)?]\}$'
PING_MAINTENANCE_PATTERN='^\{"error":"maintenance"(,"until":"[^"]+")?\}$'

# body_matches <datei> <ERE> – wahr, wenn der Body genau EINE Zeile hat und sie
# dem Muster entspricht. Ein nacktes »grep -Eq« prüft zeilenweise: eine passende
# Zeile in einer mehrzeiligen Antwort (Proxy-Fehlerseite, zwei JSON-Objekte)
# würde sonst genügen. »grep -c ''« zählt auch eine letzte Zeile ohne Zeilenumbruch.
body_matches() { [ "$(grep -c '' "$1")" -eq 1 ] && grep -Eq "$2" "$1"; }

# Erlaubte Release-Tags: vX.Y.Z. In deploy.sh und rollback.sh zugleich der
# Injection-Guard, weil der Tag unquotiert in der ssh-Kommandozeile landet.
# In [[ … =~ $TAG_PATTERN ]] UNQUOTIERT verwenden, sonst ist es ein Literal.
TAG_PATTERN='^v[0-9]+\.[0-9]+\.[0-9]+$'

# Setzt current atomar auf releases/<tag>: Link unter Tempnamen anlegen, dann
# per mv -T über den alten schieben. »ln -sfn« statt »ln -s«: Ein früherer,
# mitten im Tausch abgebrochener Lauf kann current.tmp als Symlink hinterlassen;
# ein einfaches »ln -s« würde dann still IN das alte Release hinein verlinken,
# statt current.tmp neu zu setzen, und current landete unbemerkt auf einem
# veralteten Release.
swap_current() { remote "ln -sfn 'releases/$1' '$CURRENT_LINK.tmp' && mv -T '$CURRENT_LINK.tmp' '$CURRENT_LINK'"; }
