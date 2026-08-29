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

## Admin-Backend (SP4a)

Das Admin-Backend (Passkey-Login, Moderation, Nutzerverwaltung) bringt eigene `.env.local`-Variablen und einen einmaligen Bootstrap-Schritt mit.

### Neue `.env.local`-Variablen (Server, nie im Repo)

- `MAILER_DSN` – Mailversand des Hosters (ersetzt den lokalen Default `null://null`). Auf ALL-INKL **SMTP verwenden**, nicht sendmail – siehe »Mailversand verifizieren« unten.
- `SESSION_COOKIE_DOMAIN=.gestura.eu` – Cookie-Domain der Admin-Session (mit führendem Punkt, damit sie über Subdomains gilt).
- `WEBAUTHN_RP_ID=gestura.eu` – Relying-Party-ID für WebAuthn/Passkeys (ohne führenden Punkt, muss zur Cookie-Domain passen).
- `MAILER_FROM=admin@gestura.eu` – Absenderadresse für Invite- und Admin-Mails. **Muss eine real existierende Mailbox** auf dem Account sein (SPF/DKIM), sonst wird die Mail abgewiesen oder als Spam einsortiert.

### Mailversand verifizieren (ALL-INKL)

Auf dem Shared-Hosting **SMTP statt sendmail** verwenden. Der lokale sendmail-Weg (`native://` / `sendmail://`) ist unzuverlässig: Die `php.ini` verweist zwar auf `/usr/sbin/sendmail -t -i`, in der (gejailten) SSH-Shell fehlt dieser Binary aber – ein `mail()`-Test schlägt dort mit Exit 127 fehl, selbst wenn der Web-Kontext anders aussähe. SMTP verhält sich in CLI und Web identisch und ist damit verlässlich testbar.

- `MAILER_DSN=smtp://MAILBOX%40gestura.eu:PASSWORT@wXXXXXXX.kasserver.com:587` – Benutzername ist die **volle Mailadresse** (`@` als `%40` kodieren); Host und Port stehen im KAS unter den Zugangsdaten des Postfachs. Für SMTPS `smtps://…:465`.
- Ein erfolgreicher Transport heißt »vom Server angenommen«, nicht automatisch »zugestellt« – immer zusätzlich das Zielpostfach prüfen.

Konfiguration end-to-end testen (nutzt exakt den konfigurierten `MAILER_DSN` und denselben Absender wie die Admin-Mails; sendet synchron, daher kommt ein Transportfehler direkt zurück):

```bash
php85 bin/console index:mail:test empfaenger@example.de
```

`[OK]` = Transport hat angenommen (bei SMTP echte Server-Bestätigung) → Postfach prüfen. `[ERROR]` nennt den Transport-Klartext (Auth, Port, abgelehnter Absender) – genau die Information, die ein blanker `mail()`-Aufruf verschluckt.

### Migrationen

Die Doctrine-Migrationen legen zusätzlich vier Admin-Tabellen an: `admin_user`, `webauthn_credential`, `admin_invite` und `audit_log_entry`. Sie laufen im normalen `deploy.sh`-Migrationsschritt mit, kein separater Aufruf nötig.

### Einmaliger Bootstrap nach dem ersten Deploy

Es gibt keine Registrierung ohne Einladung – der allererste Admin-Account muss per CLI auf dem Server angelegt werden:

```bash
php85 bin/console index:admin:create "Dein Name" deine@mail.tld --role=admin
```

Danach kann sich dieser Account per Passkey-Registrierung anmelden und weitere Admins/Moderatoren über den Invite-Endpoint einladen.

## Admin-SPA (`/admin`)

Die Routen unter `/admin` sind **client-only** (kein Prerendering) und werden von `adapter-static` über den `200.html`-Fallback ausgeliefert – anders als die öffentlichen Seiten, die als statisches HTML prerendert sind (`build/en/…`, `build/de/…`). Der Webserver muss deshalb:

- unbekannte Pfade unterhalb `/admin` (inkl. Deep-Links wie `/admin/entries/123` oder `/de/admin/queue`) auf `200.html` umleiten (Apache-Rewrite in der `.htaccess` des Frontend-Docroots), damit ein Reload/Direktaufruf nicht in einen 404 läuft,
- dabei die **prerenderten öffentlichen Seiten nicht übergehen** – die Rewrite-Regel darf nur greifen, wenn keine passende Datei/kein passendes Verzeichnis existiert (klassisches `RewriteCond %{REQUEST_FILENAME} !-f` / `!-d` vor dem Fallback auf `200.html`).

Serverseitige Voraussetzungen für die Admin-Auth (bereits mit dem SP4a-Deploy erfüllt, hier nur zur Erinnerung): credentialed CORS für `https://gestura.eu`, `SESSION_COOKIE_DOMAIN=.gestura.eu`, `WEBAUTHN_RP_ID=gestura.eu`, `MAILER_DSN` gesetzt.

## Phase 3: End-Nutzer-Konten (Sub-Projekt A)

Anonyme, cookielose End-Nutzer-Konten (Bearer-Token `gacc_…`) unter `/api/account*`. **Keine** neuen `.env.local`-Variablen, **keine** CORS-/Cookie-Änderungen – der Konto-Realm ist header-basiert und liegt in der öffentlichen, cookielosen API (getrennt von der `/api/admin`-Firewall).

- **Migration:** legt die Tabelle `account` an; läuft im normalen `deploy.sh`-Migrationsschritt mit, kein separater Aufruf.
- **Wartung (optional als Cron):** `php85 bin/console index:account:prune [tage]` löscht Konten, die länger als `tage` (Default 365) inaktiv sind (Datensparsamkeit).

✅ **Aufgelöst mit Sub-Projekt F (Settings-Sync):** Der `sync_blob`-FK trägt DB-seitiges `ON DELETE CASCADE` – sowohl Konto-Löschen als auch das DQL-Bulk-DELETE von `index:account:prune` entfernen Sync-Blobs zuverlässig mit (Regressionstest `SyncCascadeTest`). Neue Tabellen mit FK auf `account` müssen diesem Muster folgen.

**Edit-Token-Migration (Sub-Projekt C):** `submitter.account_id` trägt `ON DELETE SET NULL` – Konto-Löschung/-Prune lässt Einreichungen als anonyme Edit-Token-Submitter zurück (Regressionstest `AccountSubmitterCascadeTest`). Der Trust-Pfad (Sofort-Publish ab `TRUST_THRESHOLD = 3` aggregierten Freigaben) ist damit erstmals aktiv – ausschließlich für Konto-Einreichungen.

## Schaltbare Seiten (Admin-Seiten-Sichtbarkeit)

Der Frontend-Build wird wie gehabt ins Web-Root der Index-Domain geladen (prerenderte HTML unter `build/<locale>/<slug>.html`). Der `MarketingPageController` liest genau diese Dateien aber zusätzlich über das Backend – er entscheidet je Aufruf von `/{locale}/{slug}` anhand des persistierten `PageSetting`-Flags, ob die HTML ausgeliefert (200) oder ein echtes 404 zurückgegeben wird (deaktiviert oder Datei fehlt).

- **`app.frontend_build_dir`** (Parameter in `backend/config/services.yaml`) muss auf den deployten Build-Pfad zeigen. Der Default `%kernel.project_dir%/../frontend/build` passt nur, wenn `backend/` und `frontend/build/` wie im Repo als Geschwisterverzeichnisse nebeneinanderliegen; weicht das Hosting-Layout davon ab, den Parameter per `.env.local`-Override bzw. Services-Override auf den tatsächlichen Pfad setzen.
- **`.htaccess`-Interception-Regel muss vorhanden sein** (`backend/public/.htaccess`, innerhalb `<IfModule mod_rewrite.c>`, vor der `-f`-Regel): sie leitet `/{de|en}/{was-ist-gestura|maus-gesten|vergleich|beispiele}` IMMER an `index.php` weiter, statt eine vorhandene statische Datei direkt auszuliefern. Ohne sie würde eine deaktivierte Seite trotzdem als 200 aus der statischen HTML bedient – das Backend-Flag hätte keine Wirkung. Die Regel ist recipe-managed (siehe `.claude/lessons.md`) und muss nach einem `symfony/framework-bundle`-Recipe-Update erneut geprüft werden.
