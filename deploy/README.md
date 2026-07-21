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
