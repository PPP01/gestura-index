# Review: Umsetzungsplan Sync-Ping und Wartungsmodus für `/api/v1/sync/*`

- **Review-Datum:** 2026-10-09
- **Reviewer:** Unabhängiger Reviewer (Gemini)
- **Repo-Stand:** HEAD `7b7c08a` (Symfony 7.4 LTS JSON-API, PHP 8.5)
- **Reviewgegenstand:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`
- **Referenzen:**
  - Fachliche Vorgabe: `exchange/2026-10-09-instruktion-sync-ping.md`
  - Zusage des Index: `exchange/AUSTAUSCH.md` (Eintrag 2026-10-09)
  - Projektkonventionen & Fallen: `CLAUDE.md`, `.claude/lessons.md`

---

## 1. Übersicht & Gesamturteil

### Gesamturteil: **Umsetzbar nach Korrekturen**

### Begründung:
Der Plan ist architektonisch sauber aufgebaut, versteht die nicht verhandelbaren Prinzipien des Projekts (Trennung des anonymen Locator-Sync von Konto-Sync, keine Protokollierung von Tokens/Bodies, CORS-Prioritäten, kein RFC 7807 auf Sync-Routen) und orientiert sich eng an den bestehenden Konventionen (Rate-Limiter in drei YAML-Blöcken, Shared-State-Symlink-Muster).

Dennoch enthält der Plan **drei Befunde mit Schweregrad Hoch** und mehrere Befunde mittlerer Schwere, die behoben werden müssen, bevor ein externer Entwickler den Plan ohne Vorwissen abarbeiten kann:
1. **Ineffektiver Test beim Rate-Limiting in Wartung:** Ein Test in Task 7 prüft den Nicht-Verbrauch von Rate-Limit-Tokens über 10 Requests bei einem Test-Limit von 1000 – der Test wäre auch grün, wenn die Anfragen Tokens verbrauchten.
2. **Lücke im Fehlerpfad-Test des Pings:** Der DB-Ausfallpfad (`SELECT 1` scheitert $\to$ 503 `unavailable`) wird im Controller gar nicht getestet, und der angebliche Ersatztest in Task 8 ruft lediglich eine Hilfsmethode auf, statt den HTTP-/Exception-Stack zu prüfen.
3. **Betriebsblockade beim Deployment:** `deploy/smoke.sh` bricht bei aktiver Wartung mit Status 503 ab und rät zum Rollback, wodurch ein reguläres Deployment während einer geplanten Wartung (etwa für DB-Migrationen) fehlschlägt.

Nach Einarbeitung der nachfolgenden Korrekturen ist der Plan vollständig reproduzierbar und einsatzbereit.

---

## 2. Findings-Liste nach Schweregrad

### Schweregrad: Kritisch
*Keine Befunde.* Es wurden keine Fehler gefunden, die einen Totalausfall der bestehenden Plattform oder unbehebbare Syntaxfehler zur Folge hätten.

---

### Schweregrad: Hoch

#### Finding H-1: Schein-Test für Rate-Limiter-Schutz während Wartung
- **Fundstelle:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, Task 7 (`SyncMaintenanceTest.php`, Zeilen 823–837)
- **Beschreibung:** Die Testmethode `testMaintenanceDoesNotConsumeRateLimitTokens` setzt die Flag-Datei, führt in einer Schleife 10 Requests gegen `/api/v1/sync/ping` aus, entfernt die Flag-Datei und prüft, ob der 11. Request Status 200 liefert. Im `when@test`-Environment (`backend/config/packages/rate_limiter.yaml`, Task 2) ist das Limit für `sync_ping` jedoch auf **1000 Anfragen pro Stunde** gesetzt.
- **Konsequenz:** Selbst wenn jeder der 10 Wartungs-Requests den Rate-Limiter fälschlicherweise voll konsumiert hätte (z. B. weil der Subscriber nach dem Controller liefe), stünde der Zähler bei 10 von 1000 Tokens. Der 11. Request erhielte immer Status 200. Der Test prüft die behauptete Invariante (*"keiner zählt gegen den Limiter"*, Priorität 240 vor Controllern) in der vorliegenden Form überhaupt nicht und böte keinerlei Regressionsschutz.
- **Vorschlag:** 
  Vor den 10 Wartungsanfragen das Token-Kontingent gezielt bis auf 1 erschöpfen (z. B. via `$factory = static::getContainer()->get('limiter.sync_ping'); $factory->create('127.0.0.1')->consume(999);`). Wenn Wartungsanfragen fälschlicherweise Tokens verbrauchen, liefert der Request nach Deaktivierung der Wartung deterministisch `429`; wenn nicht, liefert er `200`. Alternativ: Den Pool vorab komplett auf 1000 erschöpfen und prüfen, dass der Wartungs-Request dennoch `503` (und nicht `429`) liefert.

---

#### Finding H-2: Ausfallpfad des Ping-Controllers (`SELECT 1` scheitert) ungetestet; Test in Task 8 irreführend
- **Fundstelle:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, Task 6 (Zeilen 650–652) und Task 8 (`LocatorSyncErrorShapeTest.php`, Zeilen 972–980)
- **Beschreibung:** 
  1. In Task 6 wird auf einen Test des DB-Ausfallpfads (`SELECT 1` wirft Exception $\to$ 503 `unavailable`) verzichtet mit dem Argument: *"Ein Unit-Test mit gemocktem Connection wäre möglich, aber der Aufwand steht in keinem Verhältnis zum Risiko... Im Gegenzug testen wir die Antwortform von SyncProblem::unavailable() im LocatorSyncErrorShapeTest (s. Task 8)"*.
  2. In Task 8 wird jedoch lediglich `$response = \App\Exception\SyncProblem::unavailable()->toApiResponse();` aufgerufen und die Rückgabe assertiert.
- **Konsequenz:**
  - `LocatorSyncErrorShapeTest` ist laut Klassenkommentar und bestehendem Code ein funktionaler Integrationstest (`extends ApiTestCase`), der das Verhalten des Symfony-HTTP-Stacks und von `ProblemJsonSubscriber` auf dem Draht absichert. Ein direkter Methodenaufruf von `toApiResponse()` berührt den HTTP-Kernel, das Exception-Handling und den `ProblemJsonSubscriber` überhaupt nicht.
  - Die Behauptung im Commit von Task 8 (*"Sichert ab, dass die beiden neuen 503-Antworten [...] nicht versehentlich in RFC 7807 problem+json konvertiert werden"*) ist sachlich unzutreffend: Der Test kann das gar nicht feststellen.
  - Der fachliche Kern der Anforderung der Extension (*"„ok“ heißt: der Sync-Dienst funktioniert, nicht nur „der Webserver lebt“. DB mit billiger Abfrage berühren... Klappt das nicht: 503 mit { 'error': 'unavailable' }"*) wird im Controller überhaupt nicht getestet.
- **Vorschlag:**
  Einen schlanken Unit-Test für `LocatorSyncPingController` ergänzen (z. B. in `backend/tests/Unit/LocatorSyncPingControllerTest.php`): Eine gemockte DBAL-`Connection` übergeben, deren `executeQuery()` eine Exception wirft, und verifizieren, dass `SyncProblem::unavailable()` (Status 503, Code `unavailable`) geworfen wird. In `LocatorSyncErrorShapeTest` entweder einen echten Request über den KernelBrowser triggern oder den Test sauber als Unit-Test für `SyncProblem` in `backend/tests/Unit/SyncProblemTest.php` ablegen.

---

#### Finding H-3: Deployment-Blockade: `smoke.sh` schlägt während geplanter Wartung fehl und fordert zum Rollback auf
- **Fundstelle:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, Task 10 (`deploy/smoke.sh`, Zeilen 1065–1066) im Zusammenspiel mit `deploy/deploy.sh` (Zeilen 158–161)
- **Beschreibung:** 
  Task 10 fügt in `deploy/smoke.sh` folgenden Check ein:
  ```bash
  elif [ "$STATUS" = 503 ] && grep -q '"maintenance"' "$BODY"; then
      bad "GET /api/v1/sync/ping: 503 maintenance – Wartungsmodus ist aktiv, vor dem Deploy mit »php85 bin/console index:maintenance:off« beenden"
  ```
  `bad()` setzt `fail=1`, wodurch `smoke.sh` mit Exit-Code 1 abbricht. In `deploy/deploy.sh` führt dies unmittelbar zu:
  `die "Smoke-Check fehlgeschlagen. current zeigt auf $TAG. Zurück mit: deploy/rollback.sh"`.
- **Konsequenz:** 
  Ein Betreiber aktiviert den Wartungsmodus typischerweise *vor* einem Release (z. B. um Schema-Migrationen oder größere Deployments ohne konkurrierende Sync-Schreibzugriffe durchzuführen). Führt er nun `deploy.sh` aus, schlägt der Smoke-Check nach dem Tausch von `current` fehl, die Garbage Collection (`gc.sh`) wird nicht ausgeführt, das Deployment gilt als gescheitert und das Skript fordert fälschlicherweise zum Rollback eines technisch vollkommen intakten Releases auf. Der Plan erwähnt dies zwar in E8 und Task 11 (*"Wartung vor dem Deploy beenden oder Smoke wird 503 sehen"*), doch das verfehlt den primären Einsatzzweck eines Wartungsmodus bei Deployments.
- **Vorschlag:**
  `smoke.sh` sollte eine vertragskonforme Wartungsantwort (`STATUS = 503` mit `"maintenance"`) als erfolgreichen Nachweis der Funktionsfähigkeit anerkennen (z. B. mit Ausgabe `ok "GET /api/v1/sync/ping: 503 maintenance (Wartungsmodus aktiv)"` oder als nicht-fatale Warnung `WARN`, die `fail=1` nicht setzt). Alternativ ein Flag (z. B. `deploy/smoke.sh --allow-maintenance`) unterstützen, das `deploy.sh` weiterreicht, wenn der Wartungsschalter in `shared/state/` gesetzt ist.

---

### Schweregrad: Mittel

#### Finding M-1: `MaintenanceOnCommand` erlaubt und quittiert Wartung mit `until` in der Vergangenheit
- **Fundstelle:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, Task 5 (`MaintenanceOnCommand.php`, Zeilen 459–470, 485–492)
- **Beschreibung:** Das Kommando validiert `--until` rein über `strtotime()`. Wenn ein Betreiber versehentlich ein Datum in der Vergangenheit übergibt (z. B. Vertipper beim Datum oder der Uhrzeit), schreibt das Kommando die Flag-Datei und gibt Erfolgsmeldung aus: `Wartungsmodus aktiviert (bis ...)`. Der `SyncMaintenanceSubscriber` prüft jedoch bei eingehenden Requests:
  ```php
  if ($untilTs !== false && $untilTs <= time()) {
      return; // Wartung vorbei, Datei ignorieren
  }
  ```
- **Konsequenz:** Der Betreiber erhält eine Erfolgsmeldung, aber der Wartungsmodus ist faktisch inaktiv. Die API antwortet weiterhin mit Status 200, ohne dass der Betreiber den Konfigurationsfehler bemerkt.
- **Vorschlag:**
  Im `MaintenanceOnCommand` prüfen:
  ```php
  if ($ts <= time()) {
      $io->error(sprintf('Das angegebene Datum »%s« liegt in der Vergangenheit.', $until));
      return Command::INVALID;
  }
  ```

---

#### Finding M-2: Redundantes Zeitstempel-Parsing in `SyncMaintenanceSubscriber` und `SyncProblem`
- **Fundstelle:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, Task 4 (`SyncMaintenanceSubscriber.php`, Zeilen 330, 334, 344) und Task 1 (`SyncProblem.php`, Zeile 93)
- **Beschreibung:** 
  Innerhalb eines einzigen HTTP-Requests wird derselbe Zeitstempel bis zu dreimal per `strtotime()` geparst:
  1. In `SyncMaintenanceSubscriber::parseUntil()` zur Validierung und Normierung.
  2. Direkt danach in `onKernelRequest()` zur Prüfung auf `$untilTs <= time()`.
  3. In `SyncProblem::maintenance()` zur Berechnung von `Retry-After: max(1, $untilTs - time())`.
- **Konsequenz:** Unnötige CPU-Zyklen auf dem schnellen Request-Pfad und latente Risiken bei unterschiedlicher Zeitzonen-Interpretation (falls ein String ohne expliziten UTC-Offset in der Flag-Datei landet).
- **Vorschlag:**
  `SyncMaintenanceSubscriber` kann den berechneten UNIX-Timestamp zwischenspeichern und direkt an `SyncProblem::maintenance($until, $untilTs)` übergeben, anstatt den String mehrfach hin und her zu konvertieren.

---

#### Finding M-3: Unvollständige Entwarnung zur "Bekannten Lücke" in `deploy/README.md`
- **Fundstelle:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, Task 10 (Zeile 1072) & Task 11 (Zeile 1096)
- **Beschreibung:** Der Plan schlägt vor, die Zeile zur "Bekannten Lücke" in `deploy/README.md` (Zeile 11) zu entfernen oder zu aktualisieren, weil `smoke.sh` nun den Ping prüfe.
  Die ursprüngliche Lücke in `deploy/README.md` besagte jedoch:
  > *"Die `/api/v1/sync/*`-Endpunkte (apiLevel 3) prüft `smoke.sh` noch nicht... prüfen, dass `POST /api/v1/sync/list` mit einem gültigen Locator `200` liefert, `states` ein Array ist und `features` den Eintrag `sync-meta` enthält..."*
- **Konsequenz:** Ein `GET /api/v1/sync/ping` prüft lediglich, ob der Webserver lebt und eine triviale DB-Query (`SELECT 1`) durchgeht. Er prüft *nicht* die eigentliche Funktionalität der Locator-Sync-Endpunkte (Locator-Hashing, Body-Parsing, Envelope-Prüfung, Auslesen der `sync_state`-Tabelle). Das Streichen des Hinweises täuscht eine vollständige Testabdeckung des Syncs in `smoke.sh` vor.
- **Vorschlag:** 
  Die Dokumentation in `deploy/README.md` differenzieren: Der Ping sichert die Erreichbarkeit und DB-Verbindung der Sync-Route in `smoke.sh` ab; die funktionale Prüfung von `POST /api/v1/sync/list` mit Nutzdaten bleibt als manueller Stichprobenschritt bzw. weiterführende Aufgabe bestehen.

---

### Schweregrad: Niedrig

#### Finding N-1: Text/Code-Abweichung: E1 nennt `is_file()`, Task 4 implementiert `file_exists()`
- **Fundstelle:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, Entscheidung E1 (Zeile 28) vs. Task 4 (`SyncMaintenanceSubscriber.php`, Zeile 325)
- **Beschreibung:** E1 begründet: *"Der Subscriber prüft bei jedem Request an `/api/v1/sync/` per `is_file()` und liest nur bei Treffer."* Im Code von Task 4 steht jedoch `if (!file_exists($this->syncMaintenanceFile))`.
- **Konsequenz:** Inkonsistenz zwischen Entscheidung und Code. `is_file()` ist robuster, da `file_exists()` auch wahr wäre, wenn versehentlich ein gleichnamiges Verzeichnis existiert.
- **Vorschlag:** Im Code `is_file($this->syncMaintenanceFile)` verwenden.

---

#### Finding N-2: Unbenutzter Import `JsonResponse` in `SyncMaintenanceSubscriber`
- **Fundstelle:** `backend/src/EventSubscriber/SyncMaintenanceSubscriber.php` (Task 4, Zeile 268)
- **Beschreibung:** `use Symfony\Component\HttpFoundation\JsonResponse;` wird importiert, aber in der Klasse nicht verwendet (`$problem->toApiResponse()` gibt `Response` zurück).
- **Konsequenz:** Unnötiger Import (Linter-/Code-Style-Warnung).
- **Vorschlag:** Import entfernen.

---

#### Finding N-3: Fehlende Pfadangabe in den CLI-Beispielen in `deploy/README.md`
- **Fundstelle:** `deploy/README.md` (Task 11, Zeilen 1109–1115)
- **Beschreibung:** Die Befehle werden im neuen Abschnitt als `php85 bin/console index:maintenance:on` dokumentiert. Im Cronjob-Abschnitt direkt darüber ist das Ausführungsverzeichnis explizit angegeben (`cd ~/current/backend && php85 ...`).
- **Konsequenz:** Wenn sich der Betreiber nach SSH-Login im Benutzer-Homeverzeichnis befindet, schlägt der Aufruf mit `bin/console: No such file or directory` fehl.
- **Vorschlag:** Den Pfadwechsel wie im Rest der Datei mit angeben: `cd ~/current/backend && php85 bin/console ...`.

---

## 3. Detailprüfung entlang der Kriterien des Auftrags

### 3.1. Plan gegen Code
- **Klassen- und Methodennamen:** 
  - `SyncProblem`: Konstruktorsignatur (`int $statusCode, string $errorCode, array $extra = [], array $headers = []`) und Factory-Muster passen exakt zu den vorgeschlagenen Methoden `unavailable()` und `maintenance()`.
  - `SyncContract`: `SyncContract::FEATURES` (`['sync-meta']`) und `SyncContract::formatTimestamp()` werden im Plan exakt wie im Code verwendet.
  - `RateLimitGuard`: `consume($factory, $key, $tokens, $onExhausted)` passt exakt zur Verwendung im neuen `LocatorSyncPingController`.
- **Subscriber-Prioritäten:**
  - `CorsSubscriber`: REQUEST-Priorität ist im Code tatsächlich `256` (beantwortet OPTIONS mit 204 und ruft `stopPropagation()`).
  - `AdminCsrfSubscriber`: REQUEST-Priorität ist im Code tatsächlich `200`.
  - Die geplante Priorität `240` für `SyncMaintenanceSubscriber` fügt sich perfekt zwischen CORS (256) und CSRF (200) bzw. Router (32) ein.
- **Deploy-Skripte:**
  - `deploy/deploy.sh` verlinkt in Zeile 130 `var/log` per `rm -rf ...; ln -sfn "$SHARED/log" "$RELEASE/backend/var/log"`. Die Ergänzung für `var/state` in Zeile 131 folgt exakt diesem Muster.
  - `gc.sh` und `rollback.sh` berühren `shared/` nicht, wodurch der Zustand Release-übergreifend stabil bleibt.

### 3.2. Korrektheit des Plan-Codes
- **Syntax & Typisierung:** Der im Plan abgedruckte Code für Controller, Subscriber und Commands ist syntaktisch korrektes PHP 8.5.
- **Symfony Autowiring:**
  - `RateLimiterFactoryInterface $syncPingLimiter` wird von Symfony dank des konsequenten Namensmusters (`$xxxLimiter`) automatisch auf den Service `limiter.sync_ping` aufgelöst (analog zu `syncV1Limiter` und `syncV1BytesLimiter`).
  - `Doctrine\DBAL\Connection` ist als Autowiring-Typ für `default_connection` registriert.
- **Container-Parameter & Test-Isolierung:**
  - `app.sync_maintenance_file` wird im Hauptblock von `services.yaml` als `%kernel.project_dir%/var/state/sync-maintenance` definiert.
  - Im `when@test`-Block wird er sauber auf `%kernel.project_dir%/var/test-sync-maintenance` überschrieben. Dies verhindert, dass Tests das echte State-Verzeichnis manipulieren.
  - Das Aufräumen in `setUp()` und `tearDown()` via `unlink` ist in `SyncMaintenanceTest` und `MaintenanceCommandTest` korrekt umgesetzt.

### 3.3. Vollständigkeit gegenüber der Instruktion und unserer Zusage
- **Instruktion `exchange/2026-10-09-instruktion-sync-ping.md`:**
  - `GET /api/v1/sync/ping` liefert Status 200, `Cache-Control: no-store` und `{"status":"ok","features":["sync-meta"]}`.
  - DB-Lebenszeichen über `SELECT 1`, bei Fehler 503 mit `{"error":"unavailable"}`.
  - Geplante Wartung liefert 503 mit `{"error":"maintenance"}` und optionalem `until` (ISO-8601 UTC).
  - Wartung greift auf allen `/api/v1/sync/*`-Endpunkten.
  - Kein Freitext in der Wartungsantwort.
- **Zusage in `exchange/AUSTAUSCH.md`:**
  - Eigener Rate-Limiter `sync_ping` mit 60/h (eigene Drosselung, zählt nicht auf Schreib-Limits).
  - Preflight-Anfragen (`OPTIONS`) liefern auch während der Wartung 204.
  - `until` im ATOM-UTC-Format (`2026-10-09T12:00:00+00:00`).
  - `Retry-After`-Header wird bei `until` mitgeliefert.
  - `/api/v1/updates` und `/api/account/sync/*` bleiben unberührt.
*Fazit:* Fachlich und vertraglich ist der Plan vollständig und exakt deckungsgleich.

### 3.4. Innere Konsistenz
- Die Reihenfolge der Tasks (1 $\to$ 11) ist logisch und frei von zyklischen Abhängigkeiten.
- Der Wechsel von Entscheidung E1 (Symlink in `shared/state/`) über Task 4 (Subscriber) bis Task 9 (`deploy.sh`) ist konsistent durchdacht.

---

## 4. Empfohlene Nachbesserungen vor Umsetzungsstart

Vor der Freigabe zur taskweisen Abarbeitung sollte der Plan an folgenden Stellen konkret nachgeschärft werden:

1. **Task 7 (`SyncMaintenanceTest.php`):**
   In `testMaintenanceDoesNotConsumeRateLimitTokens` den Pool vor den Wartungsaufrufen vorbelasten:
   ```php
   $factory = static::getContainer()->get('limiter.sync_ping');
   $factory->create('127.0.0.1')->consume(999);
   ```
2. **Task 6 / Task 8 (`LocatorSyncPingTest` / `LocatorSyncErrorShapeTest`):**
   Einen echten Unit-Test für den `catch`-Block von `LocatorSyncPingController` schreiben, der sicherstellt, dass ein DBAL-Verbindungsabbruch zu `SyncProblem::unavailable()` führt.
3. **Task 10 (`deploy/smoke.sh`):**
   Den Check so anpassen, dass `smoke.sh` bei aktiver Wartung nicht mit `bad` fehlschlägt, sondern den Wartungsmodus als valides Ergebnis anerkennt (oder über ein Flag steuert), damit Deployments während Wartungsfenstern nicht abbrechen.
4. **Task 5 (`MaintenanceOnCommand.php`):**
   Validierung ergänzen, dass `--until` nicht in der Vergangenheit liegen darf (`$ts > time()`).

ENDE DES REVIEWS

