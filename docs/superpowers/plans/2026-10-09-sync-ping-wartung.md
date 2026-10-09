# Sync-Ping und Wartungsmodus für `/api/v1/sync/*`

Stand: HEAD `7b7c08a`, 2026-10-09. Grundlage: die Instruktion der Extension-Seite (`exchange/2026-10-09-instruktion-sync-ping.md`) und unsere Zusage im Logbuch (`exchange/AUSTAUSCH.md`, Eintrag 2026-10-09). Überarbeitung nach Review-Runde 1 und 2 (je vier Reviews, Befunde eingearbeitet am 2026-10-09).

## Minimale Änderung in drei Stichpunkten

1. **Ping-Endpunkt** `GET /api/v1/sync/ping` (Einzelroute mit optionalem Slash, s. E9) – antwortet `200 {"status":"ok","features":[...]}` nach einem DB-Lebenszeichen (`SELECT 1`), sonst `503 {"error":"unavailable"}`. Eigener Rate-Limiter `sync_ping` (60/h). Strikter ATOM-Parser (`SyncContract::parseAtomTimestamp`) für alle Zeitstempel-Eingaben.
2. **Wartungs-Subscriber** – ein `SyncMaintenanceSubscriber` auf `KernelEvents::REQUEST` (Priorität 240) fängt alle `/api/v1/sync/`-Requests ab, wenn eine Flag-Datei existiert, und antwortet `503 {"error":"maintenance","until"?}`. OPTIONS-Preflights bleiben `204`. Abgelaufenes `until` beendet die Wartung NICHT (E2).
3. **CLI-Kommandos** `index:maintenance:on [--until=...]` / `index:maintenance:off` zum Setzen/Entfernen der Flag-Datei; Ergänzung von `deploy/smoke.sh`, `deploy/rollback.sh` und `deploy/README.md`. Rückmeldung an die Extension nach Livegang (Task 12).

## Bewusst NICHT getan

- Kein Wartungsmodus für `/api/v1/updates`, `/api/account/sync/*` oder die öffentliche Website – die Instruktion begrenzt ihn ausdrücklich auf `/api/v1/sync/*`.
- Keine Admin-UI zum Umschalten – die Wartung ist ein Serveradmin-Werkzeug (SSH, Cron), kein Feature der Moderationsoberfläche.
- Kein Refactoring der bestehenden Sync-Controller oder von `LocatorSyncRequest` – die bestandenen Tests bleiben unverändert.
- Kein `message`-Freitext in der Wartungsantwort – die Instruktion schließt ihn ausdrücklich aus.
- Kein automatisierter Livegang-Test im Plan – Task 12 ist ein manueller Prozessschritt nach Deploy und bestätigtem Smoke.

## Entscheidungen

### E1: Flag-Datei unter `var/state/`, per Symlink nach `shared/state/`

Der Wartungszustand muss releaseübergreifend, datenbankunabhängig und sofort wirksam sein. Eine Datei in `shared/` erfüllt das (wie `.env.local`, `media/`, `log/`).

**Lösung:** Der Container-Parameter `app.sync_maintenance_file` ist fest `%kernel.project_dir%/var/state/sync-maintenance` – **keine Env-Variable, kein manueller Schritt in `shared/.env.local`, kein fest verdrahteter Serverpfad.** `deploy.sh` folgt dem bestehenden Muster von `var/log`: `mkdir -p "$SHARED/state"` und im Release `ln -sfn "$SHARED/state" "$RELEASE/backend/var/state"`. In Dev liegt `var/state/` als gewöhnliches, gitignoriertes Verzeichnis (`/var/` steht in `backend/.gitignore`); die Kommandos legen es bei Bedarf an. Auf dem Server liegt die Datei dadurch dauerhaft in `shared/state/`, sodass ein Wartungsmodus einen Deploy und einen Rollback auf ein Release MIT diesem Feature überlebt. `gc.sh` und `rollback.sh` berühren `shared/` nicht und brauchen keine Änderung am Shared-Verzeichnis.

**Einschränkung bei Rollback auf Vorfeature-Releases:** Ein Rollback auf ein Release OHNE diesen Plan (vor dem Ping/Wartungs-Feature) führt dazu, dass Subscriber und Symlink fehlen – die Flag-Datei in `shared/state/` wirkt nicht, der Wartungsmodus greift nicht. Die Extension sieht dann `404` am Ping und meldet den Dienst als `nicht erreichbar` (404 zählt bei ihr nicht mehr als erreichbar, Logbuch-Nachtrag 2026-10-09). Das Runbook (Task 11) dokumentiert dieses Verhalten.

**Warum nicht über eine Env-Variable?** Ein Pfad in `shared/.env.local` wäre ein zusätzlicher, vergessbarer Betriebsschritt (ohne ihn würde die Wartung stillschweigend in einer releasegebundenen Datei landen, die beim nächsten Deploy verschwindet) und würde einen Serverpfad in `.env.local` duplizieren, den `deploy/common.sh` bereits kennt. Der Symlink erzeugt keinen neuen Zustand, den jemand pflegen muss.

**Lesekosten:** Der Subscriber prüft bei jedem Request an `/api/v1/sync/` per `is_file()` und liest nur bei Treffer. Ein Cache ist nicht nötig.

### E2: Abgelaufenes `until` – Wartung bleibt aktiv

Wenn die Flag-Datei existiert und ein `until` in der Vergangenheit enthält, bleibt die Wartung aktiv – der Server antwortet weiterhin `503 {"error":"maintenance"}`, jedoch OHNE `until`-Feld und OHNE `Retry-After`-Header. Beendet wird die Wartung ausschließlich durch `index:maintenance:off`.

**Begründung:** Der Vertrag bezeichnet `until` als `voraussichtliches Ende`, nicht als Abschaltzeit. Eine Wartung, die länger dauert als geplant, ist der Normalfall – würde der Server nach Ablauf von `until` wieder `200` antworten, startete die Extension mitten in der laufenden Wartung den Assistenten. Der Operator setzt `--until` als Schätzung für den Nutzer, nicht als Timer.

**Konsequenz für das Kommando:** `index:maintenance:on --until=<Vergangenheit>` wird mit `Command::INVALID` und einer klaren Fehlermeldung abgelehnt – ein abgelaufenes `until` zu setzen ist immer ein Tippfehler.

**Warum nicht automatisch löschen?** Der lesende Request-Pfad soll keine Seiteneffekte haben (Schreiben im Request wäre zudem ein Wettlauf zwischen parallelen Requests und dem Kommando `on`). Ob der Webserver-Prozess Schreibrechte auf `shared/state/` hat, ist nicht geprüft und wird deshalb nicht vorausgesetzt.

### E3: Priorität des Subscribers: 240

`CorsSubscriber` hat 256 (OPTIONS-Preflights). `AdminCsrfSubscriber` hat 200. Der Wartungs-Subscriber muss **nach** dem CORS-Preflight (damit OPTIONS immer 204 bleibt) und **vor** dem Router (32) sowie vor dem Rate-Limiter (der im Controller sitzt, also weit nach dem Router) laufen. Priorität 240 reiht sich sauber ein: CORS (256) → Wartung (240) → CSRF (200) → Router (32). In der Wartung verbraucht kein Request ein Limit-Token.

### E4: Kein DB-Zugriff im Wartungspfad

Der Subscriber prüft nur die Flag-Datei. Ist sie vorhanden und gültig, antwortet er sofort mit 503 – ohne die Datenbank zu berühren. Das ist Absicht: eine DB-Wartung ist der häufigste Grund für den Wartungsmodus, und der Subscriber darf dabei nicht selbst scheitern.

### E5: Ping-DB-Check per DBAL `SELECT 1`

Der Controller injiziert `Doctrine\DBAL\Connection`, ruft `$connection->executeQuery('SELECT 1')` auf und fängt eine `\Throwable` ab. Bei Fehlschlag wirft er `SyncProblem` mit Status 503 und `error: "unavailable"`. Das erfordert eine neue Factory-Methode `SyncProblem::unavailable()`.

Die DB-Exception muss der Controller selbst abfangen, **nicht** durchpropagieren lassen: `ProblemJsonSubscriber` würde daraus ein 500 `problem+json` machen, was nicht die vom Vertrag geschuldete Form ist. Der Ausfallpfad wird per Unit-Test (Task 8) mit gemockter `Connection` geprüft.

### E6: Antwortform – `SyncProblem` (nicht RFC 7807)

Alle Antworten des Ping-Endpunkts und des Wartungs-Subscribers folgen der Vertragsform `{"error":"..."}` als `application/json` – genau wie die übrigen `/api/v1/sync/*`-Endpunkte (Lessons: `Die Sync-Endpunkte antworten NICHT in RFC 7807`). `SyncProblem` bekommt dafür zwei neue Factory-Methoden: `unavailable()` (503) und `maintenance(?\DateTimeImmutable $until)` (503, mit optionalem `until`-Feld und optionalem `Retry-After`-Header). Die Signatur nimmt ein bereits geparstes `DateTimeImmutable`, damit der Zeitstempel pro Request nur einmal geparst wird (Single Source, kein redundantes `strtotime`).

### E7: `until`-Format und strikter ATOM-Parser

`SyncContract::formatTimestamp()` liefert `2026-10-09T12:00:00+00:00` (ATOM, UTC). Das stimmt mit der Zusage im Logbuch überein (`wie updatedAt`). Das Flag-Dateiformat ist eine einzige Zeile ISO-8601; ungültiger Inhalt (leere Datei, Müll, `tomorrow`, fehlendes Offset, unmöglich wie `2026-02-31`) bedeutet Wartung ohne `until`.

**Strikter Parser:** Die neue Methode `SyncContract::parseAtomTimestamp(string $raw)` wird von Kommando und Subscriber verwendet. Sie nutzt `DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, ...)` mit Fehler-/Warnungsprüfung (`getLastErrors()`). Empirisch belegt (PHP 8.5.3): `ATOM`-Format `P` akzeptiert sowohl `+00:00` als auch `Z` als Offset; `tomorrow`, fehlender Offset und unmögliche Daten (Feb 31 erzeugt Warnings) werden zuverlässig abgelehnt. Kommando UND Subscriber nutzen denselben Parser.

### E8: Smoke-Check in Wartung – Warnung, kein Fehler

`smoke.sh` ergänzt einen Ping-Check: `GET /api/v1/sync/ping` muss `200` mit `"status":"ok"` liefern. Ist der Dienst in Wartung, liefert der Ping `503 maintenance` – `smoke.sh` gibt eine **Warnung** aus (`fail` bleibt `0`), damit ein Deploy während einer geplanten Wartung (DB-Migration) nicht am Smoke scheitert.

`404` am Ping ist im Deploy ein **Fehler** (der Endpunkt muss im aktuellen Release existieren). In `rollback.sh` setzt das Skript vor dem `smoke.sh`-Aufruf die Umgebungsvariable `SMOKE_LEGACY=1` (Namenskonvention analog zu den bestehenden Variablen in `common.sh`), die `smoke.sh` dazu bringt, `404` am Ping als Legacy-Warnung zu behandeln (Rollback auf ein Release ohne Ping-Endpunkt). Ohne diese Variable ist `404` ein harter Fehler.

**Rollback auf Vorfeature-Release:** Der Wartungsmodus greift dort nicht, weil Subscriber und Symlink fehlen (s. E1). Die Extension sieht `404` und meldet den Dienst als `nicht erreichbar`. Das Runbook (Task 11) dokumentiert dieses Verhalten ausdrücklich.

### E9: Ping-Route mit optionalem Slash (Einzelroute)

**Empirisch belegt** (Kernel im Testmodus, ohne DB): `GET /api/v1/entries/` erzeugt eine Symfony-`301`-Umleitung auf `/api/v1/entries` (erste GET-Route unter dem Pfad); `GET /api/v1/updates/` und `GET /api/v1/sync/list/` liefern `404` (nur POST-Routen, der Matcher leitet nur für GET um). Der Ping wäre die erste GET-Route unter `/api/v1/sync/` – ein `GET /api/v1/sync/ping/` würde `301` nach `/api/v1/sync/ping` erzeugen.

Die Extension ruft den Ping mit `redirect: 'error'` auf. Ein `301` würde als Netzwerkfehler interpretiert und den Dienst als »nicht erreichbar« anzeigen, obwohl er funktioniert. Der Vertrag verbietet jedes `3xx` auf den `/api/v1/sync/*`-Pfaden.

**Zwei getrennte Routen funktionieren NICHT** (empirisch belegt, Matcher ohne Kernel): `RedirectableCompiledUrlMatcher` bricht beim Slash-Konflikt mit der ersten Route ab und wählt den Redirect-Controller, unabhängig von der Registrierungsreihenfolge:

| Routen (Reihenfolge) | `GET /ping` | `GET /ping/` |
|---|---|---|
| `/ping`, `/ping/` | Route a | 301 → `/ping` |
| `/ping/`, `/ping` | 301 → `/ping/` | Route b |

**Lösung (empirisch verifiziert):** Eine EINZELROUTE mit optionalem Slash-Parameter:

```php
#[Route('/api/v1/sync/ping{slash}',
    name: 'api_v1_sync_ping',
    requirements: ['slash' => '/?'],
    defaults: ['slash' => ''],
    methods: ['GET'],
)]
```

Ergebnis (gemessen):

| Methode | Pfad | Ergebnis |
|---|---|---|
| GET | `/ping` | Route, `slash=''` |
| GET | `/ping/` | Route, `slash='/'` |
| HEAD | `/ping` | Route |
| HEAD | `/ping/` | Route |
| POST | `/ping` | 405 MethodNotAllowed |
| GET | `/pingx` | 404 |
| GET | `/ping/x` | 404 |

Der Parameter `slash` wird in der Controller-Signatur nicht gebraucht – Routenparameter, die nicht in der Signatur stehen, sind in Symfony erlaubt. Kein Code generiert URLs für den Ping (er ist rein eingehend). Tests prüfen beide Pfade explizit (200 ohne Location-Header, 503 in Wartung ohne Location-Header, HEAD ohne 3xx).

---

## Tasks

### Task 1: `SyncContract::parseAtomTimestamp` und `SyncProblem`-Erweiterung

**Dateien:**
- `backend/src/Api/SyncContract.php`
- `backend/src/Exception/SyncProblem.php`

**Änderungen an `SyncContract`:**

Nach der bestehenden Methode `formatTimestamp()` die Gegenmethode ergänzen:

```php
/**
 * Strikter ATOM-Parser: Gegenstück zu formatTimestamp(). Akzeptiert
 * ausschließlich ISO-8601/ATOM mit Offset (auch Z). Lehnt ab:
 * leere Strings, natürliche Ausdrücke (tomorrow), fehlenden Offset,
 * unmögliche Daten (Feb 31 – createFromFormat normiert sie still,
 * getLastErrors meldet die Warnung), Steuerzeichen (inkl.
 * Nullbytes – PHP 8.5 wirft ValueError). Einzige Eingabeprüfung
 * für until-Werte – Kommando UND Subscriber nutzen sie.
 */
public static function parseAtomTimestamp(string $raw): ?\DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    // Steuerzeichen (inkl. Nullbytes) vorab ablehnen:
    // PHP 8.5 wirft bei Nullbytes einen ValueError in
    // createFromFormat(), und andere Steuerzeichen haben in
    // einem Zeitstempel nichts verloren.
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $raw)) {
        return null;
    }

    try {
        $dt = \DateTimeImmutable::createFromFormat(
            \DateTimeInterface::ATOM,
            $raw,
        );
    } catch (\ValueError) {
        // Sicherheitsnetz: falls ein künftiges PHP noch andere
        // Eingaben per ValueError ablehnt.
        return null;
    }
    if ($dt === false) {
        return null;
    }

    // Unmögliche Daten (z. B. 2026-02-31) erzeugen Warnungen statt
    // Fehler: createFromFormat gibt ein Objekt zurück, das den
    // Überlauf still korrigiert hat (→ 2026-03-03). Solche
    // Eingaben sind für until nicht akzeptabel.
    $errors = \DateTimeImmutable::getLastErrors();
    if ($errors !== false
        && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
    ) {
        return null;
    }

    return $dt;
}
```

**Änderungen an `SyncProblem`:**

Nach der bestehenden Methode `rateLimited()` zwei neue Factory-Methoden ergänzen:

```php
/** DB-Lebenszeichen gescheitert – der Sync-Dienst funktioniert nicht. */
public static function unavailable(): self
{
    return new self(503, 'unavailable', [], ['Cache-Control' => 'no-store']);
}

/**
 * Geplante Wartung. $until ist optional; wenn gesetzt (immer in der
 * Zukunft, Aufrufer stellt das sicher), wird until als ATOM-UTC-String
 * und Retry-After als Sekunden bis dahin mitgeliefert.
 */
public static function maintenance(?\DateTimeImmutable $until = null): self
{
    $extra = [];
    $headers = ['Cache-Control' => 'no-store'];
    if ($until !== null) {
        $extra['until'] = SyncContract::formatTimestamp($until);
        $headers['Retry-After'] = (string) max(
            1,
            $until->getTimestamp() - time(),
        );
    }

    return new self(503, 'maintenance', $extra, $headers);
}
```

**Hinweis:** `maintenance()` nimmt ein `?\DateTimeImmutable` statt eines Strings. Der Zeitstempel wird pro Request genau einmal geparst (in `SyncContract::parseAtomTimestamp`); `SyncProblem` formatiert ihn nur noch für die Antwort. Das löst das redundante Dreifach-Parsing aus der ersten Planfassung (Terra M1/Gemini M-2).

**Test:** `php backend/bin/phpunit; echo $?` – alle bestehenden Tests müssen grün bleiben. Neue Methoden werden in den folgenden Tasks getestet.

**Commit:**
```
Ergänze ATOM-Parser und SyncProblem-Methoden

SyncContract::parseAtomTimestamp() ist der strikte
Gegenspieler zu formatTimestamp(); Kommando und
Subscriber nutzen ihn. SyncProblem bekommt
unavailable() und maintenance() für die neuen
503-Fehlerformen.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 2: Rate-Limiter `sync_ping` in allen drei Blöcken ergänzen

**Dateien:**
- `backend/config/packages/rate_limiter.yaml`

**Änderungen:**

Im Hauptblock (nach `sync_v1_bytes`) ergänzen:

```yaml
        # Ping-Endpunkt (GET /api/v1/sync/ping): eigenes Per-IP-Limit,
        # getrennt vom Schreib-Limit sync_v1 – der Ping ist read-only.
        sync_ping: { policy: sliding_window, limit: 60, interval: '1 hour' }
```

Im `when@test`-Block ergänzen:

```yaml
            sync_ping: { policy: sliding_window, limit: 1000, interval: '1 hour' }
```

Im `when@dev`-Block ergänzen:

```yaml
            sync_ping: { policy: sliding_window, limit: 1000, interval: '1 hour' }
```

**Test:** `php backend/bin/phpunit; echo $?` – alle bestehenden Tests müssen grün bleiben (der Limiter wird noch nicht konsumiert).

**Commit:**
```
Ergänze Rate-Limiter sync_ping

Eigenes Per-IP-Limit (60/h) für den kommenden
Ping-Endpunkt, getrennt vom Schreib-Limit sync_v1.
Großzügige Limits in Test- und Dev-Umgebung wie bei
allen anderen Limitern.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 3: Ping-Controller `LocatorSyncPingController`

**Dateien:**
- `backend/src/Controller/Api/LocatorSyncPingController.php` (neu)

**Inhalt:**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\SyncContract;
use App\Exception\SyncProblem;
use App\Service\RateLimitGuard;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/v1/sync/ping – Erreichbarkeits- und Feature-Check (Vertrag,
 * Extension-Instruktion 2026-10-09).
 *
 * Kein Body, kein Locator, kein apiLevel. Der Ping sagt nichts über einen
 * Nutzer und braucht nichts von ihm. Ruft LocatorSyncRequest::open() bewusst
 * NICHT auf – der Endpunkt hat kein apiLevel und keinen Locator.
 *
 * »ok« heißt: der Sync-Dienst funktioniert, nicht nur der Webserver lebt.
 * Die DB wird per SELECT 1 berührt; scheitert das, meldet der Ping
 * 503 { "error": "unavailable" }.
 *
 * Einzelroute mit optionalem Slash-Parameter – ohne ihn erzeugt
 * Symfonys Router für GET-Routen einen 301, der bei
 * redirect:"error" als Netzfehler ankäme (E9). Zwei getrennte
 * Routen funktionieren nicht (empirisch belegt, s. E9).
 */
final class LocatorSyncPingController
{
    #[Route('/api/v1/sync/ping{slash}',
        name: 'api_v1_sync_ping',
        requirements: ['slash' => '/?'],
        defaults: ['slash' => ''],
        methods: ['GET'],
    )]
    public function __invoke(
        Request $request,
        Connection $connection,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncPingLimiter,
    ): JsonResponse {
        $guard->consume(
            $syncPingLimiter,
            $request->getClientIp() ?? 'unknown',
            onExhausted: SyncProblem::rateLimited(...),
        );

        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            throw SyncProblem::unavailable();
        }

        $response = new JsonResponse([
            'status' => 'ok',
            'features' => SyncContract::FEATURES,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
```

**Hinweise:**
- `RateLimiterFactoryInterface $syncPingLimiter` – Symfony löst den Parameternamen `syncPingLimiter` automatisch zum Limiter `sync_ping` auf (camelCase-Konvention, kein services.yaml-Bind nötig).
- Die DB-Exception wird im Controller abgefangen. Würde sie durchpropagieren, lieferte `ProblemJsonSubscriber` ein 500 `problem+json` statt der Vertragsform.
- `Cache-Control: no-store` – die Instruktion verlangt es explizit.
- `SyncContract::FEATURES` ist dieselbe Liste wie in `LocatorSyncListController`.
- Der Routenparameter `slash` taucht nicht in der Controller-Signatur auf – Symfony erlaubt das bei Routenparametern mit Default. Der Parameter wird nicht gebraucht, er existiert nur, damit der Matcher beide Pfadvarianten akzeptiert (E9).

**Test:** `php backend/bin/phpunit; echo $?`

**Commit:**
```
Ergänze GET /api/v1/sync/ping

Der Endpunkt meldet Erreichbarkeit und Features des
Sync-Dienstes. Die Extension prüft ihn einmal beim
Einschalten des Abgleichs, damit der Nutzer nicht erst
im Assistenten an einem Timeout scheitert.
Einzelroute mit optionalem Slash, kein 301 (E9).

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 4: Wartungs-Subscriber `SyncMaintenanceSubscriber`

**Dateien:**
- `backend/src/EventSubscriber/SyncMaintenanceSubscriber.php` (neu)
- `backend/config/services.yaml`

**`SyncMaintenanceSubscriber.php`:**

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Api\SyncContract;
use App\Exception\SyncProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wartungsmodus für alle /api/v1/sync/-Endpunkte (inkl. Ping).
 *
 * Greift NUR auf /api/v1/sync/ – nicht auf /api/v1/updates, nicht auf
 * /api/account/sync/*, nicht auf die öffentliche Website. OPTIONS-Preflights
 * bleiben 204 (CorsSubscriber, Priorität 256, läuft vorher).
 *
 * Kein DB-Zugriff: die häufigste Ursache für den Wartungsmodus ist eine
 * DB-Migration; der Subscriber darf dabei nicht selbst scheitern.
 *
 * Gesteuert über eine Flag-Datei, deren Pfad aus dem Container-Parameter
 * app.sync_maintenance_file kommt (var/state/sync-maintenance, auf dem
 * Server per Symlink in shared/state/). Inhalt:
 * optional eine ISO-8601-Zeile mit dem voraussichtlichen Ende (until).
 * Leerer oder ungültiger Inhalt = Wartung ohne until. Abgelaufenes until =
 * Wartung bleibt aktiv, aber ohne until in der Antwort (E2).
 */
final class SyncMaintenanceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $syncMaintenanceFile,
    ) {}

    /**
     * Priorität 240: nach CorsSubscriber (256, OPTIONS → 204) und vor
     * AdminCsrfSubscriber (200), vor dem Router (32) und vor den Controllern
     * (Rate-Limiter). So verbraucht eine Wartungsanfrage kein Limit-Token.
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 240]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Nur /api/v1/sync/ – exakter Präfix, kein Regex.
        if (!str_starts_with($request->getPathInfo(), '/api/v1/sync/')) {
            return;
        }

        // OPTIONS-Preflights sind schon von CorsSubscriber beantwortet
        // (stopPropagation), aber defensiv: falls doch einer durchkommt,
        // nicht mit 503 antworten.
        if ($request->getMethod() === 'OPTIONS') {
            return;
        }

        if (!is_file($this->syncMaintenanceFile)) {
            return;
        }

        // Lesefehler kontrolliert behandeln: Datei vorhanden, aber nicht
        // lesbar → Wartung ohne until. is_readable() statt @-Suppressor:
        // Symfonys ErrorHandler konvertiert die E_WARNING von
        // file_get_contents() in eine ErrorException, die das try/catch
        // zwar fangen würde, aber einen unnötigen Stacktrace loggt.
        // Die Vorprüfung vermeidet den Fehlerpfad im Normalfall; das
        // try/catch bleibt als Sicherheitsnetz für Rennbedingungen
        // (Datei zwischen is_readable und file_get_contents gelöscht).
        if (!is_readable($this->syncMaintenanceFile)) {
            $content = false;
        } else {
            try {
                $content = file_get_contents($this->syncMaintenanceFile);
            } catch (\Throwable) {
                $content = false;
            }
        }

        $until = ($content !== false)
            ? SyncContract::parseAtomTimestamp($content)
            : null;

        // E2: Abgelaufenes until beendet die Wartung NICHT. Der Server
        // antwortet weiterhin 503, aber ohne until und ohne Retry-After.
        if ($until !== null && $until <= new \DateTimeImmutable()) {
            $until = null;
        }

        $problem = SyncProblem::maintenance($until);
        $event->setResponse($problem->toApiResponse());
    }
}
```

**Konfiguration – `services.yaml`** (Parameter im `parameters:`-Block):

```yaml
    app.sync_maintenance_file: '%kernel.project_dir%/var/state/sync-maintenance'
```

**Konfiguration – `services.yaml`** (Hauptblock, nach den bestehenden Service-Definitionen):

```yaml
    App\EventSubscriber\SyncMaintenanceSubscriber:
        arguments:
            $syncMaintenanceFile: '%app.sync_maintenance_file%'
```

Keine Änderung an `.env`: der Pfad ist bewusst keine Env-Variable (siehe E1).

**`when@test`-Block in `services.yaml`** – der Test-Parameter überschreibt den Pfad, damit Tests eine temporäre Datei nutzen können, ohne `shared/` zu berühren. Ergänzung im `when@test: parameters:`-Block:

```yaml
        app.sync_maintenance_file: '%kernel.project_dir%/var/test-sync-maintenance'
```

**Test:** `php backend/bin/phpunit; echo $?` – alle bestehenden Tests müssen grün bleiben (die Flag-Datei existiert in der Test-Umgebung nicht, also greift der Subscriber nie).

**Commit:**
```
Ergänze Wartungs-Subscriber für /api/v1/sync/

Während einer geplanten Wartung antwortet jeder
/api/v1/sync/-Endpunkt mit 503 maintenance, gesteuert
über eine Flag-Datei in shared/state/. OPTIONS bleiben
204, kein DB-Zugriff im Wartungspfad. Abgelaufenes
until beendet die Wartung nicht (E2).

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 5: CLI-Kommandos `index:maintenance:on` / `index:maintenance:off`

**Dateien:**
- `backend/src/Command/MaintenanceOnCommand.php` (neu)
- `backend/src/Command/MaintenanceOffCommand.php` (neu)
- `backend/config/services.yaml`

**`MaintenanceOnCommand.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Api\SyncContract;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * index:maintenance:on – aktiviert den Sync-Wartungsmodus.
 *
 * Schreibt die Flag-Datei, die SyncMaintenanceSubscriber ausliest. Optional
 * mit --until (ISO-8601), damit die Extension "bis ..." anzeigen kann.
 * Ein until in der Vergangenheit wird mit INVALID abgelehnt.
 */
#[AsCommand(
    name: 'index:maintenance:on',
    description: 'Aktiviert den Wartungsmodus für /api/v1/sync/*',
)]
final class MaintenanceOnCommand extends Command
{
    public function __construct(
        private readonly string $syncMaintenanceFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'until',
            null,
            InputOption::VALUE_REQUIRED,
            'Voraussichtliches Ende (ISO-8601, z. B. 2026-10-09T14:00:00+00:00)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $until = $input->getOption('until');
        $content = '';

        if ($until !== null) {
            $dt = SyncContract::parseAtomTimestamp($until);
            if ($dt === null) {
                $io->error(\sprintf(
                    '»%s« ist kein gültiges ISO-8601-Datum mit Offset.',
                    $until,
                ));

                return Command::INVALID;
            }

            if ($dt <= new \DateTimeImmutable()) {
                $io->error(\sprintf(
                    'Das angegebene Datum »%s« liegt in der'
                    . ' Vergangenheit.',
                    $until,
                ));

                return Command::INVALID;
            }

            // In das kanonische UTC-Format normieren.
            $content = SyncContract::formatTimestamp($dt);
        }

        $dir = \dirname($this->syncMaintenanceFile);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            $io->error(\sprintf(
                'Verzeichnis %s konnte nicht angelegt werden.',
                $dir,
            ));

            return Command::FAILURE;
        }

        if (file_put_contents($this->syncMaintenanceFile, $content) === false) {
            $io->error(\sprintf(
                'Flag-Datei %s konnte nicht geschrieben werden.',
                $this->syncMaintenanceFile,
            ));

            return Command::FAILURE;
        }

        if ($content !== '') {
            $io->success(\sprintf('Wartungsmodus aktiviert (bis %s).', $content));
        } else {
            $io->success('Wartungsmodus aktiviert (ohne Zeitangabe).');
        }

        return Command::SUCCESS;
    }
}
```

**`MaintenanceOffCommand.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * index:maintenance:off – deaktiviert den Sync-Wartungsmodus.
 *
 * Löscht die Flag-Datei. Fehlt sie bereits, ist das kein Fehler.
 */
#[AsCommand(
    name: 'index:maintenance:off',
    description: 'Deaktiviert den Wartungsmodus für /api/v1/sync/*',
)]
final class MaintenanceOffCommand extends Command
{
    public function __construct(
        private readonly string $syncMaintenanceFile,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!is_file($this->syncMaintenanceFile)) {
            $io->success('Wartungsmodus war bereits deaktiviert.');

            return Command::SUCCESS;
        }

        error_clear_last();
        if (!unlink($this->syncMaintenanceFile)) {
            $lastError = error_get_last();
            $io->error(\sprintf(
                'Flag-Datei %s konnte nicht gelöscht werden: %s',
                $this->syncMaintenanceFile,
                $lastError['message'] ?? 'unbekannter Fehler',
            ));

            return Command::FAILURE;
        }

        $io->success('Wartungsmodus deaktiviert.');

        return Command::SUCCESS;
    }
}
```

**Konfiguration – `services.yaml`** ergänzen (neben dem Subscriber-Eintrag):

```yaml
    App\Command\MaintenanceOnCommand:
        arguments:
            $syncMaintenanceFile: '%app.sync_maintenance_file%'

    App\Command\MaintenanceOffCommand:
        arguments:
            $syncMaintenanceFile: '%app.sync_maintenance_file%'
```

**Hinweis:** Alternativ könnte `_defaults: bind: { $syncMaintenanceFile: ... }` das explizite Aufzählen sparen. Da die Codebasis bisher keine `bind:`-Einträge hat und nur drei Stellen den Parameter brauchen, bleiben die expliziten Einträge konsistenter mit dem bestehenden Stil.

**Test:** `php backend/bin/phpunit; echo $?`

**Commit:**
```
Ergänze Wartungs-Kommandos on/off

Die Kommandos index:maintenance:on und
index:maintenance:off setzen bzw. löschen die
Flag-Datei, die den Sync-Wartungsmodus steuert.
Optional mit --until; Vergangenheit wird abgelehnt.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 6: Tests – Ping-Endpunkt

**Dateien:**
- `backend/tests/Functional/LocatorSyncPingTest.php` (neu)

**Inhalt:**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;

/**
 * GET /api/v1/sync/ping – Erreichbarkeit und Features.
 */
final class LocatorSyncPingTest extends ApiTestCase
{
    public function testPingReturnsOkWithFeaturesAndNoStore(): void
    {
        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        self::assertSame('no-store', $response->headers->get('Cache-Control'));

        $body = json_decode(
            (string) $response->getContent(),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('ok', $body['status']);
        self::assertSame(SyncContract::FEATURES, $body['features']);
    }

    /** Die Featureliste des Pings ist dieselbe wie in POST /sync/list. */
    public function testPingFeaturesMatchListFeatures(): void
    {
        $this->client->request('GET', '/api/v1/sync/ping');
        $pingFeatures = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
        )['features'];

        $this->api('POST', '/api/v1/sync/list', [
            'apiLevel' => 3,
            'locator' => self::SYNC_LOCATOR,
        ]);
        $listFeatures = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
        )['features'];

        self::assertSame($pingFeatures, $listFeatures);
    }

    /** 429 in der Vertragsform, nicht RFC 7807. */
    public function testPingRateLimitUsesContractShape(): void
    {
        // Den Limiter im Test-Modus auf 1000/h gesetzt; wir konsumieren
        // stattdessen den Limiter-Pool direkt, um den Test deterministisch
        // zu halten, ohne 1000 Requests zu feuern.
        $factory = static::getContainer()->get('limiter.sync_ping');
        $limiter = $factory->create('127.0.0.1');
        $limiter->consume(1000); // Pool erschöpfen

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(429, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        self::assertNotEmpty($response->headers->get('Retry-After'));

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('rate-limited', $body['error']);
    }

    /**
     * E9: Die Slash-Variante liefert denselben Ping, kein 301.
     */
    public function testPingWithTrailingSlashReturnsOkWithoutRedirect(): void
    {
        $this->client->request('GET', '/api/v1/sync/ping/');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertNull(
            $response->headers->get('Location'),
            'Kein Location-Header – kein Redirect',
        );

        $body = json_decode(
            (string) $response->getContent(),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('ok', $body['status']);
        self::assertSame(SyncContract::FEATURES, $body['features']);
    }

    /** E9: HEAD auf /ping/ liefert 200, kein 3xx. */
    public function testHeadOnSlashVariantReturnsOkWithoutRedirect(): void
    {
        $this->client->request('HEAD', '/api/v1/sync/ping/');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertNull(
            $response->headers->get('Location'),
            'Kein Location-Header – kein Redirect',
        );
    }

    /**
     * GET steht in Access-Control-Allow-Methods der öffentlichen API
     * (CorsSubscriber); der Ping erbt das, keine CORS-Änderung nötig.
     */
    public function testPreflightForPingAllowsGet(): void
    {
        $this->client->request('OPTIONS', '/api/v1/sync/ping', server: [
            'HTTP_ORIGIN' => 'moz-extension://test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString(
            'GET',
            (string) $response->headers->get('Access-Control-Allow-Methods'),
        );
    }
}
```

**Test:** `php backend/bin/phpunit; echo $?`

**Commit:**
```
Ergänze Tests für den Ping-Endpunkt

Prüft 200-Antwort mit Features und no-store,
Übereinstimmung mit der Featureliste aus /sync/list,
429-Vertragsform bei erschöpftem Limiter, Slash-
Variante ohne 301 (E9) und CORS-Preflight mit GET.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 7: Tests – Wartungs-Subscriber und Kommandos

**Dateien:**
- `backend/tests/Functional/SyncMaintenanceTest.php` (neu)
- `backend/tests/Command/MaintenanceCommandTest.php` (neu)

**`SyncMaintenanceTest.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Wartungsmodus für /api/v1/sync/*: Flag-Datei steuert den Subscriber.
 */
final class SyncMaintenanceTest extends ApiTestCase
{
    private string $flagFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flagFile = static::getContainer()
            ->getParameter('app.sync_maintenance_file');
        // Sicherstellen, dass kein Flag aus einem früheren Test bleibt.
        if (is_file($this->flagFile)) {
            unlink($this->flagFile);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->flagFile)) {
            @unlink($this->flagFile);
        }
        parent::tearDown();
    }

    private function enableMaintenance(?string $until = null): void
    {
        $dir = \dirname($this->flagFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($this->flagFile, $until ?? '');
    }

    // --- Wartung auf Ping ---

    public function testPingReturns503DuringMaintenance(): void
    {
        $this->enableMaintenance();
        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayNotHasKey('until', $body);
    }

    public function testPingReturns503WithUntilDuringMaintenance(): void
    {
        // +02:00 in der Flag-Datei – die Antwort muss auf +00:00
        // normiert sein (E7, Terra N1).
        $dt = new \DateTimeImmutable('+2 hours', new \DateTimeZone('+02:00'));
        $this->enableMaintenance(
            $dt->format(\DateTimeInterface::ATOM),
        );

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayHasKey('until', $body);
        // Muss auf UTC normiert sein.
        self::assertStringEndsWith('+00:00', $body['until']);
        self::assertNotEmpty($response->headers->get('Retry-After'));
        // Retry-After muss eine positive Zahl sein.
        self::assertGreaterThan(
            0,
            (int) $response->headers->get('Retry-After'),
        );
    }

    // --- Wartung auf ALLEN Sync-Endpunkten (Terra Niedrig) ---

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function syncEndpointProvider(): iterable
    {
        $loc = self::SYNC_LOCATOR;
        $sid = self::SYNC_STATE_ID;

        yield 'POST /sync/list' => ['POST', '/api/v1/sync/list', [
            'apiLevel' => 3, 'locator' => $loc,
        ]];
        yield 'PUT /sync/state' => ['PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => $loc, 'stateId' => $sid,
            'meta' => 'bWV0YQ==', 'payload' => 'cGF5bG9hZA==',
        ]];
        yield 'POST /sync/get' => ['POST', '/api/v1/sync/get', [
            'apiLevel' => 3, 'locator' => $loc, 'stateId' => $sid,
        ]];
        yield 'POST /sync/meta' => ['POST', '/api/v1/sync/meta', [
            'apiLevel' => 3, 'locator' => $loc, 'stateId' => $sid,
            'meta' => 'bWV0YQ==',
        ]];
        yield 'POST /sync/delete' => ['POST', '/api/v1/sync/delete', [
            'apiLevel' => 3, 'locator' => $loc, 'stateId' => $sid,
        ]];
        yield 'GET /sync/ping' => ['GET', '/api/v1/sync/ping', []];
    }

    /**
     * Alle /api/v1/sync/-Endpunkte müssen in Wartung 503 liefern.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('syncEndpointProvider')]
    public function testAllSyncEndpointsReturn503DuringMaintenance(
        string $method,
        string $path,
        array $body,
    ): void {
        $this->enableMaintenance();

        if ($body !== []) {
            $this->api($method, $path, $body);
        } else {
            $this->client->request($method, $path);
        }

        self::assertSame(
            503,
            $this->client->getResponse()->getStatusCode(),
            "$method $path muss in Wartung 503 liefern",
        );
        $json = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
        );
        self::assertSame('maintenance', $json['error']);
    }

    // --- /api/v1/updates bleibt unberührt ---

    public function testUpdatesEndpointIsNotAffectedByMaintenance(): void
    {
        $this->enableMaintenance();
        $this->api('POST', '/api/v1/updates', [
            'apiLevel' => 3,
            'entries' => [],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // --- OPTIONS in Wartung → 204, Allow-Methods enthält GET ---

    public function testOptionsPreflightReturns204DuringMaintenance(): void
    {
        $this->enableMaintenance();
        $this->client->request('OPTIONS', '/api/v1/sync/ping', server: [
            'HTTP_ORIGIN' => 'moz-extension://test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString(
            'GET',
            (string) $response->headers->get('Access-Control-Allow-Methods'),
        );
    }

    // --- E2: Abgelaufenes until => weiter 503 ohne until/Retry-After ---

    public function testExpiredUntilStillReturnsMaintenance(): void
    {
        $past = (new \DateTimeImmutable('-1 hour'))
            ->format(\DateTimeInterface::ATOM);
        $this->enableMaintenance($past);

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayNotHasKey('until', $body);
        self::assertNull($response->headers->get('Retry-After'));
    }

    // --- Ungültiger Dateiinhalt → Wartung ohne until ---

    public function testInvalidFlagContentMeansMaintenanceWithoutUntil(): void
    {
        $this->enableMaintenance('das ist kein datum');

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayNotHasKey('until', $body);
    }

    /** »tomorrow« ist kein ATOM – Wartung ohne until (Terra M1). */
    public function testTomorrowInFlagIsTreatedAsInvalid(): void
    {
        $this->enableMaintenance('tomorrow');

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayNotHasKey('until', $body);
    }

    /**
     * Nullbyte in der Flag-Datei darf keinen 500 erzeugen (Astra 2,
     * PHP 8.5 ValueError in createFromFormat). Muss 503 maintenance
     * ohne until liefern.
     */
    public function testNullbyteInFlagGivesMaintenance(): void
    {
        $this->enableMaintenance(
            "2030-01-01T12:00:00+00:00\x00junk",
        );

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayNotHasKey('until', $body);
    }

    // --- Ohne Flag-Datei kein Wartungsmodus ---

    public function testNoFlagFileMeansNoMaintenance(): void
    {
        // setUp() hat die Datei bereits entfernt.
        $this->client->request('GET', '/api/v1/sync/ping');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // --- Wartung verbraucht kein Rate-Limit (Terra M3, Gemini H-1) ---

    public function testMaintenanceDoesNotConsumeRateLimitTokens(): void
    {
        // Pool bis auf 1 Token erschöpfen – wenn die 10 Wartungs-
        // requests fälschlicherweise Tokens verbrauchen, wäre der
        // Pool leer und der Ping nach der Wartung 429 statt 200.
        $factory = static::getContainer()->get('limiter.sync_ping');
        $factory->create('127.0.0.1')->consume(999);

        $this->enableMaintenance();

        // 10 Requests in Wartung – keiner zählt gegen den Limiter.
        for ($i = 0; $i < 10; $i++) {
            $this->client->request('GET', '/api/v1/sync/ping');
            self::assertSame(503, $this->client->getResponse()->getStatusCode());
        }

        // Wartung beenden – der eine verbleibende Token muss reichen.
        unlink($this->flagFile);
        $this->client->request('GET', '/api/v1/sync/ping');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** E9: Slash-Variante in Wartung liefert 503 ohne Location. */
    public function testSlashVariantReturns503InMaintenance(): void
    {
        $this->enableMaintenance();
        $this->client->request('GET', '/api/v1/sync/ping/');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertNull(
            $response->headers->get('Location'),
            'Kein Location-Header – kein Redirect',
        );
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
    }

    /**
     * Wartung auf Ping liefert application/json, nicht problem+json.
     * Dieser Test läuft über den vollen HTTP-Stack (Subscriber setzt
     * die Response direkt, ProblemJsonSubscriber ist nicht beteiligt).
     */
    public function testMaintenanceResponseIsNotProblemJson(): void
    {
        $this->enableMaintenance();
        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        self::assertStringNotContainsString(
            'problem',
            (string) $response->headers->get('Content-Type'),
        );
    }

    /** Cache-Control: no-store auf der Wartungsantwort (Gemini N-4). */
    public function testMaintenanceResponseHasNoStoreHeader(): void
    {
        $this->enableMaintenance();
        $this->client->request('GET', '/api/v1/sync/ping');

        self::assertSame(
            'no-store',
            $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }
}
```

**`MaintenanceCommandTest.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CLI-Kommandos zum Aktivieren/Deaktivieren des Sync-Wartungsmodus.
 */
final class MaintenanceCommandTest extends KernelTestCase
{
    private string $flagFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->flagFile = static::getContainer()
            ->getParameter('app.sync_maintenance_file');
        if (is_file($this->flagFile)) {
            unlink($this->flagFile);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->flagFile)) {
            @unlink($this->flagFile);
        }
        parent::tearDown();
    }

    private function on(): CommandTester
    {
        return new CommandTester(
            (new Application(self::$kernel))->find('index:maintenance:on'),
        );
    }

    private function off(): CommandTester
    {
        return new CommandTester(
            (new Application(self::$kernel))->find('index:maintenance:off'),
        );
    }

    public function testOnCreatesFlagFile(): void
    {
        $tester = $this->on();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->flagFile);
        self::assertSame('', file_get_contents($this->flagFile));
    }

    public function testOnWithUntilWritesNormalizedTimestamp(): void
    {
        $tester = $this->on();
        $tester->execute(['--until' => '2026-12-31T14:00:00+02:00']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        // Normiert auf UTC:
        self::assertSame(
            '2026-12-31T12:00:00+00:00',
            trim((string) file_get_contents($this->flagFile)),
        );
    }

    public function testOnRejectsInvalidUntil(): void
    {
        $tester = $this->on();
        $tester->execute(['--until' => 'kein-datum']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    /** "tomorrow" ist kein ATOM – wird abgelehnt (Terra M1). */
    public function testOnRejectsTomorrow(): void
    {
        $tester = $this->on();
        $tester->execute(['--until' => 'tomorrow']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    /** ATOM ohne Offset wird abgelehnt (Terra M1). */
    public function testOnRejectsMissingOffset(): void
    {
        $tester = $this->on();
        $tester->execute(['--until' => '2026-12-31T14:00:00']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    /** Unmögliches Datum (Feb 31) wird abgelehnt (Terra M1). */
    public function testOnRejectsImpossibleDate(): void
    {
        $tester = $this->on();
        $tester->execute(['--until' => '2026-02-31T12:00:00+00:00']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    /** E-A: until in der Vergangenheit wird abgelehnt. */
    public function testOnRejectsPastUntil(): void
    {
        $past = (new \DateTimeImmutable('-1 hour'))
            ->format(\DateTimeInterface::ATOM);
        $tester = $this->on();
        $tester->execute(['--until' => $past]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    public function testOffDeletesFlagFile(): void
    {
        // Erst anlegen ...
        $this->on()->execute([]);
        self::assertFileExists($this->flagFile);

        // ... dann entfernen.
        $tester = $this->off();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    public function testOffWhenAlreadyOffIsSuccess(): void
    {
        $tester = $this->off();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * Gemini N-5: Der Fehlerpfad (unlink scheitert → Command::FAILURE)
     * ist bewusst nicht getestet. is_file() als Guard lässt nur echte
     * reguläre Dateien durch; unlink() kann danach nur bei einem
     * Rechte-Entzug zwischen den Aufrufen oder einem Read-only-FS
     * scheitern. Ein Rechte-basierter Test wäre als root wirkungslos
     * (CI, Docker), und ein Verzeichnis unter dem Flag-Pfad fällt am
     * is_file()-Guard vorbei. Der Pfad ist durch Code-Review
     * abgedeckt: error_clear_last() + error_get_last() statt @unlink
     * geben die Ursache weiter.
     */
}
```

**Test:** `php backend/bin/phpunit; echo $?`

**Commit:**
```
Ergänze Tests für Wartungsmodus und Kommandos

Funktionale Tests: 503 auf allen Sync-Endpunkten in
Wartung (Datenprovider-Matrix), 200 auf /updates,
OPTIONS 204, abgelaufenes until, tomorrow, Nullbyte,
Rate-Limit-Invariante, Slash/HEAD ohne 3xx, no-store.
Kommandotests inkl. Offset-Normierung und tomorrow.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 8: Unit-Tests – SyncContract, SyncProblem und DB-Ausfallpfad

**Dateien:**
- `backend/tests/Unit/SyncContractTest.php` (neu)
- `backend/tests/Unit/SyncProblemTest.php` (neu)
- `backend/tests/Unit/LocatorSyncPingControllerTest.php` (neu)

**`SyncContractTest.php`:**

Datengetriebener Test für `parseAtomTimestamp()`. Deckt alle zugesagten
Vertragsfälle ab: gültige Offsets (`+00:00`, `Z`, `+02:00`), ungültige
Eingaben (leer, fehlender Offset, unmögliches Datum, natürliche Sprache,
angehängter Müll, Nullbyte, nur Whitespace) und das Trimming-Verhalten.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Api\SyncContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyncContractTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function atomTimestampProvider(): iterable
    {
        // --- gültig ---
        yield 'UTC +00:00' => ['2026-10-09T12:00:00+00:00', true];
        yield 'UTC Z' => ['2026-10-09T12:00:00Z', true];
        yield 'anderer Offset +02:00' => ['2026-10-09T14:00:00+02:00', true];
        yield 'Whitespace außen (wird getrimmt)' => [
            '  2026-10-09T12:00:00+00:00  ', true,
        ];

        // --- ungültig ---
        yield 'leer' => ['', false];
        yield 'nur Whitespace' => ['   ', false];
        yield 'fehlender Offset' => ['2026-10-09T12:00:00', false];
        yield 'unmögliches Datum (Feb 31)' => [
            '2026-02-31T12:00:00+00:00', false,
        ];
        yield 'natürliche Sprache (tomorrow)' => ['tomorrow', false];
        yield 'angehängter Müll' => [
            '2030-01-01T12:00:00+00:00junk', false,
        ];
        yield 'Nullbyte (PHP 8.5 ValueError)' => [
            "2030-01-01T12:00:00+00:00\x00junk", false,
        ];
    }

    #[DataProvider('atomTimestampProvider')]
    public function testParseAtomTimestamp(
        string $input,
        bool $expectValid,
    ): void {
        $result = SyncContract::parseAtomTimestamp($input);
        if ($expectValid) {
            self::assertInstanceOf(
                \DateTimeImmutable::class,
                $result,
                "Erwartet: gültig für »$input«",
            );
        } else {
            self::assertNull(
                $result,
                "Erwartet: null für »$input«",
            );
        }
    }

    /** +02:00 → UTC-normierte Ausgabe über formatTimestamp(). */
    public function testParseAtomTimestampNormalizesToUtc(): void
    {
        $dt = SyncContract::parseAtomTimestamp(
            '2026-10-09T14:00:00+02:00',
        );
        self::assertNotNull($dt);
        self::assertSame(
            '2026-10-09T12:00:00+00:00',
            SyncContract::formatTimestamp($dt),
        );
    }
}
```

**`SyncProblemTest.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\SyncProblem;
use PHPUnit\Framework\TestCase;

/**
 * Vertragsform der SyncProblem-Antworten: { "error": "<code>" } als
 * application/json, nicht RFC 7807 problem+json.
 */
final class SyncProblemTest extends TestCase
{
    public function testUnavailableReturns503WithContractShape(): void
    {
        $response = SyncProblem::unavailable()->toApiResponse();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        self::assertSame(
            ['error' => 'unavailable'],
            json_decode((string) $response->getContent(), true),
        );
        self::assertSame('no-store', $response->headers->get('Cache-Control'));
    }

    public function testMaintenanceWithoutUntilReturns503(): void
    {
        $response = SyncProblem::maintenance()->toApiResponse();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['error' => 'maintenance'],
            json_decode((string) $response->getContent(), true),
        );
        self::assertNull($response->headers->get('Retry-After'));
        self::assertSame('no-store', $response->headers->get('Cache-Control'));
    }

    public function testMaintenanceWithUntilIncludesFieldAndRetryAfter(): void
    {
        $until = new \DateTimeImmutable('+2 hours');
        $response = SyncProblem::maintenance($until)->toApiResponse();

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayHasKey('until', $body);
        self::assertStringEndsWith('+00:00', $body['until']);
        self::assertGreaterThan(
            0,
            (int) $response->headers->get('Retry-After'),
        );
    }
}
```

**`LocatorSyncPingControllerTest.php`:**

Unit-Test für den DB-Ausfallpfad. `Doctrine\DBAL\Connection` ist nicht `final` (geprüft in `doctrine/dbal` der installierten Version) und kann mit PHPUnit gemockt werden.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\Api\LocatorSyncPingController;
use App\Exception\SyncProblem;
use App\Service\RateLimitGuard;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\LimiterInterface;

/**
 * Prüft den try/catch um SELECT 1: ein DB-Fehler muss als
 * SyncProblem::unavailable (503, "unavailable") geworfen werden,
 * nicht als unkontrollierte Exception, die ProblemJsonSubscriber zu
 * einem 500 problem+json machen würde (Terra M2, Gemini H-2).
 */
final class LocatorSyncPingControllerTest extends TestCase
{
    public function testDbFailureThrowsSyncProblemUnavailable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')
            ->willThrowException(new \RuntimeException('DB down'));

        $limiter = $this->createMock(LimiterInterface::class);
        $rateLimit = $this->createMock(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);
        $limiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        $guard = new RateLimitGuard();

        $controller = new LocatorSyncPingController();

        $this->expectException(SyncProblem::class);

        try {
            $controller(
                new Request(),
                $connection,
                $guard,
                $factory,
            );
        } catch (SyncProblem $e) {
            self::assertSame(503, $e->getStatusCode());
            self::assertSame('unavailable', $e->errorCode);
            throw $e;
        }
    }
}
```

**Test:** `php backend/bin/phpunit; echo $?`

**Commit:**
```
Ergänze Unit-Tests für Parser, SyncProblem, Ping

SyncContractTest sichert parseAtomTimestamp()
datengetrieben ab (gültig/ungültig inkl. Nullbyte).
SyncProblemTest prüft die Vertragsform der neuen
Factory-Methoden. LocatorSyncPingControllerTest
belegt den DB-Ausfallpfad mit gemockter Connection.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 9: `deploy/deploy.sh`, `deploy/common.sh`, GC-Test, Shell-Syntaxprüfung

**Dateien:**
- `deploy/deploy.sh`
- `deploy/common.sh`
- `deploy/tests/gc-test.sh`

**Änderung an `deploy/deploy.sh`:** In der Remote-Sektion zwei Stellen anpassen (Zeilen vorher mit `grep -n 'SHARED/log' deploy/deploy.sh` ermitteln, nicht auf Zeilennummern verlassen):

1. Das bestehende `mkdir -p "$SHARED/media" "$SHARED/log"` erweitern:

```bash
mkdir -p "$SHARED/media" "$SHARED/log" "$SHARED/state"
```

2. Direkt nach der Zeile, die `var/log` verlinkt (`mkdir -p "$RELEASE/backend/var"; rm -rf "$RELEASE/backend/var/log"; ln -sfn "$SHARED/log" "$RELEASE/backend/var/log"`), eine weitere Zeile im selben Muster:

```bash
rm -rf "$RELEASE/backend/var/state"; ln -sfn "$SHARED/state" "$RELEASE/backend/var/state"
```

**Änderung an `deploy/common.sh`:** Den Layout-Kommentar von `$DEPLOY_PATH/shared/{.env.local,media,log}` auf `{.env.local,media,log,state}` erweitern.

**Änderung an `deploy/tests/gc-test.sh`:**

1. `setup_shared` erweitern – nach `echo "log-zeile" > "$1/shared/log/prod.log"`:

```bash
mkdir -p "$1/shared/state"
echo "2026-12-31T12:00:00+00:00" > "$1/shared/state/sync-maintenance"
```

2. `release_with_shared` erweitern – nach `ln -sfn "$1/shared/log" "$1/releases/$2/backend/var/log"`:

```bash
ln -sfn "$1/shared/state" "$1/releases/$2/backend/var/state"
```

3. `expect_shared_intact` erweitern – die Prüfung wird um `shared/state/sync-maintenance` ergänzt:

```bash
expect_shared_intact() {
    local root="$1"
    if [ -f "$root/shared/.env.local" ] \
       && [ -f "$root/shared/media/beispiel.webp" ] \
       && [ -f "$root/shared/log/prod.log" ] \
       && [ -f "$root/shared/state/sync-maintenance" ]; then
        echo "OK    $CASE (shared/ intakt)"
    else
        echo "FEHLT $CASE (shared/ wurde durch rm -rf eines Releases beschädigt)"; fail=1
    fi
}
```

**Testschritte:**

```bash
php backend/bin/phpunit; echo $?
deploy/tests/gc-test.sh; echo $?
bash -n deploy/deploy.sh
bash -n deploy/smoke.sh
bash -n deploy/rollback.sh
```

**Commit:**
```
Lege shared/state/ beim Deploy an

Das Verzeichnis nimmt die Wartungs-Flag-Datei auf,
analog zu shared/media/ und shared/log/. gc-test.sh
prüft den Erhalt nach Garbage Collection.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 10: `deploy/smoke.sh` und `deploy/rollback.sh` – Ping-Check, Legacy-Toleranz

**Dateien:**
- `deploy/smoke.sh`
- `deploy/rollback.sh`
- `deploy/README.md`

**Änderung an `deploy/smoke.sh`:** Nach dem Block für `GET /de/vergleich` (vor der Fehlschlag-Auswertung `if [ "$fail" -ne 0 ]`) einen neuen Check einfügen:

```bash
request ping "$ORIGIN/api/v1/sync/ping"
# Verankerte Muster (ganzer Body): JsonResponse liefert kompaktes JSON ohne
# Whitespace und ohne Slash-Escaping (Standardflags). Die Muster sind
# empirisch gegen die im Plan beschriebenen JsonResponse-Aufrufe verifiziert.
# 200: {"status":"ok","features":["sync-meta"]} (oder andere/leere Features)
# 503: {"error":"maintenance"} oder {"error":"maintenance","until":"..."}
if [ "$STATUS" = 200 ] && grep -Eq '^\{"status":"ok","features":\[("[a-z-]+"(,"[a-z-]+")*)?]\}$' "$BODY"; then
    ok "GET /api/v1/sync/ping: 200 mit gültigem Ping-Body"
elif [ "$STATUS" = 503 ] && grep -Eq '^\{"error":"maintenance"(,"until":"[^"]+")?\}$' "$BODY"; then
    echo "WARN  GET /api/v1/sync/ping: 503 maintenance – Wartungsmodus ist aktiv"
elif [ "$STATUS" = 404 ] && [ "${SMOKE_LEGACY:-}" = 1 ]; then
    echo "WARN  GET /api/v1/sync/ping: 404 – Rollback auf Release ohne Ping-Endpunkt (Legacy)"
elif [ "$STATUS" = 404 ]; then
    bad "GET /api/v1/sync/ping: 404 – Endpunkt fehlt im Release"
else
    bad "GET /api/v1/sync/ping: Status $STATUS, Body: $(head -c 200 "$BODY")"
fi
```

**Hinweise zum Smoke-Verhalten:**
- `200` mit gültigem Ping-Body (verankertes Muster) → OK (Normalfall).
- `503` mit exaktem Maintenance-Body → Warnung, `fail` bleibt 0 (Deploy während Wartung möglich, E8).
- `404` ohne `SMOKE_LEGACY=1` → Fehler (der Endpunkt muss im aktuellen Release existieren).
- `404` mit `SMOKE_LEGACY=1` → Warnung (Rollback auf Vorfeature-Release, E8).
- Alles andere (auch `503` mit anderem Body wie `{"error":"unavailable","message":"maintenance"}`) → Fehler.

**Warum verankerte Muster statt unabhängiger greps:** Die bisherige Prüfung suchte nur nach Teilstrings (`"status":"ok"` und `"features"` bzw. `"maintenance"`) und ließ semantisch falsche Bodies durch – z. B. hätte `{"error":"unavailable","message":"maintenance"}` als harmlose Wartungswarnung gegolten (Terra M, Review 2). Die verankerten Muster (`^…$`) prüfen den GANZEN Body auf exakte Schlüsselreihenfolge und Form. Kein `php85` nötig – `smoke.sh` läuft lokal per `curl` + `grep -E` gegen die Live-URL.

**Änderung an `deploy/rollback.sh`:** Vor dem `smoke.sh`-Aufruf (Zeile 61) die Umgebungsvariable setzen:

```bash
step "Smoke-Check"
SMOKE_LEGACY=1 deploy/smoke.sh "$SITE_ORIGIN" || die "Smoke-Check nach Rollback fehlgeschlagen – current zeigt auf $TARGET"
```

Das `SMOKE_LEGACY=1` ist inline vor dem Aufruf gesetzt (Shell-Konvention: gilt nur für diesen einen Befehl). So behandelt `smoke.sh` einen `404` am Ping als Legacy-Warnung statt als Fehler – ein Rollback auf ein Release ohne Ping-Endpunkt ist ein legitimer Notfall.

**Änderung an `deploy/README.md`:** Die `Bekannte Lücke`-Zeile (Zeile 11, im Bullet für `smoke.sh`) ersetzen durch:

```
  > **Teilweise geschlossen:** `smoke.sh` prüft jetzt `GET /api/v1/sync/ping` auf Erreichbarkeit, DB-Verbindung und Features. Die funktionalen Locator-Sync-Wege (`/sync/list`, `/sync/get`, `/sync/state`, `/sync/meta`, `/sync/delete`) bleiben außerhalb des Smoke-Checks und sind durch PHPUnit abgedeckt.
```

**Testschritte:**

```bash
php backend/bin/phpunit; echo $?
bash -n deploy/smoke.sh
bash -n deploy/rollback.sh
```

**Commit:**
```
Ergänze Ping-Check in smoke.sh und rollback.sh

smoke.sh prüft /api/v1/sync/ping: 200 ist OK, 503
maintenance ist eine Warnung (Deploy in Wartung), 404
ist ein Fehler außer beim Rollback (SMOKE_LEGACY=1).

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 11: `deploy/README.md` – Wartungs-Betrieb dokumentieren

**Dateien:**
- `deploy/README.md`

**Änderungen:**

1. In der Layout-Aufzählung (Zeile 23, `shared/.env.local  shared/media/  shared/log/`) um `shared/state/` ergänzen.

2. Einen neuen Abschnitt **Wartungsmodus (Sync)** nach dem Abschnitt `Locator-Sync der Extension` (vor `Schaltbare Seiten`) ergänzen:

```markdown
### Wartungsmodus (Sync)

Während einer geplanten Wartung (DB-Migration, längerer Ausfall) antwortet jeder `/api/v1/sync/*`-Endpunkt mit `503 {"error":"maintenance"}`. Der Modus wird über eine Flag-Datei in `shared/state/sync-maintenance` gesteuert, nicht über die Datenbank – so funktioniert er auch bei DB-Ausfall. Die Datei überlebt Deploys und Rollbacks (per Symlink in `shared/state/`).

Ein-/Ausschalten per SSH:

```bash
# Wartungsmodus aktivieren (ohne Zeitangabe):
cd ~/current/backend && php85 bin/console index:maintenance:on

# Wartungsmodus aktivieren mit voraussichtlichem Ende:
cd ~/current/backend && php85 bin/console index:maintenance:on --until='2026-10-09T14:00:00+00:00'

# Wartungsmodus beenden:
cd ~/current/backend && php85 bin/console index:maintenance:off
```

**Abgelaufenes `until`:** Die Wartung bleibt aktiv, auch wenn `until` in der Vergangenheit liegt – der Server antwortet dann weiterhin `503 maintenance`, aber ohne `until` und ohne `Retry-After`. Beendet wird sie nur durch `index:maintenance:off`.

**Deploy während Wartung:** `smoke.sh` gibt bei aktivem Wartungsmodus eine Warnung aus, bricht aber NICHT ab (`fail` bleibt 0). Ein Deploy während einer geplanten DB-Migration ist damit möglich.

**Rollback auf Vorfeature-Release:** Ein Rollback auf ein Release ohne den Ping-Endpunkt (vor diesem Feature) führt dazu, dass Subscriber und Symlink fehlen – die Wartung greift nicht, die Extension sieht `404` am Ping und meldet den Dienst als »nicht erreichbar«. `rollback.sh` setzt `SMOKE_LEGACY=1`, damit `smoke.sh` den `404` als Legacy-Warnung behandelt.
```

**Test:** `php backend/bin/phpunit; echo $?`

**Commit:**
```
Dokumentiere Wartungsmodus in deploy/README

Beschreibt Ein-/Ausschalten per SSH, das Verhalten
von smoke.sh in Wartung und bei Rollback, sowie die
Semantik des abgelaufenen until.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
```

---

### Task 12: Nach dem Livegang – Rückmeldung an die Extension

**Bewusst NICHT automatisiert.** Ausführen erst nach Release-Tag, Deploy, bestätigtem `200` von `https://gestura.eu/api/v1/sync/ping` und grünem `deploy/smoke.sh`-Lauf.

**Aktion:** Eintrag in `exchange/AUSTAUSCH.md` (oben, neuester Eintrag zuerst), im Stil der vorhandenen Einträge. Der Ordner `exchange/` ist gitignoriert (`.gitignore:33`, `CLAUDE.md:13`) – der Eintrag bleibt lokal und unversioniert, kein `git add -f`. Vorformulierter Wortlaut:

```markdown
## <DATUM> · ← Index · Ping live

`GET /api/v1/sync/ping` antwortet auf `https://gestura.eu` wie vereinbart:
`200` mit `{"status":"ok","features":["sync-meta"]}` nach DB-Lebenszeichen,
`503 {"error":"unavailable"}` bei DB-Ausfall, `503 {"error":"maintenance"}`
(mit optionalem `until` und `Retry-After`) bei geplanter Wartung. Eigener
Limiter 60/h, `Cache-Control: no-store`, kein `3xx` (Einzelroute mit
optionalem Slash). OPTIONS auch in Wartung weiterhin `204`.

Release-Tag: `<TAG>`, Commits: `<HASH-BEREICH>`.

**Erwartet zurück:** nichts – der Vertragstext kann jetzt im Extension-Repo
eingetragen werden.
```

`<DATUM>`, `<TAG>` und `<HASH-BEREICH>` werden zum Zeitpunkt des Eintrags aus dem Deploy abgeleitet.

**Kein Commit** – `exchange/` ist gitignoriert.

---

## Reihenfolge und Abhängigkeiten

```
Task 1 (SyncContract + SyncProblem)
  |
  +---> Task 2 (Limiter)           [unabhängig von Task 1]
  |       |
  |       +---> Task 3 (Ping-Controller, braucht 1 + 2)
  |
  +---> Task 4 (Subscriber + Config, braucht 1)
          |
          +---> Task 5 (Kommandos, braucht 1 + 4)
                  |
                  +---> Task 6 (Ping-Tests, braucht 3)
                  |       |
                  |       +---> Task 7 (Wartungs-/Kommando-Tests, braucht 4 + 5)
                  |               |
                  |               +---> Task 8 (Unit-Tests, braucht 1 + 3)
                  |
                  +---> Task 9 (deploy.sh + gc-test.sh, braucht 4)
                          |
                          +---> Task 10 (smoke.sh + rollback.sh, braucht 3 + 4)
                                  |
                                  +---> Task 11 (README, braucht 9 + 10)

Task 12 (AUSTAUSCH.md): manuell nach Deploy, kein Commit (exchange/ gitignoriert)
```

Tasks 1 und 2 sind voneinander unabhängig und könnten parallel laufen. Ab Task 3 baut jeder auf den vorherigen auf. Tasks 9–11 sind reine Deploy-/Doku-Änderungen und voneinander weitgehend unabhängig, hängen aber inhaltlich an den vorherigen. Task 12 ist ein manueller Prozessschritt nach dem Deploy – kein Commit, kein Git-Bezug.

## Offene Punkte für den Nutzer

Keine – alle Entscheidungen sind aus der Abstimmung, den bestehenden Konventionen und den Nutzerentscheidungen (E-A, E-B, E-C) ableitbar. Der Plan ist allein aus HEAD `7b7c08a` reproduzierbar.

---

## Review-Runde 1 (Eingearbeitet)

Vier Reviews: Terra, Gemini, Astra, Gemini-Risiko. Alle am 2026-10-09 gegen HEAD `7b7c08a`.

| Befund | Schwere | Entscheidung |
|--------|---------|--------------|
| **Terra H1** – Rollback auf Alt-Release bricht Smoke | Hoch | Umgesetzt: E1/E8 eingeschränkt, `SMOKE_LEGACY=1` in rollback.sh, Runbook in Task 11 |
| **Terra M1** – strtotime() nicht strikt | Mittel | Umgesetzt: `SyncContract::parseAtomTimestamp()` in Task 1, wird von Kommando und Subscriber verwendet |
| **Terra M2** – DB-Ausfallpfad ungetestet | Mittel | Umgesetzt: Unit-Test `LocatorSyncPingControllerTest` in Task 8 mit gemockter Connection |
| **Terra M3** – Rate-Limit-Test ineffektiv | Mittel | Umgesetzt: Pool in Task 7 bis auf 1 Token erschöpft, Test wird rot bei falschem Verhalten |
| **Terra M4** – Abschlussmeldung fehlt | Mittel | Umgesetzt: neuer Task 12 mit vorformuliertem AUSTAUSCH.md-Eintrag |
| **Terra M5** – gc-test.sh deckt state/ nicht ab | Mittel | Umgesetzt: gc-test.sh-Erweiterung in Task 9, bash -n-Prüfungen ergänzt |
| **Terra N1** – UTC-Normierung ungetestet | Niedrig | Umgesetzt: Test mit +02:00-Offset in Task 7, exakter Body-Wert und Retry-After geprüft |
| **Terra N2** – Bekannte Lücke zu früh geschlossen | Niedrig | Umgesetzt: präzisierter Text in Task 10 (Ping geprüft, funktionale Wege durch PHPUnit) |
| **Terra N3** – is_file() vs file_exists() | Niedrig | Umgesetzt: überall is_file() im Subscriber und Off-Kommando; Lesefehler ohne @ behandelt |
| **Gemini H-1** – Rate-Limit-Test ineffektiv | Hoch | Identisch mit Terra M3, umgesetzt |
| **Gemini H-2** – DB-Ausfallpfad ungetestet | Hoch | Identisch mit Terra M2, umgesetzt. Alter Task-8-Test (`toApiResponse()` direkt) ersetzt durch saubere Unit-Tests |
| **Gemini H-3** – smoke.sh blockiert Deploy in Wartung | Hoch | Umgesetzt: 503 maintenance ist Warnung, nicht Fehler (E8, E-B) |
| **Gemini M-1** – strtotime akzeptiert Vergangenheit | Mittel | Umgesetzt: INVALID bei --until in der Vergangenheit (E-A), strikter Parser |
| **Gemini M-2** – Redundantes Parsing | Mittel | Umgesetzt: `SyncProblem::maintenance` nimmt `?\DateTimeImmutable`, ein Parse pro Request |
| **Gemini M-3** – Bekannte Lücke unvollständig | Mittel | Identisch mit Terra N2, umgesetzt |
| **Gemini N-1** – is_file() vs file_exists() | Niedrig | Identisch mit Terra N3, umgesetzt |
| **Gemini N-2** – Unbenutzter Import JsonResponse | Niedrig | Umgesetzt: Import im Subscriber-Code entfernt |
| **Gemini N-3** – Fehlende Pfadangabe in CLI-Beispielen | Niedrig | Umgesetzt: `cd ~/current/backend && php85 ...` wie im Rest der Datei |
| **Astra 1** – Abgelaufenes until schaltet Wartung ab | – | Umgesetzt: E2 komplett umgeschrieben (E-A), Wartung bleibt aktiv |
| **Astra 2** – Trailing Slash erzeugt 301 | – | Umgesetzt: Einzelroute mit optionalem Slash (E9), Tests für beide Pfade und HEAD |
| **Gemini-Risiko 1** – Abgelaufenes until | – | Identisch mit Astra 1, umgesetzt |
| **Gemini-Risiko 2** – Trailing Slash 301 | – | Identisch mit Astra 2, umgesetzt (Runde 2 korrigiert: Einzelroute statt Mehrfach-Route) |

Bewusst NICHT umgesetzt: keine Befunde. Alle Findings der vier Reviews waren korrekt und sind eingearbeitet.

---

## Review-Runde 2 (Eingearbeitet)

Vier Reviews: Terra, Gemini, Astra, Gemini-Risiko. Alle am 2026-10-09 gegen HEAD `7b7c08a` und den Plan nach Runde 1.

| Befund | Schwere | Entscheidung / Task |
|--------|---------|---------------------|
| **Astra 1** – Zwei Ping-Routen verhindern 301 nicht | Kritisch (empirisch belegt) | Umgesetzt: E9 komplett umgeschrieben auf Einzelroute mit optionalem Slash `{slash}`. Empirisch verifiziert: kein 3xx auf GET, HEAD, /ping, /ping/. Die Mehrfach-Route aus Runde 1 versagt (Matcher-Tabelle in E9). Gemini-Risiko 2 und Gemini (allg.) hatten die Mehrfach-Route fälschlich als wirksam bewertet – widerlegt. Tasks 3, 6, 7 angepasst. |
| **Astra 2** – Nullbyte in Flag-Datei erzeugt ValueError → 500 | Kritisch (reproduziert) | Umgesetzt: `parseAtomTimestamp()` lehnt Steuerzeichen (inkl. Nullbytes) vorab per `preg_match` ab und fängt `\ValueError` als Sicherheitsnetz. Neuer Funktionstest `testNullbyteInFlagGivesMaintenance` in Task 7. Neuer Unit-Test-Fall im `SyncContractTest` (Task 8). |
| **Terra M** – Smoke-Check akzeptiert ungültige Antworten | Mittel | Umgesetzt: Task 10 nutzt verankerte `grep -E`-Muster auf den ganzen Body. Empirisch verifiziert: `{"error":"unavailable","message":"maintenance"}` wird NICHT als Wartungswarnung akzeptiert. Kein `php85` nötig. |
| **Terra M** – Parser nicht direkt getestet | Mittel | Umgesetzt: Neuer datengetriebener `SyncContractTest` in Task 8 (gültig: `+00:00`, `Z`, `+02:00`; ungültig: leer, fehlender Offset, Feb 31, tomorrow, Müll, Nullbyte). |
| **Terra M** – strtotime-Behauptung falsch | Mittel | Umgesetzt: »ersetzt alle strtotime()-Aufrufe« korrigiert zu »wird von Kommando und Subscriber verwendet« (grep: kein strtotime im Bestand). |
| **Terra M** – Task 12 Commit unmöglich | Mittel | Umgesetzt: Commit-Abschnitt entfernt, ausdrücklicher Hinweis: `exchange/` ist gitignoriert, Eintrag bleibt lokal. |
| **Terra Niedrig** – Wartung nicht auf allen Sync-Endpunkten getestet | Niedrig | Umgesetzt: Datenprovider-Matrix in Task 7 mit allen 6 Endpunkten (list, state, get, meta, delete, ping). |
| **Terra Niedrig** – Typografie | Niedrig | Umgesetzt: Vollständiger Durchgang über die gesamte Datei (s. Typografie-Abschnitt unten). |
| **Gemini M-1** – Task 12 Commit scheitert an .gitignore | Mittel | Identisch mit Terra M (Task 12), umgesetzt. |
| **Gemini M-2** – Typografie | Mittel | Identisch mit Terra Niedrig, umgesetzt. |
| **Gemini N-1** – file_get_contents-Warning ohne @ | Niedrig | Umgesetzt: `is_readable()`-Vorprüfung im Subscriber (Task 4), `try/catch` als Sicherheitsnetz für Rennbedingungen. Begründung: Symfonys ErrorHandler konvertiert `E_WARNING` zu `ErrorException`. |
| **Gemini N-2** – `@unlink` in MaintenanceOffCommand | Niedrig | Umgesetzt: `@` entfernt, `error_clear_last()` + `error_get_last()` geben die Systemursache weiter (Task 5). |
| **Gemini N-3** – AUSTAUSCH.md-Überschrift-Stil | Niedrig | Umgesetzt: `## <DATUM> · ← Index · Ping live` mit Mittelpunkt und typografischem Pfeil (Task 12). |
| **Gemini N-4** – `Cache-Control: no-store` auf 503 | Niedrig | Umgesetzt: `SyncProblem::maintenance()` und `unavailable()` setzen `Cache-Control: no-store` (Task 1). Tests in Task 7 und 8 prüfen den Header. |
| **Gemini N-5** – Fehlerpfad unlink nicht getestet | Niedrig | Bewusst nicht getestet: `is_file()`-Guard lässt nur reguläre Dateien durch; ein Rechte-Test wäre als root wirkungslos. Dokumentiert als Kommentar in Task 7. |
| **Gemini-Risiko** – »Trifft nicht zu« (kein Vertragsbruch) | – | Bestätigt. Korrekturen an der Mehrfach-Route (Astra 1) stärken das Ergebnis. Gemini-Risiko 2 hatte die Mehrfach-Route fälschlich als wirksam bewertet – empirisch widerlegt (s. Astra 1). |
