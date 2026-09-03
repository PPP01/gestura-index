#!/usr/bin/env bash
# Schaltet current auf ein früheres Release zurück (Spec 2026-09-03, Abschnitt 4.5).
# Aufruf: deploy/rollback.sh [vX.Y.Z]
#   ohne Argument: das jüngste vollständige Release, das VOR dem aktuellen
#   deployt wurde (deployed_at_epoch kleiner als das von current).
# Migrationen bleiben vorwärtsgerichtet – ein Schema-Rollback ist manuell:
#   php85 bin/console doctrine:migrations:migrate <version>  (im Zielrelease)
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=deploy/common.sh
source deploy/common.sh

TARGET="${1:-}"

step "Zielrelease bestimmen"
TARGET=$(remote RELEASES="$RELEASES_DIR" CURRENT="$CURRENT_LINK" TARGET="$TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
current=$(readlink -f "$CURRENT" 2>/dev/null || true)
[ -n "$current" ] || { echo "FEHLER – current existiert nicht" >&2; exit 1; }
epoch_of() { sed -n 's/^deployed_at_epoch=//p' "$1/RELEASE" 2>/dev/null | head -1; }
if [ -n "$TARGET" ]; then
    dir="$RELEASES/$TARGET"
    [ -d "$dir" ] || { echo "FEHLER – Release $TARGET existiert nicht" >&2; exit 1; }
    [ -f "$dir/RELEASE" ] || { echo "FEHLER – Release $TARGET ist unvollständig (keine RELEASE-Datei)" >&2; exit 1; }
    [ "$(readlink -f "$dir")" != "$current" ] || { echo "FEHLER – $TARGET ist bereits current" >&2; exit 1; }
    echo "$TARGET"; exit 0
fi
cur_epoch=$(epoch_of "$current"); [[ "$cur_epoch" =~ ^[0-9]+$ ]] || cur_epoch=9999999999
best=""; best_epoch=0
for dir in "$RELEASES"/*/; do
    dir="${dir%/}"
    [ "$(readlink -f "$dir")" = "$current" ] && continue
    e=$(epoch_of "$dir"); [[ "$e" =~ ^[0-9]+$ ]] || continue
    if [ "$e" -lt "$cur_epoch" ] && [ "$e" -gt "$best_epoch" ]; then best="$(basename "$dir")"; best_epoch="$e"; fi
done
[ -n "$best" ] || { echo "FEHLER – kein vollständiges Release vor current gefunden" >&2; exit 1; }
echo "$best"
REMOTE
)
echo "Zurück auf: $TARGET"

step "current atomar auf $TARGET setzen"
# Erzwingt »ln -sfn« statt »ln -s«: Ein früherer, mitten im Tausch
# abgebrochener Lauf kann current.tmp als stehengebliebenen Symlink
# hinterlassen – ein einfaches »ln -s« würde dann still IN das alte Release
# hinein verlinken, statt current.tmp neu zu setzen, und current landete
# unbemerkt auf einem veralteten Release.
remote "ln -sfn 'releases/$TARGET' '$CURRENT_LINK.tmp' && mv -T '$CURRENT_LINK.tmp' '$CURRENT_LINK' && php85 '$RELEASES_DIR/$TARGET/backend/bin/console' cache:clear"

step "Smoke-Check"
deploy/smoke.sh "$SITE_ORIGIN" || die "Smoke-Check nach Rollback fehlgeschlagen – current zeigt auf $TARGET"

echo; echo "== Rollback auf $TARGET erfolgreich =="
