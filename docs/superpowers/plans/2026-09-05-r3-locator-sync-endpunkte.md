# R3 — Die vier Locator-Sync-Endpunkte (apiLevel 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `POST /api/v1/sync/list`, `PUT /api/v1/sync/state`, `POST /api/v1/sync/get` und `POST /api/v1/sync/delete` bauen — anonym, locator-adressiert, mit serverseitig durchgesetzten Grenzen, gehashter Locator-Ablage, exaktem 412-Konfliktschutz und 12-Monats-Aufbewahrung.

**Architecture:** Ein neues Aggregat `SyncState` (Zeile = ein Stand unter einem Locator), adressiert ausschließlich über `SHA-256(locator)` — der Klartext-Locator wird nie gespeichert. Vier schlanke Invokable-Controller delegieren an einen `LocatorSyncService`, der Quote, Konflikt und Schreibvorgang in **einer** Transaktion mit Pessimistic Lock kapselt. Fehler reisen als `SyncProblem` und werden vom bestehenden `ProblemJsonSubscriber` in die vertragsexakte Form `{ "error": "<code>" }` gerendert — ein zweiter Antwortstil neben RFC 7807, weil der Vertrag ihn wörtlich vorschreibt.

**Tech Stack:** Symfony 7.4 LTS, Doctrine ORM, MySQL/MariaDB, PHPUnit (`php backend/bin/phpunit`), PHP 8.5 (Prod-CLI `php85`).

**Spec:**
- **Autoritativ:** `/mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md`, Commit `289a3e7` auf `main`, Abschnitte *Sync — the secret code* bis *Sync — endpoints*. Nie die Kopie, immer diese Datei.
- **Auftrag:** `exchange/2026-09-05-instruktion-was-der-index-bauen-muss.md`
- **Abzug zum Mitlesen:** `exchange/2026-09-05-gestura-eu-api.md` (byte-identisch zu `289a3e7`)

---

## Global Constraints

Diese gelten für **jede** Aufgabe unten; sie werden nicht wiederholt.

- **Der Locator wird nie im Klartext gespeichert.** Abgelegt und nachgeschlagen wird ausschließlich `hash('sha256', $locator)` als 64-stelliger Hex-String. Kein Salz (256 gleichverteilte Bits).
- **Die Locator-Form wird geprüft, bevor sie irgendetwas adressiert:** `^[A-Za-z0-9_-]{43}$`. Ungeprüft wäre das ein Pfad-Traversal.
- **Request-Bodies dürfen nicht protokolliert werden.** Kein `$logger->…($request->getContent())`, kein `dump()`, kein Exception-Kontext, der den Body mitträgt. Der Locator reist nur im Body.
- **Der HTTP-Status entscheidet, nicht der Body** (Vertrag, *How the client reads an answer*). Der Client liest den `error`-String nie; er liest genau einen Body — den des `412` — und daraus nur `updatedAt`. Ein falscher Status wird falsch gelesen, egal wie richtig der Body ist.
- **Keine Weiterleitung auf `/api/v1/sync/*`.** Ein `3xx` ist für den Client ein gescheiterter Aufruf; ein `307`/`308` würde Methode **und** Body weiterreichen — und im Body steht der Locator.
- **Antworten bleiben unter 1 MiB.** Größte legitime Antwort: ein `get` mit einem 512-KiB-Envelope.
- Fehlercodes und Status, wörtlich aus dem Vertrag:

  | Code | Status | Auslöser |
  |---|---|---|
  | `bad-request` | 400 | Malformed body, unbekanntes `apiLevel`, falsche `stateId`- oder Locator-Form |
  | `not-found` | 404 | Kein solcher Stand unter diesem Locator |
  | `conflict` | 412 | `basePayloadHash` beschreibt den gespeicherten Stand nicht |
  | `too-large` | 413 | Ein einzelner Blob überschreitet seine Grenze |
  | `quota-states` | 409 | Der Locator hält bereits die Höchstzahl an Ständen |
  | `rate-limited` | 429 | Per-IP-Ratelimit auf Anfragen und geschriebene Bytes |

- Grenzen, wörtlich aus dem Vertrag: `meta`-Envelope **8 KiB wie übertragen**, `payload`-Envelope **512 KiB wie übertragen**, **5** Stände pro Locator, 4 MiB Summe pro Locator. Die Ständegrenze wird **nur beim Anlegen** geprüft, nie rückwirkend. Einen Code `locator-full` gibt es nicht — die Summe wird deshalb **nicht** durchgesetzt.
- **Aufbewahrung:** ein Stand, der 12 Monate weder gelesen noch geschrieben wurde, wird gelöscht.
- Kommentare und Commit-Messages auf Deutsch, Guillemets »…«, Halbgeviertstrich – (Projekt-Konvention).
- Nach jeder Aufgabe: `php backend/bin/phpunit; echo $?` — **Exit-Code prüfen**, nicht nur den Text. Die Suite läuft mit `failOnDeprecation="true"` und kann »OK« drucken und trotzdem mit 1 enden.

### Zwei Auslegungen, die wir treffen mussten

Beide sind unten so implementiert und werden im Logbuch als Rückfrage an die Extension-Seite gestellt (Aufgabe 9). Falls die Antwort abweicht, ändert sich jeweils genau eine Konstante bzw. eine Zeile.

1. **`size` und die Blob-Grenzen messen die Länge des Base64-Strings**, nicht die der dekodierten Bytes. Der Vertrag sagt »the payload envelope's length in bytes as transmitted«; übertragen wird der Base64-String. Beide Zahlen (Grenze und `size`) benutzen dieselbe Formulierung, also dieselbe Messung. Der Unterschied wäre Faktor 4/3.
2. **`apiLevel` im Request wird tolerant geprüft:** vorhanden und ein Integer, sonst `bad-request`. Kein Mindest- oder Höchstwert — ein Client unter Level 3 ruft diese Endpunkte nie auf, und ein künftiger Level-4-Client darf nicht abgewiesen werden. Das folgt der Toleranzregel im Kopf des Vertrags und der bestehenden Handhabung in `UpdateCheckController`.

---

## File Structure

| Datei | Verantwortung |
|---|---|
| `backend/src/Api/SyncContract.php` | **Neu.** Reine Vertragswerte: Regexe für Locator und `stateId`, die vier Grenzen, `locatorHash()`, `payloadHash()`. Eine Datei, in der jede Zahl aus dem Vertrag genau einmal steht — Vorbild ist `App\Api\ExchangeFormat`. |
| `backend/src/Entity/SyncState.php` | **Neu.** Ein Stand: `locatorHash`, `stateId`, `meta`, `payload`, `payloadHash`, `sizeBytes`, `createdAt`, `updatedAt`, `lastAccessAt`. |
| `backend/src/Repository/SyncStateRepository.php` | **Neu.** Nachschlagen über den Hash, Sperren, Zählen, Bulk-Delete für die Aufbewahrung. |
| `backend/src/Exception/SyncProblem.php` | **Neu.** Fehler in Vertragsform (`error`-Code + optionale Felder). |
| `backend/src/EventSubscriber/ProblemJsonSubscriber.php` | **Ändern.** Rendert `SyncProblem` als `{ "error": … }`, alles andere unverändert als RFC 7807. |
| `backend/src/Service/RateLimitGuard.php` | **Ändern.** Optionales `$tokens`-Argument für das Byte-Limit. |
| `backend/src/Service/LocatorSyncService.php` | **Neu.** Der gesamte Zustandsübergang: Quote, `basePayloadHash`-Vergleich, Schreiben, Löschen — transaktional, mit Sperre. |
| `backend/src/Controller/Api/LocatorSyncListController.php` | **Neu.** `POST /api/v1/sync/list` |
| `backend/src/Controller/Api/LocatorSyncPutController.php` | **Neu.** `PUT /api/v1/sync/state` |
| `backend/src/Controller/Api/LocatorSyncGetController.php` | **Neu.** `POST /api/v1/sync/get` |
| `backend/src/Controller/Api/LocatorSyncDeleteController.php` | **Neu.** `POST /api/v1/sync/delete` |
| `backend/src/Controller/Api/SyncRequest.php` | **Neu.** Gemeinsames Parsen und Prüfen des Umschlags (JSON, `apiLevel`, `locator`, `stateId`) — vier Controller, eine Prüfung. |
| `backend/src/Command/SyncPruneCommand.php` | **Neu.** `index:sync:prune` für die 12-Monats-Frist. |
| `backend/migrations/VersionYYYYMMDDHHMMSS.php` | **Neu.** Tabelle `sync_state`. |
| `backend/config/packages/rate_limiter.yaml` | **Ändern.** `sync_v1` (Anfragen) und `sync_v1_bytes` (geschriebene KiB) in allen drei Blöcken. |
| `backend/src/Api/ApiLevel.php` | **Ändern, zuletzt.** `IMPLEMENTED = 3`. |
| `docs/gestura-eu-api.md` | **Ändern.** Vertragskopie auf `289a3e7` nachziehen. |
| `.claude/lessons.md`, `exchange/AUSTAUSCH.md` | **Ändern.** Fallen festhalten, Rückmeldung eintragen. |
| `backend/tests/Unit/SyncContractTest.php` | **Neu.** Regexe, Grenzen, Hash-Funktionen — inkl. Vertrags-Testvektor. |
| `backend/tests/Functional/LocatorSyncTest.php` | **Neu.** Die vier Endpunkte, alle Fehlercodes, der 412-Pfad. |
| `backend/tests/Command/SyncPruneCommandTest.php` | **Neu.** Aufbewahrung. |

**Namensgebung:** Alles Neue trägt `LocatorSync`/`SyncState` im Namen. Es gibt bereits `SyncGetController`, `SyncPutController`, `SyncDeleteController`, `SyncOverviewController` und die Entity `SyncBlob` — das ist der **kontogebundene** Settings-Sync unter `/api/account/sync/{collection}` aus dem Juli-Plan. Die beiden haben nur das Wort gemeinsam (so steht es auch im Logbuch). Keine der bestehenden Dateien wird angefasst.

---

## Task 1: Der Vertrag als Code — `SyncContract`

**Files:**
- Create: `backend/src/Api/SyncContract.php`
- Test: `backend/tests/Unit/SyncContractTest.php`

**Interfaces:**
- Consumes: nichts.
- Produces:
  - `SyncContract::LOCATOR_REGEX` = `'/^[A-Za-z0-9_-]{43}$/'`
  - `SyncContract::STATE_ID_REGEX` = `'/^[0-9a-f]{32}$/'`
  - `SyncContract::MAX_META_BYTES` = `8 * 1024`
  - `SyncContract::MAX_PAYLOAD_BYTES` = `512 * 1024`
  - `SyncContract::MAX_STATES_PER_LOCATOR` = `5`
  - `SyncContract::RETENTION_DAYS` = `365`
  - `SyncContract::locatorHash(string $locator): string` — 64 Zeichen Hex
  - `SyncContract::payloadHash(string $envelopeBase64): string` — Base64url ohne Padding, 43 Zeichen
  - `SyncContract::decodeEnvelope(string $value): ?string` — strikt dekodiertes Base64 oder `null`

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Api\SyncContract;
use PHPUnit\Framework\TestCase;

final class SyncContractTest extends TestCase
{
    /** Der Locator ist 32 Byte als Base64url ohne Padding – exakt 43 Zeichen. */
    public function testLocatorRegexAcceptsTheContractTestVector(): void
    {
        // Vertrag, »Derivation test vectors«, Geheimnis 0001…1f
        $locator = 'zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk';

        self::assertSame(43, \strlen($locator));
        self::assertMatchesRegularExpression(SyncContract::LOCATOR_REGEX, $locator);
    }

    /**
     * Die Form wird geprüft, BEVOR sie etwas adressiert – sonst ist sie ein
     * Pfad-Traversal. Base64 mit Padding und Standard-Alphabet ist kein
     * Locator: »+«, »/« und »=« stehen nicht im Zeichenvorrat.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badLocators')]
    public function testLocatorRegexRejects(string $bad): void
    {
        self::assertDoesNotMatchRegularExpression(SyncContract::LOCATOR_REGEX, $bad);
    }

    public static function badLocators(): iterable
    {
        yield 'zu kurz' => [str_repeat('a', 42)];
        yield 'zu lang' => [str_repeat('a', 44)];
        yield 'Pfad-Traversal' => ['../../../../../../../../../../etc/passwd'];
        yield 'Schrägstrich' => [str_repeat('a', 42) . '/'];
        yield 'Plus' => [str_repeat('a', 42) . '+'];
        yield 'Padding' => [str_repeat('a', 42) . '='];
        yield 'Nullbyte' => [str_repeat('a', 42) . "\0"];
        yield 'Zeilenumbruch' => [str_repeat('a', 21) . "\n" . str_repeat('a', 21)];
        yield 'leer' => [''];
    }

    /** stateId ist clientseitig erzeugt: 16 Byte als Kleinbuchstaben-Hex. */
    public function testStateIdRegex(): void
    {
        self::assertMatchesRegularExpression(SyncContract::STATE_ID_REGEX, '0123456789abcdef0123456789abcdef');
        self::assertDoesNotMatchRegularExpression(SyncContract::STATE_ID_REGEX, '0123456789ABCDEF0123456789ABCDEF');
        self::assertDoesNotMatchRegularExpression(SyncContract::STATE_ID_REGEX, '0123456789abcdef0123456789abcde');
    }

    /**
     * payloadHash ist SHA-256 über die ROHEN Bytes des Envelopes – also über
     * das, was das Base64 dekodiert –, als Base64url ohne Padding. Der
     * Testvektor steht im Vertrag unter »Envelope test vector«.
     */
    public function testPayloadHashMatchesTheContractTestVector(): void
    {
        $envelope = 'AQIDBAUGBwgJCgsMhBezK2ZidsR4vw2Le+JA1vfSdGXw0lkopKj0PjhL9A==';

        self::assertSame('wTZSj7yLdniic9fTzg1YQgD4WVynX3BgPTYosChka2c', SyncContract::payloadHash($envelope));
        self::assertSame(43, \strlen(SyncContract::payloadHash($envelope)));
    }

    /** Der Locator wird gehasht abgelegt – 64 Zeichen Hex, kein Salz. */
    public function testLocatorHashIsPlainSha256Hex(): void
    {
        $locator = 'zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk';

        self::assertSame(hash('sha256', $locator), SyncContract::locatorHash($locator));
        self::assertSame(64, \strlen(SyncContract::locatorHash($locator)));
    }

    /**
     * Strikt dekodieren: alles, was kein sauberes Base64 ist, ist bad-request
     * und darf nicht stillschweigend zu anderen Bytes werden.
     */
    public function testDecodeEnvelopeIsStrict(): void
    {
        self::assertSame("\x01\x02\x03", SyncContract::decodeEnvelope(base64_encode("\x01\x02\x03")));
        self::assertNull(SyncContract::decodeEnvelope('nicht base64!!'));
        self::assertNull(SyncContract::decodeEnvelope('AQID===='));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit --filter SyncContractTest`
Expected: FAIL — `Class "App\Api\SyncContract" not found`

- [ ] **Step 3: Minimal implementieren**

```php
<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Die Werte des Sync-Teils des Extension-Vertrags (autoritativ:
 * /mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md, Abschnitte »Sync — …«).
 * Jede Zahl und jede Form steht hier GENAU EINMAL – Controller, Service und
 * Tests lesen sie von hier, damit eine Vertragsänderung eine Zeile ist.
 *
 * Vorbild ist App\Api\ExchangeFormat für das Austauschformat.
 */
final class SyncContract
{
    /**
     * Der Locator: 32 abgeleitete Bytes als Base64url ohne Padding, also
     * exakt 43 Zeichen. Diese Form wird geprüft, BEVOR der Wert etwas
     * adressiert – ungeprüft wäre sie ein Pfad-Traversal.
     */
    public const LOCATOR_REGEX = '/^[A-Za-z0-9_-]{43}$/';

    /** stateId: clientseitig erzeugte 16 Zufallsbytes als Kleinbuchstaben-Hex. */
    public const STATE_ID_REGEX = '/^[0-9a-f]{32}$/';

    /**
     * Die Grenzen messen den Envelope »wie übertragen«, also die Länge des
     * Base64-Strings – nicht die der dekodierten Bytes. Dieselbe Messung
     * liefert das »size«-Feld der Antworten.
     */
    public const MAX_META_BYTES = 8 * 1024;
    public const MAX_PAYLOAD_BYTES = 512 * 1024;

    /**
     * Stände pro Locator. Wird NUR beim Anlegen geprüft, nie rückwirkend:
     * ein Locator, der beim Herabsetzen der Grenze schon mehr Stände hatte,
     * behält sie alle. Die 4-MiB-Summe des Vertrags wird bewusst nicht
     * durchgesetzt – mit Per-Stand-Grenzen ist sie konstruktiv unerreichbar,
     * und einen Fehlercode dafür gibt es nicht.
     */
    public const MAX_STATES_PER_LOCATOR = 5;

    /** Aufbewahrung: 12 Monate ohne Lesen oder Schreiben (Vertrag, »Retention«). */
    public const RETENTION_DAYS = 365;

    /**
     * Der Locator wird gehasht abgelegt und über den Hash nachgeschlagen:
     * Zugriff auf die Datenbank darf nicht das Recht bedeuten, fremde Stände
     * zu listen oder zu löschen. Kein Salz – der Locator sind 256
     * gleichverteilte Bits, es gibt nichts zu erraten, und ein Salz würde den
     * Lookup über den Hash unmöglich machen.
     */
    public static function locatorHash(string $locator): string
    {
        return hash('sha256', $locator);
    }

    /**
     * SHA-256 über die ROHEN Bytes des Envelopes (das, was das Base64
     * dekodiert), als Base64url ohne Padding – derselbe Wert, den der
     * Meta-Blob des Standes trägt und den »basePayloadHash« vergleicht.
     */
    public static function payloadHash(string $envelopeBase64): string
    {
        $raw = self::decodeEnvelope($envelopeBase64) ?? '';

        return rtrim(strtr(base64_encode(hash('sha256', $raw, true)), '+/', '-_'), '=');
    }

    /**
     * Strikt dekodiertes Base64 oder null. Strikt, weil ein toleranter
     * Decoder Müll stillschweigend zu anderen Bytes macht – und der
     * payloadHash darüber entscheidet, ob ein fremder Schreibvorgang
     * überschrieben wird.
     */
    public static function decodeEnvelope(string $value): ?string
    {
        $raw = base64_decode($value, true);

        return $raw === false ? null : $raw;
    }
}
```

- [ ] **Step 4: Test laufen lassen, Erfolg bestätigen**

Run: `php backend/bin/phpunit --filter SyncContractTest; echo $?`
Expected: OK, Exit-Code 0. Insbesondere muss der Vertrags-Testvektor `wTZSj7yLdniic9fTzg1YQgD4WVynX3BgPTYosChka2c` stimmen — trifft er nicht, ist die Hash-Definition falsch verstanden und **nichts weiter darf gebaut werden**.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Api/SyncContract.php backend/tests/Unit/SyncContractTest.php
git commit -m "Lege die Vertragswerte des Locator-Syncs als SyncContract ab

Locator-Form, stateId-Form, die vier Grenzen und die beiden Hashes
stehen ab jetzt an genau einer Stelle. Der payloadHash ist gegen den
Testvektor des Vertrags abgesichert - er entscheidet spaeter darueber,
ob ein fremder Schreibvorgang ueberschrieben wird."
```

---

## Task 2: Entity, Repository und Migration

**Files:**
- Create: `backend/src/Entity/SyncState.php`
- Create: `backend/src/Repository/SyncStateRepository.php`
- Create: `backend/migrations/Version<Zeitstempel>.php`
- Test: `backend/tests/Functional/LocatorSyncPersistenceTest.php`

**Interfaces:**
- Consumes: `SyncContract` aus Task 1.
- Produces:
  - `SyncState::__construct(string $locatorHash, string $stateId, string $meta, string $payload)` — setzt `payloadHash`, `sizeBytes`, `createdAt`, `updatedAt`, `lastAccessAt` selbst
  - `SyncState::replaceBlobs(string $meta, string $payload): void`
  - `SyncState::touchAccess(): void`
  - Öffentliche Properties: `$locatorHash`, `$stateId`, `$meta`, `$payload`, `$payloadHash`, `$sizeBytes`, `$createdAt`, `$updatedAt`, `$lastAccessAt`
  - `SyncStateRepository::findOneByLocatorAndState(string $locatorHash, string $stateId): ?SyncState`
  - `SyncStateRepository::findByLocator(string $locatorHash): SyncState[]` (nach `updatedAt` absteigend)
  - `SyncStateRepository::countByLocator(string $locatorHash): int`
  - `SyncStateRepository::deleteByLocator(string $locatorHash): int`
  - `SyncStateRepository::deleteUnusedBefore(\DateTimeImmutable $cutoff): int`

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;
use App\Entity\SyncState;
use App\Repository\SyncStateRepository;

final class LocatorSyncPersistenceTest extends ApiTestCase
{
    private const LOCATOR = 'zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk';
    private const STATE_ID = '0123456789abcdef0123456789abcdef';

    /**
     * Ein 512-KiB-Envelope muss durch die Spalte passen. MySQL-TEXT fasst nur
     * 64 KiB - die Spalte MUSS MEDIUMTEXT sein, und genau das prueft dieser
     * Test gegen die echte Datenbank statt gegen die Mapping-Absicht.
     */
    public function testAMaximumSizedPayloadSurvivesARoundTrip(): void
    {
        $payload = str_repeat('A', SyncContract::MAX_PAYLOAD_BYTES);
        $state = new SyncState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID, 'bWV0YQ==', $payload);

        $this->em->persist($state);
        $this->em->flush();
        $this->em->clear();

        $repo = static::getContainer()->get(SyncStateRepository::class);
        $found = $repo->findOneByLocatorAndState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID);

        self::assertNotNull($found);
        self::assertSame(SyncContract::MAX_PAYLOAD_BYTES, \strlen($found->payload));
        self::assertSame(SyncContract::MAX_PAYLOAD_BYTES, $found->sizeBytes);
    }

    /** Der Klartext-Locator darf nirgends in der Zeile stehen. */
    public function testOnlyTheHashIsStored(): void
    {
        $state = new SyncState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID, 'bWV0YQ==', 'cGF5bG9hZA==');
        $this->em->persist($state);
        $this->em->flush();

        $row = $this->em->getConnection()
            ->fetchAssociative('SELECT * FROM sync_state WHERE state_id = ?', [self::STATE_ID]);

        self::assertIsArray($row);
        self::assertNotContains(self::LOCATOR, array_map(strval(...), $row));
        self::assertSame(hash('sha256', self::LOCATOR), $row['locator_hash']);
    }

    /** payloadHash und sizeBytes werden abgeleitet, nie von aussen gesetzt. */
    public function testHashAndSizeAreDerivedFromTheBlobs(): void
    {
        $payload = base64_encode('irgendwelche Bytes');
        $state = new SyncState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID, 'bWV0YQ==', $payload);

        self::assertSame(SyncContract::payloadHash($payload), $state->payloadHash);
        self::assertSame(\strlen($payload), $state->sizeBytes);

        $neu = base64_encode('andere Bytes');
        $state->replaceBlobs('bmV1', $neu);

        self::assertSame(SyncContract::payloadHash($neu), $state->payloadHash);
        self::assertSame(\strlen($neu), $state->sizeBytes);
    }

    /** Zwei Staende gleicher stateId unter VERSCHIEDENEN Locators sind erlaubt. */
    public function testTheSameStateIdMayExistUnderTwoLocators(): void
    {
        $a = new SyncState(SyncContract::locatorHash('A' . str_repeat('a', 42)), self::STATE_ID, 'bQ==', 'cA==');
        $b = new SyncState(SyncContract::locatorHash('B' . str_repeat('b', 42)), self::STATE_ID, 'bQ==', 'cA==');

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        self::assertNotSame($a->id, $b->id);
    }

    /** Die Aufbewahrung raeumt nach lastAccessAt, nicht nach updatedAt. */
    public function testDeleteUnusedBeforeUsesTheAccessTimestamp(): void
    {
        $alt = new SyncState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID, 'bQ==', 'cA==');
        $alt->lastAccessAt = new \DateTimeImmutable('-400 days');
        $jung = new SyncState(SyncContract::locatorHash(self::LOCATOR), 'abcdef0123456789abcdef0123456789', 'bQ==', 'cA==');

        $this->em->persist($alt);
        $this->em->persist($jung);
        $this->em->flush();

        $repo = static::getContainer()->get(SyncStateRepository::class);
        $deleted = $repo->deleteUnusedBefore(new \DateTimeImmutable('-365 days'));

        self::assertSame(1, $deleted);
        self::assertSame(1, $repo->countByLocator(SyncContract::locatorHash(self::LOCATOR)));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit --filter LocatorSyncPersistenceTest`
Expected: FAIL — `Class "App\Entity\SyncState" not found`

- [ ] **Step 3: Entity, Repository und Migration schreiben**

`backend/src/Entity/SyncState.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Api\SyncContract;
use App\Repository\SyncStateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Sync-Stand des Extension-Vertrags (apiLevel 3): eine
 * Einstellungs-Momentaufnahme unter einem Locator, abgelegt als zwei
 * Chiffrate unter demselben clientseitigen Schlüssel. Der Server sieht nur
 * Envelopes – er entschlüsselt nichts, kennt keine Struktur und merged nicht.
 *
 * NICHT zu verwechseln mit App\Entity\SyncBlob: das ist der kontogebundene
 * Settings-Sync aus dem Juli-Plan (/api/account/sync/{collection}). Dieser
 * hier ist anonym und wird über einen abgeleiteten Locator adressiert.
 *
 * Der Locator selbst wird NIE gespeichert – nur sein SHA-256. Wer die
 * Datenbank liest, kann die Chiffrate nicht entschlüsseln und soll auch nicht
 * das Recht erben, sie zu listen oder zu löschen.
 */
#[ORM\Entity(repositoryClass: SyncStateRepository::class)]
#[ORM\Table(name: 'sync_state')]
#[ORM\UniqueConstraint(name: 'uniq_sync_state_locator_state', columns: ['locator_hash', 'state_id'])]
#[ORM\Index(name: 'idx_sync_state_last_access', columns: ['last_access_at'])]
class SyncState
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** SHA-256 des Locators als Hex – der einzige Weg, eine Zeile zu finden. */
    #[ORM\Column(length: 64)]
    public string $locatorHash;

    /** Clientseitig erzeugt, unveränderlich (Vertrag): ^[0-9a-f]{32}$. */
    #[ORM\Column(length: 32)]
    public string $stateId;

    /** Meta-Envelope, Base64, höchstens 8 KiB wie übertragen. */
    #[ORM\Column(type: 'text')]
    public string $meta;

    /**
     * Payload-Envelope, Base64, höchstens 512 KiB wie übertragen.
     * MEDIUMTEXT ist Pflicht: MySQL-TEXT fasst nur 64 KiB, ein maximal
     * großer Stand würde stillschweigend abgeschnitten.
     */
    #[ORM\Column(type: 'text', length: 16777215)]
    public string $payload;

    /**
     * SHA-256 über die rohen Bytes des Payload-Envelopes, Base64url ohne
     * Padding. Abgeleitet, nicht vom Client übernommen – er ist der
     * Vergleichswert für »basePayloadHash« und darf deshalb nie etwas
     * anderes beschreiben als das, was tatsächlich gespeichert ist.
     */
    #[ORM\Column(length: 43)]
    public string $payloadHash;

    /** Länge des Payload-Envelopes wie übertragen (Base64-Zeichen). */
    #[ORM\Column]
    public int $sizeBytes;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    /** Fachlich sichtbar: der Client zeigt und vergleicht diesen Wert. */
    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    /**
     * Nur für die Aufbewahrung: jeder Lese- UND Schreibzugriff frischt ihn
     * auf. Bewusst getrennt von updatedAt – ein Herunterladen darf den
     * Änderungszeitpunkt nicht verfälschen, den der Client anzeigt.
     */
    #[ORM\Column]
    public \DateTimeImmutable $lastAccessAt;

    public function __construct(string $locatorHash, string $stateId, string $meta, string $payload)
    {
        $this->locatorHash = $locatorHash;
        $this->stateId = $stateId;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->lastAccessAt = $now;
        $this->setBlobs($meta, $payload);
    }

    /** Ersetzt beide Blobs gemeinsam und schreibt updatedAt fort. */
    public function replaceBlobs(string $meta, string $payload): void
    {
        $this->setBlobs($meta, $payload);
        $this->updatedAt = new \DateTimeImmutable();
        $this->lastAccessAt = $this->updatedAt;
    }

    /** Frischt die Aufbewahrungsfrist auf, ohne updatedAt anzufassen. */
    public function touchAccess(): void
    {
        $this->lastAccessAt = new \DateTimeImmutable();
    }

    /**
     * meta und payload werden immer ZUSAMMEN gesetzt – der Client prüft die
     * heruntergeladene Nutzlast gegen den payloadHash aus dem Meta-Blob, ein
     * gemischtes Paar lässt seinen Download fehlschlagen.
     */
    private function setBlobs(string $meta, string $payload): void
    {
        $this->meta = $meta;
        $this->payload = $payload;
        $this->payloadHash = SyncContract::payloadHash($payload);
        $this->sizeBytes = \strlen($payload);
    }
}
```

`backend/src/Repository/SyncStateRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SyncState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Zugriff auf Sync-Stände – ausschließlich über den Locator-HASH; eine
 * Methode, die den Klartext-Locator entgegennimmt, gibt es hier bewusst nicht.
 *
 * @extends ServiceEntityRepository<SyncState>
 */
class SyncStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncState::class);
    }

    public function findOneByLocatorAndState(string $locatorHash, string $stateId): ?SyncState
    {
        return $this->findOneBy(['locatorHash' => $locatorHash, 'stateId' => $stateId]);
    }

    /** @return list<SyncState> Neueste zuerst – die Liste ist höchstens 5 lang. */
    public function findByLocator(string $locatorHash): array
    {
        return $this->findBy(['locatorHash' => $locatorHash], ['updatedAt' => 'DESC']);
    }

    public function countByLocator(string $locatorHash): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.locatorHash = :hash')->setParameter('hash', $locatorHash)
            ->getQuery()->getSingleScalarResult();
    }

    /** Löscht alle Stände eines Locators; liefert die Anzahl. */
    public function deleteByLocator(string $locatorHash): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.locatorHash = :hash')->setParameter('hash', $locatorHash)
            ->getQuery()->execute();
    }

    /**
     * Aufbewahrung: löscht Stände, die seit dem Stichtag weder gelesen noch
     * geschrieben wurden. Bulk-DELETE per DQL – SyncState hat keine
     * abhängigen Entities, die eine ORM-Kaskade brauchen würden. Wer das
     * ändert, muss diese Stelle mitziehen (siehe .claude/lessons.md zum
     * gleichen Muster bei index:account:prune).
     */
    public function deleteUnusedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.lastAccessAt < :cutoff')->setParameter('cutoff', $cutoff)
            ->getQuery()->execute();
    }
}
```

Migration erzeugen und den generierten Rumpf gegen dieses SQL prüfen (`MEDIUMTEXT` ist die Stelle, an der es schiefgehen kann):

```bash
php backend/bin/console doctrine:migrations:diff --no-interaction
```

Erwartetes `up()`:

```php
$this->addSql('CREATE TABLE sync_state (id INT AUTO_INCREMENT NOT NULL, locator_hash VARCHAR(64) NOT NULL, state_id VARCHAR(32) NOT NULL, meta TEXT NOT NULL, payload MEDIUMTEXT NOT NULL, payload_hash VARCHAR(43) NOT NULL, size_bytes INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_access_at DATETIME NOT NULL, UNIQUE INDEX uniq_sync_state_locator_state (locator_hash, state_id), INDEX idx_sync_state_last_access (last_access_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
```

`down()`: `$this->addSql('DROP TABLE sync_state');`

Beschreibung setzen: `'Locator-adressierte Sync-Stände (Extension-Vertrag apiLevel 3)'`.

- [ ] **Step 4: Migration einspielen und Tests laufen lassen**

```bash
php backend/bin/console doctrine:migrations:migrate --no-interaction
php backend/bin/phpunit --filter LocatorSyncPersistenceTest; echo $?
```
Expected: OK, Exit-Code 0. Scheitert `testAMaximumSizedPayloadSurvivesARoundTrip` mit abgeschnittenen Daten, steht in der Migration `TEXT` statt `MEDIUMTEXT`.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Entity/SyncState.php backend/src/Repository/SyncStateRepository.php backend/migrations/ backend/tests/Functional/LocatorSyncPersistenceTest.php
git commit -m "Lege die Tabelle fuer locator-adressierte Sync-Staende an

Der Locator wird nur als SHA-256 abgelegt: Zugriff auf die Datenbank
soll nicht das Recht bedeuten, fremde Staende zu listen oder zu
loeschen. payload ist MEDIUMTEXT, weil ein 512-KiB-Envelope in
MySQL-TEXT stillschweigend abgeschnitten wuerde. lastAccessAt ist von
updatedAt getrennt, damit ein Download die Aufbewahrungsfrist
auffrischt, ohne den angezeigten Aenderungszeitpunkt zu verfaelschen."
```

---

## Task 3: Fehler in Vertragsform — `SyncProblem`

**Files:**
- Create: `backend/src/Exception/SyncProblem.php`
- Modify: `backend/src/EventSubscriber/ProblemJsonSubscriber.php`
- Test: `backend/tests/Functional/LocatorSyncErrorShapeTest.php`

**Interfaces:**
- Consumes: nichts.
- Produces:
  - `SyncProblem::badRequest(): self` (400, `bad-request`)
  - `SyncProblem::notFound(): self` (404, `not-found`)
  - `SyncProblem::conflict(\DateTimeImmutable $updatedAt): self` (412, `conflict`, Feld `updatedAt`)
  - `SyncProblem::tooLarge(): self` (413, `too-large`)
  - `SyncProblem::quotaStates(): self` (409, `quota-states`)
  - `SyncProblem::rateLimited(int $retryAfter): self` (429, `rate-limited`, Header `Retry-After`)
  - Öffentlich lesbar: `$errorCode`, `$extra`

**Warum eine eigene Exception:** `ApiProblem` rendert RFC 7807 (`{type,title,status}`). Der Vertrag schreibt `{ "error": "<code>" }` wörtlich vor, und der Client liest aus genau einem Fehler-Body ein Feld: `updatedAt` aus dem `412`. Ein Fremdformat mit zusätzlichen Feldern würde heute funktionieren und beim nächsten strikteren Client brechen.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class LocatorSyncErrorShapeTest extends ApiTestCase
{
    /**
     * Fehler der Sync-Endpunkte antworten in der Vertragsform
     * { "error": "<code>" } als application/json – NICHT als RFC-7807-
     * problem+json wie der Rest der API.
     */
    public function testASyncErrorUsesTheContractShape(): void
    {
        $this->client->request('POST', '/api/v1/sync/list', server: ['CONTENT_TYPE' => 'application/json'], content: 'kein json');

        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertSame(['error' => 'bad-request'], json_decode((string) $response->getContent(), true));
    }

    /** Der Rest der API bleibt bei RFC 7807 – der Umbau darf nicht durchschlagen. */
    public function testTheRestOfTheApiStillAnswersProblemJson(): void
    {
        $this->client->request('POST', '/api/v1/updates', server: ['CONTENT_TYPE' => 'application/json'], content: 'kein json');

        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertArrayHasKey('title', json_decode((string) $response->getContent(), true));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit --filter LocatorSyncErrorShapeTest`
Expected: FAIL — der erste Test bekommt 404 (Route fehlt noch). Das ist der erwartete Fehlschlag; er wird mit Task 4 grün.

- [ ] **Step 3: `SyncProblem` schreiben und den Subscriber erweitern**

`backend/src/Exception/SyncProblem.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fehler der Locator-Sync-Endpunkte in der Form, die der Extension-Vertrag
 * wörtlich vorschreibt: { "error": "<code>" }, ausgeliefert als
 * application/json – nicht als RFC-7807-problem+json wie der Rest der API.
 *
 * Wichtig aus dem Vertrag (»How the client reads an answer«): der Client
 * bildet die Codes ALLEIN über den HTTP-Status ab und liest den error-String
 * nie. Er liest genau einen Body – den des 412 – und daraus nur updatedAt.
 * Der Status ist also die tragende Angabe; der Body ist Dokumentation, mit
 * der einen Ausnahme.
 */
final class SyncProblem extends HttpException
{
    /**
     * @param array<string, mixed>  $extra   zusätzliche Felder neben »error«
     * @param array<string, string> $headers
     */
    private function __construct(
        int $statusCode,
        public readonly string $errorCode,
        public readonly array $extra = [],
        array $headers = [],
    ) {
        parent::__construct($statusCode, $errorCode, null, $headers);
    }

    /** Kaputter Body, unbekanntes apiLevel, falsche stateId- oder Locator-Form. */
    public static function badRequest(): self
    {
        return new self(400, 'bad-request');
    }

    /** Kein solcher Stand unter diesem Locator. */
    public static function notFound(): self
    {
        return new self(404, 'not-found');
    }

    /**
     * basePayloadHash beschreibt den gespeicherten Stand nicht – jemand
     * anderes hat zuerst geschrieben. updatedAt reist mit, damit der Client
     * ohne zweite Anfrage sagen kann, WANN sich der Stand unter ihm geändert
     * hat. Es ist das einzige Feld, das der Client aus einem Fehler-Body liest.
     */
    public static function conflict(\DateTimeImmutable $updatedAt): self
    {
        return new self(412, 'conflict', ['updatedAt' => $updatedAt->format(\DateTimeInterface::ATOM)]);
    }

    /** Ein einzelner Blob überschreitet seine Grenze. */
    public static function tooLarge(): self
    {
        return new self(413, 'too-large');
    }

    /** Der Locator hält bereits die Höchstzahl an Ständen (nur beim Anlegen). */
    public static function quotaStates(): self
    {
        return new self(409, 'quota-states');
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(429, 'rate-limited', [], ['Retry-After' => (string) $retryAfter]);
    }
}
```

In `ProblemJsonSubscriber::onKernelException()` **vor** der bestehenden RFC-7807-Behandlung einfügen (direkt nach dem `/api/`-Präfix-Check und dem `$throwable`-Abruf):

```php
        // Die Locator-Sync-Endpunkte antworten in der Form, die der
        // Extension-Vertrag wörtlich vorschreibt – { "error": "<code>" } als
        // application/json. Ein zweiter Antwortstil ist hier kein Wildwuchs,
        // sondern Vertragserfüllung: der Client liest aus dem 412-Body das
        // Feld updatedAt, und alles andere entscheidet er über den Status.
        if ($throwable instanceof SyncProblem) {
            $event->setResponse(new JsonResponse(
                ['error' => $throwable->errorCode] + $throwable->extra,
                $throwable->getStatusCode(),
                $throwable->getHeaders(),
            ));

            return;
        }
```

Dazu `use App\Exception\SyncProblem;` ergänzen und den Klassenkommentar um einen Satz erweitern, der den zweiten Stil benennt.

- [ ] **Step 4: Gesamte Suite laufen lassen**

Run: `php backend/bin/phpunit; echo $?`
Expected: `LocatorSyncErrorShapeTest::testASyncErrorUsesTheContractShape` scheitert weiterhin mit 404 (Route kommt in Task 4), `testTheRestOfTheApiStillAnswersProblemJson` ist grün, **alle bisher grünen Tests bleiben grün**. Ist irgendein bestehender Test rot, hat der Subscriber-Umbau durchgeschlagen.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Exception/SyncProblem.php backend/src/EventSubscriber/ProblemJsonSubscriber.php backend/tests/Functional/LocatorSyncErrorShapeTest.php
git commit -m "Gib den Sync-Endpunkten die vertragsexakte Fehlerform

Der Vertrag schreibt { \"error\": \"<code>\" } woertlich vor, waehrend
der Rest der API RFC 7807 spricht. Der Client bildet die Codes allein
ueber den HTTP-Status ab und liest genau einen Body - den des 412, und
daraus nur updatedAt. Ein Fremdformat wuerde heute funktionieren und
beim naechsten strikteren Client brechen."
```

---

## Task 4: Umschlag-Prüfung und `POST /api/v1/sync/list`

**Files:**
- Create: `backend/src/Controller/Api/SyncRequest.php`
- Create: `backend/src/Controller/Api/LocatorSyncListController.php`
- Modify: `backend/src/Service/RateLimitGuard.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Functional/LocatorSyncTest.php`

**Interfaces:**
- Consumes: `SyncContract`, `SyncProblem`, `SyncStateRepository`.
- Produces:
  - `SyncRequest::parse(Request $request): array` — liefert das dekodierte Body-Array, wirft `SyncProblem::badRequest()` bei kaputtem JSON oder fehlendem/nicht-ganzzahligem `apiLevel`
  - `SyncRequest::locatorHash(array $body): string` — prüft die Form, liefert den Hash
  - `SyncRequest::stateId(array $body, bool $required): ?string`
  - `SyncRequest::envelope(array $body, string $key, int $maxBytes): string` — prüft String, Base64 und Grenze
  - `RateLimitGuard::consume(RateLimiterFactoryInterface $factory, string $key, int $tokens = 1): void`

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;
use App\Entity\SyncState;

final class LocatorSyncTest extends ApiTestCase
{
    protected const LOCATOR = 'zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk';
    protected const OTHER_LOCATOR = '3qzyS44KqXaBNzKvFSontDE8CfLPp8lwOUVHroaeg7M';
    protected const STATE_ID = '0123456789abcdef0123456789abcdef';

    /** @param array<string, mixed> $body */
    protected function call(string $method, string $path, array $body): array
    {
        $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    protected function seedState(string $locator, string $stateId, string $payload = 'cGF5bG9hZA=='): SyncState
    {
        $state = new SyncState(SyncContract::locatorHash($locator), $stateId, 'bWV0YQ==', $payload);
        $this->em->persist($state);
        $this->em->flush();

        return $state;
    }

    public function testListReturnsTheStatesOfThatLocatorOnly(): void
    {
        $mine = $this->seedState(self::LOCATOR, self::STATE_ID);
        $this->seedState(self::OTHER_LOCATOR, 'ffffffffffffffffffffffffffffffff');

        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => self::LOCATOR]);

        self::assertSame(200, $status);
        self::assertCount(1, $body['states']);
        self::assertSame(self::STATE_ID, $body['states'][0]['stateId']);
        self::assertSame($mine->sizeBytes, $body['states'][0]['size']);
        self::assertSame('bWV0YQ==', $body['states'][0]['meta']);
        self::assertArrayHasKey('updatedAt', $body['states'][0]);
        // Der payload gehoert NICHT in die Liste - genau dafuer ist sie geteilt.
        self::assertArrayNotHasKey('payload', $body['states'][0]);
    }

    /** Ein unbekannter Locator ist kein Fehler, sondern eine leere Liste. */
    public function testListOfAnUnknownLocatorIsEmpty(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => self::LOCATOR]);

        self::assertSame(200, $status);
        self::assertSame([], $body['states']);
    }

    /**
     * Die Locator-Form wird geprueft, BEVOR sie etwas adressiert. Ohne diese
     * Pruefung waere der Wert ein Pfad-Traversal.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedLocators')]
    public function testAMalformedLocatorIsBadRequest(mixed $locator): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => $locator]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    public static function malformedLocators(): iterable
    {
        yield 'fehlt' => [null];
        yield 'zu kurz' => [str_repeat('a', 42)];
        yield 'Pfad-Traversal' => ['../../../../../../../../../../etc/passwd'];
        yield 'Standard-Base64' => [str_repeat('a', 41) . '+/='];
        yield 'Zahl' => [12345];
        yield 'Array' => [['a']];
    }

    public function testAMissingApiLevelIsBadRequest(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['locator' => self::LOCATOR]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Kuenftige Level duerfen nicht abgewiesen werden (Toleranzregel). */
    public function testAFutureApiLevelIsAccepted(): void
    {
        [$status] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 4, 'locator' => self::LOCATOR]);

        self::assertSame(200, $status);
    }

    /** Listen frischt die Aufbewahrungsfrist auf, ohne updatedAt zu ruehren. */
    public function testListRefreshesRetentionWithoutTouchingUpdatedAt(): void
    {
        $state = $this->seedState(self::LOCATOR, self::STATE_ID);
        $state->updatedAt = new \DateTimeImmutable('-100 days');
        $state->lastAccessAt = new \DateTimeImmutable('-100 days');
        $this->em->flush();
        $updatedAt = $state->updatedAt;

        $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => self::LOCATOR]);
        $this->em->refresh($state);

        self::assertEquals($updatedAt, $state->updatedAt);
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $state->lastAccessAt);
    }

    /** Der Preflight muss beantwortet werden - Firefox schickt einen. */
    public function testThePreflightIsAnswered(): void
    {
        $this->client->request('OPTIONS', '/api/v1/sync/list');
        $response = $this->client->getResponse();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('PUT', (string) $response->headers->get('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Content-Type', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit --filter LocatorSyncTest`
Expected: FAIL — alle Endpunkt-Tests mit 404 (Route fehlt). `testThePreflightIsAnswered` ist bereits grün, weil `CorsSubscriber` OPTIONS auf `/api/` pauschal beantwortet.

- [ ] **Step 3: Implementieren**

`backend/src/Controller/Api/SyncRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\SyncContract;
use App\Exception\SyncProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Das gemeinsame Prüfwerk der vier Locator-Sync-Endpunkte: JSON auspacken,
 * apiLevel, Locator, stateId und die beiden Envelopes prüfen. Vier
 * Endpunkte, eine Prüfung – die Reihenfolge der Prüfungen ist Vertrag
 * (Form vor Adressierung), deshalb steht sie an genau einer Stelle.
 *
 * Nichts in dieser Klasse loggt. Der Body trägt den Locator, und der ist ein
 * Bearer-Token: ein Log mit Bodies wäre ein Log voller Zugangsschlüssel.
 */
final class SyncRequest
{
    /**
     * @return array<string, mixed>
     *
     * @throws SyncProblem 400 bei kaputtem JSON oder fehlendem apiLevel
     */
    public static function parse(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw SyncProblem::badRequest();
        }
        if (!\is_array($body)) {
            throw SyncProblem::badRequest();
        }
        // Tolerant geprüft: vorhanden und ganzzahlig, sonst nichts. Ein Client
        // unter Level 3 ruft diese Endpunkte nie auf, und ein künftiger
        // Level-4-Client darf nicht abgewiesen werden (Toleranzregel im Kopf
        // des Vertrags, genauso gehandhabt in UpdateCheckController).
        if (!\is_int($body['apiLevel'] ?? null)) {
            throw SyncProblem::badRequest();
        }

        return $body;
    }

    /**
     * Prüft die Locator-Form und liefert NUR den Hash – der Klartext verlässt
     * diese Methode nicht, damit er nirgends versehentlich in eine Query,
     * einen Pfad oder ein Log gerät.
     *
     * @param array<string, mixed> $body
     */
    public static function locatorHash(array $body): string
    {
        $locator = $body['locator'] ?? null;
        if (!\is_string($locator) || !preg_match(SyncContract::LOCATOR_REGEX, $locator)) {
            throw SyncProblem::badRequest();
        }

        return SyncContract::locatorHash($locator);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return ($required is true ? string : ?string)
     */
    public static function stateId(array $body, bool $required): ?string
    {
        $stateId = $body['stateId'] ?? null;
        if ($stateId === null && !$required) {
            return null;
        }
        if (!\is_string($stateId) || !preg_match(SyncContract::STATE_ID_REGEX, $stateId)) {
            throw SyncProblem::badRequest();
        }

        return $stateId;
    }

    /**
     * Envelope prüfen: String, sauberes Base64, innerhalb seiner Grenze.
     * Gemessen wird die Länge WIE ÜBERTRAGEN, also die des Base64-Strings –
     * dieselbe Messung, die das »size«-Feld der Antworten meldet.
     *
     * @param array<string, mixed> $body
     */
    public static function envelope(array $body, string $key, int $maxBytes): string
    {
        $value = $body[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw SyncProblem::badRequest();
        }
        // Grenze VOR dem Dekodieren: sonst dekodiert der Server erst 50 MiB,
        // um danach festzustellen, dass sie zu groß waren.
        if (\strlen($value) > $maxBytes) {
            throw SyncProblem::tooLarge();
        }
        if (SyncContract::decodeEnvelope($value) === null) {
            throw SyncProblem::badRequest();
        }

        return $value;
    }
}
```

`backend/src/Controller/Api/LocatorSyncListController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\SyncProblem;
use App\Repository\SyncStateRepository;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/v1/sync/list – die Stände eines Locators (Vertrag, apiLevel 3).
 *
 * Geliefert werden stateId, size, updatedAt und der META-Blob, nie der
 * payload: genau dafür ist der Stand in zwei Chiffrate geteilt. Ein zweiter
 * Browser entschlüsselt die Metas, zeigt »Arbeit – geändert am 3. September«
 * und lädt eine Nutzlast erst herunter, wenn der Nutzer eine auswählt.
 *
 * Ein unbekannter Locator ist kein Fehler, sondern eine leere Liste – der
 * Vertrag kennt für diesen Fall keinen Code, und ein 404 verriete außerdem,
 * welche Locators belegt sind.
 */
final class LocatorSyncListController
{
    #[Route('/api/v1/sync/list', methods: ['POST'])]
    public function __invoke(
        Request $request,
        SyncStateRepository $states,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
    ): JsonResponse {
        $guard->consume($syncV1Limiter, $request->getClientIp() ?? 'unknown', problem: SyncProblem::class);

        $body = SyncRequest::parse($request);
        $locatorHash = SyncRequest::locatorHash($body);

        $found = $states->findByLocator($locatorHash);

        $out = [];
        foreach ($found as $state) {
            // Listen ist ein Lesezugriff: es frischt die Aufbewahrungsfrist
            // auf, rührt updatedAt aber nicht an – das zeigt der Client.
            $state->touchAccess();
            $out[] = [
                'stateId' => $state->stateId,
                'size' => $state->sizeBytes,
                'updatedAt' => $state->updatedAt->format(\DateTimeInterface::ATOM),
                'meta' => $state->meta,
            ];
        }
        $em->flush();

        return new JsonResponse(['states' => $out]);
    }
}
```

`RateLimitGuard` erweitern — `$tokens` für das Byte-Limit und ein Schalter für die Fehlerform:

```php
    /**
     * Verbraucht $tokens des Rate-Limiters für den angegebenen Schlüssel.
     * $tokens > 1 dient dem mengenbasierten Limit (geschriebene KiB), nicht
     * der Anfragenzahl.
     *
     * $problem wählt die Fehlerform: die Locator-Sync-Endpunkte antworten in
     * der Vertragsform { "error": "rate-limited" }, alle übrigen in RFC 7807.
     *
     * @param class-string<ApiProblem|SyncProblem> $problem
     */
    public function consume(
        RateLimiterFactoryInterface $factory,
        string $key,
        int $tokens = 1,
        string $problem = ApiProblem::class,
    ): void {
        $limit = $factory->create($key)->consume($tokens);
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw $problem === SyncProblem::class
            ? SyncProblem::rateLimited($retryAfter)
            : new ApiProblem(429, 'Rate limit exceeded', ['retryAfter' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
    }
```

`rate_limiter.yaml` — in **allen drei** Blöcken (`framework`, `when@test`, `when@dev`) ergänzen. Produktion:

```yaml
        # Locator-Sync (apiLevel 3): das Per-IP-Limit ist laut Vertrag das,
        # was die Kosten wirklich bindet – die Per-Locator-Grenzen sind KEIN
        # Missbrauchsschutz, weil Locators frei und unbegrenzt ableitbar sind.
        sync_v1: { policy: sliding_window, limit: 300, interval: '1 hour' }
        # Geschriebene Bytes, gezählt in angefangenen KiB: 64 MiB pro IP und
        # Stunde. Das ist der erste Hebel, wenn Missbrauch auftritt (Vertrag,
        # »What protects the quota«).
        sync_v1_bytes: { policy: sliding_window, limit: 65536, interval: '1 hour' }
```

`when@test` und `when@dev`: `sync_v1: { policy: sliding_window, limit: 1000, interval: '1 hour' }` und `sync_v1_bytes: { policy: sliding_window, limit: 1048576, interval: '1 hour' }`. Beide sind reine Missbrauchsbremsen, keine zu prüfende Sicherheitskontrolle — daher großzügige Testlimits (siehe `.claude/lessons.md`).

- [ ] **Step 4: Tests laufen lassen**

Run: `php backend/bin/phpunit --filter 'LocatorSyncTest|LocatorSyncErrorShapeTest|RateLimitGuardTest'; echo $?`
Expected: Alle `list`-Tests und `LocatorSyncErrorShapeTest` grün; `RateLimitGuardTest` weiterhin grün (das neue Argument hat einen Default). Die Tests zu `put`, `get` und `delete` existieren noch nicht.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Controller/Api/SyncRequest.php backend/src/Controller/Api/LocatorSyncListController.php backend/src/Service/RateLimitGuard.php backend/config/packages/rate_limiter.yaml backend/tests/Functional/LocatorSyncTest.php
git commit -m "Beantworte POST /api/v1/sync/list

Die Umschlag-Pruefung liegt in SyncRequest, damit die Reihenfolge - Form
pruefen, bevor der Wert etwas adressiert - an genau einer Stelle steht.
Der Locator verlaesst SyncRequest nur als Hash. Ein unbekannter Locator
ist eine leere Liste, kein 404: der Vertrag kennt dafuer keinen Code,
und ein 404 verriete, welche Locators belegt sind."
```

---

## Task 5: `PUT /api/v1/sync/state` — Quote, Konflikt, Schreiben

**Files:**
- Create: `backend/src/Service/LocatorSyncService.php`
- Create: `backend/src/Controller/Api/LocatorSyncPutController.php`
- Test: `backend/tests/Functional/LocatorSyncTest.php` (erweitern)

**Interfaces:**
- Consumes: `SyncContract`, `SyncProblem`, `SyncState`, `SyncStateRepository`, `SyncRequest`.
- Produces:
  - `LocatorSyncService::write(string $locatorHash, string $stateId, string $meta, string $payload, ?string $basePayloadHash): SyncState`
    — wirft `SyncProblem::quotaStates()` oder `SyncProblem::conflict()`, sonst der geschriebene Stand.

**Die Falle dieses Tasks (`.claude/lessons.md`):** `EntityManager::wrapInTransaction()` **schließt den EntityManager bei jeder durchgereichten Exception** (Vendor-`finally`: `close()` + `rollBack()`). Ein Guard, der aus der Closure heraus wirft, macht den EM danach unbenutzbar — und genau das würde jeden Test brechen, der nach einem erwarteten 412 den DB-Zustand prüft. Deshalb: **Guards geben ein Sentinel zurück, das `SyncProblem` wird nach dem Commit geworfen.**

- [ ] **Step 1: Den fehlschlagenden Test schreiben** (an `LocatorSyncTest` anhängen)

```php
    public function testPutCreatesANewState(): void
    {
        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bWV0YQ==', 'payload' => 'cGF5bG9hZA==',
        ]);

        self::assertSame(200, $status);
        self::assertSame(self::STATE_ID, $body['stateId']);
        self::assertSame(\strlen('cGF5bG9hZA=='), $body['size']);
        self::assertArrayHasKey('updatedAt', $body);
        // Der payloadHash gehoert NICHT in die Antwort - ihn kennt nur der
        // hochladende Client, weil jede Verschluesselung eine frische IV nutzt.
        self::assertArrayNotHasKey('payloadHash', $body);
    }

    /** Ohne basePayloadHash wird bedingungslos geschrieben. */
    public function testPutWithoutABaseHashOverwritesUnconditionally(): void
    {
        $this->seedState(self::LOCATOR, self::STATE_ID, base64_encode('alt'));

        [$status] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
        ]);

        self::assertSame(200, $status);
        $this->em->clear();
        $state = static::getContainer()->get(\App\Repository\SyncStateRepository::class)
            ->findOneByLocatorAndState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID);
        self::assertSame(base64_encode('neu'), $state->payload);
    }

    /** Passender basePayloadHash: es wird geschrieben. */
    public function testPutWithAMatchingBaseHashWrites(): void
    {
        $state = $this->seedState(self::LOCATOR, self::STATE_ID, base64_encode('alt'));

        [$status] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
            'basePayloadHash' => $state->payloadHash,
        ]);

        self::assertSame(200, $status);
    }

    /**
     * Der tragende Fall: falscher basePayloadHash -> 412, updatedAt im Body,
     * und NICHTS wurde geschrieben. Der Merge der Extension setzt alles
     * darauf; eine Teilwirkung hier ueberschriebe still die Einstellungen
     * auf dem anderen Rechner.
     */
    public function testPutWithAStaleBaseHashIsRefusedWithoutAnyEffect(): void
    {
        $state = $this->seedState(self::LOCATOR, self::STATE_ID, base64_encode('alt'));
        $vorher = ['payload' => $state->payload, 'meta' => $state->meta, 'updatedAt' => $state->updatedAt];

        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
            'basePayloadHash' => SyncContract::payloadHash(base64_encode('etwas ganz anderes')),
        ]);

        self::assertSame(412, $status);
        self::assertSame('conflict', $body['error']);
        self::assertSame($vorher['updatedAt']->format(\DateTimeInterface::ATOM), $body['updatedAt']);

        // Nach dem 412 muss der EntityManager noch benutzbar sein und der
        // Stand unveraendert - kein Byte darf geschrieben worden sein.
        $this->em->clear();
        $nachher = static::getContainer()->get(\App\Repository\SyncStateRepository::class)
            ->findOneByLocatorAndState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID);
        self::assertSame($vorher['payload'], $nachher->payload);
        self::assertSame($vorher['meta'], $nachher->meta);
        self::assertEquals($vorher['updatedAt'], $nachher->updatedAt);
    }

    /**
     * Ein basePayloadHash auf einen Stand, den es nicht gibt: der Client hat
     * eine Basis, die der Server nicht kennt. Das ist ein Konflikt, kein
     * stillschweigendes Anlegen.
     */
    public function testPutWithABaseHashOnAMissingStateIsNotFound(): void
    {
        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
            'basePayloadHash' => SyncContract::payloadHash(base64_encode('alt')),
        ]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);
    }

    /** Die Staendegrenze wird nur beim ANLEGEN geprueft. */
    public function testTheSixthNewStateIsRefusedWithQuotaStates(): void
    {
        for ($i = 0; $i < SyncContract::MAX_STATES_PER_LOCATOR; ++$i) {
            $this->seedState(self::LOCATOR, str_pad((string) $i, 32, '0', STR_PAD_LEFT));
        }

        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => 'ffffffffffffffffffffffffffffffff',
            'meta' => 'bQ==', 'payload' => 'cA==',
        ]);

        self::assertSame(409, $status);
        self::assertSame('quota-states', $body['error']);
    }

    /** Ueber der Grenze bleibt SCHREIBEN auf bestehende Staende moeglich. */
    public function testOverTheQuotaExistingStatesStayWritable(): void
    {
        for ($i = 0; $i < SyncContract::MAX_STATES_PER_LOCATOR + 2; ++$i) {
            $this->seedState(self::LOCATOR, str_pad((string) $i, 32, '0', STR_PAD_LEFT));
        }

        [$status] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => str_pad('0', 32, '0', STR_PAD_LEFT),
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
        ]);

        self::assertSame(200, $status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('oversizedBlobs')]
    public function testAnOversizedBlobIsTooLarge(string $key, int $length): void
    {
        $body = [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bQ==', 'payload' => 'cA==',
        ];
        $body[$key] = str_repeat('A', $length);

        [$status, $answer] = $this->call('PUT', '/api/v1/sync/state', $body);

        self::assertSame(413, $status);
        self::assertSame('too-large', $answer['error']);
    }

    public static function oversizedBlobs(): iterable
    {
        yield 'meta ueber 8 KiB' => ['meta', SyncContract::MAX_META_BYTES + 1];
        yield 'payload ueber 512 KiB' => ['payload', SyncContract::MAX_PAYLOAD_BYTES + 1];
    }

    /** Genau auf der Grenze wird noch angenommen. */
    public function testABlobExactlyAtTheLimitIsAccepted(): void
    {
        [$status] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bQ==', 'payload' => str_repeat('A', SyncContract::MAX_PAYLOAD_BYTES),
        ]);

        self::assertSame(200, $status);
    }

    /** Kein sauberes Base64 ist bad-request, nicht »irgendwelche Bytes«. */
    public function testANonBase64EnvelopeIsBadRequest(): void
    {
        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bQ==', 'payload' => 'kein base64 !!!',
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Ein Stand gehoert seinem Locator - fremde stateIds sind unsichtbar. */
    public function testPutUnderAnotherLocatorDoesNotTouchTheForeignState(): void
    {
        $fremd = $this->seedState(self::OTHER_LOCATOR, self::STATE_ID, base64_encode('fremd'));

        $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('meins'),
        ]);

        $this->em->refresh($fremd);
        self::assertSame(base64_encode('fremd'), $fremd->payload);
    }
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit --filter LocatorSyncTest`
Expected: FAIL — die neuen `Put`-Tests mit 404/405 (Route fehlt), die `list`-Tests bleiben grün.

- [ ] **Step 3: Implementieren**

`backend/src/Service/LocatorSyncService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Api\SyncContract;
use App\Entity\SyncState;
use App\Exception\SyncProblem;
use App\Repository\SyncStateRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Der Zustandsübergang eines Sync-Standes (Vertrag, apiLevel 3): Quote
 * prüfen, »basePayloadHash« vergleichen, schreiben – in EINER Transaktion
 * mit Sperre auf der Zeile, damit zwei gleichzeitige Uploads nicht beide
 * »passt« sehen und der letzte gewinnt.
 *
 * WARUM DIE GUARDS NICHT WERFEN: EntityManager::wrapInTransaction() schließt
 * bei JEDER durchgereichten Exception den EntityManager (Vendor-finally:
 * close() + rollBack()). Ein 412, das aus der Closure heraus fliegt, machte
 * den EM danach unbenutzbar – und der Vertrag verlangt ausdrücklich, dass
 * nach einer Ablehnung alles weiter benutzbar und unverändert ist. Deshalb
 * gibt die Closure ein Sentinel zurück und der Aufrufer wirft NACH dem
 * (leeren) Commit. Dasselbe Muster steht in .claude/lessons.md.
 */
final class LocatorSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SyncStateRepository $states,
    ) {
    }

    /**
     * Legt einen Stand an oder ersetzt ihn.
     *
     * @param ?string $basePayloadHash null = bedingungslos schreiben (so
     *                                 entsteht ein neuer Stand, und so sagt
     *                                 ein Client nach einem Konflikt
     *                                 »trotzdem überschreiben«)
     *
     * @throws SyncProblem 409 quota-states, 404 not-found, 412 conflict
     */
    public function write(
        string $locatorHash,
        string $stateId,
        string $meta,
        string $payload,
        ?string $basePayloadHash,
    ): SyncState {
        /** @var SyncState|SyncProblem $result */
        $result = $this->em->wrapInTransaction(function () use ($locatorHash, $stateId, $meta, $payload, $basePayloadHash) {
            $state = $this->states->findOneByLocatorAndState($locatorHash, $stateId);

            if ($state === null) {
                // Ein basePayloadHash beschreibt einen Stand, den es nicht
                // gibt: der Client baut auf einer Basis auf, die der Server
                // nicht kennt. Stillschweigend anzulegen hieße, eine fremde
                // Löschung zu übergehen.
                if ($basePayloadHash !== null) {
                    return SyncProblem::notFound();
                }
                // Die Ständegrenze gilt NUR beim Anlegen. Ein Locator über
                // der Grenze behält seine Stände und bleibt beschreibbar.
                if ($this->states->countByLocator($locatorHash) >= SyncContract::MAX_STATES_PER_LOCATOR) {
                    return SyncProblem::quotaStates();
                }
                $state = new SyncState($locatorHash, $stateId, $meta, $payload);
                $this->em->persist($state);

                return $state;
            }

            // Zwei gleichzeitige Uploads auf denselben Stand serialisieren:
            // ohne Sperre sehen beide dieselbe Basis, beide halten sie für
            // passend, und der letzte Commit gewinnt – genau der stille
            // Verlust, den basePayloadHash verhindern soll.
            $this->em->lock($state, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($state);

            if ($basePayloadHash !== null && !hash_equals($state->payloadHash, $basePayloadHash)) {
                return SyncProblem::conflict($state->updatedAt);
            }

            $state->replaceBlobs($meta, $payload);

            return $state;
        });

        if ($result instanceof SyncProblem) {
            throw $result;
        }

        return $result;
    }
}
```

`backend/src/Controller/Api/LocatorSyncPutController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\SyncContract;
use App\Exception\SyncProblem;
use App\Service\LocatorSyncService;
use App\Service\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PUT /api/v1/sync/state – einen Stand anlegen oder ersetzen (Vertrag,
 * apiLevel 3).
 *
 * Die drei Fälle von »basePayloadHash« sind tragend: der Drei-Wege-Merge der
 * Extension setzt alles darauf. Ein 412, das trotzdem schreibt, oder ein
 * Vergleich gegen etwas anderes als den gespeicherten Payload-Envelope,
 * überschreibt dem Nutzer stillschweigend die Einstellungen auf dem anderen
 * Rechner.
 *
 * Ein payloadHash wird NICHT zurückgegeben – ihn kennt nur der hochladende
 * Client, weil jede Verschlüsselung eine frische IV nutzt.
 */
final class LocatorSyncPutController
{
    #[Route('/api/v1/sync/state', methods: ['PUT'])]
    public function __invoke(
        Request $request,
        LocatorSyncService $sync,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
        RateLimiterFactoryInterface $syncV1BytesLimiter,
    ): JsonResponse {
        $ip = $request->getClientIp() ?? 'unknown';
        $guard->consume($syncV1Limiter, $ip, problem: SyncProblem::class);

        $body = SyncRequest::parse($request);
        $locatorHash = SyncRequest::locatorHash($body);
        $stateId = SyncRequest::stateId($body, required: true);
        $meta = SyncRequest::envelope($body, 'meta', SyncContract::MAX_META_BYTES);
        $payload = SyncRequest::envelope($body, 'payload', SyncContract::MAX_PAYLOAD_BYTES);

        $baseHash = $body['basePayloadHash'] ?? null;
        if ($baseHash !== null && (!\is_string($baseHash) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $baseHash))) {
            throw SyncProblem::badRequest();
        }

        // Das mengenbasierte Limit erst NACH der Formprüfung: ein kaputter
        // Request soll kein Schreibbudget verbrauchen. Gezählt wird in
        // angefangenen KiB – das ist der Hebel, den der Vertrag als ersten
        // gegen Missbrauch nennt.
        $guard->consume($syncV1BytesLimiter, $ip, (int) ceil((\strlen($meta) + \strlen($payload)) / 1024), SyncProblem::class);

        $state = $sync->write($locatorHash, $stateId, $meta, $payload, $baseHash);

        return new JsonResponse([
            'stateId' => $state->stateId,
            'updatedAt' => $state->updatedAt->format(\DateTimeInterface::ATOM),
            'size' => $state->sizeBytes,
        ]);
    }
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `php backend/bin/phpunit --filter LocatorSyncTest; echo $?`
Expected: OK, Exit-Code 0. Besonders `testPutWithAStaleBaseHashIsRefusedWithoutAnyEffect` — scheitert er mit `EntityManagerClosed`, wirft ein Guard noch aus der Closure heraus.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/LocatorSyncService.php backend/src/Controller/Api/LocatorSyncPutController.php backend/tests/Functional/LocatorSyncTest.php
git commit -m "Beantworte PUT /api/v1/sync/state mit dem 412-Konfliktschutz

Die drei basePayloadHash-Faelle sind tragend: der Drei-Wege-Merge der
Extension setzt alles darauf, und ein 412 mit Teilwirkung ueberschriebe
still die Einstellungen auf dem anderen Rechner. Die Guards geben ein
Sentinel zurueck statt zu werfen - wrapInTransaction schliesst sonst den
EntityManager, und nach einer Ablehnung muss alles weiter benutzbar
sein. Die Zeile wird zum Vergleichen gesperrt, sonst sehen zwei
gleichzeitige Uploads dieselbe Basis und der letzte gewinnt."
```

---

## Task 6: `POST /api/v1/sync/get` und `POST /api/v1/sync/delete`

**Files:**
- Create: `backend/src/Controller/Api/LocatorSyncGetController.php`
- Create: `backend/src/Controller/Api/LocatorSyncDeleteController.php`
- Test: `backend/tests/Functional/LocatorSyncTest.php` (erweitern)

**Interfaces:**
- Consumes: `SyncRequest`, `SyncStateRepository`, `SyncProblem`, `RateLimitGuard`.
- Produces: nichts für spätere Tasks.

- [ ] **Step 1: Den fehlschlagenden Test schreiben** (an `LocatorSyncTest` anhängen)

```php
    public function testGetReturnsThePayload(): void
    {
        $state = $this->seedState(self::LOCATOR, self::STATE_ID, base64_encode('geheim'));

        [$status, $body] = $this->call('POST', '/api/v1/sync/get', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
        ]);

        self::assertSame(200, $status);
        self::assertSame(self::STATE_ID, $body['stateId']);
        self::assertSame(base64_encode('geheim'), $body['payload']);
        self::assertSame($state->updatedAt->format(\DateTimeInterface::ATOM), $body['updatedAt']);
    }

    /** Ein fremder Stand ist unter meinem Locator schlicht nicht da. */
    public function testGetOfAForeignStateIsNotFound(): void
    {
        $this->seedState(self::OTHER_LOCATOR, self::STATE_ID);

        [$status, $body] = $this->call('POST', '/api/v1/sync/get', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
        ]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);
    }

    /** get ohne stateId ist bad-request - hier ist er Pflicht. */
    public function testGetWithoutAStateIdIsBadRequest(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/get', ['apiLevel' => 3, 'locator' => self::LOCATOR]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Herunterladen frischt die Aufbewahrungsfrist auf, nicht updatedAt. */
    public function testGetRefreshesRetentionWithoutTouchingUpdatedAt(): void
    {
        $state = $this->seedState(self::LOCATOR, self::STATE_ID);
        $state->updatedAt = new \DateTimeImmutable('-100 days');
        $state->lastAccessAt = new \DateTimeImmutable('-100 days');
        $this->em->flush();
        $updatedAt = $state->updatedAt;

        $this->call('POST', '/api/v1/sync/get', ['apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID]);
        $this->em->refresh($state);

        self::assertEquals($updatedAt, $state->updatedAt);
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $state->lastAccessAt);
    }

    public function testDeleteRemovesOneState(): void
    {
        $this->seedState(self::LOCATOR, self::STATE_ID);
        $this->seedState(self::LOCATOR, 'ffffffffffffffffffffffffffffffff');

        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
        ]);

        self::assertSame(200, $status);
        self::assertSame(1, $body['deleted']);
        self::assertSame(1, static::getContainer()->get(\App\Repository\SyncStateRepository::class)
            ->countByLocator(SyncContract::locatorHash(self::LOCATOR)));
    }

    /** Ohne stateId faellt alles unter dem Locator - so steht es im Vertrag. */
    public function testDeleteWithoutAStateIdRemovesEverythingUnderTheLocator(): void
    {
        $this->seedState(self::LOCATOR, self::STATE_ID);
        $this->seedState(self::LOCATOR, 'ffffffffffffffffffffffffffffffff');
        $this->seedState(self::OTHER_LOCATOR, self::STATE_ID);

        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', ['apiLevel' => 3, 'locator' => self::LOCATOR]);

        self::assertSame(200, $status);
        self::assertSame(2, $body['deleted']);
        $repo = static::getContainer()->get(\App\Repository\SyncStateRepository::class);
        self::assertSame(0, $repo->countByLocator(SyncContract::locatorHash(self::LOCATOR)));
        self::assertSame(1, $repo->countByLocator(SyncContract::locatorHash(self::OTHER_LOCATOR)));
    }

    /**
     * Loeschen ist bedingungslos und nimmt keinen Token - es passiert hinter
     * einer Bestaetigung, und der Nutzer sieht dabei zu (Vertrag).
     */
    public function testDeleteOfAnUnknownStateIsNotFound(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
        ]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);
    }

    /** Ein leerer Locator ohne stateId loescht nichts und meldet 0. */
    public function testDeleteEverythingUnderAnEmptyLocatorReportsZero(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', ['apiLevel' => 3, 'locator' => self::LOCATOR]);

        self::assertSame(200, $status);
        self::assertSame(0, $body['deleted']);
    }
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit --filter LocatorSyncTest`
Expected: FAIL — die neuen `get`/`delete`-Tests mit 404, alles davor grün.

- [ ] **Step 3: Implementieren**

`backend/src/Controller/Api/LocatorSyncGetController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\SyncProblem;
use App\Repository\SyncStateRepository;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/v1/sync/get – die Nutzlast eines Standes (Vertrag, apiLevel 3).
 *
 * POST statt GET, weil der Locator ein Bearer-Token ist und im BODY reisen
 * muss: in der URL stünde er in jedem Zugriffslog und in jedem Proxy-Cache.
 *
 * Geliefert wird der payload-Envelope; der Client prüft ihn gegen den
 * payloadHash aus dem Meta-Blob, den er beim Listen entschlüsselt hat. Meta
 * und payload eines Standes werden deshalb immer gemeinsam ersetzt – ein
 * gemischtes Paar lässt seinen Download fehlschlagen.
 */
final class LocatorSyncGetController
{
    #[Route('/api/v1/sync/get', methods: ['POST'])]
    public function __invoke(
        Request $request,
        SyncStateRepository $states,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
    ): JsonResponse {
        $guard->consume($syncV1Limiter, $request->getClientIp() ?? 'unknown', problem: SyncProblem::class);

        $body = SyncRequest::parse($request);
        $locatorHash = SyncRequest::locatorHash($body);
        $stateId = SyncRequest::stateId($body, required: true);

        $state = $states->findOneByLocatorAndState($locatorHash, $stateId);
        if ($state === null) {
            throw SyncProblem::notFound();
        }

        // Lesen frischt die Aufbewahrungsfrist auf, rührt updatedAt nicht an.
        $state->touchAccess();
        $em->flush();

        return new JsonResponse([
            'stateId' => $state->stateId,
            'updatedAt' => $state->updatedAt->format(\DateTimeInterface::ATOM),
            'payload' => $state->payload,
        ]);
    }
}
```

`backend/src/Controller/Api/LocatorSyncDeleteController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\SyncProblem;
use App\Repository\SyncStateRepository;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/v1/sync/delete – einen Stand oder alle Stände eines Locators
 * löschen (Vertrag, apiLevel 3).
 *
 * Bewusst BEDINGUNGSLOS und ohne Schreib-Token: Löschen passiert hinter
 * einer Bestätigung, und anders als ein stilles Überschreiben sieht der
 * Nutzer dabei zu (so begründet es der Vertrag).
 *
 * Fehlt »stateId«, fällt alles unter dem Locator. Das ist die einzige Stelle,
 * an der ein Nutzer seine Daten selbst wieder loswird – der Server kann sie
 * ihm nicht zuordnen, also muss der Locator genügen.
 */
final class LocatorSyncDeleteController
{
    #[Route('/api/v1/sync/delete', methods: ['POST'])]
    public function __invoke(
        Request $request,
        SyncStateRepository $states,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
    ): JsonResponse {
        $guard->consume($syncV1Limiter, $request->getClientIp() ?? 'unknown', problem: SyncProblem::class);

        $body = SyncRequest::parse($request);
        $locatorHash = SyncRequest::locatorHash($body);
        $stateId = SyncRequest::stateId($body, required: false);

        if ($stateId === null) {
            return new JsonResponse(['deleted' => $states->deleteByLocator($locatorHash)]);
        }

        $state = $states->findOneByLocatorAndState($locatorHash, $stateId);
        if ($state === null) {
            throw SyncProblem::notFound();
        }
        $em->remove($state);
        $em->flush();

        return new JsonResponse(['deleted' => 1]);
    }
}
```

- [ ] **Step 4: Gesamte Suite laufen lassen**

Run: `php backend/bin/phpunit; echo $?`
Expected: OK, Exit-Code 0 — die vier Endpunkte stehen, nichts Bestehendes ist rot.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Controller/Api/LocatorSyncGetController.php backend/src/Controller/Api/LocatorSyncDeleteController.php backend/tests/Functional/LocatorSyncTest.php
git commit -m "Beantworte POST /api/v1/sync/get und /api/v1/sync/delete

get ist POST, weil der Locator ein Bearer-Token ist und im Body reisen
muss - in der URL staende er in jedem Zugriffslog. delete ist bewusst
bedingungslos und ohne Schreib-Token: es passiert hinter einer
Bestaetigung, und der Nutzer sieht dabei zu. Ohne stateId faellt alles
unter dem Locator - die einzige Stelle, an der ein Nutzer seine Daten
selbst wieder loswird."
```

---

## Task 7: Aufbewahrung — `index:sync:prune`

**Files:**
- Create: `backend/src/Command/SyncPruneCommand.php`
- Test: `backend/tests/Command/SyncPruneCommandTest.php`

**Interfaces:**
- Consumes: `SyncStateRepository::deleteUnusedBefore()`, `SyncContract::RETENTION_DAYS`.
- Produces: Command `index:sync:prune` mit optionalem Argument `days` (Default 365).

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Api\SyncContract;
use App\Entity\SyncState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncPruneCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('index:sync:prune'));
    }

    /** 12 Monate ohne Lesen oder Schreiben - dann faellt der Stand. */
    public function testItDeletesStatesUntouchedForTwelveMonths(): void
    {
        $tester = $this->tester();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alt = new SyncState(SyncContract::locatorHash(str_repeat('a', 43)), '0123456789abcdef0123456789abcdef', 'bQ==', 'cA==');
        $alt->lastAccessAt = new \DateTimeImmutable('-400 days');
        $jung = new SyncState(SyncContract::locatorHash(str_repeat('b', 43)), '0123456789abcdef0123456789abcdef', 'bQ==', 'cA==');
        $jung->lastAccessAt = new \DateTimeImmutable('-10 days');
        $em->persist($alt);
        $em->persist($jung);
        $em->flush();

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1', $tester->getDisplay());
        self::assertNull($em->find(SyncState::class, $alt->id));
        self::assertNotNull($em->find(SyncState::class, $jung->id));
    }

    public function testItRejectsANonPositiveThreshold(): void
    {
        $tester = $this->tester();
        $tester->execute(['days' => '0']);

        self::assertSame(2, $tester->getStatusCode()); // Command::INVALID
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit --filter SyncPruneCommandTest`
Expected: FAIL — `Command "index:sync:prune" is not defined.`

- [ ] **Step 3: Implementieren**

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Api\SyncContract;
use App\Repository\SyncStateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `index:sync:prune` – löscht Sync-Stände, die 12 Monate weder gelesen noch
 * geschrieben wurden (Vertrag, »Retention«).
 *
 * Das ist der EINZIGE Weg, auf dem Blobs unter einem verlorenen Geheimnis je
 * verschwinden: der Nutzer kann seinen Locator nicht mehr ableiten, also
 * kommt niemand mehr an sie heran – auch der Betreiber nicht, der nur den
 * Hash sieht. Die Frist steht so in der PRIVACY.md der Extension und wird dem
 * Nutzer angezeigt; sie hier zu verkürzen wäre ein Vertragsbruch.
 *
 * Gehört als Cronjob auf die Zielumgebung (dort heißt das CLI-Binary php85).
 */
#[AsCommand(name: 'index:sync:prune', description: 'Löscht Sync-Stände, die länger als N Tage unberührt sind')]
final class SyncPruneCommand extends Command
{
    public function __construct(
        private readonly SyncStateRepository $states,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Aufbewahrungsfrist in Tagen', (string) SyncContract::RETENTION_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = filter_var($input->getArgument('days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($days === false) {
            $io->error('Die Aufbewahrungsfrist muss eine positive Ganzzahl sein.');

            return Command::INVALID;
        }

        $deleted = $this->states->deleteUnusedBefore(new \DateTimeImmutable(sprintf('-%d days', $days)));

        $io->success(sprintf('%d Sync-Stände gelöscht (unberührt seit mehr als %d Tagen).', $deleted, $days));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `php backend/bin/phpunit --filter SyncPruneCommandTest; echo $?`
Expected: OK, Exit-Code 0.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Command/SyncPruneCommand.php backend/tests/Command/SyncPruneCommandTest.php
git commit -m "Setze die 12-Monats-Aufbewahrung der Sync-Staende durch

Das ist der einzige Weg, auf dem Blobs unter einem verlorenen Geheimnis
je verschwinden: der Nutzer kann seinen Locator nicht mehr ableiten,
also kommt niemand mehr an sie heran - auch der Betreiber nicht, der
nur den Hash sieht. Die Frist steht so in der PRIVACY.md der Extension
und wird dem Nutzer angezeigt."
```

---

## Task 8: `apiLevel` auf 3 heben

**Files:**
- Modify: `backend/src/Api/ApiLevel.php`
- Test: `backend/tests/Functional/UpdateCheckTest.php` (bestehende Erwartung anpassen, falls sie 2 festschreibt)

**Interfaces:** keine neuen.

Erst jetzt, nachdem alle vier Endpunkte antworten — vorher verspräche der Index ein Level, das er nicht bedient. Genau das steht als Bedingung im Klassenkommentar von `ApiLevel`.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
    /**
     * Der Index meldet Level 3, sobald die vier Sync-Endpunkte antworten -
     * vorher waere es ein Versprechen ohne Deckung.
     */
    public function testTheAnswerReportsApiLevelThree(): void
    {
        $this->client->request('POST', '/api/v1/updates', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['apiLevel' => 3, 'entries' => []], JSON_THROW_ON_ERROR));

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(3, $body['apiLevel']);
    }
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit --filter testTheAnswerReportsApiLevelThree`
Expected: FAIL — `Failed asserting that 2 is identical to 3.`

- [ ] **Step 3: Konstante heben und den Kommentar nachziehen**

In `backend/src/Api/ApiLevel.php`: `public const IMPLEMENTED = 3;` und den Absatz »Das R3-Paket hebt den Wert auf 3, sobald die vier Sync-Endpunkte antworten – nicht früher« durch die Feststellung ersetzen, dass sie es seit dem 2026-09-05 tun.

- [ ] **Step 4: Gesamte Suite laufen lassen**

Run: `php backend/bin/phpunit; echo $?`
Expected: OK, Exit-Code 0. Andere Tests, die `apiLevel` gegen 2 prüfen, mit anpassen.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Api/ApiLevel.php backend/tests/
git commit -m "Hebe den gemeldeten apiLevel auf 3

Erst jetzt, nachdem alle vier Sync-Endpunkte antworten. Vorher haette
der Index ein Level versprochen, das er nicht bedient - so steht die
Bedingung seit dem R2-Paket im Kommentar der Konstante."
```

---

## Task 9: Vertragskopie, Lessons und die Rückmeldung ins Logbuch

**Files:**
- Modify: `docs/gestura-eu-api.md`
- Modify: `.claude/lessons.md`
- Modify: `exchange/AUSTAUSCH.md`
- Modify: `CLAUDE.md`

**Interfaces:** keine.

Die Extension-Seite hat zweimal ausdrücklich um zwei Dinge gebeten: eine Zeile im Logbuch, sobald die Endpunkte antworten, und zwei Zeilen in der `CLAUDE.md` (Vertrag in die Referenzliste, Hinweis auf das Logbuch). Beides gehört hierher.

- [ ] **Step 1: Vertragskopie nachziehen**

```bash
{ printf '> **Kopie.** Original: `docs/gestura-eu-api.md` im Extension-Repo (`C:\\Programme.alt\\Gestura`, aus WSL `/mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md`). Änderungen werden dort gemacht und neu herüberkopiert – hier nie direkt ändern. Bei Abweichungen zwischen Vertrag und Index: im Extension-Repo melden. Stand der Kopie: 2026-09-05, Branch `main`, Commit `289a3e7`, apiLevel 3 – vollständig umgesetzt.\n\n'; cat /mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md; } > docs/gestura-eu-api.md
```

- [ ] **Step 2: `CLAUDE.md` um die zwei erbetenen Zeilen ergänzen**

Im Abschnitt »Wichtige Referenzen (Extension-Repo, aus WSL)« ergänzen:

```markdown
- Vertrag Extension ↔ Index (autoritativ, direkt lesen, nie kopieren): `/mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md`
- Austausch-Logbuch: `exchange/AUSTAUSCH.md` – vor Arbeit an der Extension-Schnittstelle zuerst hineinsehen, danach dort eine Zeile hinterlassen.
```

- [ ] **Step 3: Lessons ergänzen**

Drei Einträge, die ein anderes Teammitglied sonst teuer bezahlt:

```markdown
- **Es gibt ZWEI Syncs mit demselben Wort.** `/api/account/sync/{collection}` (`SyncBlob`, `SyncGet/Put/Delete/OverviewController`) ist der kontogebundene Settings-Sync aus dem Juli-Plan. `/api/v1/sync/*` (`SyncState`, `LocatorSync*Controller`) ist der anonyme, locator-adressierte Sync des Extension-Vertrags. Sie teilen kein Datenmodell, keine Auth und keinen Endpunkt – nur den Namen. Wer im falschen sucht, findet nichts.
- **Die Sync-Endpunkte antworten NICHT in RFC 7807.** Der Vertrag schreibt `{ "error": "<code>" }` als `application/json` wörtlich vor; `ProblemJsonSubscriber` hat dafür einen zweiten Zweig für `SyncProblem`. Entscheidend ist ohnehin der **HTTP-Status**: der Client bildet die Codes allein darüber ab und liest genau einen Body – den des `412`, und daraus nur `updatedAt`. Ein richtiger `error`-String unter falschem Status wird falsch gelesen.
- **`payload` in `sync_state` muss MEDIUMTEXT bleiben.** MySQL-TEXT fasst 64 KiB, der Vertrag erlaubt 512 KiB je Envelope – ein maximal großer Stand würde stillschweigend abgeschnitten, und der Client verwürfe ihn danach als unentschlüsselbar. `LocatorSyncPersistenceTest::testAMaximumSizedPayloadSurvivesARoundTrip` sichert das gegen die echte Datenbank ab.
- **Der Klartext-Locator darf die Datenbank nie erreichen** – `SyncRequest::locatorHash()` gibt bewusst nur den Hash heraus, und `SyncStateRepository` hat keine Methode, die einen Klartext-Locator nimmt. Genauso wenig darf ein Request-Body geloggt werden: der Locator ist ein Bearer-Token und reist nur dort.
```

- [ ] **Step 4: Logbuch-Eintrag schreiben**

Oben in `exchange/AUSTAUSCH.md` (neueste Einträge oben), unter der Überschrift »## Wo die Wahrheit steht«, einen Eintrag `## 2026-09-05 · → Extension · Die vier Sync-Endpunkte antworten` ergänzen mit: Basis-URL, Stand (Commit), Bestätigung der drei nicht verhandelbaren Punkte, der Aufbewahrungs-Cronjob — **und den beiden Rückfragen** aus »Zwei Auslegungen, die wir treffen mussten«:

1. Misst `size` (und damit die 512-KiB-Grenze) die Länge des **Base64-Strings** oder die der dekodierten Bytes? Wir haben den Base64-String genommen — »as transmitted« steht bei beiden Zahlen. Der Unterschied wäre Faktor 4/3, und die Grenze wird clientseitig gespiegelt.
2. Wie streng soll `apiLevel` im Request geprüft werden? Wir prüfen nur »vorhanden und ganzzahlig«, damit ein künftiger Level-4-Client nicht abgewiesen wird.

Außerdem: `POST /api/v1/updates` antwortet weiterhin erst nach der manuellen Docroot-Umstellung beim Hoster — das ist der einzige verbliebene Blocker und liegt nicht im Code.

- [ ] **Step 5: Commit**

```bash
git add docs/gestura-eu-api.md CLAUDE.md .claude/lessons.md exchange/AUSTAUSCH.md
git commit -m "Ziehe Vertragskopie, Lessons und Logbuch auf R3 nach

Die Extension-Seite hat zweimal um zwei Zeilen in der CLAUDE.md
gebeten - Vertrag in die Referenzliste, Hinweis auf das Logbuch - und
um eine Zeile im Logbuch, sobald die Endpunkte antworten. Dazu die
beiden Auslegungen, die wir treffen mussten, als Rueckfrage: was
'size' misst und wie streng apiLevel geprueft wird."
```

---

## Task 10: Nachweis gegen einen laufenden Dienst

**Files:** keine — dies ist die Abnahme.

Der Vertrag stellt fünf Anforderungen an die Antwort, die kein Unit-Test abdeckt (*How the client reads an answer*). Sie werden gegen den echten Dev-Server geprüft.

- [ ] **Step 1: Dienst starten**

```bash
php -S localhost:8000 -t backend/public
```

- [ ] **Step 2: Kein Redirect auf den vier Pfaden**

```bash
for p in sync/list sync/get sync/delete; do
  printf '%s -> ' "$p"
  curl -s -o /dev/null -w '%{http_code}\n' -X POST "http://localhost:8000/api/v1/$p" \
    -H 'Content-Type: application/json' -d '{"apiLevel":3,"locator":"zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk"}'
done
printf 'sync/state -> '
curl -s -o /dev/null -w '%{http_code}\n' -X PUT http://localhost:8000/api/v1/sync/state \
  -H 'Content-Type: application/json' -d '{"apiLevel":3,"locator":"zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk","stateId":"0123456789abcdef0123456789abcdef","meta":"bQ==","payload":"cA=="}'
```

Expected: `200`, `400`, `200`, `200` — **kein einziger 3xx**. Ein `301`/`308` wäre ein gescheiterter Aufruf für den Client, und ein `307`/`308` reichte den Locator im Body an die Zielorigin weiter.

- [ ] **Step 3: Preflight**

```bash
curl -s -i -X OPTIONS http://localhost:8000/api/v1/sync/state \
  -H 'Origin: moz-extension://abc' -H 'Access-Control-Request-Method: PUT' | head -12
```

Expected: `204`, `Access-Control-Allow-Origin: *`, `PUT` und `OPTIONS` in den Methoden, `Content-Type` in den Headern.

- [ ] **Step 4: Der 412-Pfad von Hand**

Anlegen, dann mit falschem `basePayloadHash` überschreiben:

```bash
L=zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk
S=0123456789abcdef0123456789abcdef
curl -s -X PUT http://localhost:8000/api/v1/sync/state -H 'Content-Type: application/json' \
  -d "{\"apiLevel\":3,\"locator\":\"$L\",\"stateId\":\"$S\",\"meta\":\"bQ==\",\"payload\":\"YWx0\"}"
echo
curl -s -i -X PUT http://localhost:8000/api/v1/sync/state -H 'Content-Type: application/json' \
  -d "{\"apiLevel\":3,\"locator\":\"$L\",\"stateId\":\"$S\",\"meta\":\"bQ==\",\"payload\":\"bmV1\",\"basePayloadHash\":\"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\"}" | tail -3
curl -s -X POST http://localhost:8000/api/v1/sync/get -H 'Content-Type: application/json' \
  -d "{\"apiLevel\":3,\"locator\":\"$L\",\"stateId\":\"$S\"}"
```

Expected: `412`, Body genau `{"error":"conflict","updatedAt":"…"}`, und der `get` liefert danach weiterhin `YWx0` — die Ablehnung hatte **keine** Teilwirkung.

- [ ] **Step 5: Kein Body im Log**

```bash
grep -rn "zoogXw2lwmt" backend/var/log/ 2>/dev/null; echo "Treffer: $?"
```

Expected: keine Treffer (`grep` endet mit 1). Ein Treffer hieße, dass irgendwo ein Request-Body oder ein Locator protokolliert wird — das ist ein Log voller Zugangsschlüssel und muss sofort behoben werden.

- [ ] **Step 6: Cronjob dokumentieren**

In `deploy/README.md` den Aufbewahrungs-Job ergänzen (Zielumgebung, CLI-Binary `php85`):

```
# täglich, Aufbewahrung der Sync-Stände (12 Monate, Vertrag »Retention«)
17 3 * * * cd ~/current/backend && php85 bin/console index:sync:prune >/dev/null
```

---

## Self-Review

**Spec-Abdeckung** (Instruktion §2.1–§2.5, §3):

| Vorgabe | Aufgabe |
|---|---|
| §2.1 vier Endpunkte | 4, 5, 6 |
| §2.1 drei `basePayloadHash`-Fälle | 5 |
| §2.1 `412` ohne Teilwirkung | 5 (Sentinel statt Wurf), 10 Step 4 |
| §2.1 meta+payload bleiben zusammen | 2 (`setBlobs` privat, nur gemeinsam) |
| §2.1 kein `payloadHash` in der Antwort | 5 (Test) |
| §2.2 Status entscheidet | 3 |
| §2.2 keine Weiterleitungen | 10 Step 2 |
| §2.2 Antwort < 1 MiB | 4/6 (nur ein Envelope je Antwort) |
| §2.2 keine Cookies | 4–6 (cookielose öffentliche API, kein Firewall-Eintrag) |
| §2.3 Blob-Grenzen → 413 | 4 (`SyncRequest::envelope`), 5 (Tests) |
| §2.3 Ständegrenze → 409, nur beim Anlegen | 5 |
| §2.3 4-MiB-Summe nicht durchgesetzt | 1 (begründet), 9 (Lessons) |
| §2.3 Aufbewahrung 12 Monate | 2, 7, 10 Step 6 |
| §2.3 Per-IP-Limit auf Anfragen **und Bytes** | 4 (Guard + zwei Limiter), 5 (Byte-Verbrauch) |
| §2.4 Komprimierung | keine Aufgabe — der Server sieht nur Envelopes; die 512 KiB messen den Envelope, nicht den Klartext (in Task 1 kommentiert) |
| §2.5 `/api/v1/updates` antwortet | **nicht im Code lösbar** — manuelle Docroot-Umstellung beim Hoster; in Task 9 als einziger verbliebener Blocker ins Logbuch |
| §3.1 keine Body-Logs | 4 (Kommentar), 10 Step 5 (Nachweis) |
| §3.2 Locator-Form vor Adressierung | 1, 4 |
| §3.3 Locator gehasht ablegen | 1, 2 |
| §3 CORS + Preflight | 4 (Test), 10 Step 3 — `CorsSubscriber` deckt es bereits ab, keine Änderung nötig |

**Platzhalter:** keine — jeder Schritt trägt lauffähigen Code oder einen konkreten Befehl mit erwarteter Ausgabe.

**Typ-Konsistenz:** `SyncContract::payloadHash()`, `locatorHash()`, `decodeEnvelope()` werden in den Tasks 2, 4, 5 mit denselben Signaturen benutzt wie in Task 1 definiert. `SyncState::replaceBlobs()`/`touchAccess()` heißen in den Tasks 4–6 genauso wie in Task 2. `RateLimitGuard::consume()` bekommt in Task 4 zwei Parameter mit Defaults, sodass die bestehenden acht Aufrufstellen unverändert bleiben — das ist der Grund für die Default-Werte.

**Bekannte, bewusst offene Stelle:** Die Quote-Prüfung beim Anlegen (`countByLocator` dann `persist`) ist unter echter Nebenläufigkeit um eins überschreitbar — zwei gleichzeitige Anlagen auf einem Locator mit vier Ständen können sechs ergeben. Eine Sperre hilft nicht, weil bei null Zeilen nichts zu sperren ist (Gap-Locking wäre der einzige Weg). Das ist unschädlich: der Vertrag toleriert Locators über der Grenze ausdrücklich und verlangt nur, dass ein *weiteres* Anlegen abgelehnt wird — was danach wieder greift.
