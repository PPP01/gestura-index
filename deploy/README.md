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

## Admin-SPA (`/admin`)

Die Routen unter `/admin` sind **client-only** (kein Prerendering) und werden von `adapter-static` über den `200.html`-Fallback ausgeliefert – anders als die öffentlichen Seiten, die als statisches HTML prerendert sind (`build/en/…`, `build/de/…`). Der Webserver muss deshalb:

- unbekannte Pfade unterhalb `/admin` (inkl. Deep-Links wie `/admin/entries/123` oder `/de/admin/queue`) auf `200.html` umleiten (Apache-Rewrite in der `.htaccess` des Frontend-Docroots), damit ein Reload/Direktaufruf nicht in einen 404 läuft,
- dabei die **prerenderten öffentlichen Seiten nicht übergehen** – die Rewrite-Regel darf nur greifen, wenn keine passende Datei/kein passendes Verzeichnis existiert (klassisches `RewriteCond %{REQUEST_FILENAME} !-f` / `!-d` vor dem Fallback auf `200.html`).

Serverseitige Voraussetzungen für die Admin-Auth (bereits mit dem SP4a-Deploy erfüllt, hier nur zur Erinnerung): credentialed CORS für `https://gestura.eu`, `SESSION_COOKIE_DOMAIN=.gestura.eu`, `WEBAUTHN_RP_ID=gestura.eu`, `MAILER_DSN` gesetzt.
