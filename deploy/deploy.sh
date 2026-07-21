#!/usr/bin/env bash
# Deployt das Backend auf das Shared-Hosting (Spec: 2026-07-21-deployment-design.md).
# Aufruf aus beliebigem Verzeichnis: deploy/deploy.sh
set -euo pipefail

DEPLOY_HOST="ssh-w00d7b19@85.13.135.147"
DEPLOY_PATH="/www/htdocs/w00d7b19/gestura.eu"
API_URL="https://api.gestura.eu"

cd "$(dirname "$0")/.."   # Repo-Root

echo "== Preflight: Tests müssen grün sein =="
php backend/bin/phpunit

if [ -n "$(git status --porcelain)" ]; then
    echo "WARNUNG – es gibt uncommittete Änderungen; deployt wird der Arbeitsstand."
fi

echo "== Guard: Server-Konfiguration =="
if ssh -o BatchMode=yes "$DEPLOY_HOST" "grep -q __DB_PASSWORT_HIER_EINTRAGEN__ '$DEPLOY_PATH/backend/.env.local'"; then
    echo "FEHLER – in $DEPLOY_PATH/backend/.env.local fehlt noch das DB-Passwort."
    exit 1
fi

echo "== rsync: backend/ und schema/ =="
# Excludes sind bei --delete automatisch vor Löschung geschützt (kein --delete-excluded).
rsync -az --delete \
    --exclude=/vendor/ \
    --exclude=/var/ \
    --exclude=/tests/ \
    --exclude=/phpunit.dist.xml \
    --exclude=/.env.local \
    --exclude='/.env.*.local' \
    --exclude=/public/media/ \
    backend/ "$DEPLOY_HOST:$DEPLOY_PATH/backend/"
rsync -az --delete schema/ "$DEPLOY_HOST:$DEPLOY_PATH/schema/"

echo "== Server: Composer, Migrationen, Cache =="
ssh -o BatchMode=yes "$DEPLOY_HOST" "cd '$DEPLOY_PATH/backend' \
    && php85 /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction \
    && php85 bin/console doctrine:migrations:migrate --no-interaction \
    && php85 bin/console cache:clear"

echo "== Smoke-Check: $API_URL/api/v1/entries =="
body=$(curl -fsS "$API_URL/api/v1/entries")
if ! grep -q '"items"' <<<"$body"; then
    echo "FEHLER – unerwartete Antwort: $body"
    exit 1
fi
echo "Antwort: $body"
echo "== Deploy erfolgreich =="
