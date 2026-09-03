#!/usr/bin/env bash
# Gemeinsame Konstanten und Helfer der Deploy-Skripte. Wird per »source« geladen.
# Layout auf dem Server (Spec 2026-09-03, Abschnitt 4.3):
#   $DEPLOY_PATH/releases/<tag>/{backend,schema,RELEASE}
#   $DEPLOY_PATH/shared/{.env.local,media,log}
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
