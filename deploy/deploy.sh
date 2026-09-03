#!/usr/bin/env bash
# Deployt einen annotierten Git-Tag als Release auf das Shared-Hosting
# (Spec: docs/superpowers/specs/2026-09-03-updates-endpoint-und-versioniertes-deployment-design.md, Abschnitt 4.4).
#
# Aufruf aus beliebigem Verzeichnis: deploy/deploy.sh vX.Y.Z
#
# Ablauf: Guards → Preflight im Worktree des Tags (PHPUnit, Frontend-Build)
# → Release hochladen → shared/ verknüpfen → Composer, Migrationen, Cache
# → RELEASE schreiben → current atomar tauschen → smoke.sh → gc.sh.
# Schlägt ein Schritt VOR dem Tausch fehl, bleibt current unberührt und das
# unvollständige Release (ohne RELEASE-Datei) wird beim nächsten Lauf ersetzt.
# Schlägt smoke.sh NACH dem Tausch fehl, bricht das Skript mit Hinweis auf
# deploy/rollback.sh ab und tauscht nicht selbst zurück (die Ursache kann
# außerhalb des Releases liegen, etwa ein noch nicht umgestelltes KAS-Docroot).
set -euo pipefail
cd "$(dirname "$0")/.."   # Repo-Root
# shellcheck source=deploy/common.sh
source deploy/common.sh

TAG="${1:-}"
[ -n "$TAG" ] || die "Aufruf: deploy/deploy.sh vX.Y.Z"
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Tag »$TAG« entspricht nicht dem Muster vX.Y.Z"

step "Guards: Tag $TAG"
git rev-parse -q --verify "refs/tags/$TAG" >/dev/null || die "Tag $TAG existiert nicht"
[ "$(git cat-file -t "$TAG")" = "tag" ] || die "Tag $TAG ist nicht annotiert – kein Deploy (git tag -a $TAG -m '…')"
COMMIT=$(git rev-list -n 1 "$TAG")
git fetch -q origin main
git merge-base --is-ancestor "$COMMIT" origin/main || die "Commit $COMMIT von $TAG ist nicht von origin/main erreichbar"
[ -z "$(git status --porcelain)" ] || die "Arbeitsbaum nicht sauber – deployt wird zwar der Tag, aber uncommittete Deploy-Skripte wären trügerisch"
MESSAGE=$(git tag -l --format='%(contents)' "$TAG")
echo "Commit: $COMMIT"; echo "Tag-Nachricht: ${MESSAGE%%$'\n'*}"

step "Guard: Server-Konfiguration"
if remote "test -f '$SHARED_DIR/.env.local' && grep -q __DB_PASSWORT_HIER_EINTRAGEN__ '$SHARED_DIR/.env.local'"; then
    die "in $SHARED_DIR/.env.local fehlt noch das DB-Passwort"
fi
if remote "test -f '$RELEASES_DIR/$TAG/RELEASE'"; then
    die "Release $TAG liegt bereits vollständig auf dem Server – Tags sind unveränderlich; für einen erneuten Deploy neuen Tag setzen"
fi

step "Preflight: Worktree des Tags, Tests, Frontend-Build"
WORK=$(mktemp -d)
cleanup() { git worktree remove --force "$WORK" 2>/dev/null || rm -rf "$WORK"; }
trap cleanup EXIT
git worktree add --detach -q "$WORK" "$TAG"
# Die Test-DB-Konfiguration ist gitignored und muss dem Worktree mitgegeben werden.
[ -f backend/.env.test.local ] && cp backend/.env.test.local "$WORK/backend/.env.test.local"
composer --working-dir="$WORK/backend" install --no-interaction --quiet
php "$WORK/backend/bin/phpunit"
npm --prefix "$WORK/frontend" ci --no-audit --no-fund --silent
npm --prefix "$WORK/frontend" run build
# Der Build hat keine eigene .htaccess mehr (frontend/static/.htaccess wurde
# entfernt); defensiv trotzdem entfernen, damit die zusammengeführte
# backend/public/.htaccess nie überschrieben wird.
rm -f "$WORK/frontend/build/.htaccess"
cp -R "$WORK/frontend/build/." "$WORK/backend/public/"
test -f "$WORK/backend/public/200.html" || die "Frontend-Build nicht in backend/public gelandet"
grep -q 'RewriteRule \^api' "$WORK/backend/public/.htaccess" || die "backend/public/.htaccess ist nicht die zusammengeführte Fassung"

step "Release $TAG hochladen (rsync, --link-dest auf das aktuelle Release)"
CURRENT_TARGET=$(remote "readlink -f '$CURRENT_LINK' 2>/dev/null || true")
remote "rm -rf '$RELEASES_DIR/$TAG' && mkdir -p '$RELEASES_DIR/$TAG'"
LINK_BACKEND=(); LINK_SCHEMA=()
if [ -n "$CURRENT_TARGET" ]; then
    LINK_BACKEND=(--link-dest="$CURRENT_TARGET/backend"); LINK_SCHEMA=(--link-dest="$CURRENT_TARGET/schema")
    echo "Aktuelles Release: $CURRENT_TARGET"
fi
rsync -az "${LINK_BACKEND[@]}" \
    --exclude=/vendor/ --exclude=/var/ --exclude=/tests/ --exclude=/phpunit.dist.xml \
    --exclude=/.phpunit.cache/ --exclude=/.env.local --exclude='/.env.*.local' --exclude=/public/media/ \
    "$WORK/backend/" "$DEPLOY_HOST:$RELEASES_DIR/$TAG/backend/"
rsync -az "${LINK_SCHEMA[@]}" "$WORK/schema/" "$DEPLOY_HOST:$RELEASES_DIR/$TAG/schema/"

step "Server: shared/ verknüpfen, Composer, Migrationen, Cache"
remote DEPLOY_PATH="$DEPLOY_PATH" RELEASE="$RELEASES_DIR/$TAG" SHARED="$SHARED_DIR" CURRENT_TARGET="$CURRENT_TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
# Erster Lauf: shared/ aus dem Legacy-Layout (backend/ direkt unter DEPLOY_PATH) befüllen, danach nie mehr anfassen.
if [ ! -d "$SHARED" ]; then
    mkdir -p "$SHARED"
    legacy="$DEPLOY_PATH/backend"
    [ -f "$legacy/.env.local" ] && cp -a "$legacy/.env.local" "$SHARED/.env.local"
    [ -d "$legacy/public/media" ] && cp -a "$legacy/public/media" "$SHARED/media"
    [ -d "$legacy/var/log" ] && cp -a "$legacy/var/log" "$SHARED/log"
    echo "shared/ neu angelegt und aus $legacy befüllt"
fi
mkdir -p "$SHARED/media" "$SHARED/log"
[ -f "$SHARED/.env.local" ] || { echo "FEHLER – $SHARED/.env.local fehlt (Secrets liegen nie im Repo)" >&2; exit 1; }

ln -sfn "$SHARED/.env.local" "$RELEASE/backend/.env.local"
rm -rf "$RELEASE/backend/public/media"; ln -sfn "$SHARED/media" "$RELEASE/backend/public/media"
mkdir -p "$RELEASE/backend/var"; rm -rf "$RELEASE/backend/var/log"; ln -sfn "$SHARED/log" "$RELEASE/backend/var/log"

# vendor/ als echte Kopie übernehmen (keine Hardlinks: Composer schreibt
# vendor/composer/* in place und würde das alte Release mitverändern).
if [ -n "$CURRENT_TARGET" ] && [ -d "$CURRENT_TARGET/backend/vendor" ]; then
    cp -a "$CURRENT_TARGET/backend/vendor" "$RELEASE/backend/vendor"
fi
cd "$RELEASE/backend"
php85 /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction
php85 bin/console doctrine:migrations:migrate --no-interaction
php85 bin/console cache:clear
REMOTE

step "RELEASE schreiben und current atomar tauschen"
RELEASE_FILE="$WORK/RELEASE"
{
    echo "tag=$TAG"
    echo "commit=$COMMIT"
    echo "deployed_at=$(date -Iseconds)"
    echo "deployed_at_epoch=$(date +%s)"
    echo "message=${MESSAGE%%$'\n'*}"
    echo
    echo "$MESSAGE"
} > "$RELEASE_FILE"
rsync -az "$RELEASE_FILE" "$DEPLOY_HOST:$RELEASES_DIR/$TAG/RELEASE"
remote "ln -s 'releases/$TAG' '$CURRENT_LINK.tmp' && mv -T '$CURRENT_LINK.tmp' '$CURRENT_LINK'"
echo "current -> releases/$TAG"

step "Smoke-Check"
if ! deploy/smoke.sh "$SITE_ORIGIN"; then
    die "Smoke-Check fehlgeschlagen. current zeigt auf $TAG. Zurück mit: deploy/rollback.sh"
fi

step "Garbage Collection"
remote 'bash -s' -- --root "$DEPLOY_PATH" < deploy/gc.sh

echo; echo "== Deploy $TAG erfolgreich =="
