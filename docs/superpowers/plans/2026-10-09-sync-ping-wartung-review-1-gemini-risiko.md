**Trifft zu.**

Der Plan enthält zwei Stellen, an denen das beschriebene Verhalten vom Vertrag in `exchange/2026-10-09-instruktion-sync-ping.md` abweicht und ein ausgelieferter Client eine Antwort falsch deutet:

---

### 1. Abgelaufenes `until` schaltet den Wartungsmodus vorzeitig ab

- **Fundstelle:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, Entscheidung E2 (Zeilen 30–35) und Task 4 (`backend/src/EventSubscriber/SyncMaintenanceSubscriber.php`, Zeilen 332–337).
- **Eingabefall:** Die Wartung dauert länger als geplant. Die Flag-Datei existiert weiterhin auf dem Server mit einem Zeitstempel in der Vergangenheit (z. B. `2026-10-09T12:00:00+00:00`). Um 12:01 UTC trifft `GET /api/v1/sync/ping` (oder ein Request auf `/api/v1/sync/*`) ein. Die Datenbank ist erreichbar (`SELECT 1` gelingt).
- **Tatsächliche Antwort:** Der `SyncMaintenanceSubscriber` ignoriert die existierende Flag-Datei (`$untilTs <= time()`), bricht nicht mit 503 ab, sondern reicht an den Controller durch. Dieser antwortet mit HTTP `200` (`status: "ok"`).
- **Fehldeutung durch den Client:** Der Client liest HTTP `200` als „erreichbar“ und startet den Assistenten bzw. schreibende Sync-Operationen, obwohl die Wartung auf dem Server noch andauert.
- **Erwartete Antwort:** Weiterhin HTTP `503` mit `{"error":"maintenance"}`. Der Vertrag definiert `until` ausdrücklich als **voraussichtliches Ende** (`exchange/2026-10-09-instruktion-sync-ping.md`, Zeile 50), nicht als automatischen Abschalt-Timer. Das Verstreichen der Schätzung beendet die reale Wartung nicht; solange die Flag-Datei liegt, muss der Server `503 maintenance` liefern.

---

### 2. Trailing Slash auf GET-Endpunkt erzeugt eine Symfony-Weiterleitung (301)

- **Fundstelle:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, Task 3 (Zeile 199: `#[Route('/api/v1/sync/ping', methods: ['GET'])]`) in Verbindung mit `backend/public/.htaccess` (Zeile 34) und Symfonys `RedirectableCompiledUrlMatcher` (`RedirectController::urlRedirectAction`).
- **Eingabefall:** `GET /api/v1/sync/ping/` (mit abschließendem Schrägstrich), keine aktive Wartung, Dienst voll funktionsfähig.
- **Tatsächliche Antwort:** Apache reicht die Anfrage dank Regel 1 der `.htaccess` intern an `index.php` weiter. Symfonys Router erkennt für GET-Routen die Slash-Variante und erzeugt über `RedirectController::urlRedirectAction` eine HTTP `301 Moved Permanently`-Weiterleitung auf `/api/v1/sync/ping`.
- **Fehldeutung durch den Client:** Der Client ruft mit `redirect: 'error'` auf (`exchange/2026-10-09-instruktion-sync-ping.md`, Zeile 40). Der Browser bricht bei einem `301` sofort mit einem Netzwerkfehler ab. Die Extension interpretiert diesen Netzwerkfehler laut Vertragstabelle als „gerade nicht erreichbar“, obwohl der Server erreichbar und gesund ist.
- **Erwartete Antwort:** Kein `3xx`. Entweder HTTP `200` mit dem regulären Ping-Body (wenn die Slash-Variante toleriert wird) oder ein direktes HTTP `404` (welches die Extension vertragsgemäß defensiv als „erreichbar – der Server hat geantwortet“ liest). Die Regel in `.htaccess` verhindert nur Weiterleitungen durch Apache, nicht die des Symfony-Routers.

---

### Weitere geprüfte Randfälle (vertragskonform)

Die übrigen Pfade verhalten sich vertragskonform:
- `OPTIONS`-Preflights werden durch `CorsSubscriber` (Priorität 256) vor dem Wartungs-Subscriber (Priorität 240) mit `204` beantwortet; `CorsSubscriber::onKernelResponse` setzt CORS-Header auf alle `/api/`-Antworten (auch `503`).
- `SyncMaintenanceSubscriber` stoppt vor `AdminCsrfSubscriber` (200), Router (32) und Controllern; Wartungsanfragen verbrauchen keine Rate-Limit-Token.
- Scheitert der DB-Check `SELECT 1` im Ping, liefert `SyncProblem::unavailable()` Status `503` mit `{"error":"unavailable"}` als `application/json` (durch `ProblemJsonSubscriber` vor RFC 7807 geschützt); der Client liest diesen Body korrekt als „gerade nicht erreichbar“.
- Ein erschöpfter Ping-Limiter liefert `429` mit `{"error":"rate-limited"}` („zu viele Anfragen“).
- Eine unlesbare oder syntaktisch ungültige Flag-Datei führt sicher zu `503` mit `{"error":"maintenance"}` ohne `until` („wird gerade gewartet“).
- `HEAD` ist nicht der vereinbarte GET-Aufruf; Großschreibung betrifft case-sensitive Pfade.

ENDE DES REVIEWS

