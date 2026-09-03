# Design: `POST /api/v1/updates` (Vertrag R2) und versioniertes Deployment im gemeinsamen Docroot

**Datum:** 2026-09-03 · **Status:** Entwurf zur Freigabe · **Vertrag:** `docs/gestura-eu-api.md` (Kopie aus dem Extension-Repo, Stand `main@8c944e9`, apiLevel 2)

## 1. Ausgangslage

Die Extension (R2, fertig auf `main`) prüft beim Öffnen ihrer Einstellungen höchstens einmal pro 24 Stunden und Index-Origin, ob für importierte Einträge neuere Versionen oder Abkündigungen vorliegen. Dafür sendet sie `POST <indexOrigin>/api/v1/updates`. Die Index-Origin ist die Origin der Website, von der importiert wurde, in Produktion also `https://gestura.eu`.

Im Backend existiert bereits ein Vorläufer aus dem Phase-2-Plan: `UpdateCheckController` unter `POST /api/v1/entries/updates`. Er weicht in Pfad, Request- und Antwortformat vom Vertrag ab. Kein Client hat diesen Pfad je benutzt.

Die Produktion trennt heute zwei Docroots: `gestura.eu` liefert den statischen Frontend-Build (ein Aufruf von `https://gestura.eu/api/v1/entries` liefert die SPA-Hülle als HTML mit Status 200, der Preflight kommt ohne CORS-Header zurück), `api.gestura.eu` liefert die Symfony-API. Der Vertrag verbietet Umleitungen (`redirect: "error"` im Client, jedes `3xx` gilt als fehlgeschlagene Prüfung) und verlangt, dass die zurückgegebene Download-`url` auf derselben Origin liegt, die geantwortet hat. Ohne Änderung des Hostings wäre der Endpunkt auf der Vertrags-Origin tot: Die Extension würde HTML als ungültige Antwort verwerfen, nie ein Abzeichen zeigen und nie ihr 24-Stunden-Fenster starten.

Die Doku des Projekts (CLAUDE.md, `MarketingPageController`, Abschnitt »Schaltbare Seiten« in `deploy/README.md`) setzt bereits ein gemeinsames Docroot auf `backend/public/` mit hineinkopiertem Frontend-Build voraus; der Einrichtungsabschnitt derselben README und die Produktion beschreiben noch die Trennung. Dieses Paket löst den Widerspruch in Richtung gemeinsames Docroot auf.

## 2. Entscheidungen

| Frage | Entscheidung | Begründung |
| --- | --- | --- |
| Wie erreicht `gestura.eu/api/…` die API? | Gemeinsames Docroot auf `backend/public/`, Frontend-Build liegt darin | Doku und Marketing-Controller setzen es bereits voraus; kein `mod_proxy` nötig; keine Vertragsänderung |
| Alter Pfad `/api/v1/entries/updates`? | Entfällt ohne Alias | Nie von einem Client benutzt |
| Deployment-Automatisierung | Vollständig: Frontend-Build und Upload im Skript | Handarbeit beim Frontend-Upload ist fehleranfällig, insbesondere wegen der `.htaccess`-Falle (Abschnitt 4.1) |
| Deployment-Strategie | Versionierte Releases mit `current`-Symlink statt rsync `--delete` an Ort und Stelle | Atomarer Wechsel, sofortiger Rollback, frischer Opcache pro Release, kein Löschen im laufenden Docroot |
| Release-ID | Annotierter Git-Tag `v<major>.<minor>.<patch>` | Bewusste Handlung, Tag-Nachricht als Release-Notiz; leichtgewichtiger `v*`-Tag bricht laut ab statt still nichts zu tun |
| CI-Automatik (Deploy bei Tag-Push) | Eigenes Folgepaket | Skript erst von Hand gegen den Server erproben, bevor es unbeaufsichtigt läuft |
| Rate-Limiter | Neuer Limiter `update_check`, 60 pro Stunde und IP | Client fragt einmal am Tag; das Limit fängt nur Missbrauch ab (Muster `bundle`) |
| Ablage der Vertragskopie | `docs/gestura-eu-api.md` mit Kopfhinweis »Kopie, hier nie ändern« | Analog zu `schema/exchange-schema.json` |
| Website auf Same-Origin-API umstellen? | Nein, `api.gestura.eu` bleibt als Alias, `PUBLIC_API_BASE` unverändert | Nicht Teil dieses Pakets; kann später folgen |

## 3. Der Endpunkt `POST /api/v1/updates`

### 3.1 Controller

Der bestehende `UpdateCheckController` wird auf den neuen Pfad umgezogen und auf den Vertrag umgebaut. Er bleibt eine schlanke Controller-Klasse ohne eigenen Service, weil die Logik aus einem Batch-Lookup und einer Filterregel besteht. Öffentliche, cookielose API; der bestehende `CorsSubscriber` deckt Preflight und Antwort-Header mit `*` ab.

### 3.2 Request

```json
{ "apiLevel": 2, "entries": [ { "id": "eu.example.shop", "version": "1.2.0" }, { "id": "eu.example.search", "version": null } ] }
```

- Body muss ein JSON-Objekt mit dem Feld `entries` (Liste) sein, sonst 400 (`ApiProblem`). Mehr als 200 Posten: 400. Ungültiges JSON: 400.
- `apiLevel` wird gelesen, aber nicht erzwungen. Der Vertrag verlangt Toleranz gegenüber Clients; ein fehlendes oder abweichendes Feld ist kein Ablehnungsgrund.
- Einzelposten werden **still übersprungen**, wenn `id` kein String ist, nicht dem Kennungsmuster `^[a-zA-Z0-9]([a-zA-Z0-9._-]*[a-zA-Z0-9])?$` entspricht oder länger als 128 Zeichen ist, oder wenn `version` weder `null` noch ein numerisches Tripel `^\d{1,5}\.\d{1,5}\.\d{1,5}$` ist. Doppelte Kennungen werden zusammengefasst (der erste Posten gewinnt). So bleibt der Check bei einzelnen fehlerhaften Posten nutzbar.
- Rate-Limiter `update_check` (60/h/IP, `sliding_window`) wird über `RateLimitGuard` vor dem Parsen konsumiert; Konfiguration in allen drei Blöcken von `rate_limiter.yaml` (`framework`, `when@test`, `when@dev`, letztere mit 1000).

### 3.3 Antwortlogik

Ein Batch-Lookup holt alle veröffentlichten Einträge zu den gültigen Kennungen (`EntryRepository`, Status `published`). Ein Eintrag erscheint in `updates`, wenn er eine `currentVersion` hat **und** mindestens eine Bedingung gilt:

1. Client-Version ist `null`: die aktuelle Version wird gemeldet (»sag mir die aktuelle Version«).
2. Server-Version ist numerisch größer als die Client-Version (`version_compare` auf numerischen Tripeln).
3. Eintrag ist `deprecated`, unabhängig von der Version.

Stumm bleiben: unbekannte Kennungen, nicht veröffentlichte Einträge, Einträge ohne `currentVersion` (Invariante: `published` impliziert `currentVersion !== null`, der Guard bleibt dennoch defensiv), sowie nicht abgekündigte Einträge mit gleicher oder höherer Client-Version. Die Reihenfolge der Antwort folgt der Reihenfolge der gültigen Eingabeposten.

### 3.4 Antwort

```json
{
  "apiLevel": 2,
  "updates": [
    {
      "id": "eu.example.shop",
      "type": "menu",
      "version": "1.3.0",
      "url": "https://gestura.eu/api/v1/entries/eu.example.shop/versions/1.3.0",
      "changelog": "Two new patterns for /cart",
      "deprecated": false,
      "successor": null
    }
  ]
}
```

- Status immer 200, auch bei leerer Liste. `apiLevel` ist die Konstante 2.
- `type` aus `Entry::$type` (`menu` | `engine`). Der Client prüft den Typ gegen seine lokale Kenntnis; der Server braucht keine Typangabe in der Anfrage und erfährt nichts über die Einrichtung des Nutzers.
- `version` ist `currentVersion->semver`.
- `url` wird aus `$request->getSchemeAndHttpHost()` und dem bestehenden Versions-Endpunkt `GET /api/v1/entries/{formatId}/versions/{semver}` gebildet. Damit liegt sie automatisch auf der antwortenden Origin: `https://gestura.eu` in Produktion, `https://api.gestura.eu` beim Alias, `http://localhost:8000` beim Dev-Index. Der Versions-Endpunkt liefert bereits das Roh-JSON mit `*`-CORS und zählt keine Installation (siehe lessons.md). Die Beispiel-URL im Vertrag (`/api/v1/menus/<id>/<version>`) ist illustrativ; vertraglich geprüft wird nur die Origin.
- `changelog` ist `currentVersion->changelog` (String oder `null`), ungekürzt; der Client kürzt auf 1000 Zeichen.
- `deprecated` aus `Entry::$deprecated`, `successor` aus `Entry::$successorFormatId` (String oder `null`).

### 3.5 CORS und Redirects

Der `CorsSubscriber` beantwortet `OPTIONS /api/…` mit 204 und `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`, `Access-Control-Allow-Headers: Authorization, Content-Type`. Das ist eine Obermenge der drei Vertrags-Header; keine Änderung nötig. Der `Origin`-Header `moz-extension://…` wird vom Subscriber nicht ausgewertet, also auch nicht abgelehnt. Die zusammengeführte `.htaccess` (Abschnitt 4.1) leitet `/api/…` direkt an `index.php`, ohne Trailing-Slash-Kanonisierung, sodass auf dem Vertragspfad kein `3xx` entsteht.

## 4. Gemeinsames Docroot und versioniertes Deployment

### 4.1 Eine `.htaccess` als einzige Quelle

`backend/public/.htaccess` wird zur zusammengeführten Datei und committet. `frontend/static/.htaccess` wird **gelöscht**: Sie würde im Build landen und beim Kopieren nach `public/` die Backend-Datei samt Front-Controller-Regeln und Marketing-Interceptor überschreiben. Zwei Quellen für dieselben Regeln sind genau die Falle, die lessons.md heute beschreibt.

Reihenfolge der Regeln in der zusammengeführten Datei:

1. Symfony-Basis wie heute: `DirectoryIndex index.php`, `Options -MultiViews`, `BASE`-Ermittlung, `Authorization`-Durchreichung, `index.php`-Redirect (301 nur für explizite `/index.php/…`-Aufrufe).
2. **`/api/…` immer an `index.php`** mit `[L]`. Die API landet nie im SPA-Fallback und wird nie von der Trailing-Slash-Umleitung berührt.
3. Marketing-Interceptor an `index.php` (bestehende Regel für `/{de|en}/{was-ist-gestura|maus-gesten|vergleich|beispiele}`).
4. Vorhandene Dateien direkt ausliefern (`-f`).
5. Trailing Slash kanonisieren (außer Wurzel, 301), Add-`.html` für prerenderte Seiten, vorhandene Verzeichnisse (`-d`).
6. Alles Übrige auf `/200.html` (SPA-Hülle für Admin- und Client-Routen).

Symfonys bisheriger Catch-all auf `index.php` entfällt zugunsten von Regel 6. Das ist sicher, weil außer `/api/…` nur die Marketing-Route (Regel 3) und die Dev-Fehlervorschau `/_error/…` existieren (per `debug:router` geprüft). `DirectoryIndex` wird `index.html index.php` (in dieser Reihenfolge, weil beide Dateien im Docroot liegen und die Wurzel `/` die prerenderte Startseite liefern soll, nicht Symfonys 404). `DirectorySlash Off` aus der bisherigen Frontend-Datei bleibt erhalten, weil `de/` und `en/` als Verzeichnisse neben `de.html`/`en.html` existieren und Apache sonst `/de` auf `/de/` umleiten würde.

### 4.2 Build-Verzeichnis des Marketing-Controllers

`app.frontend_build_dir` wird über die Env-Variable `FRONTEND_BUILD_DIR` konfigurierbar (`%env(resolve:FRONTEND_BUILD_DIR)%`). Default in `.env`: `%kernel.project_dir%/../frontend/build`, damit Dev und Tests unverändert bleiben (der `when@test`-Override auf `tests/fixtures/frontend-build` bleibt). Produktion setzt in `shared/.env.local`: `FRONTEND_BUILD_DIR=%kernel.project_dir%/public`.

### 4.3 Server-Layout

```text
/www/htdocs/w00d7b19/gestura.eu/
  releases/v1.0.3/backend/      public/ enthält den Frontend-Build
  releases/v1.0.3/schema/       ExchangeValidator liest %kernel.project_dir%/../schema/exchange-schema.json
  releases/v1.0.3/RELEASE       Tag, Commit, Tag-Nachricht, deployed-at (ISO 8601); wird als LETZTER Schritt geschrieben
  shared/.env.local
  shared/media/                 Screenshots (heute backend/public/media/)
  shared/log/                   heute backend/var/log/
  current -> releases/v1.0.3
```

Docroot für `gestura.eu` und `api.gestura.eu`: `/www/htdocs/w00d7b19/gestura.eu/current/backend/public`. Jedes Release erhält absolute Symlinks `backend/.env.local -> <DEPLOY_PATH>/shared/.env.local`, `backend/public/media -> <DEPLOY_PATH>/shared/media`, `backend/var/log -> <DEPLOY_PATH>/shared/log` (absolut statt relativ, damit die Links unabhängig von der Tiefe des Release-Verzeichnisses korrekt sind und `readlink -f` sie eindeutig auflöst). Alles übrige unter `var/` (Cache, Sessions, Rate-Limiter-Pool) ist releasegebunden; Admin-Sessions gehen beim Deploy verloren, was bei 30 Minuten Idle-Timeout vertretbar ist.

Die `RELEASE`-Datei ist der Vollständigkeitsnachweis: Ein Release-Verzeichnis ohne sie gilt als unvollständig (abgebrochener Deploy) und darf weder Rollback-Ziel sein noch die Garbage Collection überleben.

### 4.4 `deploy/deploy.sh v1.0.3`

1. **Guards lokal:** Tag existiert; `git cat-file -t <tag>` liefert `tag` (annotiert), sonst Abbruch »Tag ist nicht annotiert, kein Deploy«; der getaggte Commit ist von `origin/main` erreichbar (`git merge-base --is-ancestor`), sonst Abbruch; Arbeitsbaum ist sauber (Warnung reicht nicht mehr, weil ohnehin der Tag deployt wird, aber uncommittete Änderungen an Deploy-Skripten wären trügerisch). Server-Guard wie heute: `shared/.env.local` enthält keinen Platzhalter.
2. **Preflight aus dem Tag:** Tag in ein temporäres Git-Worktree im Scratchpad auschecken; dort `composer install` (für PHPUnit), `php bin/phpunit` mit Exit-Code-Prüfung, `npm ci` und `npm run build` im Frontend. Deployt wird der Tag, nie der Arbeitsstand.
3. **Release füllen:** Frontend-Build (ohne eine eventuell vorhandene `.htaccess`, defensiv) nach `backend/public/` des Worktrees kopieren; dann `rsync -az --link-dest=../<current-release>/backend` für `backend/` (Excludes wie heute: `vendor/`, `var/`, `tests/`, `phpunit.dist.xml`, `.env.local`, `.env.*.local`, `public/media/`) und `schema/` nach `releases/<tag>/`. Existiert `releases/<tag>/` bereits: mit `RELEASE`-Datei Abbruch (Tags sind unveränderlich), ohne `RELEASE`-Datei vorher entfernen.
4. **Shared verknüpfen:** Beim allerersten Lauf `shared/` anlegen und aus dem heutigen `backend/` befüllen (`.env.local`, `public/media/`, `var/log/`), danach nie mehr anfassen. Symlinks im Release setzen (Abschnitt 4.3).
5. **Server-Schritte im Release:** Zuerst `vendor/` des aktuellen Releases per `cp -a` (echte Kopie, keine Hardlinks: Composer schreibt `vendor/composer/*` und `autoload.php` in place, Hardlinks würden das alte Release mitverändern und den Rollback verfälschen) ins neue Release übernehmen, sofern vorhanden, damit Composer ein fast fertiges Verzeichnis vorfindet und nur Abweichungen nachzieht. Dann `php85 composer install --no-dev --optimize-autoloader --no-interaction`, `doctrine:migrations:migrate --no-interaction`, `cache:clear`.
6. **`RELEASE` schreiben, Symlink atomar tauschen:** `ln -s releases/<tag> current.tmp && mv -T current.tmp current`.
7. **`smoke.sh`** (Abschnitt 4.6), danach **`gc.sh`** (Abschnitt 4.7).

Schlägt ein Schritt vor dem Symlink-Tausch fehl, bleibt `current` unberührt und das unvollständige Release ohne `RELEASE`-Datei liegen; der nächste Lauf desselben Tags räumt es auf. Schlägt `smoke.sh` nach dem Tausch fehl, bricht das Skript mit Hinweis auf `rollback.sh` ab und tauscht nicht selbst zurück (eine bewusste Entscheidung des Betreibers, weil die Ursache auch außerhalb des Releases liegen kann, etwa ein noch nicht umgestelltes KAS-Docroot).

### 4.5 `deploy/rollback.sh [v1.0.2]`

Ohne Argument: das nach `deployed-at` jüngste vollständige Release vor dem aktuellen `current`. Mit Argument: das genannte Release. Guards: Verzeichnis existiert, `RELEASE`-Datei vorhanden, Ziel ist nicht bereits `current`. Dann Symlink atomar tauschen und `smoke.sh`. Migrationen bleiben vorwärtsgerichtet; ein Schema-Rollback bleibt wie heute ein manueller `doctrine:migrations:migrate <version>` im Zielrelease.

### 4.6 `deploy/smoke.sh [origin]`

Prüft gegen `https://gestura.eu` (Default) mit `curl --max-redirs 0`, sodass jeder `3xx` als Fehler zählt:

- `OPTIONS /api/v1/updates` mit `Origin: moz-extension://smoke` und `Access-Control-Request-Method: POST`: Status 204, `Access-Control-Allow-Origin: *`, Allow-Methods enthält `POST`, Allow-Headers enthält `Content-Type`.
- `POST /api/v1/updates` mit `{"apiLevel":2,"entries":[]}`: Status 200, `Content-Type: application/json`, Body ist genau `{"apiLevel":2,"updates":[]}`.
- `GET /api/v1/entries`: Status 200, JSON mit `"items"` (bisheriger Check).
- `GET /de`: Status 200, `Content-Type: text/html` (Frontend wird aus dem gemeinsamen Docroot bedient).
- `GET /de/vergleich`: Status 200 oder 404 mit `text/html` (Marketing-Interceptor erreicht Symfony; beide Werte sind gültig, ein Symfony-Problem-JSON wäre ein Fehler).

Liefert der API-Check HTML statt JSON, nennt die Fehlermeldung ausdrücklich das KAS-Docroot (»`gestura.eu` zeigt noch nicht auf `current/backend/public`«).

### 4.7 `deploy/gc.sh` (Garbage Collection)

Läuft am Ende von `deploy.sh`, ist auch einzeln aufrufbar. Regeln, ausgewertet nach `deployed-at` aus der `RELEASE`-Datei (nicht nach Versionsnummer):

1. `current` wird nie gelöscht.
2. Unvollständige Releases (ohne `RELEASE`-Datei) werden entfernt, außer sie sind `current`.
3. Von den vollständigen Releases außer `current` bleiben die **5 jüngsten**.
4. Ist unter diesen 5 keines mit `deployed-at` vor dem heutigen Tag (Server-Zeit, 00:00 Uhr), bleibt zusätzlich das **jüngste Release, das älter als heute ist**, sofern eines existiert. Damit bleibt nach vielen Deploys an einem Tag immer ein Stand von gestern oder früher als Rückfallpunkt für spät entdeckte Fehler.
5. Alles Übrige wird gelöscht.

Nach einem Rollback zählt das zurückgeschaltete Release als `current`; die neueren Releases gelten als alte Versionen und unterliegen denselben Regeln.

### 4.8 Umstellungs-Runbook (`deploy/README.md`)

1. Annotierten Tag setzen und `deploy/deploy.sh v<x.y.z>` ausführen. Der erste Lauf legt `releases/`, `shared/` (befüllt aus dem heutigen `backend/`) und `current` an. Die alten Docroots `backend/public` und `frontend/` laufen weiter. `smoke.sh` schlägt an diesem Punkt erwartungsgemäß mit dem KAS-Hinweis fehl.
2. `FRONTEND_BUILD_DIR=%kernel.project_dir%/public` in `shared/.env.local` ergänzen.
3. Im KAS zuerst `api.gestura.eu` auf `current/backend/public` umstellen und per `smoke.sh https://api.gestura.eu` verifizieren, dass Apache durch den Symlink ausliefert (`FollowSymLinks`/`SymLinksIfOwnerMatch`; mod_rewrite funktioniert heute schon und setzt eine der beiden Optionen voraus).
4. `gestura.eu` (und `www`) auf `current/backend/public` umstellen, `smoke.sh` ohne Argument.
5. Alte Verzeichnisse `backend/`, `frontend/`, `schema/` auf dem Server entfernen (erst nach erfolgreichem Smoke-Check).
6. Hinweis: PHPs Realpath-Cache kann nach einem Symlink-Tausch bis zu `realpath_cache_ttl` (Default 120 Sekunden) alte Pfade auflösen; auf Shared-Hosting mit kurzlebigen CGI-Prozessen praktisch unsichtbar.

Der Abschnitt »Rollback: Kein Releases-Mechanismus« der README wird durch `rollback.sh` ersetzt; die Einrichtungsschritte 1 und 2 (zwei Docroots) werden auf das gemeinsame `current/backend/public` umgeschrieben. `verify-hosting.sh` bleibt unverändert.

## 5. Tests

Backend (`UpdateCheckTest` wird vollständig ersetzt, TDD):

- Neuere Version wird gemeldet; gleiche und höhere Client-Version bleiben stumm.
- `version: null` liefert die aktuelle Version.
- `deprecated` erscheint bei gleicher Version und zusammen mit neuerer Version; `successor` wird durchgereicht, `null` wenn nicht gesetzt.
- Element-Form vollständig: `type` (Menü und Engine), `version`, `url` mit Schema und Host des Test-Requests (`http://localhost/api/v1/entries/<id>/versions/<semver>`), `changelog`, `deprecated`, `successor`; Umschlag `apiLevel: 2`.
- Still ausgelassen: ungültige Kennungen (Muster, Länge), ungültige Versionen, Doppelte (erster gewinnt), unbekannte und nicht veröffentlichte Einträge. 400 bei kaputtem JSON, fehlender oder nicht-Listen-`entries`, mehr als 200 Posten.
- Reihenfolge der Antwort entspricht der Eingabereihenfolge.
- `CorsTest`: `OPTIONS /api/v1/updates` mit `Origin: moz-extension://abc` liefert 204 mit den drei Vertrags-Headern.
- Rate-Limiter: Konfigurationsprüfung, dass `update_check` in allen drei Blöcken vorhanden ist (falls es dafür bereits ein Testmuster gibt; sonst Konfiguration ohne eigenen Test, die Drosselung selbst deckt `RateLimitGuardTest` ab).
- Der alte Pfad `/api/v1/entries/updates` liefert 404 (Regressionsschutz gegen versehentliches Wiederbeleben).

Deployment: Die `.htaccess`-Regeln lassen sich ohne Apache nicht sinnvoll unit-testen; das übernimmt `smoke.sh` gegen den Server. `gc.sh` erhält eine `--dry-run`-Option, die nur auflistet, was gelöscht würde; die Regel-Logik wird lokal gegen ein synthetisches `releases/`-Verzeichnis im Scratchpad geprüft (Skript-Test in Bash, Teil des Plans).

## 6. Dokumentation

- `docs/gestura-eu-api.md`: Kopie des Vertrags mit Kopfhinweis (Original im Extension-Repo unter `docs/gestura-eu-api.md`, hier nie ändern, bei Abweichungen dort melden).
- `deploy/README.md`: neues Layout, Runbook, `deploy.sh`/`rollback.sh`/`smoke.sh`/`gc.sh`, `FRONTEND_BUILD_DIR`, Hinweis auf CI-Folgepaket.
- `CLAUDE.md`, Abschnitt Deployment: Docroot ist `current/backend/public`, Deploy per annotiertem Tag.
- `.claude/lessons.md`: `.htaccess`-Eintrag anpassen (Merge-Reihenfolge ist jetzt im Repo festgeschrieben, Warnung vor Recipe-Updates bleibt und nennt zusätzlich die `/api/`-Regel und den SPA-Fallback); neue Einträge zu `RELEASE`-Datei als Vollständigkeitsnachweis, zu `shared/` als einzigem persistenten Zustand, und dazu, dass `frontend/static/.htaccess` bewusst nicht existiert.
- `exchange/`: bleibt untracked als Übergabe-Postfach. Die Handover-Datei und die LIESMICH werden nicht committet; ihr Inhalt ist in diesem Spec verarbeitet.

## 7. Rückmeldung an das Extension-Repo

Kein Vertragsfehler gefunden. Ein Hinweis für die Vertragspflege: Die Beispiel-`url` nennt `/api/v1/menus/<id>/<version>`; der reale Download-Pfad des Index ist `/api/v1/entries/<id>/versions/<version>` und gilt für Menüs und Engines gleich. Vertragskonform, weil nur die Origin geprüft wird; das Beispiel könnte bei Gelegenheit angeglichen werden.

## 8. Nicht Teil dieses Pakets

- GitHub-Actions-Automatik (Deploy bei Push eines annotierten `v*`-Tags): eigenes Folgepaket mit SSH-Deploy-Key als Secret, MariaDB-Service für PHPUnit, `fetch-tags` für die Annotationsprüfung.
- Umstellung der Website auf Same-Origin-API und Abschaffung von `api.gestura.eu`.
- R3-Sync-Endpunkte (`/sync/list`, `/sync/state`, `/sync/get`, `/sync/delete`, apiLevel 3): kommen additiv, sobald der R3-Vertrag herüberliegt.
