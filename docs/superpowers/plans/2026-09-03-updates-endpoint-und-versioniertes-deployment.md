# `POST /api/v1/updates` und versioniertes Deployment – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Den Update-Check der Extension (Vertrag R2, apiLevel 2) unter `POST /api/v1/updates` vertragskonform bereitstellen und das Deployment so umbauen, dass `gestura.eu` API und Frontend aus einem gemeinsamen, versionierten Docroot ausliefert.

**Architecture:** Der vorhandene `UpdateCheckController` wird auf Pfad und Format des Vertrags umgebaut (Batch-Lookup, drei Antwortbedingungen, `url` aus Schema+Host des Requests). Die `.htaccess` in `backend/public/` wird zur einzigen Regelquelle für API, Marketing-Interceptor und statisches Frontend. Das Deployment wechselt auf Releases (`releases/<tag>/`, `shared/`, `current`-Symlink) mit annotierten Git-Tags als Auslöser, `rollback.sh`, `smoke.sh` und einer Garbage Collection mit Fünf-plus-eins-Regel.

**Tech Stack:** Symfony 7.4 (PHP 8.5), PHPUnit, Apache `.htaccess` (mod_rewrite), Bash (`set -euo pipefail`), rsync, ssh, curl. SvelteKit `adapter-static` liefert den Frontend-Build.

**Spec:** `docs/superpowers/specs/2026-09-03-updates-endpoint-und-versioniertes-deployment-design.md`

## Global Constraints

- Server-PHP heißt `php85`, Composer liegt unter `/usr/bin/composer`; Deploy-Host `ssh-w00d7b19@85.13.135.147`, Deploy-Pfad `/www/htdocs/w00d7b19/gestura.eu` (aus dem heutigen `deploy.sh`).
- Tests immer mit Exit-Code prüfen: `php backend/bin/phpunit; echo $?` (Suite läuft mit `failOnDeprecation`, kann »OK« drucken und trotzdem 1 liefern).
- `RateLimiterFactoryInterface` typehinten, Parametername = Limiter-Name mit Suffix `Limiter` (z. B. `$updateCheckLimiter`). Neue Limiter in allen drei Blöcken von `backend/config/packages/rate_limiter.yaml` ergänzen (`framework`, `when@test`, `when@dev`).
- Texte in Kommentaren, Doku und Commit-Messages auf Deutsch mit Guillemets »…« und Halbgeviertstrich –; UTF-8 mit echten Umlauten. Code-Bezeichner Englisch.
- Commit-Format: Subject im Imperativ (max. 50, Ausnahme 72 Zeichen), Leerzeile, Body mit Begründung (max. 72 Zeichen pro Zeile). Kein Mantis-Ticket, daher kein `[NNNN]`-Prefix.
- Kein Push, kein Deploy, keine Änderung am Server im Rahmen dieses Plans. Der Runbook-Lauf (Spec 4.8) erfolgt durch den Eigentümer nach Abschluss.
- `frontend/src/lib/paraglide/` ist Build-Artefakt und gitignored; nie committen.
- Scratch-Dateien nur unterhalb des Scratchpad-Verzeichnisses der Session, nicht in `/tmp`.
- Nach Recipe-Updates von `symfony/framework-bundle` die zusammengeführte `.htaccess` neu prüfen (recipe-managed).

## Dateistruktur

| Datei | Verantwortung |
| --- | --- |
| `docs/gestura-eu-api.md` (neu) | Kopie des Vertrags mit Kopfhinweis |
| `backend/config/packages/rate_limiter.yaml` | Limiter `update_check` in drei Blöcken |
| `backend/tests/Unit/UpdateCheckLimiterTest.php` (neu) | Isolierter Drosseltest nach Muster `SyncWriteLimiterTest` |
| `backend/src/Controller/Api/UpdateCheckController.php` | Endpunkt `POST /api/v1/updates` |
| `backend/tests/Functional/UpdateCheckTest.php` | Vollständig ersetzt: Vertragsverhalten |
| `backend/tests/Functional/CorsTest.php` | Preflight-Test für `/api/v1/updates` |
| `backend/config/services.yaml`, `backend/.env` | `FRONTEND_BUILD_DIR` |
| `backend/public/.htaccess` | Zusammengeführte Regeln (einzige Quelle) |
| `frontend/static/.htaccess` | Gelöscht |
| `deploy/common.sh` (neu) | Host, Pfad, `die`, `remote`, `step` |
| `deploy/smoke.sh` (neu) | Vertrags- und Layout-Prüfung gegen eine Origin |
| `deploy/gc.sh` (neu) | Garbage Collection, läuft lokal gegen `--root` (auf dem Server per `bash -s`) |
| `deploy/tests/gc-test.sh` (neu) | Bash-Test der Aufbewahrungsregeln |
| `deploy/deploy.sh` | Tag-basiertes Release-Deployment |
| `deploy/rollback.sh` (neu) | Symlink auf früheres Release |
| `deploy/README.md`, `CLAUDE.md`, `.claude/lessons.md` | Dokumentation |

---

### Task 1: Vertragskopie ins Repo

**Files:**
- Create: `docs/gestura-eu-api.md`

**Interfaces:**
- Produces: die im Repo referenzierbare Vertragsfassung; spätere Tasks und Doku verweisen auf `docs/gestura-eu-api.md`.

- [ ] **Step 1: Kopie mit Kopfhinweis erzeugen**

```bash
{
  printf '%s\n' '> **Kopie.** Original: `docs/gestura-eu-api.md` im Extension-Repo (`C:\Programme.alt\Gestura`, aus WSL `/mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md`). Änderungen werden dort gemacht und neu herüberkopiert – hier nie direkt ändern. Bei Abweichungen zwischen Vertrag und Index: im Extension-Repo melden. Stand der Kopie: 2026-09-03, Branch `feature/eu-integration-r3`, Commit `4c9f2bb`, apiLevel 3. Der Index setzt die Level additiv um: `/api/v1/updates` (Level 2) mit dem Paket vom 2026-09-03, die `/api/v1/sync/*`-Endpunkte (Level 3) folgen als eigenes Paket.' ''
  cat exchange/2026-09-03-gestura-eu-api.md
} > docs/gestura-eu-api.md
```

- [ ] **Step 2: Prüfen, dass der Rumpf byte-identisch zur Übergabe ist**

Run: `tail -n +3 docs/gestura-eu-api.md | diff - exchange/2026-09-03-gestura-eu-api.md && echo IDENTISCH`
Expected: `IDENTISCH` (kein Diff).

- [ ] **Step 3: Commit**

```bash
git add docs/gestura-eu-api.md
git commit -F - <<'EOF'
Übernimm den Vertrag gestura.eu ↔ Extension (Stand R3) als Kopie

Der Vertrag wird im Extension-Repo gepflegt; die Kopie hier ist der
Stand, gegen den der Index gebaut und getestet wird. Sie enthält bereits
die Sync-Hälfte (apiLevel 3), obwohl dieses Paket nur den Level-2-Teil
umsetzt – die Level sind additiv, und es soll genau eine Vertragsfassung
im Repo liegen. Der Kopfhinweis verhindert, dass jemand die Kopie für
das Original hält.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
```

---

### Task 2: Rate-Limiter `update_check`

**Files:**
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Unit/UpdateCheckLimiterTest.php`

**Interfaces:**
- Produces: Limiter-Service, in Controllern per `RateLimiterFactoryInterface $updateCheckLimiter` autowirebar (Symfony leitet den Alias aus dem Limiter-Namen ab).

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Service\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Yaml\Yaml;

final class UpdateCheckLimiterTest extends TestCase
{
    /**
     * Der Limiter muss in allen drei Konfigurationsblöcken stehen – sonst
     * fehlt er in genau der Umgebung, in der niemand hinschaut.
     */
    public function testLimiterIsConfiguredInEveryEnvironmentBlock(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2) . '/config/packages/rate_limiter.yaml');

        self::assertSame(
            ['policy' => 'sliding_window', 'limit' => 60, 'interval' => '1 hour'],
            $config['framework']['rate_limiter']['update_check'],
        );
        self::assertArrayHasKey('update_check', $config['when@test']['framework']['rate_limiter']);
        self::assertArrayHasKey('update_check', $config['when@dev']['framework']['rate_limiter']);
    }

    public function testBlocksAfterLimitReached(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'update_check', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $guard = new RateLimitGuard();

        for ($i = 0; $i < 3; ++$i) {
            $guard->consume($factory, '203.0.113.9');
        }

        try {
            $guard->consume($factory, '203.0.113.9');
            self::fail('Erwartetes ApiProblem 429 blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(429, $e->getStatusCode());
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit backend/tests/Unit/UpdateCheckLimiterTest.php; echo $?`
Expected: FAIL in `testLimiterIsConfiguredInEveryEnvironmentBlock` (Key `update_check` fehlt), Exit-Code 1.

- [ ] **Step 3: Limiter in allen drei Blöcken ergänzen**

In `backend/config/packages/rate_limiter.yaml` jeweils direkt unter der `bundle:`-Zeile einfügen:

Block `framework:`:

```yaml
        # Update-Check der Extension (POST /api/v1/updates): der Client fragt
        # höchstens einmal täglich pro Origin; das Limit fängt nur Missbrauch ab.
        update_check: { policy: sliding_window, limit: 60, interval: '1 hour' }
```

Block `when@test:` und Block `when@dev:` (jeweils):

```yaml
            update_check: { policy: sliding_window, limit: 1000, interval: '1 hour' }
```

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `php backend/bin/phpunit backend/tests/Unit/UpdateCheckLimiterTest.php; echo $?`
Expected: `OK (2 tests, …)`, Exit-Code 0.

- [ ] **Step 5: Commit**

```bash
git add backend/config/packages/rate_limiter.yaml backend/tests/Unit/UpdateCheckLimiterTest.php
git commit -F - <<'EOF'
Ergänze Rate-Limiter für den Update-Check der Extension

Der Endpunkt ist anonym und öffentlich; ein IP-Limit nach dem Muster
des Bundle-Endpunkts deckelt Missbrauch, ohne den vertraglichen
Ein-Aufruf-pro-Tag der Extension zu berühren. Der Konfigurationstest
stellt sicher, dass der Limiter in Test- und Dev-Block nicht fehlt.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
```

---

### Task 3: Endpunkt `POST /api/v1/updates`

**Files:**
- Create: `backend/src/Api/ApiLevel.php`
- Modify: `backend/src/Controller/Api/UpdateCheckController.php` (vollständig ersetzen)
- Test: `backend/tests/Functional/UpdateCheckTest.php` (vollständig ersetzen)
- Test: `backend/tests/Functional/CorsTest.php` (eine Methode ergänzen)

**Interfaces:**
- Consumes: `RateLimitGuard::consume(RateLimiterFactoryInterface, string)`, `EntryRepository::findPublishedByFormatIds(list<string>): list<Entry>` (fetch-joined `currentVersion`), `Entry::$type` (`EntryType` mit `->value` `menu`|`engine`), `Entry::$deprecated`, `Entry::$successorFormatId`, `EntryVersion::$semver`, `EntryVersion::$changelog`.
- Consumes aus `ApiTestCase`: `createPublishedEntry(string $formatId, array $payloadOverrides)`, `createRejectedJunkEntry(string $formatId)`, `api(method, uri, body)`, `json()`, `$this->em`.
- Produces: `App\Api\ApiLevel::IMPLEMENTED` (int, jetzt 2; das R3-Paket hebt sie auf 3 und alle Endpunkte melden denselben Wert); Antwortformat `{apiLevel: <IMPLEMENTED>, updates: [{id, type, version, url, changelog, deprecated, successor}]}`. `smoke.sh` (Task 6) prüft die leere Antwort per Regex `{"apiLevel":<Zahl>,"updates":[]}`, damit der R3-Bump den Smoke-Check nicht bricht.

- [ ] **Step 1: Funktionstest vollständig ersetzen**

`backend/tests/Functional/UpdateCheckTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\ApiLevel;

final class UpdateCheckTest extends ApiTestCase
{
    public function testAnswersWithContractEnvelopeAndElementShape(): void
    {
        $entry = $this->createPublishedEntry('com.example.shop', ['version' => '2.1.0']);
        $entry->currentVersion->changelog = 'Two new patterns for /cart';
        $this->em->flush();

        $this->api('POST', '/api/v1/updates', ['apiLevel' => 2, 'entries' => [
            ['id' => 'com.example.shop', 'version' => '1.0.0'],
        ]]);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame(ApiLevel::IMPLEMENTED, $body['apiLevel']);
        self::assertSame([[
            'id' => 'com.example.shop',
            'type' => 'menu',
            'version' => '2.1.0',
            'url' => 'http://localhost/api/v1/entries/com.example.shop/versions/2.1.0',
            'changelog' => 'Two new patterns for /cart',
            'deprecated' => false,
            'successor' => null,
        ]], $body['updates']);
    }

    public function testReportsEngineTypeFromEntry(): void
    {
        $this->createPublishedEntry('com.example.search', ['gesturaEngine' => 1, 'version' => '3.0.0']);

        $this->api('POST', '/api/v1/updates', ['entries' => [['id' => 'com.example.search', 'version' => '1.0.0']]]);

        $updates = $this->json()['updates'];
        self::assertCount(1, $updates);
        self::assertSame('engine', $updates[0]['type']);
        self::assertNull($updates[0]['changelog']);
    }

    public function testStaysSilentForEqualOrHigherClientVersion(): void
    {
        $this->createPublishedEntry('com.example.shop', ['version' => '1.3.0']);

        $this->api('POST', '/api/v1/updates', ['entries' => [
            ['id' => 'com.example.shop', 'version' => '1.3.0'],
        ]]);
        self::assertSame([], $this->json()['updates']);

        // Handimport einer neueren Fassung: 1.3.0 darf nicht als »Update« angeboten werden.
        $this->api('POST', '/api/v1/updates', ['entries' => [
            ['id' => 'com.example.shop', 'version' => '1.4.0'],
        ]]);
        self::assertSame([], $this->json()['updates']);
    }

    public function testComparesVersionsNumericallyNotLexically(): void
    {
        $this->createPublishedEntry('com.example.shop', ['version' => '1.10.0']);

        $this->api('POST', '/api/v1/updates', ['entries' => [['id' => 'com.example.shop', 'version' => '1.9.0']]]);

        self::assertSame('1.10.0', $this->json()['updates'][0]['version']);
    }

    public function testNullVersionAsksForCurrentVersion(): void
    {
        $this->createPublishedEntry('com.example.shop', ['version' => '1.3.0']);

        $this->api('POST', '/api/v1/updates', ['entries' => [['id' => 'com.example.shop', 'version' => null]]]);

        $updates = $this->json()['updates'];
        self::assertCount(1, $updates);
        self::assertSame('1.3.0', $updates[0]['version']);
    }

    public function testDeprecatedEntryIsReportedEvenWithUnchangedVersion(): void
    {
        $entry = $this->createPublishedEntry('com.example.old', ['version' => '1.0.0']);
        $entry->deprecated = true;
        $entry->successorFormatId = 'com.example.new';
        $this->em->flush();

        $this->api('POST', '/api/v1/updates', ['entries' => [['id' => 'com.example.old', 'version' => '1.0.0']]]);

        $updates = $this->json()['updates'];
        self::assertCount(1, $updates);
        self::assertTrue($updates[0]['deprecated']);
        self::assertSame('com.example.new', $updates[0]['successor']);
        self::assertSame('1.0.0', $updates[0]['version']);
    }

    public function testDeprecatedEntryWithNewerVersionCarriesBoth(): void
    {
        $entry = $this->createPublishedEntry('com.example.old', ['version' => '2.0.0']);
        $entry->deprecated = true;
        $this->em->flush();

        $this->api('POST', '/api/v1/updates', ['entries' => [['id' => 'com.example.old', 'version' => '1.0.0']]]);

        $updates = $this->json()['updates'];
        self::assertSame('2.0.0', $updates[0]['version']);
        self::assertTrue($updates[0]['deprecated']);
        self::assertNull($updates[0]['successor']);
    }

    public function testSkipsUnknownUnpublishedAndMalformedEntriesButKeepsOrder(): void
    {
        $this->createPublishedEntry('com.example.b', ['version' => '2.0.0']);
        $this->createPublishedEntry('com.example.a', ['version' => '2.0.0']);
        $this->createRejectedJunkEntry('com.example.junk');

        $this->api('POST', '/api/v1/updates', ['entries' => [
            ['id' => 'com.example.b', 'version' => '1.0.0'],
            ['id' => 'com.example.unbekannt', 'version' => '1.0.0'],
            ['id' => 'com.example.junk', 'version' => '1.0.0'],
            ['id' => 'com.example.a', 'version' => 'keine-semver'],       // Version ungültig
            ['id' => 'com.example.a', 'version' => 7],                   // Version kein String
            ['id' => '-fuehrender-bindestrich', 'version' => '1.0.0'],   // Kennung verletzt das Muster
            ['id' => str_repeat('a', 129), 'version' => '1.0.0'],        // Kennung zu lang
            ['id' => 'com.example.a'],                                    // version fehlt ganz (≠ null)
            'kein-objekt',
            ['version' => '1.0.0'],                                       // id fehlt
            ['id' => 'com.example.a', 'version' => '1.0.0'],
            ['id' => 'com.example.b', 'version' => '0.0.1'],             // Doppelt: der erste Posten gewinnt
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['com.example.b', 'com.example.a'], array_column($this->json()['updates'], 'id'));
    }

    public function testEmptyListIsTheHealthyAnswer(): void
    {
        $this->api('POST', '/api/v1/updates', ['apiLevel' => 2, 'entries' => []]);

        self::assertResponseIsSuccessful();
        // Reihenfolge und Form sind Vertrag; smoke.sh prüft dieselbe Form per Regex.
        self::assertSame(
            sprintf('{"apiLevel":%d,"updates":[]}', ApiLevel::IMPLEMENTED),
            $this->client->getResponse()->getContent(),
        );
    }

    public function testRejectsMalformedEnvelope(): void
    {
        $many = array_fill(0, 201, ['id' => 'com.example.x', 'version' => '1.0.0']);
        $this->api('POST', '/api/v1/updates', ['entries' => $many]);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/v1/updates', ['entries' => 'quatsch']);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/v1/updates', ['apiLevel' => 2]);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/api/v1/updates',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{kaputtes json',
        );
        self::assertResponseStatusCodeSame(400);
    }

    public function testOldPathIsGone(): void
    {
        // /api/v1/entries/{formatId} existiert für GET/PUT/DELETE, daher 405 statt 404 – beides heißt: kein Update-Check mehr.
        $this->api('POST', '/api/v1/entries/updates', ['entries' => []]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 405]);
    }
}
```

- [ ] **Step 2: Preflight-Test in `CorsTest` ergänzen**

In `backend/tests/Functional/CorsTest.php` nach `testPreflightIsAnswered()` einfügen:

```php
    /**
     * Vertrag R2, Abschnitt »Update check / CORS«: Firefox MV3 schickt den
     * Preflight von moz-extension://… – der Endpunkt muss ihn offen beantworten.
     */
    public function testUpdateCheckPreflightMatchesContract(): void
    {
        $this->client->request('OPTIONS', '/api/v1/updates', server: [
            'HTTP_ORIGIN' => 'moz-extension://3f1c2a6e-0000-4000-8000-000000000000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
        self::assertStringContainsString('OPTIONS', (string) $response->headers->get('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Content-Type', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }
```

- [ ] **Step 3: Tests laufen lassen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit backend/tests/Functional/UpdateCheckTest.php; echo $?`
Expected: Mehrere FAIL/ERROR (404 auf `/api/v1/updates`), Exit-Code 1. `CorsTest` ist bereits grün (der Subscriber deckt alle `/api/`-Pfade), das ist erwartet.

- [ ] **Step 4a: Gemeinsame API-Level-Konstante anlegen**

`backend/src/Api/ApiLevel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Der apiLevel, den dieser Index gegenüber der Extension umsetzt (Vertrag
 * docs/gestura-eu-api.md). Die Level sind additiv: 2 = Update-Check
 * (/api/v1/updates), 3 = zusätzlich die /api/v1/sync/*-Endpunkte.
 *
 * Genau EINE Stelle für die Zahl: jede Antwort, die einen apiLevel trägt,
 * liest sie hier. Das R3-Paket hebt den Wert auf 3, sobald die vier
 * Sync-Endpunkte antworten – nicht früher, sonst verspräche der Index ein
 * Level, das er nicht bedient.
 */
final class ApiLevel
{
    public const IMPLEMENTED = 2;
}
```

- [ ] **Step 4: Controller vollständig ersetzen**

`backend/src/Controller/Api/UpdateCheckController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiLevel;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Update-Check der Extension (Vertrag docs/gestura-eu-api.md, Abschnitt
 * »Update check«, apiLevel 2). Anonym, cookielos, öffentliche »*«-CORS-API.
 *
 * Der Client fragt mit (id, version|null)-Paaren; geantwortet wird nur für
 * Einträge, zu denen es etwas zu sagen gibt: eine numerisch neuere Version,
 * eine Abkündigung oder beides. »version: null« heißt »sag mir die aktuelle
 * Version«. Der Typ (menu|engine) reist bewusst nur in der Antwort – der
 * Client prüft ihn gegen seine lokale Kenntnis, der Server braucht ihn nicht
 * und erfährt nichts über die Einrichtung des Nutzers.
 *
 * Die Download-URL wird aus Schema und Host des Requests gebildet und liegt
 * damit auf der antwortenden Origin – der Client verwirft alles andere.
 * Fehlerhafte Einzelposten werden still übersprungen (der Check bleibt
 * nutzbar); nur der Umschlag wird streng geprüft.
 */
final class UpdateCheckController
{
    private const MAX_ENTRIES = 200;
    // Kennungsmuster aus dem Vertrag (Abschnitt »Bridge«, Limits) – identisch zu ID_RE der Extension.
    private const ID_RE = '/^[a-zA-Z0-9]([a-zA-Z0-9._-]*[a-zA-Z0-9])?$/';
    private const ID_MAX_LENGTH = 128;
    // Dieselbe SEMVER_RE wie im Austauschformat: nur numerische Tripel sind vergleichbar.
    private const SEMVER_RE = '/^\d{1,5}\.\d{1,5}\.\d{1,5}$/';

    #[Route('/api/v1/updates', methods: ['POST'])]
    public function __invoke(
        Request $request,
        EntryRepository $entries,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $updateCheckLimiter,
    ): JsonResponse {
        $guard->consume($updateCheckLimiter, $request->getClientIp() ?? 'unknown');

        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }

        // apiLevel wird bewusst nicht erzwungen: der Vertrag verlangt Toleranz
        // gegenüber älteren und künftigen Clients.
        $list = \is_array($body) ? ($body['entries'] ?? null) : null;
        if (!\is_array($list)) {
            throw new ApiProblem(400, 'entries must be a list');
        }
        if (\count($list) > self::MAX_ENTRIES) {
            throw new ApiProblem(400, 'entries must contain at most ' . self::MAX_ENTRIES . ' items');
        }

        // Schritt 1: gültige (id, version|null)-Paare einsammeln, Doppelte
        // zusammenfassen (der erste Posten gewinnt), Fehlerhaftes überspringen.
        /** @var array<string, ?string> $wanted */
        $wanted = [];
        foreach ($list as $item) {
            if (!\is_array($item) || !\array_key_exists('version', $item)) {
                continue;
            }
            $id = $item['id'] ?? null;
            $version = $item['version'];
            if (!\is_string($id) || \strlen($id) > self::ID_MAX_LENGTH || !preg_match(self::ID_RE, $id)) {
                continue;
            }
            if ($version !== null && (!\is_string($version) || !preg_match(self::SEMVER_RE, $version))) {
                continue;
            }
            if (\array_key_exists($id, $wanted)) {
                continue;
            }
            $wanted[$id] = $version;
        }

        // Schritt 2: ein Batch-Lookup (fetch-joined currentVersion, kein N+1).
        // strval: PHP macht rein numerische Array-Schlüssel zu int.
        $byFormatId = [];
        foreach ($entries->findPublishedByFormatIds(array_map(strval(...), array_keys($wanted))) as $entry) {
            $byFormatId[$entry->formatId] = $entry;
        }

        // Schritt 3: Antwort in Eingabereihenfolge aufbauen.
        $base = $request->getSchemeAndHttpHost();
        $updates = [];
        foreach ($wanted as $id => $clientVersion) {
            $entry = $byFormatId[(string) $id] ?? null;
            $current = $entry?->currentVersion;
            if ($entry === null || $current === null) {
                continue; // unbekannt, nicht veröffentlicht oder (defensiv) ohne Version
            }
            $newer = $clientVersion === null || version_compare($current->semver, $clientVersion, '>');
            if (!$newer && !$entry->deprecated) {
                continue; // aktuell oder Handimport einer neueren Fassung – nichts zu sagen
            }
            $updates[] = [
                'id' => $entry->formatId,
                'type' => $entry->type->value,
                'version' => $current->semver,
                'url' => sprintf('%s/api/v1/entries/%s/versions/%s', $base, rawurlencode($entry->formatId), $current->semver),
                'changelog' => $current->changelog,
                'deprecated' => $entry->deprecated,
                'successor' => $entry->successorFormatId,
            ];
        }

        return new JsonResponse(['apiLevel' => ApiLevel::IMPLEMENTED, 'updates' => $updates]);
    }
}
```

- [ ] **Step 5: Tests laufen lassen, Erfolg bestätigen**

Run: `php backend/bin/phpunit backend/tests/Functional/UpdateCheckTest.php backend/tests/Functional/CorsTest.php; echo $?`
Expected: `OK`, Exit-Code 0.

Falls `testSkipsUnknownUnpublishedAndMalformedEntriesButKeepsOrder` scheitert, weil `str_repeat('a', 129)` als gültige ID durchgeht: Längenprüfung vor `preg_match` steht, Ergebnis muss leer bleiben, da `strlen` 129 > 128.

- [ ] **Step 6: Gesamte Suite laufen lassen**

Run: `php backend/bin/phpunit; echo $?`
Expected: `OK`, Exit-Code 0. Der Phase-2-Plan `docs/superpowers/plans/2026-07-20-index-backend-core.md` erwähnt noch den alten Pfad; historische Pläne werden nicht umgeschrieben.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Api/ApiLevel.php backend/src/Controller/Api/UpdateCheckController.php backend/tests/Functional/UpdateCheckTest.php backend/tests/Functional/CorsTest.php
git commit -F - <<'EOF'
Stelle den Update-Check unter POST /api/v1/updates bereit

Die Extension (Vertrag R2, apiLevel 2) erwartet den Update-Check unter
/api/v1/updates mit dem Umschlag {apiLevel, updates} und Elementen aus
type, version, url, changelog, deprecated und successor. Der Vorläufer
unter /api/v1/entries/updates überging »version: null« und meldete
Abkündigungen nur zusammen mit einer neueren Version; beides verlangt
der Vertrag anders. Die Download-URL entsteht aus Schema und Host des
Requests, weil der Client alles verwirft, was nicht auf der
antwortenden Origin liegt.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
```

---

### Task 4: `FRONTEND_BUILD_DIR` konfigurierbar machen

**Files:**
- Modify: `backend/config/services.yaml:17`
- Modify: `backend/.env` (Block `###> app ###`)
- Test: bestehender `backend/tests/Functional/MarketingPageControllerTest.php` (unverändert, muss grün bleiben)

**Interfaces:**
- Produces: Env-Variable `FRONTEND_BUILD_DIR`; `deploy/README.md` (Task 10) dokumentiert den Produktionswert `%kernel.project_dir%/public`.

- [ ] **Step 1: Parameter auf Env-Variable umstellen**

In `backend/config/services.yaml` die Zeile

```yaml
    app.frontend_build_dir: '%kernel.project_dir%/../frontend/build'
```

ersetzen durch

```yaml
    app.frontend_build_dir: '%env(resolve:FRONTEND_BUILD_DIR)%'
```

und den Kommentar darüber anpassen zu:

```yaml
    # Pfad zum prerenderten Frontend-Build, aus dem der MarketingPageController
    # die statische HTML der schaltbaren Marketing-Seiten liest. Default (.env)
    # ist das Geschwisterverzeichnis frontend/build; in Produktion liegt der
    # Build im gemeinsamen Docroot backend/public (shared/.env.local:
    # FRONTEND_BUILD_DIR=%kernel.project_dir%/public, siehe deploy/README.md).
```

Der `when@test`-Override auf `tests/fixtures/frontend-build` bleibt unverändert.

- [ ] **Step 2: Default in `.env` ergänzen**

In `backend/.env` im Block `###> app ###` nach `MAILER_FROM=admin@gestura.eu` einfügen:

```dotenv
# Verzeichnis des prerenderten Frontend-Builds (MarketingPageController).
# Produktion: %kernel.project_dir%/public (gemeinsames Docroot), siehe deploy/README.md.
FRONTEND_BUILD_DIR=%kernel.project_dir%/../frontend/build
```

- [ ] **Step 3: Container-Auflösung und Tests prüfen**

Run: `php backend/bin/console debug:container --parameter=app.frontend_build_dir --env=dev; php backend/bin/phpunit backend/tests/Functional/MarketingPageControllerTest.php; echo $?`
Expected: Parameter zeigt den aufgelösten Pfad `…/frontend/build` (kein literales `%kernel…`), Test `OK`, Exit-Code 0.

- [ ] **Step 4: Commit**

```bash
git add backend/config/services.yaml backend/.env
git commit -F - <<'EOF'
Mache das Frontend-Build-Verzeichnis per Env konfigurierbar

Im gemeinsamen Docroot liegt der Frontend-Build in backend/public,
nicht mehr im Geschwisterverzeichnis frontend/build. Ein Env-Wert
statt eines festen Parameters erlaubt Produktion den Pfad in
shared/.env.local zu setzen, ohne services.yaml zu überschreiben.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
```

---

### Task 5: `.htaccess` zusammenführen, Frontend-`.htaccess` entfernen

**Files:**
- Modify: `backend/public/.htaccess` (vollständig ersetzen)
- Delete: `frontend/static/.htaccess`

**Interfaces:**
- Produces: die einzige Regeldatei des Docroots; `deploy.sh` (Task 8) kopiert den Frontend-Build ohne `.htaccess` nach `backend/public/`; `smoke.sh` (Task 6) prüft das Ergebnis auf dem Server.

- [ ] **Step 1: Zusammengeführte `.htaccess` schreiben**

`backend/public/.htaccess` vollständig ersetzen durch:

```apache
# Gemeinsames Docroot: Symfony-API (/api/…), Marketing-Interceptor und
# statischer SvelteKit-Build (adapter-static, Prerender + 200.html-Fallback).
# EINZIGE Regelquelle – frontend/static/.htaccess existiert bewusst nicht.
# Recipe-managed (symfony/framework-bundle): nach Recipe-Updates die Regeln
# 1–7 prüfen (siehe .claude/lessons.md).

# index.html VOR index.php: die Wurzel / liefert die prerenderte Startseite,
# nicht Symfonys 404. de/ und en/ existieren als Verzeichnisse NEBEN de.html
# und en.html; ohne DirectorySlash Off würde Apache /de auf /de/ umleiten.
DirectoryIndex index.html index.php
DirectorySlash Off

<IfModule mod_negotiation.c>
    Options -MultiViews
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_URI}::$0 ^(/.+)/(.*)::\2$
    RewriteRule .* - [E=BASE:%1]

    # Authorization-Header an PHP durchreichen (Bearer-Edit-Tokens)
    RewriteCond %{HTTP:Authorization} .+
    RewriteRule ^ - [E=HTTP_AUTHORIZATION:%0]

    RewriteCond %{ENV:REDIRECT_STATUS} =""
    RewriteRule ^index\.php(?:/(.*)|$) %{ENV:BASE}/$1 [R=301,L]

    # 1. API immer an Symfony – vor jeder Datei-, Slash- oder Fallback-Regel.
    #    Der Vertrag mit der Extension verbietet jedes 3xx auf /api/v1/updates.
    RewriteRule ^api(/.*)?$ %{ENV:BASE}/index.php [L]

    # 2. Schaltbare Marketing-Seiten IMMER an Symfony: der
    #    MarketingPageController liefert 200 (aktiv) oder echtes 404
    #    (deaktiviert). Muss VOR Regel 3 und 5 stehen, sonst liefert Apache
    #    die statische de/vergleich.html mit 200 aus, bevor Symfony gefragt wird.
    RewriteRule ^(de|en)/(was-ist-gestura|maus-gesten|vergleich|beispiele)/?$ %{ENV:BASE}/index.php [L]

    # 3. Vorhandene Dateien direkt ausliefern (Assets, favicon, robots.txt).
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]

    # 4. Trailing Slash kanonisieren (außer Wurzel): /de/ → /de.
    RewriteCond %{REQUEST_URI} !^/$
    RewriteRule ^(.+)/$ /$1 [R=301,L]

    # 5. Prerenderte Seiten ohne .html-Endung (/en → /en.html, /en/docs → /en/docs.html).
    RewriteCond %{REQUEST_FILENAME}.html -f
    RewriteRule ^(.+?)/?$ /$1.html [L]

    # 6. Vorhandene Verzeichnisse (v. a. die Wurzel → index.html).
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # 7. Alles andere (client-gerenderte Routen, Admin-SPA inkl. Deep-Links)
    #    auf die SPA-Hülle. Symfony hat außer /api/… und Regel 2 keine Routen.
    RewriteRule ^ /200.html [L]
</IfModule>

<IfModule !mod_rewrite.c>
    <IfModule mod_alias.c>
        RedirectMatch 307 ^/$ /index.php/
    </IfModule>
</IfModule>
```

- [ ] **Step 2: Frontend-`.htaccess` löschen**

```bash
git rm frontend/static/.htaccess
```

- [ ] **Step 3: Routenprüfung dokumentieren (Regel 7 ist nur sicher, wenn Symfony keine weiteren Nicht-API-Routen hat)**

Run: `php backend/bin/console debug:router --env=prod | awk 'NR>2 && $0 !~ /\/api\// && $0 !~ /^ *-/'`
Expected: Genau eine Zeile: `app_marketingpage__invoke GET /{locale}/{slug}`. Erscheint eine weitere Route, STOPP – sie braucht eine eigene Regel vor Regel 7; Rücksprache.

- [ ] **Step 4: Frontend-Build ohne `.htaccess` verifizieren**

Run: `npm --prefix frontend run build >/dev/null && test ! -e frontend/build/.htaccess && echo "build ohne .htaccess"`
Expected: `build ohne .htaccess`.

- [ ] **Step 5: Commit**

```bash
git add backend/public/.htaccess
git commit -F - <<'EOF'
Führe die .htaccess für das gemeinsame Docroot zusammen

gestura.eu soll API und Frontend aus backend/public ausliefern. Zwei
Regeldateien (Backend und Frontend-Build) würden sich beim Kopieren
gegenseitig überschreiben; die Regeln leben deshalb nur noch hier.
/api/… geht vor jeder Datei- und Fallback-Regel an Symfony, weil der
Vertrag mit der Extension jedes 3xx auf /api/v1/updates als Fehler
wertet. index.html steht vor index.php, damit / die prerenderte
Startseite liefert.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
```

---

### Task 6: `deploy/common.sh` und `deploy/smoke.sh`

**Files:**
- Create: `deploy/common.sh`
- Create: `deploy/smoke.sh`

**Interfaces:**
- Produces: `common.sh` exportiert `DEPLOY_HOST`, `DEPLOY_PATH`, `RELEASES_DIR`, `SHARED_DIR`, `CURRENT_LINK` sowie die Funktionen `die <msg>` (Exit 1), `step <msg>` (Überschrift), `remote <cmd…>` (ssh mit BatchMode). `smoke.sh [origin]` liefert Exit 0 bei Erfolg, Exit 1 mit Klartext-Grund sonst; Default-Origin `https://gestura.eu`.

- [ ] **Step 1: `deploy/common.sh` schreiben**

```bash
#!/usr/bin/env bash
# Gemeinsame Konstanten und Helfer der Deploy-Skripte. Wird per »source« geladen.
# Layout auf dem Server (Spec 2026-09-03, Abschnitt 4.3):
#   $DEPLOY_PATH/releases/<tag>/{backend,schema,RELEASE}
#   $DEPLOY_PATH/shared/{.env.local,media,log}
#   $DEPLOY_PATH/current -> releases/<tag>
# Docroot beider Domains: $DEPLOY_PATH/current/backend/public

DEPLOY_HOST="ssh-w00d7b19@85.13.135.147"
DEPLOY_PATH="/www/htdocs/w00d7b19/gestura.eu"
RELEASES_DIR="$DEPLOY_PATH/releases"
SHARED_DIR="$DEPLOY_PATH/shared"
CURRENT_LINK="$DEPLOY_PATH/current"
SITE_ORIGIN="https://gestura.eu"

die()  { echo "FEHLER – $*" >&2; exit 1; }
step() { echo; echo "== $* =="; }
# Führt einen Befehl auf dem Server aus. Bei mehrzeiligen Skripten:
#   remote 'bash -s' <<'REMOTE' … REMOTE   (Variablen vorher als VAR=… voranstellen)
remote() { ssh -o BatchMode=yes "$DEPLOY_HOST" "$@"; }
```

- [ ] **Step 2: `deploy/smoke.sh` schreiben**

```bash
#!/usr/bin/env bash
# Smoke-Check gegen eine Origin (Default https://gestura.eu). Prüft genau das,
# was der Vertrag mit der Extension und das gemeinsame Docroot verlangen.
# Aufruf: deploy/smoke.sh [origin]      Exit 0 = alles grün.
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=deploy/common.sh
source deploy/common.sh

ORIGIN="${1:-$SITE_ORIGIN}"
ORIGIN="${ORIGIN%/}"
fail=0
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# request <name> <curl-args…>  → setzt STATUS, HEADERS (Datei), BODY (Datei).
# Kein -L: curl folgt nie einer Umleitung, jedes 3xx bleibt als Status stehen.
request() {
    local name="$1"; shift
    STATUS=$(curl -sS --max-time 20 -o "$tmp/$name.body" -D "$tmp/$name.headers" -w '%{http_code}' "$@") || STATUS=000
    HEADERS="$tmp/$name.headers"; BODY="$tmp/$name.body"
}
header() { # header <Name> → Wert (case-insensitiv, ohne CR)
    grep -i "^$1:" "$HEADERS" | head -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'
}
ok()   { echo "OK    $*"; }
bad()  { echo "FEHLT $*"; fail=1; }

echo "== Smoke-Check gegen $ORIGIN =="

request preflight -X OPTIONS \
    -H 'Origin: moz-extension://smoke-check' \
    -H 'Access-Control-Request-Method: POST' \
    -H 'Access-Control-Request-Headers: content-type' \
    "$ORIGIN/api/v1/updates"
if [ "$STATUS" = 204 ] \
   && [ "$(header Access-Control-Allow-Origin)" = '*' ] \
   && header Access-Control-Allow-Methods | grep -q POST \
   && header Access-Control-Allow-Headers | grep -qi content-type; then
    ok "OPTIONS /api/v1/updates: 204 mit offenem CORS"
else
    bad "OPTIONS /api/v1/updates: Status $STATUS, ACAO »$(header Access-Control-Allow-Origin)«"
fi

request updates -X POST -H 'Content-Type: application/json' \
    --data '{"apiLevel":3,"entries":[]}' "$ORIGIN/api/v1/updates"
# apiLevel als Zahl, nicht fest 2: das R3-Paket hebt den Wert auf 3, die Form bleibt.
if [ "$STATUS" = 200 ] && grep -Eq '^\{"apiLevel":[0-9]+,"updates":\[\]\}$' "$BODY"; then
    ok "POST /api/v1/updates: 200 mit leerer Vertragsantwort ($(cat "$BODY"))"
elif [ "$STATUS" = 200 ] && header Content-Type | grep -qi text/html; then
    bad "POST /api/v1/updates liefert HTML – das KAS-Docroot von ${ORIGIN#https://} zeigt noch nicht auf current/backend/public"
else
    bad "POST /api/v1/updates: Status $STATUS, Body: $(head -c 200 "$BODY")"
fi

request entries "$ORIGIN/api/v1/entries"
if [ "$STATUS" = 200 ] && grep -q '"items"' "$BODY"; then
    ok "GET /api/v1/entries: JSON mit items"
else
    bad "GET /api/v1/entries: Status $STATUS, Content-Type »$(header Content-Type)«"
fi

request de "$ORIGIN/de"
if [ "$STATUS" = 200 ] && header Content-Type | grep -qi text/html; then
    ok "GET /de: 200 text/html (Frontend aus dem gemeinsamen Docroot)"
else
    bad "GET /de: Status $STATUS, Content-Type »$(header Content-Type)«"
fi

request vergleich "$ORIGIN/de/vergleich"
if { [ "$STATUS" = 200 ] || [ "$STATUS" = 404 ]; } && header Content-Type | grep -qi text/html; then
    ok "GET /de/vergleich: $STATUS text/html (Marketing-Interceptor erreicht Symfony)"
else
    bad "GET /de/vergleich: Status $STATUS, Content-Type »$(header Content-Type)« – erwartet 200/404 als HTML"
fi

if [ "$fail" -ne 0 ]; then
    echo "== Smoke-Check FEHLGESCHLAGEN =="; exit 1
fi
echo "== Smoke-Check erfolgreich =="
```

- [ ] **Step 3: Ausführbar machen und Syntax prüfen**

Run: `chmod +x deploy/smoke.sh && bash -n deploy/common.sh && bash -n deploy/smoke.sh && echo SYNTAX-OK`
Expected: `SYNTAX-OK`.

- [ ] **Step 4: Gegen den lokalen Dev-Server laufen lassen (API-Checks müssen grün sein, Frontend-Checks dürfen fehlen)**

Run (Dev-Server in zweitem Terminal: `php -S localhost:8000 -t backend/public`):

```bash
deploy/smoke.sh http://localhost:8000; echo "exit=$?"
```

Expected: Die drei API-Zeilen (`OPTIONS`, `POST`, `GET /api/v1/entries`) mit `OK`; `/de` und `/de/vergleich` mit `FEHLT` (der PHP-Dev-Server kennt keine `.htaccess`); `exit=1`. Genau dieses Muster bestätigt, dass die API-Checks vertragskonform prüfen und die Layout-Checks unabhängig davon fehlschlagen.

- [ ] **Step 5: Gegen die heutige Produktion laufen lassen (Erwartung: KAS-Hinweis)**

Run: `deploy/smoke.sh; echo "exit=$?"`
Expected: `POST /api/v1/updates liefert HTML – das KAS-Docroot …` und `exit=1`. Gegen `deploy/smoke.sh https://api.gestura.eu` müssen `OPTIONS` grün, `POST` rot mit Status 404 (Endpunkt noch nicht deployt), `/de` rot sein. Das ist der erwartete Vor-Deploy-Zustand.

- [ ] **Step 6: Commit**

```bash
git add deploy/common.sh deploy/smoke.sh
git commit -F - <<'EOF'
Ergänze Smoke-Check für Vertragspfad und gemeinsames Docroot

Der Update-Check der Extension funktioniert nur, wenn gestura.eu selbst
ohne Umleitung antwortet und den Preflight offen beantwortet. Ein
Skript, das genau diese Erwartungen gegen eine Origin prüft, macht den
Zustand vor und nach der Docroot-Umstellung sichtbar und benennt das
noch nicht umgestellte KAS-Docroot ausdrücklich.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
```

---

### Task 7: Garbage Collection `deploy/gc.sh` mit Bash-Test

**Files:**
- Create: `deploy/gc.sh`
- Create: `deploy/tests/gc-test.sh`

**Interfaces:**
- Consumes: `RELEASE`-Datei je Release mit einer Zeile `deployed_at_epoch=<unix-sekunden>` (schreibt `deploy.sh`, Task 8).
- Produces: `gc.sh --root <pfad> [--dry-run] [--keep N] [--today-epoch E]`; läuft auf jedem Linux mit GNU `date`, also lokal (Test) und auf dem Server (per `remote 'bash -s' -- --root …`). Ausgabe je Release `behalte`/`lösche` plus Grund.

- [ ] **Step 1: Test schreiben (Fixture-Builder + fünf Fälle)**

`deploy/tests/gc-test.sh`:

```bash
#!/usr/bin/env bash
# Testet die Aufbewahrungsregeln von deploy/gc.sh gegen synthetische Releases.
# Aufruf: deploy/tests/gc-test.sh        Exit 0 = alle Fälle grün.
set -euo pipefail
cd "$(dirname "$0")/../.."
GC=deploy/gc.sh
TODAY=1700000000                       # fixer »Tagesbeginn« für deterministische Fälle
H=3600
fail=0

# release <root> <name> <epoch>  – vollständiges Release mit RELEASE-Datei
release() { mkdir -p "$1/releases/$2"; printf 'tag=%s\ndeployed_at_epoch=%s\n' "$2" "$3" > "$1/releases/$2/RELEASE"; }
# incomplete <root> <name>       – Verzeichnis ohne RELEASE-Datei
incomplete() { mkdir -p "$1/releases/$2"; }
current() { ln -sfn "releases/$2" "$1/current"; }
# expect <root> <name…>          – genau diese Releases dürfen übrig sein
expect() {
    local root="$1"; shift
    local want; want=$(printf '%s\n' "$@" | sort)
    local have; have=$(ls "$root/releases" | sort)
    if [ "$want" = "$have" ]; then echo "OK    $CASE"; else echo "FEHLT $CASE"; echo "  erwartet: $(tr '\n' ' ' <<<"$want")"; echo "  vorhanden: $(tr '\n' ' ' <<<"$have")"; fail=1; fi
}
newroot() { local r; r=$(mktemp -d); mkdir -p "$r/releases"; echo "$r"; }

CASE="1: sieben heutige Releases – fünf jüngste bleiben, kein älteres vorhanden"
r=$(newroot); current "$r" v8
release "$r" v8 $((TODAY+8*H))
for i in 1 2 3 4 5 6 7; do release "$r" v$i $((TODAY+i*H)); done
"$GC" --root "$r" --today-epoch "$TODAY" >/dev/null
expect "$r" v8 v7 v6 v5 v4 v3

CASE="2: sechs heutige + zwei gestrige – fünf heutige plus jüngstes gestriges"
r=$(newroot); current "$r" v9
release "$r" v9 $((TODAY+9*H))
for i in 1 2 3 4 5 6; do release "$r" v$i $((TODAY+i*H)); done
release "$r" g1 $((TODAY-30*H)); release "$r" g2 $((TODAY-2*H))
"$GC" --root "$r" --today-epoch "$TODAY" >/dev/null
expect "$r" v9 v6 v5 v4 v3 v2 g2

CASE="3: älteres Release bereits unter den fünf – kein zusätzliches"
r=$(newroot); current "$r" v3
release "$r" v3 $((TODAY+3*H)); release "$r" v2 $((TODAY+2*H)); release "$r" v1 $((TODAY+1*H))
release "$r" g1 $((TODAY-5*H)); release "$r" g2 $((TODAY-50*H)); release "$r" g3 $((TODAY-70*H))
release "$r" g4 $((TODAY-90*H)); release "$r" g5 $((TODAY-99*H))
"$GC" --root "$r" --today-epoch "$TODAY" >/dev/null
# Kandidaten außer current: v2 v1 g1 g2 g3 | g4 g5 – g1 ist bereits älter als heute, also kein Zusatz.
expect "$r" v3 v2 v1 g1 g2 g3

CASE="4: unvollständige Releases verschwinden, current überlebt auch ohne RELEASE"
r=$(newroot); current "$r" cur
incomplete "$r" cur; incomplete "$r" halb; release "$r" v1 $((TODAY+H))
"$GC" --root "$r" --today-epoch "$TODAY" >/dev/null
expect "$r" cur v1

CASE="5: --dry-run löscht nichts"
r=$(newroot); current "$r" v8
release "$r" v8 $((TODAY+8*H))
for i in 1 2 3 4 5 6 7; do release "$r" v$i $((TODAY+i*H)); done
incomplete "$r" halb
"$GC" --root "$r" --today-epoch "$TODAY" --dry-run | grep -q 'lösche' || { echo "FEHLT $CASE: dry-run meldet keine Löschkandidaten"; fail=1; }
expect "$r" v8 v7 v6 v5 v4 v3 v2 v1 halb

CASE="6: Rollback-Situation – current ist alt, neuere Releases unterliegen den Regeln"
r=$(newroot); current "$r" v1
release "$r" v1 $((TODAY-40*H))
for i in 2 3 4 5 6 7 8; do release "$r" v$i $((TODAY+i*H)); done
"$GC" --root "$r" --today-epoch "$TODAY" >/dev/null
expect "$r" v1 v8 v7 v6 v5 v4

[ "$fail" -eq 0 ] && echo "== gc-test: alle Fälle grün ==" || { echo "== gc-test FEHLGESCHLAGEN =="; exit 1; }
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `chmod +x deploy/tests/gc-test.sh && deploy/tests/gc-test.sh; echo "exit=$?"`
Expected: Abbruch, weil `deploy/gc.sh` nicht existiert; `exit` ungleich 0.

- [ ] **Step 3: `deploy/gc.sh` schreiben**

```bash
#!/usr/bin/env bash
# Garbage Collection alter Releases (Spec 2026-09-03, Abschnitt 4.7).
# Läuft lokal gegen --root (Tests) oder auf dem Server per »bash -s«.
#
# Regeln (Sortierung nach deployed_at_epoch aus der RELEASE-Datei):
#   1. current wird nie gelöscht.
#   2. Unvollständige Releases (ohne RELEASE) werden gelöscht – außer current.
#   3. Von den vollständigen Releases außer current bleiben die KEEP jüngsten.
#   4. Ist darunter keines mit deployed_at vor dem heutigen Tag (00:00 Uhr),
#      bleibt zusätzlich das jüngste Release, das älter als heute ist.
#   5. Alles Übrige wird gelöscht.
#
# Aufruf: gc.sh --root <deploy-path> [--dry-run] [--keep N] [--today-epoch E]
set -euo pipefail

ROOT=""; DRY=0; KEEP=5; TODAY=""
while [ $# -gt 0 ]; do
    case "$1" in
        --root) ROOT="$2"; shift 2 ;;
        --dry-run) DRY=1; shift ;;
        --keep) KEEP="$2"; shift 2 ;;
        --today-epoch) TODAY="$2"; shift 2 ;;
        *) echo "Unbekannte Option: $1" >&2; exit 2 ;;
    esac
done
[ -n "$ROOT" ] || { echo "--root fehlt" >&2; exit 2; }
[ -d "$ROOT/releases" ] || { echo "Kein releases/-Verzeichnis unter $ROOT" >&2; exit 2; }
[ -n "$TODAY" ] || TODAY=$(date -d "$(date +%F) 00:00:00" +%s)

current=$(readlink -f "$ROOT/current" 2>/dev/null || true)

remove() { # remove <verzeichnis> <grund>
    if [ "$DRY" -eq 1 ]; then echo "lösche  $(basename "$1")  ($2) [dry-run]"; else rm -rf "$1"; echo "lösche  $(basename "$1")  ($2)"; fi
}

# Kandidaten einsammeln: "epoch<TAB>pfad", nur vollständige Releases außer current.
candidates=()
for dir in "$ROOT"/releases/*/; do
    dir="${dir%/}"
    [ -d "$dir" ] || continue
    if [ "$(readlink -f "$dir")" = "$current" ]; then
        echo "behalte $(basename "$dir")  (current)"; continue
    fi
    if [ ! -f "$dir/RELEASE" ]; then
        remove "$dir" "unvollständig, keine RELEASE-Datei"; continue
    fi
    epoch=$(sed -n 's/^deployed_at_epoch=//p' "$dir/RELEASE" | head -1)
    [[ "$epoch" =~ ^[0-9]+$ ]] || { remove "$dir" "RELEASE ohne gültiges deployed_at_epoch"; continue; }
    candidates+=("$epoch|$dir")
done

# Nach Epoch absteigend sortieren; die ersten KEEP bleiben.
mapfile -t sorted < <(printf '%s\n' "${candidates[@]-}" | grep -v '^$' | sort -t '|' -k1,1nr)
keep=(); rest=()
for i in "${!sorted[@]}"; do
    if [ "$i" -lt "$KEEP" ]; then keep+=("${sorted[$i]}"); else rest+=("${sorted[$i]}"); fi
done

for line in "${keep[@]-}"; do
    [ -n "$line" ] && echo "behalte $(basename "${line#*|}")  (unter den $KEEP jüngsten)"
done

# Regel 4: Rückfallpunkt von gestern oder früher sichern.
has_older=0
for line in "${keep[@]-}"; do
    if [ -n "$line" ] && [ "${line%%|*}" -lt "$TODAY" ]; then has_older=1; fi
done
if [ "$has_older" -eq 0 ]; then
    for i in "${!rest[@]}"; do
        line="${rest[$i]}"
        if [ "${line%%|*}" -lt "$TODAY" ]; then
            echo "behalte $(basename "${line#*|}")  (jüngster Stand vor heute – Rückfallpunkt)"
            unset 'rest[i]'
            break
        fi
    done
fi

for line in "${rest[@]-}"; do
    [ -n "$line" ] || continue
    remove "${line#*|}" "älter als die $KEEP jüngsten, kein benötigter Rückfallpunkt"
done
```

Feldtrenner zwischen Epoch und Pfad ist `|` (Release-Verzeichnisse heißen `vX.Y.Z`, ein `|` kommt darin nie vor).

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `chmod +x deploy/gc.sh && bash -n deploy/gc.sh && deploy/tests/gc-test.sh; echo "exit=$?"`
Expected: sechs `OK`-Zeilen, `== gc-test: alle Fälle grün ==`, `exit=0`.

Falls Fall 4 scheitert, weil `readlink -f` auf den relativen Symlink `releases/cur` nicht denselben Pfad liefert wie auf `$ROOT/releases/cur`: `current` wird bereits mit `readlink -f "$ROOT/current"` aufgelöst und die Verzeichnisse ebenfalls per `readlink -f`, beide sind damit kanonisch. Prüfen, ob `mktemp -d` unter einem Symlink-Pfad liegt (z. B. `/tmp` → `/private/tmp`), dann `newroot` um `readlink -f` ergänzen.

- [ ] **Step 5: Commit**

```bash
git add deploy/gc.sh deploy/tests/gc-test.sh
git commit -F - <<'EOF'
Ergänze Garbage Collection für versionierte Releases

Viele Deploys an einem Tag dürfen den Rückfallpunkt auf einen
nachweislich laufenden Vorstand nicht verdrängen. Neben den fünf
jüngsten Releases bleibt deshalb immer das jüngste, das älter als heute
ist. Die Regeln sind als Bash-Test gegen synthetische Releases fixiert,
damit sie nicht erst in Produktion falsifiziert werden.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
```

---

### Task 8: `deploy/deploy.sh` als Tag-basiertes Release-Deployment

**Files:**
- Modify: `deploy/deploy.sh` (vollständig ersetzen)

**Interfaces:**
- Consumes: `deploy/common.sh` (Task 6), `deploy/smoke.sh` (Task 6), `deploy/gc.sh` (Task 7), zusammengeführte `.htaccess` (Task 5), `FRONTEND_BUILD_DIR` (Task 4).
- Produces: `RELEASE`-Datei im Format `tag=…`, `commit=…`, `deployed_at=<ISO 8601>`, `deployed_at_epoch=<unix>`, `message=<erste Zeile der Tag-Nachricht>`, Leerzeile, vollständige Tag-Nachricht. `rollback.sh` (Task 9) liest `deployed_at_epoch`.

- [ ] **Step 1: `deploy/deploy.sh` vollständig ersetzen**

```bash
#!/usr/bin/env bash
# Deployt einen annotierten Git-Tag als Release auf das Shared-Hosting
# (Spec: docs/superpowers/specs/2026-09-03-updates-endpoint-und-versioniertes-deployment-design.md, Abschnitt 4.4).
#
# Aufruf aus beliebigem Verzeichnis: deploy/deploy.sh vX.Y.Z
#
# Ablauf: Guards → Preflight im Worktree des Tags (PHPUnit, Frontend-Build)
# → Release hochladen → shared/ verknüpfen → Composer, Migrationen, Cache
# → RELEASE schreiben → current atomar tauschen → smoke.sh → gc.sh.
# Schlägt ein Schritt VOR dem Tausch fehl, bleibt current unberührt und das
# unvollständige Release (ohne RELEASE-Datei) wird beim nächsten Lauf ersetzt.
# Schlägt smoke.sh NACH dem Tausch fehl, bricht das Skript mit Hinweis auf
# deploy/rollback.sh ab und tauscht nicht selbst zurück (die Ursache kann
# außerhalb des Releases liegen, etwa ein noch nicht umgestelltes KAS-Docroot).
set -euo pipefail
cd "$(dirname "$0")/.."   # Repo-Root
# shellcheck source=deploy/common.sh
source deploy/common.sh

TAG="${1:-}"
[ -n "$TAG" ] || die "Aufruf: deploy/deploy.sh vX.Y.Z"
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Tag »$TAG« entspricht nicht dem Muster vX.Y.Z"

step "Guards: Tag $TAG"
git rev-parse -q --verify "refs/tags/$TAG" >/dev/null || die "Tag $TAG existiert nicht"
[ "$(git cat-file -t "$TAG")" = "tag" ] || die "Tag $TAG ist nicht annotiert – kein Deploy (git tag -a $TAG -m '…')"
COMMIT=$(git rev-list -n 1 "$TAG")
git fetch -q origin main
git merge-base --is-ancestor "$COMMIT" origin/main || die "Commit $COMMIT von $TAG ist nicht von origin/main erreichbar"
[ -z "$(git status --porcelain)" ] || die "Arbeitsbaum nicht sauber – deployt wird zwar der Tag, aber uncommittete Deploy-Skripte wären trügerisch"
MESSAGE=$(git tag -l --format='%(contents)' "$TAG")
echo "Commit: $COMMIT"; echo "Tag-Nachricht: ${MESSAGE%%$'\n'*}"

step "Guard: Server-Konfiguration"
if remote "test -f '$SHARED_DIR/.env.local' && grep -q __DB_PASSWORT_HIER_EINTRAGEN__ '$SHARED_DIR/.env.local'"; then
    die "in $SHARED_DIR/.env.local fehlt noch das DB-Passwort"
fi
if remote "test -f '$RELEASES_DIR/$TAG/RELEASE'"; then
    die "Release $TAG liegt bereits vollständig auf dem Server – Tags sind unveränderlich; für einen erneuten Deploy neuen Tag setzen"
fi

step "Preflight: Worktree des Tags, Tests, Frontend-Build"
WORK=$(mktemp -d)
cleanup() { git worktree remove --force "$WORK" 2>/dev/null || rm -rf "$WORK"; }
trap cleanup EXIT
git worktree add --detach -q "$WORK" "$TAG"
# Die Test-DB-Konfiguration ist gitignored und muss dem Worktree mitgegeben werden.
[ -f backend/.env.test.local ] && cp backend/.env.test.local "$WORK/backend/.env.test.local"
composer --working-dir="$WORK/backend" install --no-interaction --quiet
php "$WORK/backend/bin/phpunit"
npm --prefix "$WORK/frontend" ci --no-audit --no-fund --silent
npm --prefix "$WORK/frontend" run build
# Der Build hat keine eigene .htaccess mehr (frontend/static/.htaccess wurde
# entfernt); defensiv trotzdem entfernen, damit die zusammengeführte
# backend/public/.htaccess nie überschrieben wird.
rm -f "$WORK/frontend/build/.htaccess"
cp -R "$WORK/frontend/build/." "$WORK/backend/public/"
test -f "$WORK/backend/public/200.html" || die "Frontend-Build nicht in backend/public gelandet"
grep -q 'RewriteRule \^api' "$WORK/backend/public/.htaccess" || die "backend/public/.htaccess ist nicht die zusammengeführte Fassung"

step "Release $TAG hochladen (rsync, --link-dest auf das aktuelle Release)"
CURRENT_TARGET=$(remote "readlink -f '$CURRENT_LINK' 2>/dev/null || true")
remote "rm -rf '$RELEASES_DIR/$TAG' && mkdir -p '$RELEASES_DIR/$TAG'"
LINK_BACKEND=(); LINK_SCHEMA=()
if [ -n "$CURRENT_TARGET" ]; then
    LINK_BACKEND=(--link-dest="$CURRENT_TARGET/backend"); LINK_SCHEMA=(--link-dest="$CURRENT_TARGET/schema")
    echo "Aktuelles Release: $CURRENT_TARGET"
fi
rsync -az "${LINK_BACKEND[@]}" \
    --exclude=/vendor/ --exclude=/var/ --exclude=/tests/ --exclude=/phpunit.dist.xml \
    --exclude=/.phpunit.cache/ --exclude=/.env.local --exclude='/.env.*.local' --exclude=/public/media/ \
    "$WORK/backend/" "$DEPLOY_HOST:$RELEASES_DIR/$TAG/backend/"
rsync -az "${LINK_SCHEMA[@]}" "$WORK/schema/" "$DEPLOY_HOST:$RELEASES_DIR/$TAG/schema/"

step "Server: shared/ verknüpfen, Composer, Migrationen, Cache"
remote DEPLOY_PATH="$DEPLOY_PATH" RELEASE="$RELEASES_DIR/$TAG" SHARED="$SHARED_DIR" CURRENT_TARGET="$CURRENT_TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
# Erster Lauf: shared/ aus dem Legacy-Layout (backend/ direkt unter DEPLOY_PATH) befüllen, danach nie mehr anfassen.
if [ ! -d "$SHARED" ]; then
    mkdir -p "$SHARED"
    legacy="$DEPLOY_PATH/backend"
    [ -f "$legacy/.env.local" ] && cp -a "$legacy/.env.local" "$SHARED/.env.local"
    [ -d "$legacy/public/media" ] && cp -a "$legacy/public/media" "$SHARED/media"
    [ -d "$legacy/var/log" ] && cp -a "$legacy/var/log" "$SHARED/log"
    echo "shared/ neu angelegt und aus $legacy befüllt"
fi
mkdir -p "$SHARED/media" "$SHARED/log"
[ -f "$SHARED/.env.local" ] || { echo "FEHLER – $SHARED/.env.local fehlt (Secrets liegen nie im Repo)" >&2; exit 1; }

ln -sfn "$SHARED/.env.local" "$RELEASE/backend/.env.local"
rm -rf "$RELEASE/backend/public/media"; ln -sfn "$SHARED/media" "$RELEASE/backend/public/media"
mkdir -p "$RELEASE/backend/var"; rm -rf "$RELEASE/backend/var/log"; ln -sfn "$SHARED/log" "$RELEASE/backend/var/log"

# vendor/ als echte Kopie übernehmen (keine Hardlinks: Composer schreibt
# vendor/composer/* in place und würde das alte Release mitverändern).
if [ -n "$CURRENT_TARGET" ] && [ -d "$CURRENT_TARGET/backend/vendor" ]; then
    cp -a "$CURRENT_TARGET/backend/vendor" "$RELEASE/backend/vendor"
fi
cd "$RELEASE/backend"
php85 /usr/bin/composer install --no-dev --optimize-autoloader --no-interaction
php85 bin/console doctrine:migrations:migrate --no-interaction
php85 bin/console cache:clear
REMOTE

step "RELEASE schreiben und current atomar tauschen"
RELEASE_FILE="$WORK/RELEASE"
{
    echo "tag=$TAG"
    echo "commit=$COMMIT"
    echo "deployed_at=$(date -Iseconds)"
    echo "deployed_at_epoch=$(date +%s)"
    echo "message=${MESSAGE%%$'\n'*}"
    echo
    echo "$MESSAGE"
} > "$RELEASE_FILE"
rsync -az "$RELEASE_FILE" "$DEPLOY_HOST:$RELEASES_DIR/$TAG/RELEASE"
remote "ln -s 'releases/$TAG' '$CURRENT_LINK.tmp' && mv -T '$CURRENT_LINK.tmp' '$CURRENT_LINK'"
echo "current -> releases/$TAG"

step "Smoke-Check"
if ! deploy/smoke.sh "$SITE_ORIGIN"; then
    die "Smoke-Check fehlgeschlagen. current zeigt auf $TAG. Zurück mit: deploy/rollback.sh"
fi

step "Garbage Collection"
remote 'bash -s' -- --root "$DEPLOY_PATH" < deploy/gc.sh

echo; echo "== Deploy $TAG erfolgreich =="
```

- [ ] **Step 2: Syntax prüfen und Guards ohne Serverkontakt testen**

Run:

```bash
bash -n deploy/deploy.sh && echo SYNTAX-OK
deploy/deploy.sh 2>&1 | head -1                       # kein Tag
deploy/deploy.sh v1 2>&1 | head -1                    # falsches Muster
deploy/deploy.sh v99.99.99 2>&1 | tail -1             # Tag existiert nicht
git tag gc-probe-lightweight && deploy/deploy.sh gc-probe-lightweight 2>&1 | head -1; git tag -d gc-probe-lightweight
```

Expected: `SYNTAX-OK`; `FEHLER – Aufruf: deploy/deploy.sh vX.Y.Z`; `FEHLER – Tag »v1« entspricht nicht dem Muster vX.Y.Z`; `FEHLER – Tag v99.99.99 existiert nicht`; für den leichtgewichtigen Probe-Tag `FEHLER – Tag »gc-probe-lightweight« entspricht nicht dem Muster …` (Muster-Guard greift vor dem Annotations-Guard, das ist in Ordnung). Zusätzlich den Annotations-Guard isoliert prüfen:

```bash
git tag v0.0.0 && deploy/deploy.sh v0.0.0 2>&1 | grep -m1 'nicht annotiert'; git tag -d v0.0.0
```

Expected: `FEHLER – Tag v0.0.0 ist nicht annotiert – kein Deploy …`. Die Probe-Tags müssen danach weg sein: `git tag -l 'v0.0.0*'` liefert nichts. **Kein** Lauf mit einem echten annotierten Tag im Rahmen dieses Plans (würde den Server anfassen).

- [ ] **Step 3: Commit**

```bash
git add deploy/deploy.sh
git commit -F - <<'EOF'
Baue das Deployment auf versionierte Releases per Tag um

Bisher überschrieb rsync --delete das laufende Docroot; ein Rollback
hieß alten Git-Stand neu deployen. Jetzt deployt ein annotierter Tag
in releases/<tag>, geteilter Zustand liegt in shared/, und current
wird atomar umgesetzt. Damit erreicht gestura.eu die API ohne
Umleitung aus demselben Docroot, wie es der Vertrag mit der Extension
verlangt, und ein fehlgeschlagenes Release lässt sich per Symlink
zurücknehmen statt neu bauen.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
```

---

### Task 9: `deploy/rollback.sh`

**Files:**
- Create: `deploy/rollback.sh`

**Interfaces:**
- Consumes: `RELEASE`-Datei (`deployed_at_epoch=`), `common.sh`, `smoke.sh`.
- Produces: `rollback.sh [vX.Y.Z]`; ohne Argument das jüngste vollständige Release mit `deployed_at_epoch` kleiner als das von `current`.

- [ ] **Step 1: Skript schreiben**

```bash
#!/usr/bin/env bash
# Schaltet current auf ein früheres Release zurück (Spec 2026-09-03, Abschnitt 4.5).
# Aufruf: deploy/rollback.sh [vX.Y.Z]
#   ohne Argument: das jüngste vollständige Release, das VOR dem aktuellen
#   deployt wurde (deployed_at_epoch kleiner als das von current).
# Migrationen bleiben vorwärtsgerichtet – ein Schema-Rollback ist manuell:
#   php85 bin/console doctrine:migrations:migrate <version>  (im Zielrelease)
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=deploy/common.sh
source deploy/common.sh

TARGET="${1:-}"

step "Zielrelease bestimmen"
TARGET=$(remote RELEASES="$RELEASES_DIR" CURRENT="$CURRENT_LINK" TARGET="$TARGET" 'bash -s' <<'REMOTE'
set -euo pipefail
current=$(readlink -f "$CURRENT" 2>/dev/null || true)
[ -n "$current" ] || { echo "FEHLER – current existiert nicht" >&2; exit 1; }
epoch_of() { sed -n 's/^deployed_at_epoch=//p' "$1/RELEASE" 2>/dev/null | head -1; }
if [ -n "$TARGET" ]; then
    dir="$RELEASES/$TARGET"
    [ -d "$dir" ] || { echo "FEHLER – Release $TARGET existiert nicht" >&2; exit 1; }
    [ -f "$dir/RELEASE" ] || { echo "FEHLER – Release $TARGET ist unvollständig (keine RELEASE-Datei)" >&2; exit 1; }
    [ "$(readlink -f "$dir")" != "$current" ] || { echo "FEHLER – $TARGET ist bereits current" >&2; exit 1; }
    echo "$TARGET"; exit 0
fi
cur_epoch=$(epoch_of "$current"); [[ "$cur_epoch" =~ ^[0-9]+$ ]] || cur_epoch=9999999999
best=""; best_epoch=0
for dir in "$RELEASES"/*/; do
    dir="${dir%/}"
    [ "$(readlink -f "$dir")" = "$current" ] && continue
    e=$(epoch_of "$dir"); [[ "$e" =~ ^[0-9]+$ ]] || continue
    if [ "$e" -lt "$cur_epoch" ] && [ "$e" -gt "$best_epoch" ]; then best="$(basename "$dir")"; best_epoch="$e"; fi
done
[ -n "$best" ] || { echo "FEHLER – kein vollständiges Release vor current gefunden" >&2; exit 1; }
echo "$best"
REMOTE
)
echo "Zurück auf: $TARGET"

step "current atomar auf $TARGET setzen"
remote "ln -s 'releases/$TARGET' '$CURRENT_LINK.tmp' && mv -T '$CURRENT_LINK.tmp' '$CURRENT_LINK' && php85 '$RELEASES_DIR/$TARGET/backend/bin/console' cache:clear"

step "Smoke-Check"
deploy/smoke.sh "$SITE_ORIGIN" || die "Smoke-Check nach Rollback fehlgeschlagen – current zeigt auf $TARGET"

echo; echo "== Rollback auf $TARGET erfolgreich =="
```

- [ ] **Step 2: Syntax prüfen**

Run: `chmod +x deploy/rollback.sh && bash -n deploy/rollback.sh && echo SYNTAX-OK`
Expected: `SYNTAX-OK`. Kein Lauf gegen den Server im Rahmen dieses Plans.

- [ ] **Step 3: Commit**

```bash
git add deploy/rollback.sh
git commit -F - <<'EOF'
Ergänze Rollback auf ein früheres Release

Mit versionierten Releases ist ein Rollback ein Symlink-Tausch, kein
erneuter Build. Ohne Argument wählt das Skript das jüngste Release,
das vor dem aktuellen deployt wurde; unvollständige Releases ohne
RELEASE-Datei kommen nie in Frage.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
```

---

### Task 10: Dokumentation (README, CLAUDE.md, lessons.md)

**Files:**
- Modify: `deploy/README.md`
- Modify: `CLAUDE.md` (Abschnitt »Deployment (Zielumgebung, bestätigt)«)
- Modify: `.claude/lessons.md`

- [ ] **Step 1: `deploy/README.md` umschreiben**

Die Abschnitte »Skripte«, »Einmalige manuelle Einrichtung (KAS)« und »Rollback« vollständig ersetzen durch:

````markdown
## Skripte

- `verify-hosting.sh` – prüft die Server-Umgebung (php85, Module inkl. WebP/Argon2id, Composer, DB-Verbindung). Jederzeit gefahrlos wiederholbar.
- `deploy.sh vX.Y.Z` – deployt einen **annotierten** Git-Tag als Release: Guards (Tag annotiert, Commit auf `origin/main`, Arbeitsbaum sauber), Preflight im Worktree des Tags (PHPUnit, `npm run build`), Upload nach `releases/<tag>/`, `shared/` verknüpfen, `php85 composer install --no-dev -o`, Migrationen, `cache:clear`, `RELEASE`-Datei, atomarer Tausch von `current`, `smoke.sh`, `gc.sh`. Leichtgewichtige Tags und Tags außerhalb von `main` werden mit Klartext abgelehnt. Braucht lokal `backend/.env.test.local` (Test-DB) für den Preflight.
- `rollback.sh [vX.Y.Z]` – setzt `current` auf ein früheres vollständiges Release (ohne Argument: das jüngste vor dem aktuellen) und läuft `smoke.sh`. Migrationen bleiben vorwärtsgerichtet.
- `smoke.sh [origin]` – prüft gegen `https://gestura.eu` (Default): Preflight und leere Antwort von `POST /api/v1/updates` ohne Umleitung, `GET /api/v1/entries` als JSON, `/de` als HTML, `/de/vergleich` erreicht Symfony. Nennt ein noch nicht umgestelltes KAS-Docroot ausdrücklich.
- `gc.sh --root <pfad> [--dry-run]` – Garbage Collection der Releases (läuft auf dem Server per `ssh … 'bash -s' -- --root … < deploy/gc.sh`): `current` nie; die 5 jüngsten bleiben; zusätzlich immer das jüngste Release, das älter als heute ist, falls keines der 5 das schon ist; unvollständige Releases (ohne `RELEASE`) verschwinden. Regeln sind in `tests/gc-test.sh` fixiert.

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

Ein Release ohne `RELEASE`-Datei ist unvollständig (abgebrochener Deploy): nie Rollback-Ziel, wird von `gc.sh` entfernt, vom nächsten Deploy desselben Tags ersetzt. Der Symlink-Tausch ist atomar (`ln -s … current.tmp && mv -T current.tmp current`). PHPs Realpath-Cache kann danach bis zu `realpath_cache_ttl` (Default 120 s) alte Pfade auflösen – auf Shared-Hosting mit kurzlebigen CGI-Prozessen praktisch unsichtbar.

### `.env.local`-Variablen (Server, nie im Repo)

Zusätzlich zu den unten dokumentierten Admin-Variablen:

- `FRONTEND_BUILD_DIR=%kernel.project_dir%/public` – der prerenderte Frontend-Build liegt im gemeinsamen Docroot; der `MarketingPageController` liest die schaltbaren Seiten von dort. Ohne diesen Wert greift der Dev-Default `../frontend/build`, der auf dem Server nicht existiert.

## Umstellung vom alten Layout (einmalig)

Ausgangslage: `backend/`, `frontend/`, `schema/` direkt unter dem Deploy-Pfad, zwei Docroots (`api.gestura.eu` → `backend/public`, `gestura.eu` → `frontend`).

1. Annotierten Tag setzen (`git tag -a vX.Y.Z -m '…'`, pushen) und `deploy/deploy.sh vX.Y.Z` ausführen. Der erste Lauf legt `releases/`, `shared/` (befüllt aus dem alten `backend/`: `.env.local`, `public/media`, `var/log`) und `current` an. Die alten Docroots laufen weiter. `smoke.sh` schlägt an diesem Punkt **erwartungsgemäß** mit dem KAS-Hinweis fehl; `current` zeigt trotzdem auf das neue Release.
2. `FRONTEND_BUILD_DIR=%kernel.project_dir%/public` in `shared/.env.local` ergänzen, danach im Release `php85 bin/console cache:clear`.
3. Im KAS zuerst **`api.gestura.eu`** auf `…/gestura.eu/current/backend/public` umstellen und `deploy/smoke.sh https://api.gestura.eu` laufen lassen. Grün heißt: Apache liefert durch den Symlink aus (`FollowSymLinks`/`SymLinksIfOwnerMatch`; mod_rewrite funktioniert heute schon und setzt eine der beiden Optionen voraus) und die zusammengeführte `.htaccess` greift.
4. **`gestura.eu`** (und `www`) auf dasselbe Docroot umstellen, `deploy/smoke.sh` ohne Argument.
5. Erst nach grünem Smoke-Check die alten Verzeichnisse `backend/`, `frontend/`, `schema/` unter dem Deploy-Pfad entfernen.

## Rollback

`deploy/rollback.sh` (jüngstes Release vor dem aktuellen) oder `deploy/rollback.sh vX.Y.Z`. Migrationen sind vorwärtsgerichtet – bei Schema-Rollbacks `php85 bin/console doctrine:migrations:migrate <version>` im Zielrelease.

## Ausblick: Deploy bei Tag-Push (Folgepaket)

Eine GitHub-Actions-Automatik, die bei Push eines annotierten `v*`-Tags `deploy.sh` ausführt, ist als eigenes Paket vorgesehen (SSH-Deploy-Key als Secret, MariaDB-Service für PHPUnit, `fetch-tags` für die Annotationsprüfung). Bis dahin läuft `deploy.sh` von Hand.
````

Im Abschnitt »Schaltbare Seiten (Admin-Seiten-Sichtbarkeit)« die vier Aufzählungspunkte ersetzen durch:

```markdown
- **`FRONTEND_BUILD_DIR`** muss in `shared/.env.local` auf `%kernel.project_dir%/public` zeigen (siehe oben), weil der Build im gemeinsamen Docroot liegt.
- **Die zusammengeführte `backend/public/.htaccess` ist die einzige Regelquelle** (Marketing-Interceptor vor Datei-, Add-.html- und SPA-Fallback-Regel; `/api/…` davor immer an Symfony). `frontend/static/.htaccess` existiert bewusst nicht mehr. Die Datei ist recipe-managed (`symfony/framework-bundle`); nach Recipe-Updates prüfen, dass Regeln 1–7 erhalten sind (siehe `.claude/lessons.md`).
```

Den Absatz »Der Frontend-Build wird wie gehabt ins Web-Root der Index-Domain geladen …« am Anfang des Abschnitts ersetzen durch: »Der Frontend-Build liegt seit dem versionierten Deployment in `releases/<tag>/backend/public/` (gemeinsames Docroot). Der `MarketingPageController` liest die prerenderten Dateien von dort und entscheidet je Aufruf von `/{locale}/{slug}` anhand des persistierten `PageSetting`-Flags, ob die HTML ausgeliefert (200) oder ein echtes 404 zurückgegeben wird.«

Im Abschnitt »Admin-SPA (`/admin`)« die Formulierung »Apache-Rewrite in der `.htaccess` des Frontend-Docroots« ersetzen durch »Regel 7 der zusammengeführten `backend/public/.htaccess`«.

- [ ] **Step 2: `CLAUDE.md` Deployment-Abschnitt anpassen**

Den Abschnitt »## Deployment (Zielumgebung, bestätigt)« ersetzen durch:

```markdown
## Deployment (Zielumgebung, bestätigt)

Shared-Linux-Hosting mit SSH, MySQL, Composer 2.9.8. **PHP-CLI heißt dort `php85`**, nicht `php` – Deploy-Skripte müssen `php85` verwenden. **Versionierte Releases:** `deploy/deploy.sh vX.Y.Z` deployt einen **annotierten** Git-Tag nach `releases/<tag>/`, geteilter Zustand liegt in `shared/`, `current` zeigt auf das aktive Release. Docroot **beider** Domains (`gestura.eu`, `api.gestura.eu`) ist `current/backend/public/` – der Frontend-Build liegt im Release in `backend/public/`, damit die Extension `https://gestura.eu/api/v1/updates` ohne Umleitung erreicht. `deploy/rollback.sh` schaltet zurück, `deploy/smoke.sh` prüft, `deploy/gc.sh` räumt auf. Secrets ausschließlich in `shared/.env.local`. Details: `deploy/README.md`.
```

- [ ] **Step 3: `.claude/lessons.md` anpassen**

Den bestehenden Eintrag, der mit »**`backend/public/.htaccess` ist recipe-managed**« beginnt, vollständig ersetzen durch:

```markdown
- **`backend/public/.htaccess` ist die EINZIGE Regelquelle des gemeinsamen Docroots und recipe-managed** (kommt aus dem `symfony/framework-bundle`-Recipe). Seit dem versionierten Deployment liegt der Frontend-Build in `backend/public/`, und `frontend/static/.htaccess` existiert bewusst nicht mehr – eine `.htaccess` im Build würde beim Kopieren die Backend-Datei überschreiben (`deploy.sh` entfernt sie defensiv). Reihenfolge der Regeln, die erhalten bleiben muss: (1) `/api/…` immer an `index.php` – der Vertrag mit der Extension verbietet jedes `3xx` auf `/api/v1/updates`, also darf die Trailing-Slash-Umleitung die API nie berühren; (2) Marketing-Interceptor `^(de|en)/(was-ist-gestura|maus-gesten|vergleich|beispiele)/?$` an `index.php` – VOR der Datei-Regel und VOR der Add-.html-Regel, sonst liefert Apache `de/vergleich.html` statisch mit 200 aus, bevor Symfony das echte 404 einer deaktivierten Seite zeigen kann; (3) `-f`; (4) Trailing Slash; (5) Add-.html; (6) `-d`; (7) SPA-Fallback auf `/200.html`. `DirectoryIndex index.html index.php` in dieser Reihenfolge (sonst 404 auf `/`), `DirectorySlash Off` (sonst `/de` → `/de/`, weil `de/` neben `de.html` existiert). Ein `composer recipes:update symfony/framework-bundle` verwirft alles davon stillschweigend – danach prüfen und wieder einfügen; `deploy.sh` bricht ab, wenn die `/api/`-Regel fehlt.
```

Am Ende der Datei ergänzen:

```markdown
- **Versionierte Releases: die `RELEASE`-Datei ist der Vollständigkeitsnachweis.** `deploy.sh` schreibt sie als LETZTEN Schritt vor dem Symlink-Tausch. Ein Release-Verzeichnis ohne sie ist ein abgebrochener Deploy: `rollback.sh` wählt es nie, `gc.sh` löscht es, der nächste Deploy desselben Tags ersetzt es. Wer serverseitig Hand anlegt, darf die Datei nie »vorab« anlegen.
- **`shared/` ist der einzige persistente Zustand zwischen Releases** (`.env.local`, `media/`, `log/`); alles unter `var/` ist releasegebunden (Cache, Sessions, Rate-Limiter-Pool). Admin-Sessions enden mit jedem Deploy (30-Minuten-Idle macht das unauffällig). Wer neue Dateien persistieren muss (Uploads, Exporte), legt sie in `shared/` und ergänzt den Symlink-Block in `deploy.sh` – sonst verschwinden sie mit dem Release in der Garbage Collection.
- **`vendor/` wird zwischen Releases per `cp -a` kopiert, NICHT per Hardlink (`cp -al`):** Composer schreibt `vendor/composer/*` und `autoload.php` in place; Hardlinks würden das alte Release mitverändern und den Rollback verfälschen. Für den restlichen Quellcode ist rsync `--link-dest` unbedenklich, weil dort nichts in place schreibt.
- **Der Update-Check-Endpunkt `POST /api/v1/updates` bildet die Download-`url` aus `getSchemeAndHttpHost()` des Requests.** Der Client verwirft jede URL, die nicht auf der antwortenden Origin liegt – ein fest konfigurierter Host (etwa `api.gestura.eu`) wäre auf `gestura.eu` falsch und beim Dev-Index erst recht. `version: null` ist im Vertrag eine Frage (»sag mir die aktuelle Version«), kein Fehler; `deprecated` wird auch bei unveränderter Version gemeldet. Vertragskopie: `docs/gestura-eu-api.md`.
```

- [ ] **Step 4: Verweise auf den alten Zustand suchen**

Run: `grep -rn "frontend/static/.htaccess\|api/v1/entries/updates\|rsync --delete\|Kein Releases-Mechanismus" CLAUDE.md deploy/README.md .claude/lessons.md docs/gestura-index-context.md docs/design-system.md 2>/dev/null`
Expected: Keine Treffer außer bewussten Erwähnungen (»existiert bewusst nicht mehr«). `docs/gestura-index-context.md` beschreibt die Deploy-Zeile noch als »SSH/rsync + composer install + migrate« mit Docroot `backend/public/` – das bleibt inhaltlich richtig (Docroot ist `current/backend/public`); historische Pläne und Specs werden nicht umgeschrieben.

- [ ] **Step 5: Commit**

```bash
git add deploy/README.md CLAUDE.md .claude/lessons.md
git commit -F - <<'EOF'
Dokumentiere Release-Layout, Runbook und .htaccess-Regeln

Das versionierte Deployment ändert, wo der Frontend-Build liegt, welche
Docroots gelten und wie ein Rollback aussieht. Ohne aktualisiertes
Runbook wäre die einmalige KAS-Umstellung Rätselraten, und die
zusammengeführte .htaccess braucht einen Merkposten für den Fall, dass
ein Recipe-Update sie überschreibt.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
EOF
```

---

### Task 11: Abschlussprüfung

**Files:** keine neuen.

- [ ] **Step 1: Backend-Suite komplett**

Run: `php backend/bin/phpunit; echo "exit=$?"`
Expected: `OK`, `exit=0`.

- [ ] **Step 2: Frontend-Checks**

Run: `npm --prefix frontend run check; echo "exit=$?"; npm --prefix frontend run test -- --run; echo "exit=$?"`
Expected: beide `exit=0` (die bekannte Warnung `Unknown property: 'corner-shape'` in `MenuPreview.svelte` ist erlaubt). Hinweis: Nach `paraglide compile` einen laufenden Vite-Dev-Server neu starten (lessons.md).

- [ ] **Step 3: Deploy-Skripte**

Run: `for f in deploy/*.sh deploy/tests/*.sh; do bash -n "$f" || echo "SYNTAXFEHLER $f"; done; deploy/tests/gc-test.sh; ls -l deploy/*.sh deploy/tests/*.sh | awk '{print $1, $NF}'`
Expected: keine Syntaxfehler, gc-test grün, alle Skripte ausführbar (`-rwx…`).

- [ ] **Step 4: Arbeitsbaum und Historie**

Run: `git status --short; git log --oneline main..HEAD`
Expected: Nur `exchange/` untracked; zwölf Commits auf dem Branch (Spec, Plan, dann zehn Task-Commits: Vertragskopie, Limiter, Endpunkt, `FRONTEND_BUILD_DIR`, `.htaccess`, smoke, gc, deploy, rollback, Doku).

- [ ] **Step 5: Übergabe an den Eigentümer**

Kein Deploy im Rahmen des Plans. Zusammenfassung ausgeben: Was gebaut wurde, dass `smoke.sh` gegen die heutige Produktion den KAS-Hinweis liefert (erwartet), und dass der nächste Schritt das Runbook in `deploy/README.md` (Abschnitt »Umstellung vom alten Layout«) ist – beginnend mit einem annotierten Tag.

---

## Selbst-Review gegen das Spec

- **3.1–3.5 Endpunkt:** Task 3 (Controller, Tests, CORS-Test), Task 2 (Limiter). ✔
- **4.1 `.htaccess` / Frontend-Datei löschen:** Task 5. ✔ **4.2 `FRONTEND_BUILD_DIR`:** Task 4. ✔
- **4.3 Layout, 4.4 `deploy.sh`:** Task 8 (inkl. `shared/`-Bootstrap, `cp -a` für `vendor/`, `RELEASE`, atomarer Tausch, Verhalten bei Smoke-Fehler). ✔
- **4.5 `rollback.sh`:** Task 9. ✔ **4.6 `smoke.sh`:** Task 6. ✔ **4.7 `gc.sh` + Dry-Run + Test:** Task 7. ✔ **4.8 Runbook:** Task 10. ✔
- **5 Tests:** Funktionstests, CORS, Limiter-Konfig, alter Pfad, gc-Test. ✔ **6 Doku, Vertragskopie:** Task 1, Task 10. ✔
- **7 Rückmeldung an Extension-Repo:** steht im Spec; keine Code-Aufgabe. **8 Nicht-Ziele:** unberührt.
- **Typkonsistenz:** `RELEASE`-Schlüssel `deployed_at_epoch` in Task 7 (gc), Task 8 (deploy) und Task 9 (rollback) identisch; `common.sh`-Namen (`DEPLOY_PATH`, `RELEASES_DIR`, `SHARED_DIR`, `CURRENT_LINK`, `SITE_ORIGIN`, `die`, `step`, `remote`) in Task 6, 8, 9 identisch; `ApiLevel::IMPLEMENTED` in Task 3 (Konstante, Controller, Test) identisch, `smoke.sh` (Task 6) prüft die Form unabhängig vom Wert.

## Abgleich mit dem R3-Vertrag (Sync, apiLevel 3)

Der Vertragsabzug in `exchange/` wurde am 2026-09-03 auf Stand R3 überschrieben (`feature/eu-integration-r3@4c9f2bb`). Geprüft, ob etwas darin gegen die Umsetzung dieses R2-Plans spricht: **nein.** Die Level sind additiv, der Abschnitt »Update check« ist bis auf die Beispielzahl `apiLevel: 3` unverändert, und der Client liest `apiLevel` aus der Antwort gar nicht (`parseUpdateResponse` prüft nur `updates`). Folgen für diesen Plan sind oben eingearbeitet: Vertragskopie im R3-Stand (Task 1), gemeinsame Konstante `ApiLevel::IMPLEMENTED` (Task 3), wertunabhängiger Smoke-Check (Task 6).

Was das R3-Paket von diesem Layout übernehmen muss, damit es hier nicht kollidiert:

- **Blob-Ablage gehört in `shared/`, nie ins Release.** Speichert R3 die Sync-Blobs im Dateisystem, braucht es ein Verzeichnis unter `shared/` und eine Zeile im Symlink-Block von `deploy.sh`; sonst löscht `gc.sh` die Blobs mit dem Release. Alternativ MySQL (`MEDIUMBLOB`, Blobs bis 512 KiB, 4 MiB je Locator) – dann entfällt die Frage. Der Locator (`^[A-Za-z0-9_-]{43}$`) darf nie ungeprüft zum Pfad werden.
- **Request-Bodies nicht protokollieren.** `shared/log/` ist persistent; nichts darin darf Bodies der `/api/v1/sync/*`-Requests enthalten (der Locator ist ein Bearer-Token). Symfony/Monolog loggt Bodies nicht von selbst; Exception-Handler und eigene Log-Aufrufe müssen es auch nicht tun. Apache-Access-Logs des Hosters enthalten keine Bodies.
- **Fehlerformat abweichend:** R3 verlangt `{ "error": "<code>" }` mit den fünf Codes `bad-request`, `not-found`, `too-large`, `quota-states`, `rate-limited`, nicht das `application/problem+json` von `ApiProblem`. Die Sync-Controller brauchen eine eigene Fehlerklasse oder einen eigenen Response-Pfad; der bestehende `ProblemJsonSubscriber` darf sie nicht umformen.
- **CORS** ist bereits ausreichend (`GET, POST, PUT, DELETE, OPTIONS`, `Content-Type`); `.htaccess`-Regel 1 leitet `/api/v1/sync/*` ohne Umleitung an Symfony. Keine Änderung nötig.
- **Namenskollision im Bestand:** Unter `/api/account/sync/*` existiert bereits der kontogebundene Settings-Sync aus Phase 3 (Sub-Projekt F, `SyncBlob` mit FK auf `account`, Bearer `gacc_…`). R3 beschreibt einen davon unabhängigen, anonymen, locator-basierten Sync unter `/api/v1/sync/*`. Vor dem R3-Plan ist zu entscheiden, ob beide nebeneinander bestehen oder der Konto-Sync auf das R3-Modell umgestellt wird – das ist eine Entscheidung des Eigentümers, kein Detail der Umsetzung.
- **Retention 12 Monate** braucht einen Cron (`php85 bin/console index:sync:prune` o. ä.) im Runbook; das Layout mit `current/backend/bin/console` macht den Cron-Pfad releaseunabhängig.
- **`ApiLevel::IMPLEMENTED` auf 3 heben** erst, wenn alle vier Sync-Endpunkte antworten – zusammen mit dem Bump muss `smoke.sh` um Sync-Preflight und einen `list`-Aufruf mit unbekanntem Locator (erwartet `{"states":[]}`) erweitert werden.
