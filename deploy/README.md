# deploy/

Deployment des gestura-index auf das Shared-Hosting (ALL-INKL). Aktuelle Spec: `../docs/superpowers/specs/2026-09-03-updates-endpoint-und-versioniertes-deployment-design.md` – das ursprüngliche Deployment-Design (Hosting-Umgebung, KAS-Einrichtung, die inzwischen ersetzten Entscheidungen) steht in `../docs/superpowers/specs/2026-07-21-deployment-design.md`.

## Skripte

- `verify-hosting.sh` – prüft die Server-Umgebung (php85, Module inkl. WebP/Argon2id, Composer, DB-Verbindung). Jederzeit gefahrlos wiederholbar.
- `deploy.sh vX.Y.Z` – deployt einen **annotierten** Git-Tag als Release: Guards (Tag annotiert, Commit auf `origin/main`, Arbeitsbaum sauber), Preflight im Worktree des Tags (PHPUnit, `npm run build`), Upload nach `releases/<tag>/`, `shared/` verknüpfen, `php85 composer install --no-dev -o`, Migrationen, `cache:clear`, `RELEASE`-Datei, atomarer Tausch von `current`, `smoke.sh`, `gc.sh`. Leichtgewichtige Tags und Tags außerhalb von `main` werden mit Klartext abgelehnt. Braucht lokal `backend/.env.test.local` (Test-DB) für den Preflight.
- `rollback.sh [vX.Y.Z]` – setzt `current` atomar auf ein früheres vollständiges Release (ohne Argument: das jüngste vor dem aktuellen), leert danach in einem eigenen Schritt den Cache – ein scheiterndes `cache:clear` meldet, dass `current` bereits auf dem Zielrelease steht, statt den Fehler zu verschlucken – und läuft abschließend `smoke.sh`. Migrationen bleiben vorwärtsgerichtet.
- `smoke.sh [origin]` – prüft gegen `https://gestura.eu` (Default): Preflight und leere Antwort von `POST /api/v1/updates` ohne Umleitung, `GET /api/v1/entries` als JSON, `/de` als HTML, `/de/vergleich` erreicht Symfony. Nennt ein noch nicht umgestelltes KAS-Docroot ausdrücklich.
- `gc.sh --root <pfad> [--dry-run]` – Garbage Collection der Releases (läuft auf dem Server per `ssh … 'bash -s' -- --root … < deploy/gc.sh`): `current` nie; die 5 jüngsten bleiben; zusätzlich immer das jüngste Release, das älter als heute ist, falls keines der 5 das schon ist; unvollständige Releases (ohne `RELEASE`) verschwinden. `--keep` und `--today-epoch` werden auf ganzzahlige Werte geprüft – ein fehlerhafter Wert bricht mit Exit-Code 2 ab, ohne etwas zu löschen. Regeln sind in `tests/gc-test.sh` fixiert.

## Server-Layout (versionierte Releases)

```text
/www/htdocs/w00d7b19/gestura.eu/
  releases/v1.0.3/backend/      public/ enthält den Frontend-Build
  releases/v1.0.3/schema/
  releases/v1.0.3/RELEASE       tag, commit, deployed_at, deployed_at_epoch, message – wird als LETZTER Schritt geschrieben
  shared/.env.local  shared/media/  shared/log/
  current -> releases/v1.0.3
```

Docroot **beider** Domains (`gestura.eu` und `api.gestura.eu`): `/www/htdocs/w00d7b19/gestura.eu/current/backend/public`. Die Extension schickt ihren Update-Check an `https://gestura.eu/api/v1/updates` und verwirft jede Umleitung – deshalb muss die Website-Domain selbst die API ausliefern. `api.gestura.eu` bleibt als Alias für `PUBLIC_API_BASE` der Website bestehen.

Ein Release ohne `RELEASE`-Datei ist unvollständig (abgebrochener Deploy): nie Rollback-Ziel, wird von `gc.sh` entfernt, vom nächsten Deploy desselben Tags ersetzt. Der Symlink-Tausch ist atomar (`ln -sfn … current.tmp && mv -T current.tmp current`) – das `-f` erzwingt den Verweis, weil ein aus einem abgebrochenen Lauf liegen gebliebenes `current.tmp` sonst ein einfaches `ln -s` still ins alte Release hinein verlinken ließe, statt `current.tmp` neu zu setzen. PHPs Realpath-Cache kann danach bis zu `realpath_cache_ttl` (Default 120 s) alte Pfade auflösen – auf Shared-Hosting mit kurzlebigen CGI-Prozessen praktisch unsichtbar.

### `.env.local`-Variablen (Server, nie im Repo)

Zusätzlich zu den unten dokumentierten Admin-Variablen:

- `FRONTEND_BUILD_DIR=%kernel.project_dir%/public` – der prerenderte Frontend-Build liegt im gemeinsamen Docroot; der `MarketingPageController` liest die schaltbaren Seiten von dort. Ohne diesen Wert greift der Dev-Default `../frontend/build`, der auf dem Server nicht existiert.

## Umstellung vom alten Layout (einmalig)

Ausgangslage: `backend/`, `frontend/`, `schema/` direkt unter dem Deploy-Pfad, zwei Docroots (`api.gestura.eu` → `backend/public`, `gestura.eu` → `frontend`).

1. Annotierten Tag setzen (`git tag -a vX.Y.Z -m '…'`, pushen) und `deploy/deploy.sh vX.Y.Z` ausführen. Der erste Lauf legt `releases/`, `shared/` (befüllt aus dem alten `backend/`: `.env.local`, `public/media`, `var/log`) und `current` an. Die alten Docroots laufen weiter. `smoke.sh` schlägt an diesem Punkt **erwartungsgemäß** mit dem KAS-Hinweis fehl; `current` zeigt trotzdem auf das neue Release.
2. `FRONTEND_BUILD_DIR=%kernel.project_dir%/public` in `shared/.env.local` ergänzen, danach im Release `php85 bin/console cache:clear`.
3. Im KAS zuerst **`api.gestura.eu`** auf `…/gestura.eu/current/backend/public` umstellen und `deploy/smoke.sh https://api.gestura.eu` laufen lassen. Grün heißt: Apache liefert durch den Symlink aus (`FollowSymLinks`/`SymLinksIfOwnerMatch`; mod_rewrite funktioniert heute schon und setzt eine der beiden Optionen voraus) und die zusammengeführte `.htaccess` greift.
4. **`gestura.eu`** (und `www`) auf dasselbe Docroot umstellen, `deploy/smoke.sh` ohne Argument.
5. Zwischen Schritt 1 und hier lief die alte Seite unter dem alten Docroot weiter und hat neue Screenshots in das alte `backend/public/media` geschrieben – ohne diesen Schritt gehen sie beim Löschen in Schritt 6 verloren:

   ```bash
   rsync -a --ignore-existing /www/htdocs/w00d7b19/gestura.eu/backend/public/media/ /www/htdocs/w00d7b19/gestura.eu/shared/media/
   ```

6. Erst nach grünem Smoke-Check die alten Verzeichnisse `backend/`, `frontend/`, `schema/` unter dem Deploy-Pfad entfernen.

## Rollback

`deploy/rollback.sh` (jüngstes Release vor dem aktuellen) oder `deploy/rollback.sh vX.Y.Z`. Migrationen sind vorwärtsgerichtet – bei Schema-Rollbacks `php85 bin/console doctrine:migrations:migrate <version>` im Zielrelease.

## Ausblick: Deploy bei Tag-Push (Folgepaket)

Eine GitHub-Actions-Automatik, die bei Push eines annotierten `v*`-Tags `deploy.sh` ausführt, ist als eigenes Paket vorgesehen (SSH-Deploy-Key als Secret, MariaDB-Service für PHPUnit, `fetch-tags` für die Annotationsprüfung). Bis dahin läuft `deploy.sh` von Hand.

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

- unbekannte Pfade unterhalb `/admin` (inkl. Deep-Links wie `/admin/entries/123` oder `/de/admin/queue`) auf `200.html` umleiten (Regel 7 der zusammengeführten `backend/public/.htaccess`), damit ein Reload/Direktaufruf nicht in einen 404 läuft,
- dabei die **prerenderten öffentlichen Seiten nicht übergehen** – die Rewrite-Regel darf nur greifen, wenn keine passende Datei/kein passendes Verzeichnis existiert (klassisches `RewriteCond %{REQUEST_FILENAME} !-f` / `!-d` vor dem Fallback auf `200.html`).

Serverseitige Voraussetzungen für die Admin-Auth (bereits mit dem SP4a-Deploy erfüllt, hier nur zur Erinnerung): credentialed CORS für `https://gestura.eu`, `SESSION_COOKIE_DOMAIN=.gestura.eu`, `WEBAUTHN_RP_ID=gestura.eu`, `MAILER_DSN` gesetzt.

## Phase 3: End-Nutzer-Konten (Sub-Projekt A)

Anonyme, cookielose End-Nutzer-Konten (Bearer-Token `gacc_…`) unter `/api/account*`. **Keine** neuen `.env.local`-Variablen, **keine** CORS-/Cookie-Änderungen – der Konto-Realm ist header-basiert und liegt in der öffentlichen, cookielosen API (getrennt von der `/api/admin`-Firewall).

- **Migration:** legt die Tabelle `account` an; läuft im normalen `deploy.sh`-Migrationsschritt mit, kein separater Aufruf.
- **Wartung (optional als Cron):** `php85 bin/console index:account:prune [tage]` löscht Konten, die länger als `tage` (Default 365) inaktiv sind (Datensparsamkeit).

✅ **Aufgelöst mit Sub-Projekt F (Settings-Sync):** Der `sync_blob`-FK trägt DB-seitiges `ON DELETE CASCADE` – sowohl Konto-Löschen als auch das DQL-Bulk-DELETE von `index:account:prune` entfernen Sync-Blobs zuverlässig mit (Regressionstest `SyncCascadeTest`). Neue Tabellen mit FK auf `account` müssen diesem Muster folgen.

### Locator-Sync der Extension (`/api/v1/sync/*`, apiLevel 3)

Anonym, cookielos, ohne Konto: adressiert wird über einen aus dem Nutzergeheimnis abgeleiteten **Locator**, der nur als SHA-256 in der Spalte `sync_state.locator_hash` liegt. Nicht zu verwechseln mit dem kontogebundenen `/api/account/sync/{collection}` – die beiden haben nur das Wort gemeinsam.

- **Migration:** legt die Tabelle `sync_state` an; läuft im normalen `deploy.sh`-Migrationsschritt mit. Die Spalte `payload` ist `MEDIUMTEXT` – der Vertrag erlaubt 512 KiB je Envelope, `TEXT` fasst nur 64 KiB.
- **Wartung (Cron, NICHT optional):** Der Vertrag sagt dem Nutzer eine Aufbewahrung von 12 Monaten zu und zeigt sie ihm an. Ohne diesen Job wird die Zusage nicht eingehalten:

  ```
  # täglich, Aufbewahrung der Sync-Stände (12 Monate, Vertrag »Retention«)
  17 3 * * * cd ~/current/backend && php85 bin/console index:sync:prune >/dev/null
  ```

- **Kein Body-Logging.** Der Locator ist ein Bearer-Token und reist ausschließlich im Request-Body – nie in der URL, nie in einem Header. Ein Zugriffslog mit Bodies wäre ein Log voller Zugangsschlüssel. Beim Einrichten von Logging oder eines Reverse-Proxys ist das die eine Zeile, die nicht übersehen werden darf.
- **Keine Weiterleitung auf `/api/v1/sync/*`.** Der Client schickt `redirect: "error"`; ein `307`/`308` erhielte Methode **und** Body und reichte den Locator an die Zielorigin weiter. Die `.htaccess`-Regel »`/api/…` immer an `index.php`« deckt das ab – siehe den Abschnitt zu den `.htaccess`-Regeln.

**Edit-Token-Migration (Sub-Projekt C):** `submitter.account_id` trägt `ON DELETE SET NULL` – Konto-Löschung/-Prune lässt Einreichungen als anonyme Edit-Token-Submitter zurück (Regressionstest `AccountSubmitterCascadeTest`). Der Trust-Pfad (Sofort-Publish ab `TRUST_THRESHOLD = 3` aggregierten Freigaben) ist damit erstmals aktiv – ausschließlich für Konto-Einreichungen.

## Schaltbare Seiten (Admin-Seiten-Sichtbarkeit)

Der Frontend-Build liegt seit dem versionierten Deployment in `releases/<tag>/backend/public/` (gemeinsames Docroot). Der `MarketingPageController` liest die prerenderten Dateien von dort und entscheidet je Aufruf von `/{locale}/{slug}` anhand des persistierten `PageSetting`-Flags, ob die HTML ausgeliefert (200) oder ein echtes 404 zurückgegeben wird.

- **`FRONTEND_BUILD_DIR`** muss in `shared/.env.local` auf `%kernel.project_dir%/public` zeigen (siehe oben), weil der Build im gemeinsamen Docroot liegt.
- **Die zusammengeführte `backend/public/.htaccess` ist die einzige Regelquelle** (Marketing-Interceptor vor Datei-, Add-.html- und SPA-Fallback-Regel; `/api/…` davor immer an Symfony). `frontend/static/.htaccess` existiert bewusst nicht mehr. Die Datei ist recipe-managed (`symfony/framework-bundle`); nach Recipe-Updates prüfen, dass Regeln 1–7 erhalten sind (siehe `.claude/lessons.md`).
