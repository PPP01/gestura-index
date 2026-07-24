# Phase-3 E2E-Settings-Sync (Backend) – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zero-Knowledge-Blob-Store für den Settings-Sync: `SyncBlob`-Entität mit DB-Kaskade, Endpunkte GET-Übersicht/GET/PUT/DELETE unter `/api/account/sync/*` mit optimistischem Locking (409-Konflikt), Größen-/Whitelist-Grenzen und `sync_write`-Limiter.

**Architecture:** Der Server speichert nur opake Chiffrate mit Versionszähler – kein Entschlüsseln, kein Merge (unmöglich by design). Auth läuft über den bestehenden `AccountResolver` (Bearer `gacc_…`). PUT serialisiert Konfliktprüfung + Schreiben über eine pessimistische Sperre auf der Konto-Zeile innerhalb `wrapInTransaction` (Sentinel-Muster: 409 wird NACH dem Commit geworfen, nie aus der Closure – sonst schließt der EntityManager).

**Tech Stack:** PHP 8.5, Symfony 7.4 LTS, Doctrine ORM/MariaDB, PHPUnit, Symfony RateLimiter.

## Global Constraints

- **Collections-Whitelist:** exakt `settings`, `menus`, `engines` – als Konstante `SyncBlob::COLLECTIONS`. Unbekannte Collection → `ApiProblem(400, 'Unknown collection')`.
- **Größenlimit:** `SyncBlob::MAX_CIPHERTEXT_BYTES = 262144` (256 KB, `strlen` des Chiffrat-Strings). Überschreitung → `ApiProblem(413, 'Ciphertext too large')`, geprüft VOR jedem DB-Schreiben.
- **Konfliktmodell:** `baseVersion` muss dem Server-Stand entsprechen (leerer Slot = `0`); sonst `ApiProblem(409, 'Sync version conflict', ['version' => <aktuell>])`, Blob unverändert.
- **Rate-Limiter `sync_write`** (nur PUT/DELETE) in ALLEN drei Blöcken von `backend/config/packages/rate_limiter.yaml`: `framework` `{ policy: sliding_window, limit: 120, interval: '1 hour' }`; `when@test` und `when@dev` je `limit: 1000`. Typehint `RateLimiterFactoryInterface $syncWriteLimiter` (nie das deprecated `RateLimiterFactory`).
- **Auth:** ausschließlich `App\Service\AccountResolver::requireAccount(Request $request, bool $touchLastSeen = true): Account` (wirft `ApiProblem(401)`).
- **`wrapInTransaction`-Regel (lessons.md):** Domain-Konflikte (409) NIE aus der Closure werfen – als Sentinel zurückgeben, `ApiProblem` erst nach dem Commit. `$em->lock(..., PESSIMISTIC_WRITE)` braucht eine aktive Transaktion; nach dem Sperren `refresh()`.
- **Bezeichner Englisch, Kommentare/Docblocks Deutsch.** PHPUnit läuft mit `failOnDeprecation="true"` – Exit-Code prüfen (`; echo $?`), nie nur den »OK«-Text. Lokales CLI ist `php` (Server: `php85`).

---

## Datei-Struktur

- `backend/src/Entity/SyncBlob.php` (neu) – Entität + Domänen-Konstanten (Whitelist, Max-Größe).
- `backend/src/Repository/SyncBlobRepository.php` (neu) – Standard-Repository.
- `backend/migrations/Version*.php` (neu, generiert) – Tabelle `sync_blob` mit `UNIQUE(account_id, collection)` und FK `ON DELETE CASCADE`.
- `backend/src/Controller/Api/SyncPutController.php`, `SyncGetController.php`, `SyncOverviewController.php`, `SyncDeleteController.php` (neu) – je ein Endpunkt.
- `backend/config/packages/rate_limiter.yaml` (ändern) – `sync_write`.
- `deploy/README.md`, `.claude/lessons.md` (ändern, Task 4) – Kaskaden-Vorbehalt als aufgelöst markieren.
- Tests: `backend/tests/Functional/Api/SyncTest.php` (Endpunkt-Verhalten), `backend/tests/Functional/Api/SyncCascadeTest.php` (Kaskaden-Regression), `backend/tests/Unit/SyncWriteLimiterTest.php` (isolierter Limiter).

Wiederverwendet (nicht ändern): `AccountResolver`, `RateLimitGuard`, `ApiProblem`, `AccountTokenService`, `Account`.

---

### Task 1: SyncBlob-Entität, Repository & Migration

**Files:**
- Create: `backend/src/Entity/SyncBlob.php`
- Create: `backend/src/Repository/SyncBlobRepository.php`
- Create: `backend/migrations/Version*.php` (generiert)
- Test: `backend/tests/Functional/Api/SyncBlobPersistenceTest.php`

**Interfaces:**
- Consumes: `App\Entity\Account` (Sub-Projekt A).
- Produces: `App\Entity\SyncBlob` mit public `?int $id`, `Account $account`, `string $collection`, `string $ciphertext`, `int $version` (Start 1), `\DateTimeImmutable $updatedAt`; Konstruktor `__construct(Account $account, string $collection, string $ciphertext)`; Konstanten `SyncBlob::COLLECTIONS = ['settings', 'menus', 'engines']` und `SyncBlob::MAX_CIPHERTEXT_BYTES = 262144`. `App\Repository\SyncBlobRepository` (Standard `ServiceEntityRepository<SyncBlob>`).

- [ ] **Step 1: Write the failing test**

`backend/tests/Functional/Api/SyncBlobPersistenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\SyncBlob;
use App\Tests\Functional\ApiTestCase;

final class SyncBlobPersistenceTest extends ApiTestCase
{
    public function testPersistAndFindByAccountAndCollection(): void
    {
        $account = new Account(bin2hex(random_bytes(8)), 'hash');
        $this->em->persist($account);
        $blob = new SyncBlob($account, 'settings', 'ciphertext-payload');
        $this->em->persist($blob);
        $this->em->flush();
        $accountId = $account->id;
        $this->em->clear();

        $found = $this->em->getRepository(SyncBlob::class)->findOneBy([
            'account' => $accountId,
            'collection' => 'settings',
        ]);
        self::assertNotNull($found);
        self::assertSame('ciphertext-payload', $found->ciphertext);
        self::assertSame(1, $found->version);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php backend/bin/phpunit --filter SyncBlobPersistenceTest; echo $?`
Expected: FAIL (Class `App\Entity\SyncBlob` not found).

- [ ] **Step 3: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SyncBlobRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein clientseitig verschlüsselter Sync-Blob eines End-Nutzer-Kontos (Phase 3,
 * Zero-Knowledge-Settings-Sync). Der Server behandelt ciphertext als opaken
 * String – kein Entschlüsseln, keine Struktur-Kenntnis, kein Merge. version
 * ist der monoton steigende Zähler fürs optimistische Locking (PUT mit
 * baseVersion). Der FK trägt DB-seitiges ON DELETE CASCADE, damit auch das
 * DQL-Bulk-DELETE von index:account:prune (umgeht die ORM-Kaskade) die Blobs
 * zuverlässig mit entfernt.
 */
#[ORM\Entity(repositoryClass: SyncBlobRepository::class)]
#[ORM\Table(name: 'sync_blob')]
#[ORM\UniqueConstraint(columns: ['account_id', 'collection'])]
class SyncBlob
{
    /** Erlaubte Slots – hart begrenzt, kein Free-Form-Storage. */
    public const COLLECTIONS = ['settings', 'menus', 'engines'];

    /** Maximale Chiffrat-Größe in Bytes (256 KB) – Missbrauchsbremse. */
    public const MAX_CIPHERTEXT_BYTES = 262144;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Account $account;

    #[ORM\Column(length: 16)]
    public string $collection;

    #[ORM\Column(type: 'text', length: self::MAX_CIPHERTEXT_BYTES)]
    public string $ciphertext;

    #[ORM\Column]
    public int $version = 1;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(Account $account, string $collection, string $ciphertext)
    {
        $this->account = $account;
        $this->collection = $collection;
        $this->ciphertext = $ciphertext;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 4: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SyncBlob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncBlob>
 */
final class SyncBlobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncBlob::class);
    }
}
```

- [ ] **Step 5: Generate and review the migration**

Run: `php backend/bin/console make:migration`
Open the generated `backend/migrations/Version*.php` and verify `up()` creates `sync_blob` with: `id` PK auto-increment; `account_id` NOT NULL with **`FOREIGN KEY … REFERENCES account (id) ON DELETE CASCADE`**; `collection VARCHAR(16)`; `ciphertext MEDIUMTEXT`; `version INT`; `updated_at DATETIME`; **UNIQUE index on (`account_id`, `collection`)**. The `ON DELETE CASCADE` clause is the critical piece – if it is missing, the `onDelete: 'CASCADE'` attribute was not picked up; fix the entity, regenerate. Remove unrelated generator noise if any.

- [ ] **Step 6: Apply the migration to dev and test databases**

Run: `php backend/bin/console doctrine:migrations:migrate -n`
Run: `php backend/bin/console doctrine:migrations:migrate -n --env=test`

- [ ] **Step 7: Run test to verify it passes**

Run: `php backend/bin/phpunit --filter SyncBlobPersistenceTest; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/SyncBlob.php backend/src/Repository/SyncBlobRepository.php backend/migrations/ backend/tests/Functional/Api/SyncBlobPersistenceTest.php
git commit -m "Ergänze SyncBlob-Entität mit DB-Kaskade und Migration"
```

---

### Task 2: PUT /api/account/sync/{collection} + sync_write-Limiter

**Files:**
- Create: `backend/src/Controller/Api/SyncPutController.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Functional/Api/SyncTest.php`

**Interfaces:**
- Consumes: `SyncBlob` (Task 1), `AccountResolver::requireAccount(Request): Account`, `RateLimitGuard::consume(RateLimiterFactoryInterface, string)`, `SyncBlobRepository`.
- Produces: `PUT /api/account/sync/{collection}` mit Body `{"baseVersion": int, "ciphertext": string}` → `200 {"version": int}` | `409 {"version": int}` (im problem+json-`extra`) | `400`/`413`/`401`. Später von Task 3/4-Tests als Upload-Helfer benutzt.

- [ ] **Step 1: Add the limiter config**

In `backend/config/packages/rate_limiter.yaml` in allen drei Blöcken ergänzen:

`framework > rate_limiter`:
```yaml
        sync_write: { policy: sliding_window, limit: 120, interval: '1 hour' }
```
`when@test` und `when@dev` (je):
```yaml
            sync_write: { policy: sliding_window, limit: 1000, interval: '1 hour' }
```

- [ ] **Step 2: Write the failing tests**

`backend/tests/Functional/Api/SyncTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

final class SyncTest extends ApiTestCase
{
    /** @return string Klartext-Token */
    private function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    /** @return array<string, string> */
    private function authHdr(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'];
    }

    /** @return array{token: string} */
    private function putBlob(string $token, string $collection, int $baseVersion, string $ciphertext): void
    {
        $this->client->request('PUT', '/api/account/sync/' . $collection, server: $this->authHdr($token),
            content: json_encode(['baseVersion' => $baseVersion, 'ciphertext' => $ciphertext], JSON_THROW_ON_ERROR));
    }

    public function testPutCreatesAndIncrementsVersion(): void
    {
        $token = $this->createAccount();

        $this->putBlob($token, 'settings', 0, 'cipher-v1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['version']);

        $this->putBlob($token, 'settings', 1, 'cipher-v2');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json()['version']);
    }

    public function testPutWithStaleBaseVersionIs409AndKeepsBlob(): void
    {
        $token = $this->createAccount();
        $this->putBlob($token, 'settings', 0, 'cipher-v1');
        $this->putBlob($token, 'settings', 1, 'cipher-v2');

        // Veraltete baseVersion (1, aktuell ist 2) → 409 mit aktueller Version:
        $this->putBlob($token, 'settings', 1, 'cipher-stale');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(2, $this->json()['version']);

        // Blob unverändert:
        $this->client->request('GET', '/api/account/sync/settings', server: $this->authHdr($token));
        self::assertSame('cipher-v2', $this->json()['ciphertext']);
    }

    public function testPutUnknownCollectionIs400(): void
    {
        $token = $this->createAccount();
        $this->putBlob($token, 'bookmarks', 0, 'x');
        self::assertResponseStatusCodeSame(400);
    }

    public function testPutInvalidFieldsAre400(): void
    {
        $token = $this->createAccount();

        $this->client->request('PUT', '/api/account/sync/settings', server: $this->authHdr($token),
            content: json_encode(['baseVersion' => -1, 'ciphertext' => 'x'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);

        $this->client->request('PUT', '/api/account/sync/settings', server: $this->authHdr($token),
            content: json_encode(['baseVersion' => 0], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);

        $this->client->request('PUT', '/api/account/sync/settings', server: $this->authHdr($token),
            content: 'not-json');
        self::assertResponseStatusCodeSame(400);
    }

    public function testPutOversizedCiphertextIs413(): void
    {
        $token = $this->createAccount();
        $this->putBlob($token, 'settings', 0, str_repeat('a', 262145));
        self::assertResponseStatusCodeSame(413);
    }

    public function testPutWithoutTokenIs401(): void
    {
        $this->client->request('PUT', '/api/account/sync/settings', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['baseVersion' => 0, 'ciphertext' => 'x'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);
    }
}
```

Hinweis: `testPutWithStaleBaseVersionIs409AndKeepsBlob` nutzt bereits den GET-Endpunkt aus Task 3. Beim isolierten Task-2-Lauf schlägt genau diese eine Nachprüfung mit 404 fehl – der Implementer lässt diesen Test bis Task 3 mit `self::markTestSkipped('GET folgt in Task 3')` NACH dem 409-Assert enden **oder** verschiebt nur die GET-Nachprüfung in Task 3. Bevorzugt: die zwei GET-Zeilen in Task 2 weglassen und in Task 3 (Step 2) ergänzen – der Plan zeigt sie hier der Vollständigkeit halber am Zielzustand.

- [ ] **Step 3: Run tests to verify they fail**

Run: `php backend/bin/phpunit --filter SyncTest; echo $?`
Expected: FAIL (404 – Route existiert nicht).

- [ ] **Step 4: Write the PUT controller**

`backend/src/Controller/Api/SyncPutController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SyncBlob;
use App\Exception\ApiProblem;
use App\Repository\SyncBlobRepository;
use App\Service\AccountResolver;
use App\Service\RateLimitGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lädt einen clientseitig verschlüsselten Sync-Blob hoch (Zero-Knowledge:
 * der Server prüft nur Größe und Versionszähler, nie den Inhalt).
 * Optimistisches Locking: baseVersion muss dem Server-Stand entsprechen
 * (leerer Slot = 0), sonst 409 mit der aktuellen Version – der Client lädt
 * dann neu, merged lokal und versucht es erneut.
 */
final class SyncPutController
{
    #[Route('/api/account/sync/{collection}', methods: ['PUT'])]
    public function __invoke(
        string $collection,
        Request $request,
        AccountResolver $resolver,
        SyncBlobRepository $blobs,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncWriteLimiter,
    ): JsonResponse {
        $account = $resolver->requireAccount($request);
        if (!\in_array($collection, SyncBlob::COLLECTIONS, true)) {
            throw new ApiProblem(400, 'Unknown collection');
        }
        $guard->consume($syncWriteLimiter, $request->getClientIp() ?? 'unknown');

        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        $baseVersion = $body['baseVersion'] ?? null;
        $ciphertext = $body['ciphertext'] ?? null;
        if (!\is_int($baseVersion) || $baseVersion < 0) {
            throw new ApiProblem(400, 'baseVersion must be a non-negative integer');
        }
        if (!\is_string($ciphertext) || $ciphertext === '') {
            throw new ApiProblem(400, 'ciphertext must be a non-empty string');
        }
        if (\strlen($ciphertext) > SyncBlob::MAX_CIPHERTEXT_BYTES) {
            throw new ApiProblem(413, 'Ciphertext too large');
        }

        // Konfliktprüfung + Schreiben atomar. Die Konto-Zeile dient als
        // Aggregat-Sperre: sie existiert immer (anders als der Blob beim
        // Erst-Upload) und serialisiert damit auch zwei parallele erste
        // Uploads, die sonst beide »Slot leer« sähen und in die Unique-
        // Constraint-Verletzung liefen. Der 409 wird als Sentinel
        // ZURÜCKGEGEBEN, nie aus der Closure geworfen – ein Throw würde den
        // EntityManager schließen (lessons.md).
        /** @var array{version: int}|array{conflict: int} $result */
        $result = $em->wrapInTransaction(function () use ($em, $blobs, $account, $collection, $baseVersion, $ciphertext): array {
            $em->lock($account, LockMode::PESSIMISTIC_WRITE);
            $blob = $blobs->findOneBy(['account' => $account, 'collection' => $collection]);
            if ($blob !== null) {
                // Nach dem Warten auf die Sperre den frischen Stand lesen:
                $em->refresh($blob);
            }
            $current = $blob?->version ?? 0;
            if ($baseVersion !== $current) {
                return ['conflict' => $current];
            }
            if ($blob === null) {
                $blob = new SyncBlob($account, $collection, $ciphertext);
                $em->persist($blob);
            } else {
                $blob->ciphertext = $ciphertext;
                $blob->version = $current + 1;
                $blob->updatedAt = new \DateTimeImmutable();
            }

            return ['version' => $blob->version];
        });

        if (isset($result['conflict'])) {
            throw new ApiProblem(409, 'Sync version conflict', ['version' => $result['conflict']]);
        }

        return new JsonResponse(['version' => $result['version']]);
    }
}
```

Hinweis für den Implementer: Prüfe den `LockMode`-Import gegen `backend/src/Controller/Admin/VersionApproveController.php` (dort ist das Lock+Refresh-Muster etabliert) und übernimm exakt dessen Import, falls er von `Doctrine\DBAL\LockMode` abweicht.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php backend/bin/phpunit --filter SyncTest; echo $?`
Expected: PASS (alle Task-2-Tests; die GET-Nachprüfung folgt in Task 3), Exit 0.

- [ ] **Step 6: Prüfe, ob der 409 die Version im problem+json trägt**

Der `ApiProblem`-Konstruktor nimmt `extra` als drittes Argument (vgl. `RateLimitGuard`: `['retryAfter' => …]`); `ProblemJsonSubscriber` legt diese Felder in den Response-Body. Der Test `testPutWithStaleBaseVersionIs409AndKeepsBlob` weist `$this->json()['version']` nach – schlägt das fehl, den Subscriber ansehen statt raten.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Controller/Api/SyncPutController.php backend/config/packages/rate_limiter.yaml backend/tests/Functional/Api/SyncTest.php
git commit -m "Ergänze PUT /api/account/sync mit optimistischem Locking"
```

---

### Task 3: GET-Übersicht, GET-Collection und DELETE

**Files:**
- Create: `backend/src/Controller/Api/SyncOverviewController.php`, `backend/src/Controller/Api/SyncGetController.php`, `backend/src/Controller/Api/SyncDeleteController.php`
- Modify: `backend/tests/Functional/Api/SyncTest.php`

**Interfaces:**
- Consumes: Task 1/2 (`SyncBlob`, `SyncBlobRepository`, PUT-Endpunkt als Test-Helfer, `AccountResolver`, `RateLimitGuard`, `$syncWriteLimiter`).
- Produces: `GET /api/account/sync` → `200 {"collections": {"settings": {"version": 1, "updatedAt": "<ATOM>", "size": 9}, …}}` (leeres Objekt bei neuem Konto); `GET /api/account/sync/{collection}` → `200 {"version": n, "ciphertext": "…"}` | `404`; `DELETE /api/account/sync/{collection}` → `204` | `404`.

- [ ] **Step 1: Write the failing tests (in SyncTest.php ergänzen)**

```php
    public function testGetReturnsBlobAndEmptySlotIs404(): void
    {
        $token = $this->createAccount();
        $this->client->request('GET', '/api/account/sync/settings', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(404);

        $this->putBlob($token, 'settings', 0, 'cipher-v1');
        $this->client->request('GET', '/api/account/sync/settings', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['version']);
        self::assertSame('cipher-v1', $this->json()['ciphertext']);
    }

    public function testOverviewListsVersionsWithoutCiphertext(): void
    {
        $token = $this->createAccount();

        $this->client->request('GET', '/api/account/sync', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], (array) $this->json()['collections']);

        $this->putBlob($token, 'settings', 0, 'cipher-set');
        $this->putBlob($token, 'menus', 0, 'cipher-menus');

        $this->client->request('GET', '/api/account/sync', server: $this->authHdr($token));
        $collections = $this->json()['collections'];
        self::assertSame(1, $collections['settings']['version']);
        self::assertSame(\strlen('cipher-menus'), $collections['menus']['size']);
        self::assertArrayHasKey('updatedAt', $collections['settings']);
        self::assertArrayNotHasKey('ciphertext', $collections['settings']);
    }

    public function testDeleteClearsSlotAndSecondDeleteIs404(): void
    {
        $token = $this->createAccount();
        $this->putBlob($token, 'engines', 0, 'cipher-e');

        $this->client->request('DELETE', '/api/account/sync/engines', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', '/api/account/sync/engines', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(404);

        // Nach dem Löschen beginnt der Slot wieder bei baseVersion 0:
        $this->putBlob($token, 'engines', 0, 'cipher-neu');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['version']);
    }

    public function testGetUnknownCollectionIs400(): void
    {
        $token = $this->createAccount();
        $this->client->request('GET', '/api/account/sync/bookmarks', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(400);
    }
```

Falls in Task 2 die GET-Nachprüfung aus `testPutWithStaleBaseVersionIs409AndKeepsBlob` weggelassen wurde: jetzt ergänzen (zwei Zeilen, siehe Task 2 Step 2).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php backend/bin/phpunit --filter SyncTest; echo $?`
Expected: FAIL (404/405 – GET-/DELETE-Routen existieren nicht).

- [ ] **Step 3: Write SyncGetController**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SyncBlob;
use App\Exception\ApiProblem;
use App\Repository\SyncBlobRepository;
use App\Service\AccountResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert das Chiffrat einer Collection samt Versionszähler – der Client
 * entschlüsselt, merged lokal und lädt per PUT wieder hoch.
 */
final class SyncGetController
{
    #[Route('/api/account/sync/{collection}', methods: ['GET'])]
    public function __invoke(string $collection, Request $request, AccountResolver $resolver, SyncBlobRepository $blobs): JsonResponse
    {
        $account = $resolver->requireAccount($request);
        if (!\in_array($collection, SyncBlob::COLLECTIONS, true)) {
            throw new ApiProblem(400, 'Unknown collection');
        }
        $blob = $blobs->findOneBy(['account' => $account, 'collection' => $collection])
            ?? throw new ApiProblem(404, 'No sync data for this collection');

        return new JsonResponse(['version' => $blob->version, 'ciphertext' => $blob->ciphertext]);
    }
}
```

- [ ] **Step 4: Write SyncOverviewController**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\SyncBlobRepository;
use App\Service\AccountResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Billiger Änderungs-Poll: listet je belegter Collection Version, Zeitstempel
 * und Größe – ohne Chiffrat. Der Client vergleicht die Versionen mit seinem
 * letzten Stand und lädt nur bei Abweichung das Chiffrat nach.
 */
final class SyncOverviewController
{
    #[Route('/api/account/sync', methods: ['GET'])]
    public function __invoke(Request $request, AccountResolver $resolver, SyncBlobRepository $blobs): JsonResponse
    {
        $account = $resolver->requireAccount($request);

        $collections = [];
        foreach ($blobs->findBy(['account' => $account]) as $blob) {
            $collections[$blob->collection] = [
                'version' => $blob->version,
                'updatedAt' => $blob->updatedAt->format(\DateTimeInterface::ATOM),
                'size' => \strlen($blob->ciphertext),
            ];
        }

        // (object)-Cast, damit ein leeres Ergebnis als {} statt [] serialisiert:
        return new JsonResponse(['collections' => (object) $collections]);
    }
}
```

- [ ] **Step 5: Write SyncDeleteController**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SyncBlob;
use App\Exception\ApiProblem;
use App\Repository\SyncBlobRepository;
use App\Service\AccountResolver;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Leert einen Sync-Slot (Teil des Löschrechts). Der Versionszähler beginnt
 * danach wieder bei 0 (Erst-Upload mit baseVersion 0).
 */
final class SyncDeleteController
{
    #[Route('/api/account/sync/{collection}', methods: ['DELETE'])]
    public function __invoke(
        string $collection,
        Request $request,
        AccountResolver $resolver,
        SyncBlobRepository $blobs,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncWriteLimiter,
    ): Response {
        $account = $resolver->requireAccount($request);
        if (!\in_array($collection, SyncBlob::COLLECTIONS, true)) {
            throw new ApiProblem(400, 'Unknown collection');
        }
        $guard->consume($syncWriteLimiter, $request->getClientIp() ?? 'unknown');

        $blob = $blobs->findOneBy(['account' => $account, 'collection' => $collection])
            ?? throw new ApiProblem(404, 'No sync data for this collection');
        $em->remove($blob);
        $em->flush();

        return new Response('', 204);
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php backend/bin/phpunit --filter SyncTest; echo $?`
Expected: PASS (alle SyncTest-Methoden inkl. Task 2), Exit 0.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Controller/Api/SyncGetController.php backend/src/Controller/Api/SyncOverviewController.php backend/src/Controller/Api/SyncDeleteController.php backend/tests/Functional/Api/SyncTest.php
git commit -m "Ergänze Sync-Endpunkte für Lesen, Übersicht und Löschen"
```

---

### Task 4: Kaskaden-Regression, isolierter Limiter-Test, Doku-Auflösung, volle Suite

**Files:**
- Create: `backend/tests/Functional/Api/SyncCascadeTest.php`, `backend/tests/Unit/SyncWriteLimiterTest.php`
- Modify: `deploy/README.md` (Phase-3-Sektion), `.claude/lessons.md` (Prune-Eintrag)

**Interfaces:**
- Consumes: alles aus Task 1–3; `App\Entity\Account`; Command `index:account:prune` (Sub-Projekt A); Test-Idiom aus `backend/tests/Command/AccountPruneCommandTest.php` (KernelTestCase + CommandTester).
- Produces: keine neuen Code-Schnittstellen – Regressionsnetz + aktualisierte Doku.

- [ ] **Step 1: Write the failing cascade tests**

`backend/tests/Functional/Api/SyncCascadeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\SyncBlob;
use App\Tests\Functional\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regressionsnetz für den in Sub-Projekt A dokumentierten Vorbehalt: sowohl
 * das Konto-Löschen (ORM remove) als auch index:account:prune (DQL-Bulk-
 * DELETE, umgeht die ORM-Kaskade) müssen zugehörige SyncBlobs über die
 * DB-seitige ON-DELETE-CASCADE-Kaskade mit entfernen.
 */
final class SyncCascadeTest extends ApiTestCase
{
    /** @return string Klartext-Token */
    private function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    public function testAccountDeleteCascadesSyncBlobs(): void
    {
        $token = $this->createAccount();
        $this->client->request('PUT', '/api/account/sync/settings',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['baseVersion' => 0, 'ciphertext' => 'cipher'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->client->request('DELETE', '/api/account',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(SyncBlob::class)->count([]));
    }

    public function testPruneCascadesSyncBlobs(): void
    {
        $account = new Account(bin2hex(random_bytes(8)), 'hash');
        $account->lastSeenAt = new \DateTimeImmutable('-400 days');
        $this->em->persist($account);
        $this->em->persist(new SyncBlob($account, 'settings', 'cipher'));
        $this->em->flush();
        $this->em->clear();

        $command = (new Application(self::$kernel))->find('index:account:prune');
        $tester = new CommandTester($command);
        $tester->execute(['days' => '365']);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Account::class)->count([]));
        self::assertSame(0, $this->em->getRepository(SyncBlob::class)->count([]));
    }
}
```

Hinweis: `ApiTestCase` bootet den Kernel über `createClient()`; falls `self::$kernel` dort nicht gesetzt ist, das Bootstrapping aus `backend/tests/Command/AccountPruneCommandTest.php` übernehmen (dort ist das CommandTester-Idiom etabliert).

- [ ] **Step 2: Write the isolated limiter test**

`backend/tests/Unit/SyncWriteLimiterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Service\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class SyncWriteLimiterTest extends TestCase
{
    public function testBlocksAfterLimitReached(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'sync_write', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'],
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

- [ ] **Step 3: Run the new tests**

Run: `php backend/bin/phpunit --filter 'SyncCascadeTest|SyncWriteLimiterTest'; echo $?`
Expected: PASS, Exit 0. (Schlägt `testPruneCascadesSyncBlobs` fehl, fehlt das `ON DELETE CASCADE` in der Migration – zurück zu Task 1 Step 5.)

- [ ] **Step 4: Mark the cascade caveat as resolved in the docs**

In `deploy/README.md`, Abschnitt »Phase 3: End-Nutzer-Konten (Sub-Projekt A)«, den ⚠️-Absatz ERSETZEN durch:

```markdown
✅ **Aufgelöst mit Sub-Projekt F (Settings-Sync):** Der `sync_blob`-FK trägt DB-seitiges `ON DELETE CASCADE` – sowohl Konto-Löschen als auch das DQL-Bulk-DELETE von `index:account:prune` entfernen Sync-Blobs zuverlässig mit (Regressionstest `SyncCascadeTest`). Neue Tabellen mit FK auf `account` müssen diesem Muster folgen.
```

In `.claude/lessons.md` im Prune-Eintrag den Satz »ABER sobald Sub-Projekt F `SyncBlob` (o. ä.) mit FK auf `account` einführt …« ergänzen um: »(In F umgesetzt: `sync_blob`-FK trägt `ON DELETE CASCADE`, Regressionstest `SyncCascadeTest`; neue FKs auf `account` müssen dem Muster folgen.)«

- [ ] **Step 5: Run the FULL backend suite (final gate)**

Run: `php backend/bin/phpunit; echo $?`
Expected: OK, Exit **0** (Exit-Code prüfen, nicht nur den Text – `failOnDeprecation`).

- [ ] **Step 6: Commit**

```bash
git add backend/tests/Functional/Api/SyncCascadeTest.php backend/tests/Unit/SyncWriteLimiterTest.php deploy/README.md .claude/lessons.md
git commit -m "Sichere SyncBlob-Kaskade ab und löse Prune-Vorbehalt auf"
```

---

## Self-Review

**1. Spec-Abdeckung:** §4 Datenmodell → Task 1. §5 Endpunkte inkl. aller Validierungen (400/404/409/413/401) und Atomarität → Task 2+3. §8 Sicherheit (`sync_write` alle drei Blöcke, 256-KB-Limit, Whitelist) → Task 2. §9 Tests → Task 2/3/4; Kaskaden-Regression (wichtigster Test) → Task 4. §6/§7 (Krypto-/Merge-Vertrag) sind reine Extension-Verträge ohne Backend-Code – korrekt ohne Task. §2 Nicht-Ziele haben keine Tasks – korrekt.
**2. Placeholder-Scan:** keine TBD/TODO. Zwei bewusste Nachschlag-Verweise mit konkreter Fundstelle (LockMode-Import → `VersionApproveController`; CommandTester-Bootstrap → `AccountPruneCommandTest`) – Code ist jeweils vollständig ausgeschrieben.
**3. Typkonsistenz:** `SyncBlob::COLLECTIONS`/`MAX_CIPHERTEXT_BYTES` (Task 1) in Task 2/3 identisch; `putBlob()`-Helper konsistent zwischen Task 2 und 3; `requireAccount(Request)`-Signatur wie in A gemergt; 409-Body `['version' => …]` konsistent zwischen Controller (Task 2) und Test; `(object)`-Cast der leeren Übersicht konsistent mit `(array)`-Assert im Test.
