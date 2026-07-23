#!/usr/bin/env bash
#
# Startet die komplette Anwendung für die lokale Entwicklung mit EINEM Befehl:
#
#     ./dev.sh
#
# - Backend:  Symfony über den PHP-Built-in-Server (APP_ENV=dev)
# - Frontend: Vite-Dev-Server auf Port 5173; proxyt /api ans Backend
#             (frontend/vite.config.ts liest denselben Port aus $BACKEND_PORT)
#
# Der Backend-Port wird automatisch bestimmt: ab 8000 aufwärts der erste freie
# (in manchen Umgebungen ist 8000 belegt). Er wird an Backend UND Vite-Proxy
# durchgereicht – eine Stellschraube, immer konsistent.
#
# Strg+C beendet BEIDE Server. Danach http://localhost:5173 öffnen.
#
# Überschreibbar per Umgebungsvariable:
#   BACKEND_PORT  – festen Port erzwingen (statt Auto-Suche ab 8000)
#   PHP_BIN       – PHP-CLI (Default `php`), z. B. `PHP_BIN=php85 ./dev.sh`
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"

port_in_use() {
	ss -ltn 2>/dev/null | grep -qE "[:.]$1[[:space:]]"
}

# Freien Backend-Port ab 8000 suchen (oder den erzwungenen BACKEND_PORT nehmen).
if [ -z "${BACKEND_PORT:-}" ]; then
	BACKEND_PORT=8000
	while port_in_use "$BACKEND_PORT"; do
		BACKEND_PORT=$((BACKEND_PORT + 1))
	done
elif port_in_use "$BACKEND_PORT"; then
	echo "✗ Erzwungener BACKEND_PORT=$BACKEND_PORT ist belegt." >&2
	exit 1
fi
# Vite liest denselben Port (vite.config.ts → server.proxy).
export BACKEND_PORT

# Frontend-Abhängigkeiten einmalig installieren, falls noch nicht vorhanden.
if [ ! -d "$ROOT/frontend/node_modules" ]; then
	echo "→ frontend/node_modules fehlt – installiere einmalig …"
	npm --prefix "$ROOT/frontend" install
fi

# Job-Control aktivieren: jeder mit `&` gestartete Job wird eigener
# Prozessgruppen-Leader. So lässt sich beim Beenden gezielt NUR die jeweilige
# Kind-Prozessgruppe (php bzw. npm+vite und deren Kinder) killen – niemals die
# aufrufende Shell/IDE-Task, wie es das frühere `kill 0` (ganze Gruppe) tat.
set -m

BACKEND_PID=""
FRONTEND_PID=""

cleanup() {
	# Traps zuerst entfernen, sonst löst das an die Kinder gesendete Signal beim
	# eigenen Exit den Trap erneut aus (Endlosschleife).
	trap - INT TERM EXIT
	for pid in "$BACKEND_PID" "$FRONTEND_PID"; do
		# Negatives PID = ganze Prozessgruppe des Kindes (inkl. Enkel wie vite),
		# ohne die Gruppe dieses Skripts zu treffen.
		[ -n "$pid" ] && kill -- -"$pid" 2>/dev/null || true
	done
}
trap cleanup INT TERM EXIT

echo "▶ Backend   → http://localhost:${BACKEND_PORT}  (APP_ENV=dev, ${PHP_BIN})"
(
	cd "$ROOT/backend"
	APP_ENV=dev "$PHP_BIN" -S "localhost:${BACKEND_PORT}" -t public public/index.php
) &
BACKEND_PID=$!

echo "▶ Frontend  → http://localhost:5173"
(
	cd "$ROOT/frontend"
	npm run dev
) &
FRONTEND_PID=$!

# Fail-fast: sobald EIN Server endet (Absturz, belegter Port, Strg+C), bringt der
# EXIT-Trap den anderen mit runter – kein verwaistes Frontend mit totem /api.
wait -n
