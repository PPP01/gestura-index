# Deployment (Sub-Projekt 2) — Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Das Backend reproduzierbar per `deploy/deploy.sh` auf das Shared-Hosting deployen; Abnahme ist die leere Entries-Liste über `https://api.gestura.eu/api/v1/entries`.

**Architecture:** rsync-Push von lokal + serverseitiges `php85 composer install` + Migrationen + Smoke-Check. Kein CI, kein Releases-Mechanismus (Spec). Zwei Bash-Skripte in `deploy/`, eine Code-Änderung (`.htaccess` für Apache).

**Tech Stack:** Bash (`set -euo pipefail`), rsync, ssh, curl; Server: ALL-INKL (`ssh-w00d7b19@85.13.135.147`), `php85` 8.5.3, Composer 2.9.8, MariaDB/MySQL `d047b128@localhost`.

**Spec:** `docs/superpowers/specs/2026-07-21-deployment-design.md` — bei Detailfragen gilt der Spec.

## Global Constraints

- Zielpfad auf dem Server: `DEPLOY_PATH=/www/htdocs/w00d7b19/gestura.eu`; Host: `DEPLOY_HOST=ssh-w00d7b19@85.13.135.147`; API-Basis: `https://api.gestura.eu`.
- Die Server-Dateien `backend/.env.local` (chmod 600, enthält APP_SECRET + DATABASE_URL) und `backend/public/media/` dürfen von keinem Deploy überschrieben oder gelöscht werden.
- Auf dem Server IMMER `php85` verwenden — auch Composer als `php85 /usr/bin/composer …` aufrufen (das nackte `composer` nutzt sonst die Default-PHP-CLI des Hostings).
- Skripte: `#!/usr/bin/env bash`, `set -euo pipefail`, keine Secrets im Skript, deutsche Meldungen mit Halbgeviertstrich –.
- Manuelle KAS-Voraussetzungen für Task 4 (macht der Nutzer): Subdomain api.gestura.eu → `…/gestura.eu/backend/public`, gestura.eu+www → `…/gestura.eu/frontend`, HTTPS aktiv, DB-Passwort in der Server-`.env.local` eingetragen.
- Commits: Deutsch, Imperativ, Subject ≤50 Zeichen, Body mit Warum, `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`; vor jedem Commit `php backend/bin/phpunit; echo $?` → OK + Exit-Code 0.

## Datei-Struktur

```text
backend/public/.htaccess     ← Task 1 (Apache-Routing inkl. Authorization-Header)
deploy/verify-hosting.sh     ← Task 2 (wiederholbare Hosting-Prüfung per SSH)
deploy/deploy.sh             ← Task 3 (das eigentliche Deployment)
deploy/README.md             ← Task 3 (Ablauf, manuelle Schritte, Rollback)
```

---

### Task 1: Apache-Routing (.htaccess)

**Files:**
- Modify: `backend/composer.json` (via composer require)
- Create (falls Rezept blockiert): `backend/public/.htaccess`

**Interfaces:**
- Produces: `backend/public/.htaccess` mit Rewrite auf `index.php` **und** Authorization-Header-Durchreichung (Bearer-Tokens!). Wird von Task 3 mitge-rsynct.

- [ ] **Step 1: apache-pack anfordern**

```bash
composer --working-dir=backend require symfony/apache-pack
```

Hinweis: `symfony/apache-pack` ist ein **contrib**-Rezept; das Projekt hat `allow-contrib: false` (bekannt aus Task 1 des Backend-Plans — dama-Rezept wurde ebenso übersprungen). Flex fragt ggf. oder überspringt still.

- [ ] **Step 2: Prüfen, ob die .htaccess entstanden ist**

Run: `ls backend/public/.htaccess && grep -c HTTP_AUTHORIZATION backend/public/.htaccess`
Erwartet: Datei existiert, Treffer ≥ 1. **Falls die Datei fehlt** (Rezept übersprungen), manuell anlegen mit exakt diesem Inhalt (Standard-Inhalt des apache-pack):

```apache
DirectoryIndex index.php

<IfModule mod_negotiation.c>
    Options -MultiViews
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_URI}::$0 ^(/.+)/(.*)::\2$
    RewriteRule .* - [E=BASE:%1]

    # Authorization-Header an PHP durchreichen (Bearer-Edit-Tokens)
    RewriteCond %{HTTP:Authorization} .+
    RewriteRule ^ - [E=HTTP_AUTHORIZATION:%0]

    RewriteCond %{ENV:REDIRECT_STATUS} =""
    RewriteRule ^index\.php(?:/(.*)|$) %{ENV:BASE}/$1 [R=301,L]

    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]

    RewriteRule ^ %{ENV:BASE}/index.php [L]
</IfModule>

<IfModule !mod_rewrite.c>
    <IfModule mod_alias.c>
        RedirectMatch 307 ^/$ /index.php/
    </IfModule>
</IfModule>
```

- [ ] **Step 3: Tests laufen lassen**

Run: `php backend/bin/phpunit; echo $?`
Erwartet: `OK (79 tests, …)`, Exit-Code 0 (die .htaccess berührt PHP nicht — reine Absicherung).

- [ ] **Step 4: Commit**

```bash
git add backend/ && git commit -m "Ergänze Apache-.htaccess für das Hosting" -m "ALL-INKL ist Apache: ohne Rewrite auf index.php gibt es kein
Routing, ohne HTTP_AUTHORIZATION-Regel verliert PHP den
Bearer-Header der Edit-Tokens. Lokal (php -S) bleibt alles
unverändert.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: deploy/verify-hosting.sh

**Files:**
- Create: `deploy/verify-hosting.sh` (chmod +x)

**Interfaces:**
- Produces: wiederholbares Prüfskript; Exit 0 = Hosting ok. Nutzt dieselben Kopf-Variablen wie Task 3 (`DEPLOY_HOST`, `DEPLOY_PATH`).

- [ ] **Step 1: Skript anlegen**

`deploy/verify-hosting.sh`:

```bash
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
```

- [ ] **Step 2: Syntax-Check und Ausführbarkeit**

```bash
bash -n deploy/verify-hosting.sh && chmod +x deploy/verify-hosting.sh && echo SYNTAX-OK
```

- [ ] **Step 3: Real ausführen**

Run: `deploy/verify-hosting.sh`
Erwartet: alle Zeilen `OK …`; solange das DB-Passwort fehlt, zusätzlich die `WARTET`-Zeile (kein Fehler). Exit-Code 0.

- [ ] **Step 4: Commit**

```bash
git add deploy/verify-hosting.sh && git commit -m "Ergänze wiederholbare Hosting-Verifikation" -m "Prüft php85, Composer, rsync, die PHP-Module inkl. WebP und
Argon2id sowie – sobald das DB-Passwort eingetragen ist – die
DB-Verbindung samt echter Server-Version für die serverVersion-
Angabe in der DATABASE_URL.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: deploy/deploy.sh + README

**Files:**
- Create: `deploy/deploy.sh` (chmod +x)
- Modify: `deploy/README.md` (vollständig ersetzen)

**Interfaces:**
- Consumes: `.htaccess` (Task 1) wird mitge-rsynct; Variablenkopf wie Task 2.
- Produces: `deploy/deploy.sh` — ein Aufruf deployt Backend + Schema.

- [ ] **Step 1: deploy.sh anlegen**

`deploy/deploy.sh`:

```bash
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
```

- [ ] **Step 2: README ersetzen**

`deploy/README.md`:

```markdown
# deploy/

Deployment des gestura-index auf das Shared-Hosting (ALL-INKL). Spec: `../docs/superpowers/specs/2026-07-21-deployment-design.md`.

## Skripte

- `verify-hosting.sh` – prüft die Server-Umgebung (php85, Module inkl. WebP/Argon2id, Composer, DB-Verbindung). Jederzeit gefahrlos wiederholbar.
- `deploy.sh` – deployt `backend/` + `schema/` per rsync, führt auf dem Server `php85 composer install --no-dev -o`, die Doctrine-Migrationen und `cache:clear` aus und prüft zum Schluss `https://api.gestura.eu/api/v1/entries`. Bricht bei jedem Fehler hart ab; `backend/.env.local` und `public/media/` auf dem Server werden nie angetastet.

## Einmalige manuelle Einrichtung (KAS)

1. Subdomain **api.gestura.eu** → Docroot `/www/htdocs/w00d7b19/gestura.eu/backend/public`.
2. **gestura.eu** (+ www) → Docroot `/www/htdocs/w00d7b19/gestura.eu/frontend`.
3. HTTPS/Let's Encrypt für alle drei; Weiterleitung www → gestura.eu.
4. DB-Passwort in `/www/htdocs/w00d7b19/gestura.eu/backend/.env.local` eintragen (Platzhalter `__DB_PASSWORT_HIER_EINTRAGEN__` ersetzen).

## Rollback

Kein Releases-Mechanismus (bewusst, Phase 2): vorherigen Git-Stand auschecken und `deploy/deploy.sh` erneut ausführen. Migrationen sind vorwärtsgerichtet – bei Schema-Rollbacks `php85 bin/console doctrine:migrations:migrate <version>` auf dem Server.
```

- [ ] **Step 3: Syntax-Check**

```bash
bash -n deploy/deploy.sh && chmod +x deploy/deploy.sh && echo SYNTAX-OK
```

- [ ] **Step 4: Probelauf bis zum Guard**

Run: `deploy/deploy.sh`
Erwartet, solange das DB-Passwort noch fehlt: Tests laufen grün durch, dann `FEHLER – … fehlt noch das DB-Passwort.` mit Exit 1 (beweist Preflight + Guard). Ist das Passwort schon eingetragen, läuft das Deploy komplett durch — dann gelten die Abnahme-Kriterien aus Task 4.

- [ ] **Step 5: Commit**

```bash
git add deploy/ && git commit -m "Ergänze Deploy-Skript mit Preflight und Smoke-Check" -m "Ein Aufruf deployt Backend und Schema: rsync mit geschützten
Excludes (.env.local, media), serverseitiges composer install
unter php85, Migrationen, Cache-Clear und Smoke-Check gegen die
Live-API. Guard verhindert Deploys gegen unkonfigurierte DB.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Erst-Deploy und Abnahme

**Files:** keine Repo-Änderungen erwartet (ggf. Doku-Korrekturen).

**Interfaces:**
- Consumes: Tasks 1–3; **manuelle KAS-Schritte des Nutzers müssen erledigt sein** (Subdomain, Docroots, HTTPS, DB-Passwort). Falls nicht: beim Nutzer nachfragen und warten — nichts improvisieren.

- [ ] **Step 1: Verifikation mit DB**

Run: `deploy/verify-hosting.sh`
Erwartet: komplett `OK`, inkl. `OK DB-Verbindung – Server-Version: …`. Weicht die gemeldete Server-Version von `10.11.0-MariaDB` ab, per SSH die `serverVersion` in der Server-`.env.local` korrigieren (z. B. `mariadb-10.11.14`):

```bash
ssh ssh-w00d7b19@85.13.135.147 "sed -i 's/serverVersion=10.11.0-MariaDB/serverVersion=<ECHTE-VERSION>/' /www/htdocs/w00d7b19/gestura.eu/backend/.env.local"
```

- [ ] **Step 2: Deploy ausführen**

Run: `deploy/deploy.sh`
Erwartet: alle Abschnitte durchlaufen; am Ende `== Deploy erfolgreich ==` mit `{"items":[],"page":1,"perPage":20,"total":0}`.

- [ ] **Step 3: Abnahme-Checks**

```bash
curl -s -o /dev/null -w '%{http_code} %{content_type}\n' https://api.gestura.eu/api/v1/entries
curl -s -o /dev/null -w '%{http_code} %{content_type}\n' https://api.gestura.eu/api/v1/entries/gibt-es-nicht
curl -s -o /dev/null -w '%{http_code}\n' https://gestura.eu/
```

Erwartet: `200 application/json`, `404 application/problem+json`, `200` (Platzhalterseite). Zusätzlich Sicherheits-Stichprobe:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://api.gestura.eu/.env.local
```

Erwartet: `404` (Datei liegt außerhalb des Docroots).

- [ ] **Step 4: Ledger/Abschluss**

Ergebnis (echte Server-Version, Abnahme-Ausgaben) im Task-Report festhalten; keine Commits nötig, außer eine Doku-Korrektur ergab sich.
