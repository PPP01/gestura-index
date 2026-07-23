# Phase-3 End-Nutzer-Konto-Fundament – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein anonymes, leichtgewichtiges End-Nutzer-Konto (Bearer-Token) als Backend-Fundament für spätere Phase-3-Features, mit Anlegen/Prüfen/Löschen-Endpunkten, cookieloser Token-Auth, Missbrauchsschutz und Aufräum-Command.

**Architecture:** Neue eigenständige `Account`-Entität (strikt getrennt von `AdminUser`), Token nach bewährtem Selector/Verifier-Muster (`gacc_…`, nur Argon2id-Hash gespeichert). Auth über einen `AccountResolver` (analog `SubmitterResolver`), der direkt in schlanken Controllern aufgerufen wird – keine Firewall, kein Cookie. Endpunkte unter `/api/account/*`.

**Tech Stack:** PHP 8.5, Symfony 7.4 LTS, Doctrine ORM/MariaDB, PHPUnit (Funktions-/Unit-Tests), Symfony RateLimiter.

## Global Constraints

- **Token-Format:** `gacc_<selector16hex>_<verifier43base64url>`; nur `selector` (Klartext, 16 hex) + `password_hash($verifier, PASSWORD_ARGON2ID)` werden persistiert, das Klartext-Token **nie**.
- **Datensparsamkeit:** kein Username, keine E-Mail, keine IP-Persistenz (nur Limiter-Cache); sofortiges Löschen möglich.
- **Trennung:** eigene Entität, keine Verknüpfung zu `AdminUser`, cookieloser Bearer-Realm unter `/api/account/*`.
- **PHP-CLI:** lokal `php`, auf dem Server `php85`. Tests: `php backend/bin/phpunit` – Exit-Code prüfen (`; echo $?`), **nicht** nur den »OK«-Text (Suite läuft mit `failOnDeprecation="true"`).
- **Rate-Limiter:** neue Limiter in ALLEN drei Blöcken von `config/packages/rate_limiter.yaml` ergänzen (`framework`, `when@test`, `when@dev`). Limiter, die selbst eine Sicherheitskontrolle sind (`account_auth_ip`), behalten im `when@test`-Block ihr **echtes** Limit; reine Komfort-Limiter (`account_create`) werden dort großzügig (1000). `RateLimiterFactoryInterface` typehinten (Parametername = camelCase des Limiter-Namens + `Limiter`).
- **Tests:** `ApiTestCase::setUp()` leert den Limiter-Cache bereits; für isolierte Limiter-Tests `InMemoryStorage` verwenden.

---

## Datei-Struktur (was entsteht/geändert wird)

- `backend/src/Entity/Account.php` (neu) – Entität, eine Verantwortung: Konto-Identität.
- `backend/src/Repository/AccountRepository.php` (neu) – Lookup.
- `backend/migrations/VersionYYYYMMDDHHMMSS.php` (neu, generiert) – `account`-Tabelle.
- `backend/src/Service/AccountTokenService.php` (neu) – Token erzeugen/parsen/prüfen (spiegelt `EditTokenService`).
- `backend/src/Service/AccountResolver.php` (neu) – Bearer-Auth-Auflösung (spiegelt `SubmitterResolver`).
- `backend/src/Controller/Api/AccountCreateController.php`, `AccountMeController.php`, `AccountDeleteController.php` (neu) – je ein Endpunkt.
- `backend/config/packages/rate_limiter.yaml` (ändern) – `account_create`, `account_auth_ip`.
- `backend/src/Service/SubmissionService.php` (ändern) – `gacc_`-Guard in `validatePayload`.
- `backend/src/Command/AccountPruneCommand.php` (neu) – Aufräumen inaktiver Konten.
- Tests: `tests/Unit/AccountTokenServiceTest.php`, `tests/Functional/Api/AccountResolverTest.php`, `tests/Functional/Api/AccountTest.php`, `tests/Unit/AccountCreateLimiterTest.php`, `tests/Functional/Api/SubmissionTokenGuardTest.php`, `tests/Functional/Api/AccountPruneCommandTest.php`.

Wiederverwendet (nicht ändern): `App\Service\GeneratedToken` (Wertobjekt {token, selector, hash}), `App\Service\RateLimitGuard`, `App\Exception\ApiProblem`.

---

### Task 1: AccountTokenService

**Files:**
- Create: `backend/src/Service/AccountTokenService.php`
- Test: `backend/tests/Unit/AccountTokenServiceTest.php`

**Interfaces:**
- Consumes: `App\Service\GeneratedToken` (Konstruktor: `token`, `selector`, `hash`).
- Produces: `generate(): GeneratedToken`; `parseAuthorizationHeader(?string $header): ?array{selector:string,verifier:string}`; `verify(string $verifier, string $hash): bool`. Token-Präfix `gacc_`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit;

use App\Service\AccountTokenService;
use PHPUnit\Framework\TestCase;

final class AccountTokenServiceTest extends TestCase
{
    public function testGenerateProducesWellFormedTokenAndMatchingHash(): void
    {
        $gen = (new AccountTokenService())->generate();
        self::assertMatchesRegularExpression('/^gacc_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $gen->token);
        self::assertSame(16, strlen($gen->selector));
        $verifier = substr($gen->token, strrpos($gen->token, '_') + 1);
        self::assertTrue(password_verify($verifier, $gen->hash));
    }

    public function testParseValidHeaderReturnsSelectorAndVerifier(): void
    {
        $svc = new AccountTokenService();
        $gen = $svc->generate();
        $parsed = $svc->parseAuthorizationHeader('Bearer ' . $gen->token);
        self::assertNotNull($parsed);
        self::assertSame($gen->selector, $parsed['selector']);
    }

    public function testParseRejectsMissingBearerForeignPrefixAndGarbage(): void
    {
        $svc = new AccountTokenService();
        self::assertNull($svc->parseAuthorizationHeader(null));
        self::assertNull($svc->parseAuthorizationHeader('Bearer nonsense'));
        // Edit-Token-Präfix (gsti_) darf NICHT als Konto-Token durchgehen:
        self::assertNull($svc->parseAuthorizationHeader('Bearer gsti_' . str_repeat('a', 16) . '_' . str_repeat('b', 43)));
    }

    public function testVerifyRejectsWrongVerifier(): void
    {
        $svc = new AccountTokenService();
        $gen = $svc->generate();
        self::assertFalse($svc->verify('wrong-verifier', $gen->hash));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php backend/bin/phpunit --filter AccountTokenServiceTest; echo $?`
Expected: FAIL (Class `App\Service\AccountTokenService` not found).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Erzeugt und prüft anonyme End-Nutzer-Konto-Tokens (Bearer). Spiegelt
 * EditTokenService, aber mit eigenem Präfix »gacc_«. Das Klartext-Token wird
 * dem Client einmalig ausgehändigt und nie gespeichert; persistiert werden nur
 * Selector und Argon2id-Hash des Verifiers.
 */
final class AccountTokenService
{
    /** Token-Format: »gacc_<selector16hex>_<verifier43base64url>«. */
    public function generate(): GeneratedToken
    {
        $selector = bin2hex(random_bytes(8));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new GeneratedToken(
            token: sprintf('gacc_%s_%s', $selector, $verifier),
            selector: $selector,
            hash: password_hash($verifier, PASSWORD_ARGON2ID),
        );
    }

    /** @return array{selector: string, verifier: string}|null */
    public function parseAuthorizationHeader(?string $header): ?array
    {
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }
        if (!preg_match('/^gacc_([0-9a-f]{16})_([A-Za-z0-9_-]{43})$/', trim(substr($header, 7)), $m)) {
            return null;
        }

        return ['selector' => $m[1], 'verifier' => $m[2]];
    }

    public function verify(string $verifier, string $hash): bool
    {
        return password_verify($verifier, $hash);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php backend/bin/phpunit --filter AccountTokenServiceTest; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/AccountTokenService.php backend/tests/Unit/AccountTokenServiceTest.php
git commit -m "Ergänze AccountTokenService (gacc_-Konto-Token)"
```

---

### Task 2: Account-Entität, Repository & Migration

**Files:**
- Create: `backend/src/Entity/Account.php`
- Create: `backend/src/Repository/AccountRepository.php`
- Create: `backend/migrations/Version*.php` (generiert)
- Test: `backend/tests/Functional/Api/AccountResolverTest.php` (nur Persistenz-Teil in diesem Task; Resolver folgt in Task 3 – hier zunächst ein reiner Persistenz-Test in derselben Datei ODER inline; siehe unten)

**Interfaces:**
- Produces: `App\Entity\Account` mit public `?int $id`, `string $tokenSelector`, `string $tokenHash`, `\DateTimeImmutable $createdAt`, `\DateTimeImmutable $lastSeenAt`; Konstruktor `__construct(string $tokenSelector, string $tokenHash)` (setzt `createdAt`/`lastSeenAt` = jetzt). `App\Repository\AccountRepository` (Standard `ServiceEntityRepository<Account>`).

- [ ] **Step 1: Write the failing test**

`backend/tests/Functional/Api/AccountPersistenceTest.php`:

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Tests\Functional\ApiTestCase;

final class AccountPersistenceTest extends ApiTestCase
{
    public function testPersistAndFindBySelector(): void
    {
        $account = new Account('0123456789abcdef', 'hash-value');
        $this->em->persist($account);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => '0123456789abcdef']);
        self::assertNotNull($found);
        self::assertSame('hash-value', $found->tokenHash);
        self::assertEquals($found->createdAt, $found->lastSeenAt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php backend/bin/phpunit --filter AccountPersistenceTest; echo $?`
Expected: FAIL (Class `App\Entity\Account` not found).

- [ ] **Step 3: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Anonymes End-Nutzer-Konto (Phase 3), identifiziert über ein geheimes Bearer-
 * Token. Strikt getrennt von AdminUser: keine Rolle, keine E-Mail, kein
 * Username. Das Token selbst wird nie gespeichert; nur tokenSelector (16
 * Zeichen, öffentlich) und tokenHash (Argon2id). lastSeenAt dient allein dem
 * Aufräumen inaktiver Konten.
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'account')]
class Account
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 16, unique: true)]
    public string $tokenSelector;

    #[ORM\Column(length: 255)]
    public string $tokenHash;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $lastSeenAt;

    public function __construct(string $tokenSelector, string $tokenHash)
    {
        $this->tokenSelector = $tokenSelector;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->createdAt;
    }
}
```

- [ ] **Step 4: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
final class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }
}
```

- [ ] **Step 5: Generate and review the migration**

Run: `php backend/bin/console make:migration`
Then open the generated `backend/migrations/Version*.php` and verify the `up()` creates the `account` table with columns `id` (PK, auto-increment), `token_selector VARCHAR(16)` with a **UNIQUE** index, `token_hash VARCHAR(255)`, `created_at`, `last_seen_at` (DATETIME). Remove any unrelated statements if the generator added noise.

- [ ] **Step 6: Apply the migration to dev and test databases**

Run: `php backend/bin/console doctrine:migrations:migrate -n`
Run: `php backend/bin/console doctrine:migrations:migrate -n --env=test`
(If the project prepares the test schema differently, follow that mechanism so the `account` table exists in the test DB.)

- [ ] **Step 7: Run test to verify it passes**

Run: `php backend/bin/phpunit --filter AccountPersistenceTest; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/Account.php backend/src/Repository/AccountRepository.php backend/migrations/ backend/tests/Functional/Api/AccountPersistenceTest.php
git commit -m "Ergänze Account-Entität, Repository und Migration"
```

---

### Task 3: AccountResolver + account_auth_ip-Limiter

**Files:**
- Create: `backend/src/Service/AccountResolver.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Functional/Api/AccountResolverTest.php`

**Interfaces:**
- Consumes: `AccountTokenService`, `AccountRepository`, `RateLimitGuard`, `RateLimiterFactoryInterface $accountAuthIpLimiter`, `EntityManagerInterface`.
- Produces: `resolve(Request $request): ?Account` (null ohne Header; wirft `ApiProblem(401)` bei ungültigem Token; aktualisiert `lastSeenAt`); `requireAccount(Request $request): Account` (wirft `ApiProblem(401)` ohne Token).

- [ ] **Step 1: Add the limiter config**

In `backend/config/packages/rate_limiter.yaml` in **allen drei** Blöcken ergänzen:

`framework > rate_limiter`:
```yaml
        account_auth_ip: { policy: sliding_window, limit: 60, interval: '1 hour' }
```
`when@test > framework > rate_limiter` (echtes Limit – Sicherheitskontrolle):
```yaml
            account_auth_ip: { policy: sliding_window, limit: 60, interval: '1 hour' }
```
`when@dev > framework > rate_limiter`:
```yaml
            account_auth_ip: { policy: sliding_window, limit: 1000, interval: '1 hour' }
```

- [ ] **Step 2: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Exception\ApiProblem;
use App\Service\AccountResolver;
use App\Service\AccountTokenService;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AccountResolverTest extends ApiTestCase
{
    /** @return string Klartext-Token */
    private function seedAccount(): string
    {
        $gen = (new AccountTokenService())->generate();
        $account = new Account($gen->selector, $gen->hash);
        $this->em->persist($account);
        $this->em->flush();

        return $gen->token;
    }

    private function resolver(): AccountResolver
    {
        return static::getContainer()->get(AccountResolver::class);
    }

    private function requestWithToken(?string $token): Request
    {
        $req = Request::create('/api/account/me');
        if ($token !== null) {
            $req->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $req;
    }

    public function testResolvesValidTokenAndUpdatesLastSeen(): void
    {
        $token = $this->seedAccount();
        $account = $this->resolver()->resolve($this->requestWithToken($token));
        self::assertNotNull($account);
        self::assertGreaterThanOrEqual($account->createdAt->getTimestamp(), $account->lastSeenAt->getTimestamp());
    }

    public function testNoHeaderReturnsNull(): void
    {
        self::assertNull($this->resolver()->resolve($this->requestWithToken(null)));
    }

    public function testUnknownSelectorThrows401(): void
    {
        $this->seedAccount();
        $bogus = 'gacc_' . str_repeat('a', 16) . '_' . str_repeat('b', 43);
        $this->expectException(ApiProblem::class);
        $this->expectExceptionCode(401);
        $this->resolver()->resolve($this->requestWithToken($bogus));
    }

    public function testRequireAccountThrowsWithoutToken(): void
    {
        $this->expectException(ApiProblem::class);
        $this->expectExceptionCode(401);
        $this->resolver()->requireAccount($this->requestWithToken(null));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php backend/bin/phpunit --filter AccountResolverTest; echo $?`
Expected: FAIL (Class `App\Service\AccountResolver` not found).

- [ ] **Step 4: Write the resolver**

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Exception\ApiProblem;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Löst die cookielose Bearer-Authentifizierung von End-Nutzer-Konten auf:
 * liest den Authorization-Header, prüft Selector und Verifier gegen den
 * gespeicherten Argon2id-Hash und gibt das zugehörige Account zurück.
 * Timing-Oracle-Angriffe werden durch konstante-Zeit-Verifikation gegen einen
 * Dummy-Hash bei unbekanntem Selector verhindert; ein Per-IP-Limit VOR der
 * teuren Argon2id-Prüfung deckelt CPU-/Memory-DoS.
 */
final class AccountResolver
{
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$QVEua0R0WlVBVUwzbG9UNg$3HdEGtQyGMrgXeKEroenDHXyp6drNFUfnpnvSMZs0YA';

    public function __construct(
        private readonly AccountTokenService $tokens,
        private readonly AccountRepository $accounts,
        private readonly RateLimitGuard $guard,
        private readonly RateLimiterFactoryInterface $accountAuthIpLimiter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resolve(Request $request): ?Account
    {
        $header = $request->headers->get('Authorization');
        if ($header === null) {
            return null;
        }

        $parsed = $this->tokens->parseAuthorizationHeader($header)
            ?? throw new ApiProblem(401, 'Invalid token');

        $this->guard->consume($this->accountAuthIpLimiter, $request->getClientIp() ?? 'unknown');

        // verify() wird IMMER aufgerufen (bei unbekanntem Selector gegen den
        // Dummy-Hash), damit die Antwortzeit nicht verrät, ob ein Selector
        // existiert (Timing-Oracle).
        $account = $this->accounts->findOneBy(['tokenSelector' => $parsed['selector']]);
        $hash = $account?->tokenHash ?? self::DUMMY_HASH;
        if (!$this->tokens->verify($parsed['verifier'], $hash) || $account === null) {
            throw new ApiProblem(401, 'Invalid token');
        }

        $account->lastSeenAt = new \DateTimeImmutable();
        $this->em->flush();

        return $account;
    }

    public function requireAccount(Request $request): Account
    {
        return $this->resolve($request) ?? throw new ApiProblem(401, 'Token required');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php backend/bin/phpunit --filter AccountResolverTest; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/AccountResolver.php backend/config/packages/rate_limiter.yaml backend/tests/Functional/Api/AccountResolverTest.php
git commit -m "Ergänze AccountResolver mit Konto-Bearer-Auth"
```

---

### Task 4: POST /api/account (Konto anlegen) + account_create-Limiter

**Files:**
- Create: `backend/src/Controller/Api/AccountCreateController.php`
- Modify: `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Functional/Api/AccountTest.php`, `backend/tests/Unit/AccountCreateLimiterTest.php`

**Interfaces:**
- Consumes: `AccountTokenService`, `EntityManagerInterface`, `RateLimitGuard`, `RateLimiterFactoryInterface $accountCreateLimiter`.
- Produces: `POST /api/account` → `201 {"token": "gacc_…"}`; legt genau ein `Account` an.

- [ ] **Step 1: Add the limiter config**

In `backend/config/packages/rate_limiter.yaml` in allen drei Blöcken ergänzen:

`framework`:
```yaml
        account_create: { policy: sliding_window, limit: 10, interval: '1 hour' }
```
`when@test` (großzügig – kein Sicherheitskern):
```yaml
            account_create: { policy: sliding_window, limit: 1000, interval: '1 hour' }
```
`when@dev`:
```yaml
            account_create: { policy: sliding_window, limit: 1000, interval: '1 hour' }
```

- [ ] **Step 2: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Tests\Functional\ApiTestCase;

final class AccountTest extends ApiTestCase
{
    /** @return string Klartext-Token */
    protected function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    public function testCreateReturnsTokenAndPersistsAccount(): void
    {
        $token = $this->createAccount();
        self::assertMatchesRegularExpression('/^gacc_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame(1, $this->em->getRepository(Account::class)->count([]));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php backend/bin/phpunit --filter "AccountTest::testCreateReturnsTokenAndPersistsAccount"; echo $?`
Expected: FAIL (404 – Route existiert nicht).

- [ ] **Step 4: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Account;
use App\Service\AccountTokenService;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legt ein anonymes End-Nutzer-Konto an und gibt das Bearer-Token EINMALIG
 * zurück – die einzige Stelle, an der das Klartext-Token je herausgegeben wird.
 * Kein Request-Body, keine Nutzerdaten. Per-IP-Limit gegen Massen-Erstellung.
 */
final class AccountCreateController
{
    #[Route('/api/account', methods: ['POST'])]
    public function __invoke(
        Request $request,
        AccountTokenService $tokens,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $accountCreateLimiter,
    ): JsonResponse {
        $guard->consume($accountCreateLimiter, $request->getClientIp() ?? 'unknown');

        $generated = $tokens->generate();
        $account = new Account($generated->selector, $generated->hash);
        $em->persist($account);
        $em->flush();

        return new JsonResponse(['token' => $generated->token], 201);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php backend/bin/phpunit --filter "AccountTest::testCreateReturnsTokenAndPersistsAccount"; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 6: Write the isolated limiter test**

`backend/tests/Unit/AccountCreateLimiterTest.php`:

```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Service\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AccountCreateLimiterTest extends TestCase
{
    public function testBlocksAfterLimitReached(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'account_create', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $guard = new RateLimitGuard();

        for ($i = 0; $i < 3; ++$i) {
            $guard->consume($factory, '203.0.113.7');
        }

        $this->expectException(ApiProblem::class);
        $this->expectExceptionCode(429);
        $guard->consume($factory, '203.0.113.7');
    }
}
```

- [ ] **Step 7: Run the limiter test**

Run: `php backend/bin/phpunit --filter AccountCreateLimiterTest; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Controller/Api/AccountCreateController.php backend/config/packages/rate_limiter.yaml backend/tests/Functional/Api/AccountTest.php backend/tests/Unit/AccountCreateLimiterTest.php
git commit -m "Ergänze POST /api/account (Konto anlegen)"
```

---

### Task 5: GET /api/account/me und DELETE /api/account

**Files:**
- Create: `backend/src/Controller/Api/AccountMeController.php`, `backend/src/Controller/Api/AccountDeleteController.php`
- Modify: `backend/tests/Functional/Api/AccountTest.php`

**Interfaces:**
- Consumes: `AccountResolver` (aus Task 3), `EntityManagerInterface`.
- Produces: `GET /api/account/me` → `200 {"createdAt": "<ATOM>"}` bei gültigem Token, sonst `401`. `DELETE /api/account` → `204` (Konto entfernt), sonst `401`.

- [ ] **Step 1: Write the failing tests (in AccountTest.php ergänzen)**

```php
    public function testMeWithValidTokenReturnsCreatedAt(): void
    {
        $token = $this->createAccount();
        $this->client->request('GET', '/api/account/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('createdAt', $this->json());
    }

    public function testMeWithoutTokenIs401(): void
    {
        $this->client->request('GET', '/api/account/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMeWithInvalidTokenIs401(): void
    {
        $bogus = 'gacc_' . str_repeat('a', 16) . '_' . str_repeat('b', 43);
        $this->client->request('GET', '/api/account/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $bogus]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testDeleteRemovesAccountAndInvalidatesToken(): void
    {
        $token = $this->createAccount();
        $this->client->request('DELETE', '/api/account', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/account/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php backend/bin/phpunit --filter AccountTest; echo $?`
Expected: FAIL (die neuen Methoden liefern 404, kein 200/204/401).

- [ ] **Step 3: Write AccountMeController**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\AccountResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prüft die Gültigkeit eines Konto-Tokens. Liefert minimal den Erstellzeitpunkt
 * (kein Nutzer-Datum vorhanden) bzw. 401 bei ungültigem/gelöschtem Token –
 * so erkennt die Extension ein nicht mehr gültiges Token.
 */
final class AccountMeController
{
    #[Route('/api/account/me', methods: ['GET'])]
    public function __invoke(Request $request, AccountResolver $resolver): JsonResponse
    {
        $account = $resolver->requireAccount($request);

        return new JsonResponse(['createdAt' => $account->createdAt->format(\DateTimeInterface::ATOM)]);
    }
}
```

- [ ] **Step 4: Write AccountDeleteController**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\AccountResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Löscht das eigene Konto sofort (festgezurrtes Löschrecht). Entfernt die
 * Konto-Zeile; das Token ist danach ungültig. Liefert 204.
 */
final class AccountDeleteController
{
    #[Route('/api/account', methods: ['DELETE'])]
    public function __invoke(Request $request, AccountResolver $resolver, EntityManagerInterface $em): Response
    {
        $account = $resolver->requireAccount($request);
        $em->remove($account);
        $em->flush();

        return new Response('', 204);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php backend/bin/phpunit --filter AccountTest; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controller/Api/AccountMeController.php backend/src/Controller/Api/AccountDeleteController.php backend/tests/Functional/Api/AccountTest.php
git commit -m "Ergänze GET /api/account/me und DELETE /api/account"
```

---

### Task 6: gacc_-Guard bei Einreichungen (Verteidigung in der Tiefe)

**Files:**
- Modify: `backend/src/Service/SubmissionService.php`
- Test: `backend/tests/Functional/Api/SubmissionTokenGuardTest.php`

**Interfaces:**
- Der Guard hängt sich in die bereits gemeinsam von Submit UND Update genutzte Validierung `SubmissionService::validatePayload(...)`. Zuerst die Methode lesen und den Namen des ersten Parameters (der rohe Payload-JSON-String, im Controller als `$meta['payloadJson']` übergeben) bestätigen.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

final class SubmissionTokenGuardTest extends ApiTestCase
{
    public function testSubmissionContainingAccountTokenIsRejected(): void
    {
        // gültiges Menü-Payload, aber ein gacc_-Token versehentlich im Namen:
        $bogusToken = 'gacc_' . str_repeat('a', 16) . '_' . str_repeat('b', 43);
        $payload = $this->menuPayload(['name' => ['en' => 'My ' . $bogusToken . ' menu']]);

        // Request-Körper exakt wie in den bestehenden Submit-Funktionstests aufbauen
        // (siehe tests/Functional/Api/*Submit*/*Submission*-Test für das Body-Format).
        $this->client->request(
            'POST',
            '/api/v1/entries',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['payload' => $payload], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
    }
}
```

Hinweis für den Implementierer: Zuerst den vorhandenen Submit-Funktionstest öffnen und das exakte Body-Format (`payload`-Wrapper, ggf. `categories`) übernehmen; nur das eingeschmuggelte `gacc_`-Token ist neu. Der `menuPayload()`-Helper existiert in `ApiTestCase`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php backend/bin/phpunit --filter SubmissionTokenGuardTest; echo $?`
Expected: FAIL (Einreichung wird akzeptiert bzw. anderer Statuscode statt 400).

- [ ] **Step 3: Add the guard at the top of validatePayload**

Am Anfang von `SubmissionService::validatePayload()` – gegen den rohen JSON-String-Parameter (Name gemäß Signatur, hier beispielhaft `$payloadJson`):

```php
        // Verteidigung in der Tiefe: Ein End-Nutzer-Konto-Token (gacc_-Muster)
        // darf NIE über den öffentlichen Index austreten. Eingereichte Inhalte,
        // die irgendwo ein solches Token enthalten (z. B. versehentlich in
        // name/description/url), werden abgelehnt.
        if (preg_match('/gacc_[0-9a-f]{16}_[A-Za-z0-9_-]{43}/', $payloadJson) === 1) {
            throw new ApiProblem(400, 'Submission must not contain an account token');
        }
```

(`ApiProblem` ist dort bereits importiert.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php backend/bin/phpunit --filter SubmissionTokenGuardTest; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/SubmissionService.php backend/tests/Functional/Api/SubmissionTokenGuardTest.php
git commit -m "Lehne Einreichungen mit Konto-Token ab (gacc_-Guard)"
```

---

### Task 7: index:account:prune – inaktive Konten aufräumen

**Files:**
- Create: `backend/src/Command/AccountPruneCommand.php`
- Test: `backend/tests/Functional/Api/AccountPruneCommandTest.php`

**Interfaces:**
- Consumes: `AccountRepository`, `EntityManagerInterface`.
- Produces: Command `index:account:prune [days=365]` – löscht Konten mit `lastSeenAt < now - days`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Tests\Functional\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AccountPruneCommandTest extends ApiTestCase
{
    public function testPruneRemovesOnlyStaleAccounts(): void
    {
        $fresh = new Account(bin2hex(random_bytes(8)), 'hash');
        $stale = new Account(bin2hex(random_bytes(8)), 'hash');
        $stale->lastSeenAt = new \DateTimeImmutable('-400 days');
        $this->em->persist($fresh);
        $this->em->persist($stale);
        $this->em->flush();
        $freshSelector = $fresh->tokenSelector;

        $command = (new Application(self::$kernel))->find('index:account:prune');
        $tester = new CommandTester($command);
        $tester->execute(['days' => '365']);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $repo = $this->em->getRepository(Account::class);
        self::assertSame(1, $repo->count([]));
        self::assertNotNull($repo->findOneBy(['tokenSelector' => $freshSelector]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php backend/bin/phpunit --filter AccountPruneCommandTest; echo $?`
Expected: FAIL (Command `index:account:prune` nicht gefunden).

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Account;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:account:prune` – löscht anonyme End-Nutzer-Konten,
 * deren letzte Aktivität (lastSeenAt) länger als die angegebene Frist zurückliegt
 * (Default 365 Tage). Datensparsamkeit. Sobald F (Settings-Sync) existiert,
 * müssen zugehörige Sync-Blobs hier mitgelöscht werden (dort umzusetzen).
 */
#[AsCommand(name: 'index:account:prune', description: 'Löscht Konten, die länger als N Tage inaktiv sind')]
final class AccountPruneCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Inaktivitätsschwelle in Tagen', '365');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getArgument('days'));
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));

        /** @var list<Account> $stale */
        $stale = $this->accounts->createQueryBuilder('a')
            ->where('a.lastSeenAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        foreach ($stale as $account) {
            $this->em->remove($account);
        }
        $this->em->flush();

        $io->success(sprintf('%d inaktive Konten gelöscht (älter als %d Tage).', count($stale), $days));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php backend/bin/phpunit --filter AccountPruneCommandTest; echo $?`
Expected: PASS, Exit 0.

- [ ] **Step 5: Run the FULL backend suite (Regression + Deprecations)**

Run: `php backend/bin/phpunit; echo $?`
Expected: OK, Exit **0** (nicht nur »OK«-Text – der Exit-Code muss 0 sein).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Command/AccountPruneCommand.php backend/tests/Functional/Api/AccountPruneCommandTest.php
git commit -m "Ergänze index:account:prune für inaktive Konten"
```

---

## Self-Review

**1. Spec-Abdeckung:**
- §4 Datenmodell → Task 2. §5 Auth-Realm (`AccountTokenService`/`AccountResolver`, `gacc_`, cookielos) → Task 1, 3. §6 Endpunkte (POST/GET me/DELETE) → Task 4, 5. §7 Prune → Task 7. §8 Export-Vertrag → reiner Vertrag, kein Code in diesem Repo (nur §9-Serverabwehr wird umgesetzt). §9 Sicherheit (`account_create`, `account_auth_ip`, Konstante-Zeit, `gacc_`-Guard) → Task 3, 4, 6. §11 Tests → in jeder Task + volle Suite (Task 7, Step 5). **Keine Lücke.**
- §2 Nicht-Ziele (Sync/Merge, Passkey, Rotation, Web-Frontend) sind nicht als Tasks vorhanden – korrekt.

**2. Placeholder-Scan:** Kein »TBD/TODO«. Zwei bewusste Verweise auf bestehende Muster (Test-DB-Schema-Mechanismus in Task 2 Step 6; Submit-Body-Format in Task 6 Step 1) – jeweils mit konkreter Anleitung, wo nachzusehen ist; der eigentliche neue Code ist vollständig.

**3. Typkonsistenz:** `GeneratedToken` {token, selector, hash} durchgängig; Entität-Properties `tokenSelector`/`tokenHash`/`createdAt`/`lastSeenAt` konsistent zwischen Task 2, 3, 4, 7; `AccountResolver::resolve()/requireAccount()` konsistent zwischen Task 3 und 5; Limiter-Parameternamen `$accountAuthIpLimiter` (Task 3) / `$accountCreateLimiter` (Task 4) passend zu den Limiter-IDs `account_auth_ip` / `account_create`.
