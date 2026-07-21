# Design: gestura-index Sub-Projekt 2 – Deployment (minimal)

- **Datum:** 2026-07-21
- **Status:** vom Nutzer freigegeben (Brainstorming abgeschlossen)
- **Übergeordnete Specs:** Phase-2-Gesamtdesign (Extension-Repo) und `2026-07-20-index-backend-core-design.md` (Zerlegung)

## Ziel

Das Backend (Sub-Projekt 1) reproduzierbar per Skript auf das Shared-Hosting deployen; Erfolgskriterium ist die leere Entries-Liste über `https://gestura.eu/api/v1/entries` (HTTP 200, JSON). Kein CI, kein Releases-/Symlink-Mechanismus – bewusst minimal (KISS), Sekunden-Downtime beim Deploy ist in Phase 2 irrelevant. Rollback = vorherigen Git-Stand deployen.

## Zielumgebung (verifiziert am 2026-07-21 per SSH)

| Fakt | Wert |
| --- | --- |
| Server | `ssh-w00d7b19@85.13.135.147` (ALL-INKL, chroot; SSH-Key-Zugang aus WSL funktioniert) |
| PHP-CLI | `php85` = PHP 8.5.3; Module: gd (**imagewebp: true**), sodium (**PASSWORD_ARGON2ID: true**), intl, pdo_mysql, mysqli |
| Composer | 2.9.8 (`composer`); rsync, git, mysql-Client vorhanden |
| Domain | **gestura.eu** (+ www, HTTPS) – Docroot-Pfad `/www/htdocs/w00d7b19/gestura.eu` |
| MySQL | DB/User `d047b128@localhost` (existiert; Passwort nur in der Server-`.env.local`) |

## Server-Layout

```text
/www/htdocs/w00d7b19/gestura.eu/
├── backend/              ← rsync-Ziel: Symfony-App (src, config, migrations, composer.*, bin, public)
│   ├── public/           ← KAS-Docroot – einzig web-erreichbarer Ordner (bereits angelegt, Platzhalter-index.html)
│   │   └── media/        ← Screenshots; wird beim Deploy nie gelöscht oder überschrieben
│   └── .env.local        ← bereits angelegt (chmod 600): APP_ENV=prod, generiertes APP_SECRET,
│                            DATABASE_URL mit Platzhalter __DB_PASSWORT_HIER_EINTRAGEN__
└── schema/               ← exchange-schema.json (ExchangeValidator lädt %kernel.project_dir%/../schema/)
```

`vendor/`, `var/` und `.env.local` liegen unter `backend/`, aber außerhalb des Docroots → nicht web-erreichbar.

## Manuelle Schritte (Nutzer im KAS, einmalig)

1. Docroot der Domain `gestura.eu` (und `www.gestura.eu`) auf `/www/htdocs/w00d7b19/gestura.eu/backend/public` stellen – die Platzhalterseite bestätigt die Umstellung sofort.
2. HTTPS/Let's Encrypt für gestura.eu + www aktivieren; Weiterleitung www → gestura.eu.
3. DB-Passwort in `/www/htdocs/w00d7b19/gestura.eu/backend/.env.local` eintragen (per SSH-Editor oder KAS-WebFTP). Das Passwort geht nie durch das Repo oder die Session.

## Änderungen im Repo

- **`symfony/apache-pack`** ins Backend aufnehmen (einzige Code-Änderung): ALL-INKL ist Apache, ohne die `public/.htaccess` gibt es kein Routing auf `index.php`. Lokal bleibt alles unverändert (php -S ignoriert .htaccess).
- **`deploy/verify-hosting.sh`** – wiederholbare Hosting-Prüfung per SSH: php85-Version, Module (gd+WebP, sodium+Argon2id, intl, pdo_mysql), Composer, rsync; wenn die Server-`.env.local` ein echtes Passwort enthält zusätzlich: DB-Verbindung per `php85`+PDO und Ausgabe der echten Server-Version (→ `serverVersion` in der DATABASE_URL ggf. korrigieren, aktuell angenommen: `10.11.0-MariaDB`).
- **`deploy/deploy.sh`** – ein Aufruf von lokal, `set -euo pipefail`, harter Abbruch bei jedem Fehler:
  1. **Preflight lokal:** `php backend/bin/phpunit` grün mit Exit-Code 0 (sonst Abbruch); Warnung bei uncommitteten Änderungen (kein Abbruch).
  2. **rsync** `backend/` und `schema/` zum Server. Excludes: `vendor/`, `var/`, `tests/`, `phpunit.dist.xml`, `.env.local`, `.env.*.local`, `public/media/`. Mit `--delete`, wobei die Excludes (insb. `public/media/`, `.env.local`, `vendor/`, `var/`) vor Löschung geschützt sind (`--filter`-Regeln).
  3. **Auf dem Server:** `php85 composer install --no-dev --optimize-autoloader`, `php85 bin/console doctrine:migrations:migrate --no-interaction`, `php85 bin/console cache:clear` (APP_ENV=prod aus `.env.local`).
  4. **Smoke-Check:** `curl -fsS https://gestura.eu/api/v1/entries` muss HTTP 200 und gültiges JSON (`items`-Feld) liefern – erst dann Erfolgsmeldung. Vorher zusätzlich Guard: bricht ab, wenn `.env.local` auf dem Server noch den Passwort-Platzhalter enthält.
- Beide Skripte nutzen gemeinsame Variablen am Kopf (`DEPLOY_HOST`, `DEPLOY_PATH`), keine Secrets im Skript.
- `deploy/README.md` aktualisieren (Ablauf, manuelle Schritte, Rollback-Hinweis).

## Fehlerbehandlung

- Jeder Skript-Schritt mit klarer Fehlermeldung; Migrations-Fehler stoppen vor Cache-Clear.
- Platzhalter-Guard verhindert Deploy gegen unkonfigurierte DB.
- Der Platzhalter `public/index.html` wird beim ersten echten Deploy durch rsync `--delete` entfernt (nicht excludiert) – sonst würde Apache ihn vor `index.php` ausliefern.

## Tests / Abnahme

1. `deploy/verify-hosting.sh` läuft grün (inkl. DB-Verbindung nach Passwort-Eintrag; echte serverVersion notiert).
2. `deploy/deploy.sh` einmal real ausführen (SSH-Zugang vorhanden).
3. Abnahme: `curl https://gestura.eu/api/v1/entries` → `{"items":[],"page":1,"perPage":20,"total":0}`; problem+json-Verhalten stichprobenartig (`/api/v1/entries/gibt-es-nicht` → 404 mit `application/problem+json`); Platzhalterseite verschwunden.

## Nicht-Ziele

Kein CI/GitHub-Actions (kann später dasselbe Skript aufrufen), kein Frontend-Upload (Sub-Projekt 3), kein Staging, keine Blue-Green-/Releases-Mechanik, keine KAS-Automatisierung (Docroot/SSL/DB bleiben manuell).
