#!/usr/bin/env bash
# Testet die Aufbewahrungsregeln von deploy/gc.sh gegen synthetische Releases.
# Aufruf: deploy/tests/gc-test.sh        Exit 0 = alle Fälle grün.
set -euo pipefail
cd "$(dirname "$0")/../.."
GC=deploy/gc.sh
TODAY=1700000000                       # fixer »Tagesbeginn« für deterministische Fälle
H=3600
fail=0

# release <root> <name> <epoch>  – vollständiges Release mit RELEASE-Datei
release() { mkdir -p "$1/releases/$2"; printf 'tag=%s\ndeployed_at_epoch=%s\n' "$2" "$3" > "$1/releases/$2/RELEASE"; }
# incomplete <root> <name>       – Verzeichnis ohne RELEASE-Datei
incomplete() { mkdir -p "$1/releases/$2"; }
current() { ln -sfn "releases/$2" "$1/current"; }
# expect <root> <name…>          – genau diese Releases dürfen übrig sein
expect() {
    local root="$1"; shift
    local want; want=$(printf '%s\n' "$@" | sort)
    local have; have=$(ls "$root/releases" | sort)
    if [ "$want" = "$have" ]; then echo "OK    $CASE"; else echo "FEHLT $CASE"; echo "  erwartet: $(tr '\n' ' ' <<<"$want")"; echo "  vorhanden: $(tr '\n' ' ' <<<"$have")"; fail=1; fi
}
newroot() { local r; r=$(mktemp -d); mkdir -p "$r/releases"; echo "$r"; }

CASE="1: sieben heutige Releases – fünf jüngste bleiben, kein älteres vorhanden"
r=$(newroot); current "$r" v8
release "$r" v8 $((TODAY+8*H))
for i in 1 2 3 4 5 6 7; do release "$r" v$i $((TODAY+i*H)); done
"$GC" --root "$r" --today-epoch "$TODAY" >/dev/null
expect "$r" v8 v7 v6 v5 v4 v3

CASE="2: sechs heutige + zwei gestrige – fünf heutige plus jüngstes gestriges"
r=$(newroot); current "$r" v9
release "$r" v9 $((TODAY+9*H))
for i in 1 2 3 4 5 6; do release "$r" v$i $((TODAY+i*H)); done
release "$r" g1 $((TODAY-30*H)); release "$r" g2 $((TODAY-2*H))
"$GC" --root "$r" --today-epoch "$TODAY" >/dev/null
expect "$r" v9 v6 v5 v4 v3 v2 g2

CASE="3: älteres Release bereits unter den fünf – kein zusätzliches"
r=$(newroot); current "$r" v3
release "$r" v3 $((TODAY+3*H)); release "$r" v2 $((TODAY+2*H)); release "$r" v1 $((TODAY+1*H))
release "$r" g1 $((TODAY-5*H)); release "$r" g2 $((TODAY-50*H)); release "$r" g3 $((TODAY-70*H))
release "$r" g4 $((TODAY-90*H)); release "$r" g5 $((TODAY-99*H))
"$GC" --root "$r" --today-epoch "$TODAY" >/dev/null
# Kandidaten außer current: v2 v1 g1 g2 g3 | g4 g5 – g1 ist bereits älter als heute, also kein Zusatz.
expect "$r" v3 v2 v1 g1 g2 g3

CASE="4: unvollständige Releases verschwinden, current überlebt auch ohne RELEASE"
r=$(newroot); current "$r" cur
incomplete "$r" cur; incomplete "$r" halb; release "$r" v1 $((TODAY+H))
"$GC" --root "$r" --today-epoch "$TODAY" >/dev/null
expect "$r" cur v1

CASE="5: --dry-run löscht nichts"
r=$(newroot); current "$r" v8
release "$r" v8 $((TODAY+8*H))
for i in 1 2 3 4 5 6 7; do release "$r" v$i $((TODAY+i*H)); done
incomplete "$r" halb
out=$("$GC" --root "$r" --today-epoch "$TODAY" --dry-run)
echo "$out" | grep -q 'lösche' || { echo "FEHLT $CASE: dry-run meldet keine Löschkandidaten"; fail=1; }
expect "$r" v8 v7 v6 v5 v4 v3 v2 v1 halb

CASE="6: Rollback-Situation – current ist alt, neuere Releases unterliegen den Regeln"
r=$(newroot); current "$r" v1
release "$r" v1 $((TODAY-40*H))
for i in 2 3 4 5 6 7 8; do release "$r" v$i $((TODAY+i*H)); done
"$GC" --root "$r" --today-epoch "$TODAY" >/dev/null
expect "$r" v1 v8 v7 v6 v5 v4

[ "$fail" -eq 0 ] && echo "== gc-test: alle Fälle grün ==" || { echo "== gc-test FEHLGESCHLAGEN =="; exit 1; }
