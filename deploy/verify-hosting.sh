#!/usr/bin/env bash
# Prüft die Hosting-Umgebung für den gestura-index (Spec: 2026-07-21-deployment-design.md).
# Exit 0 = alles bereit. Wiederholbar; verändert nichts auf dem Server.
set -euo pipefail

DEPLOY_HOST="ssh-w00d7b19@85.13.135.147"
DEPLOY_PATH="/www/htdocs/w00d7b19/gestura.eu"

echo "== Hosting-Verifikation auf $DEPLOY_HOST =="

ssh -o BatchMode=yes "$DEPLOY_HOST" DEPLOY_PATH="$DEPLOY_PATH" 'bash -s' <<'REMOTE'
set -euo pipefail
fail=0
check() { # check <Name> <Befehl…>
    local name="$1"; shift
    if out=$("$@" 2>&1); then
        echo "OK   $name: ${out%%$'\n'*}"
    else
        echo "FEHLT $name"; fail=1
    fi
}

check "php85"    php85 -r 'echo PHP_VERSION;'
check "composer" php85 /usr/bin/composer --version --no-ansi
check "rsync"    which rsync

for mod in gd sodium intl pdo_mysql; do
    if php85 -m | grep -qx "$mod"; then echo "OK   Modul $mod"; else echo "FEHLT Modul $mod"; fail=1; fi
done
check "imagewebp"  php85 -r 'exit(function_exists("imagewebp") ? 0 : 1);'
check "Argon2id"   php85 -r 'exit(defined("PASSWORD_ARGON2ID") ? 0 : 1);'

envfile="$DEPLOY_PATH/backend/.env.local"
if [ ! -f "$envfile" ]; then
    echo "FEHLT $envfile"; fail=1
elif grep -q "__DB_PASSWORT_HIER_EINTRAGEN__" "$envfile"; then
    echo "WARTET .env.local: DB-Passwort noch nicht eingetragen – DB-Prüfung übersprungen"
else
    php85 -r '
        $env = file_get_contents($argv[1]);
        if (!preg_match("/^DATABASE_URL=\"?([^\"\n]+)/m", $env, $m)) { fwrite(STDERR, "DATABASE_URL fehlt\n"); exit(1); }
        $p = parse_url($m[1]);
        $pdo = new PDO("mysql:host={$p["host"]};dbname=" . ltrim($p["path"], "/"), $p["user"], urldecode($p["pass"] ?? ""));
        echo "OK   DB-Verbindung – Server-Version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
        echo "     (serverVersion in DATABASE_URL entsprechend setzen, falls abweichend)\n";
    ' "$envfile" || fail=1
fi

exit "$fail"
REMOTE

echo "== Verifikation erfolgreich =="
