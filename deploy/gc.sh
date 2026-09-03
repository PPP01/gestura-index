#!/usr/bin/env bash
# Garbage Collection alter Releases (Spec 2026-09-03, Abschnitt 4.7).
# Läuft lokal gegen --root (Tests) oder auf dem Server per »bash -s«.
#
# Regeln (Sortierung nach deployed_at_epoch aus der RELEASE-Datei):
#   1. current wird nie gelöscht.
#   2. Unvollständige Releases (ohne RELEASE) werden gelöscht – außer current.
#   3. Von den vollständigen Releases außer current bleiben die KEEP jüngsten.
#   4. Ist darunter keines mit deployed_at vor dem heutigen Tag (00:00 Uhr),
#      bleibt zusätzlich das jüngste Release, das älter als heute ist.
#   5. Alles Übrige wird gelöscht.
#
# Aufruf: gc.sh --root <deploy-path> [--dry-run] [--keep N] [--today-epoch E]
set -euo pipefail

ROOT=""; DRY=0; KEEP=5; TODAY=""
while [ $# -gt 0 ]; do
    case "$1" in
        --root) ROOT="$2"; shift 2 ;;
        --dry-run) DRY=1; shift ;;
        --keep) KEEP="$2"; shift 2 ;;
        --today-epoch) TODAY="$2"; shift 2 ;;
        *) echo "Unbekannte Option: $1" >&2; exit 2 ;;
    esac
done
[ -n "$ROOT" ] || { echo "--root fehlt" >&2; exit 2; }
[ -d "$ROOT/releases" ] || { echo "Kein releases/-Verzeichnis unter $ROOT" >&2; exit 2; }
[[ "$KEEP" =~ ^[0-9]+$ ]] || { echo "--keep muss eine positive ganze Zahl sein" >&2; exit 2; }
[ -n "$TODAY" ] || TODAY=$(date -d "$(date +%F) 00:00:00" +%s)
[[ "$TODAY" =~ ^[0-9]+$ ]] || { echo "--today-epoch muss eine positive ganze Zahl sein" >&2; exit 2; }

current=$(readlink -f "$ROOT/current" 2>/dev/null || true)

remove() { # remove <verzeichnis> <grund>
    if [ "$DRY" -eq 1 ]; then echo "lösche  $(basename "$1")  ($2) [dry-run]"; else rm -rf "$1"; echo "lösche  $(basename "$1")  ($2)"; fi
}

# Kandidaten einsammeln: "epoch<TAB>pfad", nur vollständige Releases außer current.
candidates=()
for dir in "$ROOT"/releases/*/; do
    dir="${dir%/}"
    [ -d "$dir" ] || continue
    if [ "$(readlink -f "$dir")" = "$current" ]; then
        echo "behalte $(basename "$dir")  (current)"; continue
    fi
    if [ ! -f "$dir/RELEASE" ]; then
        remove "$dir" "unvollständig, keine RELEASE-Datei"; continue
    fi
    epoch=$(sed -n 's/^deployed_at_epoch=//p' "$dir/RELEASE" | head -1)
    [[ "$epoch" =~ ^[0-9]+$ ]] || { remove "$dir" "RELEASE ohne gültiges deployed_at_epoch"; continue; }
    candidates+=("$epoch|$dir")
done

# Nach Epoch absteigend sortieren; die ersten KEEP bleiben.
mapfile -t sorted < <(printf '%s\n' "${candidates[@]-}" | grep -v '^$' | sort -t '|' -k1,1nr)
keep=(); rest=()
for i in "${!sorted[@]}"; do
    if [ "$i" -lt "$KEEP" ]; then keep+=("${sorted[$i]}"); else rest+=("${sorted[$i]}"); fi
done

for line in "${keep[@]-}"; do
    [ -n "$line" ] && echo "behalte $(basename "${line#*|}")  (unter den $KEEP jüngsten)"
done

# Regel 4: Rückfallpunkt von gestern oder früher sichern.
has_older=0
for line in "${keep[@]-}"; do
    if [ -n "$line" ] && [ "${line%%|*}" -lt "$TODAY" ]; then has_older=1; fi
done
if [ "$has_older" -eq 0 ]; then
    for i in "${!rest[@]}"; do
        line="${rest[$i]}"
        if [ "${line%%|*}" -lt "$TODAY" ]; then
            echo "behalte $(basename "${line#*|}")  (jüngster Stand vor heute – Rückfallpunkt)"
            unset 'rest[i]'
            break
        fi
    done
fi

for line in "${rest[@]-}"; do
    [ -n "$line" ] || continue
    remove "${line#*|}" "älter als die $KEEP jüngsten, kein benötigter Rückfallpunkt"
done
