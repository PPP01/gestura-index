# Review 2: Unabhängige Risikoprüfung zu Sync-Ping und Wartungsmodus

**Ergebnis:** Trifft nicht zu.

## Begründung

Das im Plan `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md` beschriebene Verhalten von `GET /api/v1/sync/ping` und des Wartungs-`503` auf allen `/api/v1/sync/*`-Pfaden weicht an keiner Stelle so vom Vertrag in `exchange/2026-10-09-instruktion-sync-ping.md` (inklusive Nachtrag und Abstimmung im Logbuch `exchange/AUSTAUSCH.md`) ab, dass ein ausgelieferter Client eine Antwort falsch deuten würde.

Im Einzelnen geprüft am Code und den Plan-Spezifikationen:

1. **Erfolgreicher Ping (`GET /api/v1/sync/ping`):**
   - Status: `200`
   - Content-Type: `application/json`
   - Header: `Cache-Control: no-store`
   - Body: `{"status":"ok","features":["sync-meta"]}` (`SyncContract::FEATURES`, identisch mit `POST /sync/list`)
   - Client-Deutung: Der Client prüft Status 200 und defensiv `status: "ok"` -> wertet den Dienst korrekt als **erreichbar**.

2. **Datenbankausfall beim Ping:**
   - Der Controller fängt Ausfälle von `$connection->executeQuery('SELECT 1')` ab und wirft `SyncProblem::unavailable()`.
   - Da `SyncProblem` das Interface `RendersOwnApiResponse` implementiert, unterdrückt `ProblemJsonSubscriber` das RFC-7807-Format (`problem+json`) und liefert die vertragstreue Form `503 {"error":"unavailable"}` als `application/json`.
   - Client-Deutung: Status 503 mit Body ungleich `"maintenance"` -> wertet den Dienst laut Tabelle korrekt als **nicht erreichbar**.

3. **Wartungsmodus (`/api/v1/sync/*` und `/api/v1/sync/ping`):**
   - Priorität: `SyncMaintenanceSubscriber` reagiert auf `KernelEvents::REQUEST` mit Priorität 240. Er greift nach `CorsSubscriber` (256), vor `AdminCsrfSubscriber` (200) und vor dem Router (32) sowie den Controllern.
   - Status und Body: Antwortet mit `503` und `application/json`.
   - Bei gesetztem, künftigem `until`: `{"error":"maintenance","until":"...Z"|"...+00:00"}` mit `Retry-After`. Client meldet „wird gerade gewartet (bis ...)“.
   - Bei fehlendem, unlesbarem oder ungültigem Inhalt der Flag-Datei sowie bei **abgelaufenem `until`** (E2): Antwortet mit `503 {"error":"maintenance"}` ohne `until`-Feld und ohne `Retry-After`. Client meldet „wird gerade gewartet“.
   - Limiter-Verbrauch: Da der Subscriber vor den Controllern läuft und das Event stoppt, verbrauchen Anfragen während der Wartung keine Rate-Limit-Tokens.

4. **CORS-Preflight (`OPTIONS`):**
   - `CorsSubscriber` (Priorität 256) beantwortet OPTIONS-Anfragen unter `/api/` vor dem Wartungs-Subscriber sofort mit HTTP `204`.
   - `Access-Control-Allow-Methods` enthält bereits `GET, POST, PUT, DELETE, OPTIONS` (Nachtrag 1 erfüllt).
   - Preflights gelingen somit auch während einer Wartung unterbrechungsfrei.

5. **Redirect-Freiheit (`redirect: 'error'`):**
   - Apache: Regel 1 in `backend/public/.htaccess` leitet alle `/api/*`-Pfade vor den datei- und schrägstrichbasierten 301-Regeln direkt an `index.php` weiter.
   - Symfony Router: Der Controller registriert explizit sowohl `/api/v1/sync/ping` als auch `/api/v1/sync/ping/` (E9), sodass Symfonys automatischer 301-Redirect für GET-Routen mit abschließendem Schrägstrich verhindert wird.
   - Im Wartungsmodus greift der Subscriber über `str_starts_with($path, '/api/v1/sync/')` bereits vor dem Router, sodass auch Slash-Varianten direkt `503` ohne `3xx` erhalten.

6. **Rate-Limiting (HTTP 429):**
   - Eigener Limiter `sync_ping` (60/h), getrennt von Schreibkontingenten.
   - Bei Erschöpfung wirft `RateLimitGuard` über den Callback `SyncProblem::rateLimited(...)` HTTP `429` mit `{"error":"rate-limited"}` und `Retry-After`.
   - Client-Deutung: „zu viele Anfragen, versuch es gleich noch einmal“.

7. **Deploy und Rollback:**
   - Die Flag-Datei wird über den Symlink `shared/state/sync-maintenance` releaseübergreifend gehalten.
   - Bei einem Notfall-Rollback auf ein Vorfeature-Release (vor Einführung von Ping/Wartung) liefert der Server HTTP `404`. Laut Nachtrag 2 wertet der Client `404` nicht mehr als erreichbar, sondern als **nicht erreichbar**, was dem tatsächlichen Fehlen des Dienstes entspricht.

ENDE DES REVIEWS

