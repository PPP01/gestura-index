# Phase-3 Edit-Token-Migration (C) – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Edit-Tokens (`gsti_`) lassen sich per Claim ins End-Nutzer-Konto (`gacc_`) überführen; danach kann das Konto verknüpfte Einträge verwalten und neue direkt einreichen – mit aggregiertem Trust (Sofort-Publish ab Schwelle, erstmalige Aktivierung des in Phase 2 vorgesehenen Trust-Pfads, **nur** für Konten) und Ban-Bündel-Wirkung.

**Architecture:** `Submitter` erhält einen nullable `account`-FK (`ON DELETE SET NULL` – Konto-Löschung lässt anonyme Edit-Token-Submitter zurück). Die Edit-Token-Verifikation wird aus `SubmitterResolver::resolve()` in eine wiederverwendbare Methode extrahiert (Claim prüft das Token aus dem Body durch dieselbe Schutzkette). `requireOwner()` bekommt eine Präfix-Weiche (`gacc_` → Konto-Eigentum), der Submit-Controller einen Konto-Pfad (ältesten aktiven Submitter reusen / lazy anlegen) plus Trust-Auto-Publish.

**Tech Stack:** PHP 8.5, Symfony 7.4 LTS, Doctrine ORM/MariaDB, PHPUnit.

## Global Constraints

- **Claim:** `POST /api/account/claims`, `Authorization: Bearer gacc_…` + Body `{"editToken": "gsti_…"}`. Antworten: `200 {"entries": n}` (auch idempotent bei erneutem Claim durch dasselbe Konto); `409 'Submitter already claimed by another account'`; `403 'Submitter is banned'`; `401` ungültiges Token; `400` fehlender/ungültiger Body. Edit-Token bleibt gültig.
- **Edit-Token-Prüfung im Claim** durchläuft die identische Schutzkette wie `SubmitterResolver::resolve()`: `token_auth_ip`-Limit VOR Argon2id, konstante Zeit via `DUMMY_HASH`, `token_auth`-Limit pro IP+Selector. **Keine neuen Limiter.**
- **Trust:** `SubmissionService::TRUST_THRESHOLD = 3`. Konto-Submit publiziert sofort, wenn Summe der `approvedCount` aller **nicht gesperrten** Konto-Submitter ≥ 3 **und** die Version keinen `transformCode` hat (Supply-Chain-Schutz bleibt absolut). Anonyme Einreichungen bleiben IMMER `pending` (Phase-2-Entscheidung, unverändert).
- **Ban-Bündel:** irgendein gesperrter verknüpfter Submitter ⇒ Konto-Submit und konto-basiertes Verwalten `403`.
- **Statusmaschinen-Invariante (lessons.md):** `pending` ⇒ `currentVersion === null`; `published` ⇒ `currentVersion !== null`. Auto-Publish setzt Version-Status, `currentVersion` und Entry-Status zusammen, VOR dem flush.
- **Bezeichner Englisch, Docblocks Deutsch.** PHPUnit `failOnDeprecation="true"` – Exit-Code prüfen (`; echo $?`). Lokales CLI `php`.

---

## Datei-Struktur

- `backend/src/Entity/Submitter.php` (ändern) – nullable `account`-FK.
- `backend/migrations/Version*.php` (neu, generiert).
- `backend/src/Service/EditTokenService.php` (ändern) – `parseToken()` extrahiert (Header-Variante delegiert).
- `backend/src/Service/SubmitterResolver.php` (ändern) – `resolveFromEditToken()` extrahiert; `requireOwner()` mit `gacc_`-Weiche; neuer Konstruktor-Dep `AccountResolver`.
- `backend/src/Repository/SubmitterRepository.php` (ändern) – `oldestActiveForAccount()`, `sumApprovedCountForAccount()`, `hasBannedForAccount()`.
- `backend/src/Controller/Api/AccountClaimController.php` (neu).
- `backend/src/Controller/Api/EntrySubmitController.php` (ändern) – Konto-Pfad + Trust-Auto-Publish.
- `backend/src/Service/SubmissionService.php` (ändern) – nur Konstante `TRUST_THRESHOLD`.
- Tests: `backend/tests/Functional/Api/AccountClaimTest.php`, `backend/tests/Functional/Api/AccountOwnershipTest.php`, `backend/tests/Functional/Api/AccountSubmitTest.php`, `backend/tests/Functional/Api/AccountSubmitterCascadeTest.php`.

Wiederverwendet: `AccountResolver`, `AccountTokenService`, `RateLimitGuard`, `ApiProblem`, `EditTokenService`, `GeneratedToken`.

---

### Task 1: Submitter.account-FK + Migration

**Files:**
- Modify: `backend/src/Entity/Submitter.php`
- Create: `backend/migrations/Version*.php` (generiert)
- Test: `backend/tests/Functional/Api/SubmitterAccountPersistenceTest.php`

**Interfaces:**
- Produces: `Submitter::$account` (public `?Account`, Default `null`, ManyToOne, `JoinColumn(nullable: true, onDelete: 'SET NULL')`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Submitter;
use App\Tests\Functional\ApiTestCase;

final class SubmitterAccountPersistenceTest extends ApiTestCase
{
    public function testSubmitterCanBeLinkedToAccount(): void
    {
        $account = new Account(bin2hex(random_bytes(8)), 'hash');
        $this->em->persist($account);
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $this->em->flush();
        $submitterId = $submitter->id;
        $accountId = $account->id;
        $this->em->clear();

        $found = $this->em->getRepository(Submitter::class)->find($submitterId);
        self::assertNotNull($found->account);
        self::assertSame($accountId, $found->account->id);
    }
}
```

(`createSubmitterWithToken()` existiert in `ApiTestCase`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php backend/bin/phpunit --filter SubmitterAccountPersistenceTest; echo $?`
Expected: FAIL (Property `account` existiert nicht / Schema-Fehler).

- [ ] **Step 3: Add the field to the entity**

In `backend/src/Entity/Submitter.php` nach dem `banned`-Feld einfügen:

```php
    /**
     * Zugehöriges End-Nutzer-Konto (Phase 3, Edit-Token-Migration) oder null
     * für klassische anonyme Submitter. ON DELETE SET NULL: Konto-Löschung
     * (inkl. index:account:prune-Bulk-DELETE) lässt den Submitter samt seiner
     * Einträge als anonymen Edit-Token-Submitter zurück — nichts geht verloren.
     */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Account $account = null;
```

- [ ] **Step 4: Generate, review and apply the migration**

Run: `php backend/bin/console make:migration`
Verify the generated `up()`: `ALTER TABLE submitter ADD account_id INT DEFAULT NULL` + `FOREIGN KEY … REFERENCES account (id) ON DELETE SET NULL` + Index. **`SET NULL` ist der kritische Teil** – fehlt es, Entity-Attribut prüfen und neu generieren.
Run: `php backend/bin/console doctrine:migrations:migrate -n`
Run: `php backend/bin/console doctrine:migrations:migrate -n --env=test`

- [ ] **Step 5: Run test to verify it passes**

Run: `php backend/bin/phpunit --filter SubmitterAccountPersistenceTest; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Entity/Submitter.php backend/migrations/ backend/tests/Functional/Api/SubmitterAccountPersistenceTest.php
git commit -m "Verknüpfe Submitter optional mit End-Nutzer-Konto"
```

---

### Task 2: Token-Verifikation extrahieren + Claim-Endpunkt

**Files:**
- Modify: `backend/src/Service/EditTokenService.php`, `backend/src/Service/SubmitterResolver.php`
- Create: `backend/src/Controller/Api/AccountClaimController.php`
- Test: `backend/tests/Functional/Api/AccountClaimTest.php`

**Interfaces:**
- Consumes: `Submitter::$account` (Task 1), `AccountResolver::requireAccount(Request): Account`, `EntryRepository` (`count(['submitter' => $s])`).
- Produces: `EditTokenService::parseToken(string $token): ?array{selector: string, verifier: string}`; `SubmitterResolver::resolveFromEditToken(string $editToken, Request $request): Submitter` (wirft 401/429, konstante Zeit); `POST /api/account/claims`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\EntryType;
use App\Service\PayloadAnalyzer;
use App\Tests\Functional\ApiTestCase;

final class AccountClaimTest extends ApiTestCase
{
    /** @return string Klartext-Konto-Token */
    private function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    private function claim(string $accountToken, mixed $editToken): void
    {
        $this->client->request('POST', '/api/account/claims',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['editToken' => $editToken], JSON_THROW_ON_ERROR));
    }

    /** Hängt einen minimalen Entry an den Submitter (für den entries-Zähler). */
    private function attachEntry(Submitter $submitter, string $formatId): void
    {
        $entry = new Entry($formatId, EntryType::Menu, $submitter);
        $payload = ['gesturaMenu' => 1, 'id' => $formatId, 'version' => '1.0.0', 'name' => 'X',
            'items' => [['id' => 'a', 'label' => 'A', 'action' => 'newTab']]];
        $version = new EntryVersion($entry, '1.0.0', $payload, (new PayloadAnalyzer())->contentHash($payload));
        $this->em->persist($entry);
        $this->em->persist($version);
        $this->em->flush();
    }

    public function testClaimLinksSubmitterAndCountsEntries(): void
    {
        $accountToken = $this->createAccount();
        [$submitter, $editToken] = $this->createSubmitterWithToken();
        $this->attachEntry($submitter, 'com.example.claim-a');

        $this->claim($accountToken, $editToken);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['entries']);

        $this->em->refresh($submitter);
        self::assertNotNull($submitter->account);
    }

    public function testClaimIsIdempotentForSameAccount(): void
    {
        $accountToken = $this->createAccount();
        [, $editToken] = $this->createSubmitterWithToken();

        $this->claim($accountToken, $editToken);
        self::assertResponseStatusCodeSame(200);
        $this->claim($accountToken, $editToken);
        self::assertResponseStatusCodeSame(200);
    }

    public function testClaimBySecondAccountIs409(): void
    {
        $first = $this->createAccount();
        $second = $this->createAccount();
        [, $editToken] = $this->createSubmitterWithToken();

        $this->claim($first, $editToken);
        self::assertResponseStatusCodeSame(200);
        $this->claim($second, $editToken);
        self::assertResponseStatusCodeSame(409);
    }

    public function testClaimOfBannedSubmitterIs403(): void
    {
        $accountToken = $this->createAccount();
        [$submitter, $editToken] = $this->createSubmitterWithToken();
        $submitter->banned = true;
        $this->em->flush();

        $this->claim($accountToken, $editToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testClaimWithInvalidEditTokenIs401(): void
    {
        $accountToken = $this->createAccount();
        $this->claim($accountToken, 'gsti_' . str_repeat('a', 16) . '_' . str_repeat('b', 43));
        self::assertResponseStatusCodeSame(401);
    }

    public function testClaimWithMissingEditTokenIs400(): void
    {
        $accountToken = $this->createAccount();
        $this->claim($accountToken, null);
        self::assertResponseStatusCodeSame(400);
    }

    public function testClaimWithoutAccountTokenIs401(): void
    {
        $this->client->request('POST', '/api/account/claims',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['editToken' => 'x'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php backend/bin/phpunit --filter AccountClaimTest; echo $?`
Expected: FAIL (404 – Route existiert nicht).

- [ ] **Step 3: Extract parseToken in EditTokenService**

In `backend/src/Service/EditTokenService.php` ergänzen und `parseAuthorizationHeader()` delegieren lassen:

```php
    /**
     * Zerlegt ein rohes Edit-Token (ohne Bearer-Präfix) in Selector und
     * Verifier. Gibt null zurück, wenn das Format nicht dem Muster entspricht.
     *
     * @return array{selector: string, verifier: string}|null
     */
    public function parseToken(string $token): ?array
    {
        if (!preg_match('/^gsti_([0-9a-f]{16})_([A-Za-z0-9_-]{43})$/', trim($token), $m)) {
            return null;
        }

        return ['selector' => $m[1], 'verifier' => $m[2]];
    }
```

`parseAuthorizationHeader()` wird zu:

```php
    public function parseAuthorizationHeader(?string $header): ?array
    {
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return $this->parseToken(substr($header, 7));
    }
```

- [ ] **Step 4: Extract resolveFromEditToken in SubmitterResolver**

In `backend/src/Service/SubmitterResolver.php`: den Kern von `resolve()` (ab dem Parse) in eine neue öffentliche Methode ziehen; `resolve()` delegiert. Der bestehende Schutz-Kommentarblock wandert mit:

```php
    /**
     * Verifiziert ein rohes Edit-Token (z. B. aus einem Request-Body) über
     * dieselbe Schutzkette wie resolve(): Per-IP-Limit VOR der Argon2id-
     * Verifikation, konstante Zeit via Dummy-Hash, Limit pro IP+Selector.
     * Wirft ApiProblem 401 bei ungültigem Token.
     */
    public function resolveFromEditToken(string $editToken, Request $request): Submitter
    {
        $parsed = $this->tokens->parseToken($editToken)
            ?? throw new ApiProblem(401, 'Invalid token');

        $ip = $request->getClientIp() ?? 'unknown';
        $this->guard->consume($this->tokenAuthIpLimiter, $ip);
        $this->guard->consume($this->tokenAuthLimiter, $ip . '|' . $parsed['selector']);

        $submitter = $this->submitters->findOneBy(['tokenSelector' => $parsed['selector']]);
        $hash = $submitter?->tokenHash ?? self::DUMMY_HASH;
        if (!$this->tokens->verify($parsed['verifier'], $hash) || $submitter === null) {
            throw new ApiProblem(401, 'Invalid token');
        }

        return $submitter;
    }
```

`resolve()` wird zu (Header-Behandlung bleibt, Kern delegiert – die ausführlichen Sicherheits-Kommentare bleiben an der jeweils passenden Stelle erhalten):

```php
    public function resolve(Request $request): ?Submitter
    {
        $header = $request->headers->get('Authorization');
        if ($header === null) {
            return null;
        }
        if (!str_starts_with($header, 'Bearer ')) {
            throw new ApiProblem(401, 'Invalid token');
        }

        return $this->resolveFromEditToken(substr($header, 7), $request);
    }
```

- [ ] **Step 5: Write the claim controller**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\AccountResolver;
use App\Service\SubmitterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Überführt einen anonymen Edit-Token-Submitter ins End-Nutzer-Konto
 * (Phase 3, Edit-Token-Migration). Besitzbeweis beider Geheimnisse in einem
 * Request: gacc_-Bearer im Header, gsti_-Edit-Token im Body. Das Edit-Token
 * bleibt danach gültig (beide Wege parallel). Idempotent für dasselbe Konto.
 */
final class AccountClaimController
{
    #[Route('/api/account/claims', methods: ['POST'])]
    public function __invoke(
        Request $request,
        AccountResolver $accounts,
        SubmitterResolver $submitters,
        EntryRepository $entries,
        EntityManagerInterface $em,
    ): JsonResponse {
        $account = $accounts->requireAccount($request);

        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        $editToken = $body['editToken'] ?? null;
        if (!\is_string($editToken) || $editToken === '') {
            throw new ApiProblem(400, 'editToken is required');
        }

        $submitter = $submitters->resolveFromEditToken($editToken, $request);

        if ($submitter->banned) {
            // Ein gesperrter Ruf lässt sich nicht in ein Konto einbringen.
            throw new ApiProblem(403, 'Submitter is banned');
        }
        if ($submitter->account !== null && $submitter->account->id !== $account->id) {
            throw new ApiProblem(409, 'Submitter already claimed by another account');
        }

        $submitter->account = $account;
        $em->flush();

        return new JsonResponse(['entries' => $entries->count(['submitter' => $submitter])]);
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php backend/bin/phpunit --filter AccountClaimTest; echo $?`
Expected: PASS, Exit 0. Zusätzlich Regression der bestehenden Token-Pfade: `php backend/bin/phpunit --filter 'SubmitterResolverTest|SubmitTest|UpdateTest'; echo $?` → Exit 0.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/EditTokenService.php backend/src/Service/SubmitterResolver.php backend/src/Controller/Api/AccountClaimController.php backend/tests/Functional/Api/AccountClaimTest.php
git commit -m "Ergänze Claim-Endpunkt zur Edit-Token-Migration"
```

---

### Task 3: requireOwner mit gacc_-Weiche + Ban-Bündel

**Files:**
- Modify: `backend/src/Service/SubmitterResolver.php`, `backend/src/Repository/SubmitterRepository.php`
- Test: `backend/tests/Functional/Api/AccountOwnershipTest.php`

**Interfaces:**
- Consumes: `AccountResolver` (neuer Konstruktor-Dep von `SubmitterResolver` – Symfony autowired das ohne Config), `Submitter::$account`.
- Produces: `SubmitterRepository::hasBannedForAccount(Account $account): bool`; `requireOwner()` akzeptiert `gacc_`-Header (Konto-Eigentum) zusätzlich zum bestehenden `gsti_`-Pfad. Task 4 verlässt sich auf `hasBannedForAccount()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Submitter;
use App\Service\EditTokenService;
use App\Tests\Functional\ApiTestCase;

final class AccountOwnershipTest extends ApiTestCase
{
    /** @return string Klartext-Konto-Token */
    private function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    private function linkSubmitterToAccountToken(Submitter $submitter, string $accountToken): Account
    {
        // Konto über den Selector aus dem Token laden und verknüpfen:
        [, $selector] = explode('_', $accountToken, 3);
        $account = $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => $selector]);
        self::assertNotNull($account);
        $submitter->account = $account;
        $this->em->flush();

        return $account;
    }

    public function testAccountCanDeleteLinkedEntry(): void
    {
        $accountToken = $this->createAccount();
        [$entry, ] = $this->createPublishedEntry();
        $this->linkSubmitterToAccountToken($entry->submitter, $accountToken);

        $this->client->request('DELETE', '/api/v1/entries/' . $entry->formatId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testAccountCannotManageForeignEntry(): void
    {
        $accountToken = $this->createAccount();
        [$entry, ] = $this->createPublishedEntry(); // Submitter NICHT verknüpft

        $this->client->request('DELETE', '/api/v1/entries/' . $entry->formatId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBannedBundleBlocksAccountManagement(): void
    {
        $accountToken = $this->createAccount();
        [$entry, ] = $this->createPublishedEntry();
        $account = $this->linkSubmitterToAccountToken($entry->submitter, $accountToken);

        // Zweiter, gesperrter Submitter im selben Konto-Bündel:
        $generated = (new EditTokenService())->generate();
        $banned = new Submitter($generated->selector, $generated->hash);
        $banned->banned = true;
        $banned->account = $account;
        $this->em->persist($banned);
        $this->em->flush();

        $this->client->request('DELETE', '/api/v1/entries/' . $entry->formatId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testClassicEditTokenPathStillWorks(): void
    {
        [$entry, $editToken] = $this->createPublishedEntry();

        $this->client->request('DELETE', '/api/v1/entries/' . $entry->formatId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $editToken]);
        self::assertResponseStatusCodeSame(204);
    }
}
```

Hinweis: `createPublishedEntry()` existiert in `ApiTestCase` (liefert `[Entry, editToken]` – Signatur dort prüfen und ggf. die Destrukturierung anpassen; falls sie kein Token liefert, `createSubmitterWithToken()` + veröffentlichten Entry manuell bauen, Muster siehe `ApiTestCase::createPublishedEntry`-Implementierung).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php backend/bin/phpunit --filter AccountOwnershipTest; echo $?`
Expected: FAIL (gacc_-Header → 401 'Invalid token', da `parseToken` das gsti_-Muster verlangt).

- [ ] **Step 3: Add the repository helper**

In `backend/src/Repository/SubmitterRepository.php`:

```php
    /**
     * True, wenn irgendein mit dem Konto verknüpfter Submitter gesperrt ist.
     * Ban-Bündel-Wirkung: wer Trust über das Bündel aggregiert, aggregiert
     * auch Sperren — eine Sperre gegen ein Token wirkt gegen das ganze Konto.
     */
    public function hasBannedForAccount(Account $account): bool
    {
        return (bool) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.account = :account')->andWhere('s.banned = true')
            ->setParameter('account', $account)
            ->getQuery()->getSingleScalarResult();
    }
```

(Import `App\Entity\Account` ergänzen.)

- [ ] **Step 4: Add the gacc_ branch to requireOwner**

`SubmitterResolver`: Konstruktor um `private readonly AccountResolver $accountResolver` und `requireOwner()` um die Weiche erweitern:

```php
    public function requireOwner(Request $request, Entry $entry): Submitter
    {
        // gacc_-Weiche: Konto-Token statt Edit-Token — Eigentum besteht, wenn
        // der Submitter des Entrys mit genau diesem Konto verknüpft ist.
        // Ban-Bündel: ein gesperrter verknüpfter Submitter blockiert das
        // gesamte konto-basierte Verwalten.
        $header = $request->headers->get('Authorization') ?? '';
        if (str_starts_with($header, 'Bearer gacc_')) {
            $account = $this->accountResolver->requireAccount($request);
            if ($this->submitters->hasBannedForAccount($account)) {
                throw new ApiProblem(403, 'Account is banned');
            }
            if ($entry->submitter->account?->id !== $account->id) {
                throw new ApiProblem(403, 'Not the owner of this entry');
            }

            return $entry->submitter;
        }

        $submitter = $this->resolve($request) ?? throw new ApiProblem(401, 'Token required');
        if ($submitter->banned) {
            throw new ApiProblem(403, 'Submitter is banned');
        }
        if ($entry->submitter->id !== $submitter->id) {
            throw new ApiProblem(403, 'Not the owner of this entry');
        }

        return $submitter;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php backend/bin/phpunit --filter AccountOwnershipTest; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/SubmitterResolver.php backend/src/Repository/SubmitterRepository.php backend/tests/Functional/Api/AccountOwnershipTest.php
git commit -m "Erlaube Entry-Verwaltung per Konto-Token"
```

---

### Task 4: Konto-Submit + Trust-Aggregation (Auto-Publish)

**Files:**
- Modify: `backend/src/Controller/Api/EntrySubmitController.php`, `backend/src/Service/SubmissionService.php`, `backend/src/Repository/SubmitterRepository.php`
- Test: `backend/tests/Functional/Api/AccountSubmitTest.php`

**Interfaces:**
- Consumes: `hasBannedForAccount()` (Task 3), `AccountResolver`, `AccountTokenService`-Analogon NICHT nötig (Edit-Token via bestehendem `EditTokenService::generate()`).
- Produces: `SubmitterRepository::oldestActiveForAccount(Account): ?Submitter`, `SubmitterRepository::sumApprovedCountForAccount(Account): int`, `SubmissionService::TRUST_THRESHOLD = 3`; Submit akzeptiert `gacc_`-Header.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Submitter;
use App\Service\EditTokenService;
use App\Tests\Functional\ApiTestCase;

final class AccountSubmitTest extends ApiTestCase
{
    /** @return array{string, Account} Klartext-Token + Account-Entity */
    private function createAccountWithEntity(): array
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);
        $token = $this->json()['token'];
        [, $selector] = explode('_', $token, 3);
        $account = $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => $selector]);

        return [$token, $account];
    }

    private function submitAs(string $accountToken, string $formatId): void
    {
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(['id' => $formatId]),
            'categories' => ['shopping', 'other'],
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken]);
    }

    private function linkedSubmitter(Account $account, int $approvedCount = 0, bool $banned = false): Submitter
    {
        $generated = (new EditTokenService())->generate();
        $submitter = new Submitter($generated->selector, $generated->hash);
        $submitter->account = $account;
        $submitter->approvedCount = $approvedCount;
        $submitter->banned = $banned;
        $this->em->persist($submitter);
        $this->em->flush();

        return $submitter;
    }

    public function testFirstAccountSubmitCreatesLinkedSubmitterWithFallbackToken(): void
    {
        [$token, $account] = $this->createAccountWithEntity();

        $this->submitAs($token, 'com.example.acc-first');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $this->json()['status']);
        self::assertArrayHasKey('editToken', $this->json());

        $submitter = $this->em->getRepository(Submitter::class)->findOneBy(['account' => $account]);
        self::assertNotNull($submitter);
    }

    public function testSecondAccountSubmitReusesSubmitterWithoutNewToken(): void
    {
        [$token, $account] = $this->createAccountWithEntity();
        $this->submitAs($token, 'com.example.acc-one');
        self::assertResponseStatusCodeSame(201);

        $this->submitAs($token, 'com.example.acc-two');
        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('editToken', $this->json());
        self::assertSame(1, $this->em->getRepository(Submitter::class)->count(['account' => $account]));
    }

    public function testMigratedTrustPublishesImmediately(): void
    {
        [$token, $account] = $this->createAccountWithEntity();
        $this->linkedSubmitter($account, approvedCount: 3);

        $this->submitAs($token, 'com.example.acc-trusted');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('published', $this->json()['status']);
        self::assertArrayNotHasKey('editToken', $this->json());
    }

    public function testBelowThresholdStaysPending(): void
    {
        [$token, $account] = $this->createAccountWithEntity();
        $this->linkedSubmitter($account, approvedCount: 2);

        $this->submitAs($token, 'com.example.acc-low');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $this->json()['status']);
    }

    public function testTransformCodeAlwaysQueuedDespiteTrust(): void
    {
        [$token, $account] = $this->createAccountWithEntity();
        $this->linkedSubmitter($account, approvedCount: 5);

        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->enginePayload([
                'id' => 'com.example.acc-transform',
                'transformCode' => 'return r;',
            ]),
            'categories' => ['search'],
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $this->json()['status']);
    }

    public function testBannedBundleBlocksAccountSubmit(): void
    {
        [$token, $account] = $this->createAccountWithEntity();
        $this->linkedSubmitter($account, approvedCount: 5, banned: true);

        $this->submitAs($token, 'com.example.acc-banned');
        self::assertResponseStatusCodeSame(403);
    }
}
```

Hinweis: Signatur von `ApiTestCase::api()` prüfen – falls sie keinen Server-/Header-Parameter hat, die Requests wie in `SyncTest` direkt über `$this->client->request(...)` mit `server:`-Array bauen (dann `CONTENT_TYPE: application/json` mitgeben und den Body selbst `json_encode`n). Der Trust-Schwellwert 3 kommt aus `SubmissionService::TRUST_THRESHOLD`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php backend/bin/phpunit --filter AccountSubmitTest; echo $?`
Expected: FAIL (gacc_-Header → 401 im bestehenden `resolve()`).

- [ ] **Step 3: Add constant and repository helpers**

`backend/src/Service/SubmissionService.php` (bei den anderen Konstanten):

```php
    /**
     * Ab dieser Summe freigegebener Einreichungen (über alle nicht gesperrten
     * Submitter eines Kontos) publizieren Konto-Einreichungen sofort. Gilt NUR
     * für Konten — anonyme Einreichungen bleiben immer in der Warteschlange
     * (Phase-2-Entscheidung: geleaktes Token darf kein Spam-Freifahrtschein
     * sein). transformCode geht unabhängig davon IMMER in die Warteschlange.
     */
    public const TRUST_THRESHOLD = 3;
```

`backend/src/Repository/SubmitterRepository.php`:

```php
    /**
     * Ältester nicht gesperrter Submitter eines Kontos — deterministische Wahl
     * für Konto-Einreichungen (kein Marker-Feld nötig).
     */
    public function oldestActiveForAccount(Account $account): ?Submitter
    {
        return $this->createQueryBuilder('s')
            ->where('s.account = :account')->andWhere('s.banned = false')
            ->setParameter('account', $account)
            ->orderBy('s.createdAt', 'ASC')->addOrderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Summe der approvedCounts aller nicht gesperrten Submitter eines Kontos —
     * Grundlage der Trust-Aggregation (migrierter Ruf wirkt sofort weiter).
     */
    public function sumApprovedCountForAccount(Account $account): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.approvedCount), 0)')
            ->where('s.account = :account')->andWhere('s.banned = false')
            ->setParameter('account', $account)
            ->getQuery()->getSingleScalarResult();
    }
```

- [ ] **Step 4: Add the account path to EntrySubmitController**

In `backend/src/Controller/Api/EntrySubmitController.php`. Neue Konstruktor-/`__invoke`-Deps: `AccountResolver $accountResolver`, `SubmitterRepository $submitterRepo` (Imports ergänzen). Nach dem `$guard->consume(...)` die Submitter-Auflösung ERSETZEN:

Bisher:

```php
        $submitter = $resolver->resolve($request);
        if ($submitter?->banned === true) {
            throw new ApiProblem(403, 'Submitter is banned');
        }
```

Neu:

```php
        // gacc_-Weiche: Konto-Einreichung nutzt deterministisch den ältesten
        // nicht gesperrten verknüpften Submitter; nur ohne Bündel entsteht ein
        // neuer (Edit-Token als Rückfall-Credential einmalig in der Antwort).
        $account = null;
        $header = $request->headers->get('Authorization') ?? '';
        if (str_starts_with($header, 'Bearer gacc_')) {
            $account = $accountResolver->requireAccount($request);
            if ($submitterRepo->hasBannedForAccount($account)) {
                throw new ApiProblem(403, 'Account is banned');
            }
            $submitter = $submitterRepo->oldestActiveForAccount($account);
        } else {
            $submitter = $resolver->resolve($request);
            if ($submitter?->banned === true) {
                throw new ApiProblem(403, 'Submitter is banned');
            }
        }
```

Der bestehende Block `if ($submitter === null) { … new Submitter … }` bleibt – ergänzt um die Konto-Verknüpfung:

```php
        $freshToken = null;
        if ($submitter === null) {
            $generated = $tokens->generate();
            $freshToken = $generated->token;
            $submitter = new Submitter($generated->selector, $generated->hash);
            $submitter->account = $account; // null bei anonymer Einreichung
            $em->persist($submitter);
        }
```

Nach dem Erzeugen von `$version` (vor `$em->persist($entry)`) der Trust-Auto-Publish:

```php
        // Trust-Pfad (erstmals aktiv, NUR für Konten): ab TRUST_THRESHOLD
        // aggregierter Freigaben publiziert die Einreichung sofort — außer bei
        // transformCode (Supply-Chain-Schutz ist absolut). Version-Status,
        // currentVersion und Entry-Status werden zusammen gesetzt, damit die
        // Statusmaschinen-Invariante (published ⇒ currentVersion) hält.
        if ($account !== null
            && !$version->hasTransformCode
            && $submitterRepo->sumApprovedCountForAccount($account) >= SubmissionService::TRUST_THRESHOLD
        ) {
            $version->status = VersionStatus::Approved;
            $entry->currentVersion = $version;
            $entry->status = EntryStatus::Published;
        }
```

(`VersionStatus` importieren, falls noch nicht vorhanden; `EntryStatus` ist importiert.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `php backend/bin/phpunit --filter AccountSubmitTest; echo $?`
Expected: PASS, Exit 0. Regression: `php backend/bin/phpunit --filter 'SubmitTest|SubmissionTokenGuardTest'; echo $?` → Exit 0.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controller/Api/EntrySubmitController.php backend/src/Service/SubmissionService.php backend/src/Repository/SubmitterRepository.php backend/tests/Functional/Api/AccountSubmitTest.php
git commit -m "Aktiviere Konto-Einreichung mit Trust-Aggregation"
```

---

### Task 5: Kaskaden-Regression + volle Suite

**Files:**
- Create: `backend/tests/Functional/Api/AccountSubmitterCascadeTest.php`
- Modify: `deploy/README.md` (Phase-3-Sektion um einen C-Absatz ergänzen)

**Interfaces:**
- Consumes: alles aus Task 1–4; Command `index:account:prune`; CommandTester-Idiom aus `backend/tests/Functional/Api/SyncCascadeTest.php`.

- [ ] **Step 1: Write the failing cascade tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Submitter;
use App\Tests\Functional\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regressionsnetz für die SET-NULL-Kaskade der Edit-Token-Migration: sowohl
 * Konto-Löschen (ORM remove) als auch index:account:prune (DQL-Bulk-DELETE)
 * müssen verknüpfte Submitter als anonyme Edit-Token-Submitter zurücklassen —
 * Einträge bleiben erhalten und über das Edit-Token verwaltbar.
 */
final class AccountSubmitterCascadeTest extends ApiTestCase
{
    public function testAccountDeleteSetsSubmitterAccountNullAndEditTokenStillWorks(): void
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);
        $accountToken = $this->json()['token'];
        [, $selector] = explode('_', $accountToken, 3);
        $account = $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => $selector]);

        [$entry, $editToken] = $this->createPublishedEntry();
        $entry->submitter->account = $account;
        $this->em->flush();
        $submitterId = $entry->submitter->id;
        $formatId = $entry->formatId;

        $this->client->request('DELETE', '/api/account',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken]);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $submitter = $this->em->getRepository(Submitter::class)->find($submitterId);
        self::assertNotNull($submitter);
        self::assertNull($submitter->account);

        // Der klassische Edit-Token-Weg funktioniert weiter:
        $this->client->request('DELETE', '/api/v1/entries/' . $formatId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $editToken]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testPruneSetsSubmitterAccountNull(): void
    {
        $account = new Account(bin2hex(random_bytes(8)), 'hash');
        $account->lastSeenAt = new \DateTimeImmutable('-400 days');
        $this->em->persist($account);
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $this->em->flush();
        $submitterId = $submitter->id;
        $this->em->clear();

        $command = (new Application(self::$kernel))->find('index:account:prune');
        $tester = new CommandTester($command);
        $tester->execute(['days' => '365']);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Account::class)->count([]));
        $submitter = $this->em->getRepository(Submitter::class)->find($submitterId);
        self::assertNotNull($submitter);
        self::assertNull($submitter->account);
    }
}
```

Hinweis: `createPublishedEntry()`-Rückgabe in `ApiTestCase` prüfen (Entry + Token) und die Destrukturierung ggf. anpassen — dieselbe Prüfung wie in Task 3.

- [ ] **Step 2: Run tests**

Run: `php backend/bin/phpunit --filter AccountSubmitterCascadeTest; echo $?`
Expected: PASS, Exit 0 (die Kaskade kommt aus der Task-1-Migration; schlägt der Test fehl, fehlt `ON DELETE SET NULL` – zurück zu Task 1 Step 4).

- [ ] **Step 3: Extend the deploy note**

In `deploy/README.md`, Abschnitt »Phase 3: End-Nutzer-Konten«, nach dem ✅-Absatz ergänzen:

```markdown
**Edit-Token-Migration (Sub-Projekt C):** `submitter.account_id` trägt `ON DELETE SET NULL` – Konto-Löschung/-Prune lässt Einreichungen als anonyme Edit-Token-Submitter zurück (Regressionstest `AccountSubmitterCascadeTest`). Der Trust-Pfad (Sofort-Publish ab `TRUST_THRESHOLD = 3` aggregierten Freigaben) ist damit erstmals aktiv – ausschließlich für Konto-Einreichungen.
```

- [ ] **Step 4: Run the FULL backend suite (final gate)**

Run: `php backend/bin/phpunit; echo $?`
Expected: OK, Exit **0**.

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Functional/Api/AccountSubmitterCascadeTest.php deploy/README.md
git commit -m "Sichere SET-NULL-Kaskade der Token-Migration ab"
```

---

## Self-Review

**1. Spec-Abdeckung:** §3 Datenmodell → Task 1. §4 Claim (alle Statuscodes, Schutzkette, Idempotenz, Token bleibt gültig) → Task 2. §5 Verwalten per Konto → Task 3. §6 Konto-Submit + deterministische Submitter-Wahl + Trust-Aggregation + transformCode-Absolutheit → Task 4. §7 Ban-Bündel → Task 3 (Verwalten) + Task 4 (Submit). §9 Tests → Tasks 2–5 inkl. Kaskaden-Regression (Task 5). §8 Extension-Vertrag → dokumentiert, kein Code. §2 Nicht-Ziele ohne Tasks – korrekt.
**2. Placeholder-Scan:** keine TBD/TODO. Zwei bewusste Prüf-Verweise mit Fundstelle (`createPublishedEntry()`-Signatur; `api()`-Helper-Signatur) – der neue Code ist vollständig.
**3. Typkonsistenz:** `Submitter::$account` (`?Account`) konsistent Task 1→2→3→4→5; `resolveFromEditToken(string, Request): Submitter` konsistent Task 2 (Definition) und Claim-Controller; `hasBannedForAccount`/`oldestActiveForAccount`/`sumApprovedCountForAccount` konsistent Task 3/4; `TRUST_THRESHOLD` einheitlich `SubmissionService::TRUST_THRESHOLD = 3`; 403-Texte `'Account is banned'` (Bündel) vs. `'Submitter is banned'` (klassisch/Claim) konsistent zwischen Code und Tests.
