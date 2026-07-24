# »Meine Daten« (Selbstauskunft) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Einen lesenden Endpunkt `GET /api/account/data` bauen, der dem authentifizierten End-Nutzer-Konto seine vollständige Selbstauskunft liefert – Konto-Metadaten, Sync-Blobs inkl. Chiffrat und die verknüpften Submitter mit ihren Einträgen als Referenzliste.

**Architecture:** Ein dünner Controller (`AccountDataController`) löst das Konto über den bestehenden `AccountResolver` auf und delegiert an einen testbaren Assembler-Service (`AccountDataAssembler`), der die Antwort aus bestehenden Repositories zusammensetzt. Keine neue Entity, keine Migration, kein neuer Rate-Limiter.

**Tech Stack:** Symfony 7.4, Doctrine ORM/MariaDB, PHPUnit (`failOnDeprecation="true"`).

**Spec:** `docs/superpowers/specs/2026-07-24-phase3-meine-daten-design.md`

## Global Constraints

- **Kein `tokenHash` in der Ausgabe** – weder im `account`- noch in den `submitters`-Blöcken. `tokenSelector` (öffentliche Hälfte) ist erlaubt.
- **Keine Entry-Payloads** – Einträge nur als Referenz (`formatId`, `type`, `status`, `createdAt`, `versions`).
- **Harte Konto-Isolation** – alle Queries filtern auf `account = <aufgelöstes Konto>`; Fremdkonto-Daten dürfen nie erscheinen.
- **Datumsformat** durchgehend `\DateTimeInterface::ATOM` (ISO-8601 mit Offset).
- **Leeres `sync`** serialisiert als `{}` (JSON-Objekt), nicht `[]` – per `(object)`-Cast (F-Muster).
- **Keine neue Migration, keine neue Entity, kein neuer Rate-Limiter** – der Endpunkt ist rein lesend, `account_auth_ip` (im Resolver) deckt den DoS-Schutz ab.
- **`lastSeenAt`-Reihenfolge:** Controller löst mit `touchLastSeen: false` auf, der Assembler liest den vorherigen Wert, **danach** berührt der Controller explizit und flusht.
- Alle Entities haben **public properties** (keine Getter): `Account`, `SyncBlob`, `Submitter`, `Entry`, `EntryVersion`.
- **Unidirektionale Relationen:** `Entry→Submitter` und `EntryVersion→Entry` sind `ManyToOne` **ohne** inverse Collection. Einträge per `EntryRepository::findBy(['submitter' => …])`, Versionen per `EntryVersionRepository::findBy(['entry' => …])` laden – **niemals** `$submitter->entries` o. ä. (existiert nicht).
- **Enums** per `->value` serialisieren: `Entry.type` (`EntryType`), `Entry.status` (`EntryStatus`), `EntryVersion.status` (`VersionStatus`).

---

### Task 1: `AccountDataAssembler`-Service

Baut die Selbstauskunft aus einem `Account` als serialisierbares Array. Reine Datenlogik, ohne HTTP-Schicht – über einen Funktionstest mit echter DB geprüft (die relevante Logik IST das repository-basierte Zusammensetzen inkl. Konto-Isolation).

**Files:**
- Create: `backend/src/Service/AccountDataAssembler.php`
- Test: `backend/tests/Functional/Api/AccountDataAssemblerTest.php`

**Interfaces:**
- Consumes: `SyncBlobRepository`, `SubmitterRepository`, `EntryRepository`, `EntryVersionRepository` (alle vorhanden, autowirebar). Entities `Account`, `SyncBlob`, `Submitter`, `Entry`, `EntryVersion` (public properties).
- Produces: `AccountDataAssembler::assemble(Account $account): array` – von Task 2 (Controller) konsumiert. Rückgabe: `['account' => [...], 'sync' => \stdClass, 'submitters' => list<array>]`.

- [ ] **Step 1: Testklasse mit Seeding-Helfern und dem Leer-Fall schreiben**

Erstelle `backend/tests/Functional/Api/AccountDataAssemblerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Entity\SyncBlob;
use App\Enum\VersionStatus;
use App\Service\AccountDataAssembler;
use App\Service\PayloadAnalyzer;
use App\Tests\Functional\ApiTestCase;

final class AccountDataAssemblerTest extends ApiTestCase
{
    /**
     * Assembler direkt instanziieren (statt aus dem Container), mit den echten
     * Repositories aus dem EntityManager – robust gegen private-Service-Regeln
     * und explizit über die Abhängigkeiten.
     */
    private function assembler(): AccountDataAssembler
    {
        return new AccountDataAssembler(
            $this->em->getRepository(SyncBlob::class),
            $this->em->getRepository(Submitter::class),
            $this->em->getRepository(Entry::class),
            $this->em->getRepository(EntryVersion::class),
        );
    }

    private function makeAccount(string $selector): Account
    {
        $account = new Account($selector, 'hash-' . $selector);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function testEmptyAccountYieldsEmptyStructures(): void
    {
        $account = $this->makeAccount(str_pad('a', 16, 'a'));

        $data = $this->assembler()->assemble($account);

        self::assertArrayHasKey('createdAt', $data['account']);
        self::assertArrayHasKey('lastSeenAt', $data['account']);
        self::assertArrayNotHasKey('tokenHash', $data['account']);
        self::assertArrayNotHasKey('tokenSelector', $data['account']);
        // Leeres sync MUSS als Objekt ({}) serialisieren, nicht als []:
        self::assertInstanceOf(\stdClass::class, $data['sync']);
        self::assertSame([], (array) $data['sync']);
        self::assertSame([], $data['submitters']);
    }
}
```

- [ ] **Step 2: Test ausführen – erwartet Fehler (Klasse fehlt)**

Run: `php backend/bin/phpunit --filter AccountDataAssemblerTest; echo "EXIT:$?"`
Expected: FAIL – `Class "App\Service\AccountDataAssembler" not found`.

- [ ] **Step 3: Assembler implementieren**

Erstelle `backend/src/Service/AccountDataAssembler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\Submitter;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Repository\SubmitterRepository;
use App\Repository\SyncBlobRepository;

/**
 * Baut die vollständige Selbstauskunft eines End-Nutzer-Kontos (»Meine Daten«,
 * Phase 3 E) als serialisierbares Array: Konto-Metadaten, Sync-Blobs inkl.
 * Chiffrat und die verknüpften Submitter mit ihren Einträgen als Referenzliste.
 *
 * Bewusst NICHT enthalten: tokenHash (Konto UND Submitter – abgeleitetes
 * Geheimnis-Material) sowie Entry-Payloads (öffentlicher Index-Inhalt, über die
 * Browse-API abrufbar). Alle Queries filtern hart auf das übergebene Konto
 * (Isolation). Entry→Submitter und EntryVersion→Entry sind unidirektional –
 * die Kinder werden per Repository nachgeladen, nicht über Assoziationen.
 */
final class AccountDataAssembler
{
    public function __construct(
        private readonly SyncBlobRepository $blobs,
        private readonly SubmitterRepository $submitters,
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
    ) {
    }

    /**
     * @return array{account: array<string, string>, sync: \stdClass, submitters: list<array<string, mixed>>}
     */
    public function assemble(Account $account): array
    {
        return [
            'account' => [
                'createdAt' => $account->createdAt->format(\DateTimeInterface::ATOM),
                'lastSeenAt' => $account->lastSeenAt->format(\DateTimeInterface::ATOM),
            ],
            // (object)-Cast: ein leeres Ergebnis serialisiert als {} statt [].
            'sync' => (object) $this->assembleSync($account),
            'submitters' => $this->assembleSubmitters($account),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function assembleSync(Account $account): array
    {
        $out = [];
        foreach ($this->blobs->findBy(['account' => $account]) as $blob) {
            $out[$blob->collection] = [
                'version' => $blob->version,
                'updatedAt' => $blob->updatedAt->format(\DateTimeInterface::ATOM),
                'size' => \strlen($blob->ciphertext),
                'ciphertext' => $blob->ciphertext,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function assembleSubmitters(Account $account): array
    {
        $out = [];
        foreach ($this->submitters->findBy(['account' => $account]) as $submitter) {
            $out[] = [
                'tokenSelector' => $submitter->tokenSelector,
                'approvedCount' => $submitter->approvedCount,
                'banned' => $submitter->banned,
                'createdAt' => $submitter->createdAt->format(\DateTimeInterface::ATOM),
                'entries' => $this->assembleEntries($submitter),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function assembleEntries(Submitter $submitter): array
    {
        $out = [];
        foreach ($this->entries->findBy(['submitter' => $submitter]) as $entry) {
            $out[] = [
                'formatId' => $entry->formatId,
                'type' => $entry->type->value,
                'status' => $entry->status->value,
                'createdAt' => $entry->createdAt->format(\DateTimeInterface::ATOM),
                'versions' => $this->assembleVersions($entry),
            ];
        }

        return $out;
    }

    /** @return list<array{semver: string, status: string}> */
    private function assembleVersions(Entry $entry): array
    {
        $out = [];
        foreach ($this->versions->findBy(['entry' => $entry]) as $version) {
            $out[] = [
                'semver' => $version->semver,
                'status' => $version->status->value,
            ];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Leer-Test ausführen – erwartet grün**

Run: `php backend/bin/phpunit --filter testEmptyAccountYieldsEmptyStructures; echo "EXIT:$?"`
Expected: PASS, `EXIT:0`.

- [ ] **Step 5: Vollprofil- und Isolations-Test ergänzen**

Füge in `AccountDataAssemblerTest` folgende Methoden hinzu (Imports oben sind bereits gesetzt):

```php
    public function testFullProfileContainsAllData(): void
    {
        $account = $this->makeAccount(str_pad('b', 16, 'b'));

        // Zwei Sync-Blobs (verschiedene Collections):
        $this->em->persist(new SyncBlob($account, 'settings', 'cipher-settings'));
        $this->em->persist(new SyncBlob($account, 'menus', 'cipher-menus'));

        // Verknüpfter Submitter mit einem Entry und zwei Versionen:
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $submitter->approvedCount = 3;
        $this->em->flush();

        $entry = $this->createPublishedEntry('com.example.shop', [], $submitter); // legt Version 1.0.0 (approved) an
        $analyzer = new PayloadAnalyzer();
        $payload2 = $this->menuPayload(['id' => 'com.example.shop', 'version' => '1.1.0']);
        $v2 = new EntryVersion($entry, '1.1.0', $payload2, $analyzer->contentHash($payload2));
        $v2->status = VersionStatus::Pending;
        $this->em->persist($v2);
        $this->em->flush();

        $data = $this->assembler()->assemble($account);

        // sync (inkl. Chiffrat):
        $sync = (array) $data['sync'];
        self::assertSame('cipher-settings', $sync['settings']['ciphertext']);
        self::assertSame(1, $sync['settings']['version']);
        self::assertSame(\strlen('cipher-menus'), $sync['menus']['size']);
        self::assertArrayHasKey('updatedAt', $sync['menus']);

        // submitters (ohne tokenHash):
        self::assertCount(1, $data['submitters']);
        $s = $data['submitters'][0];
        self::assertSame($submitter->tokenSelector, $s['tokenSelector']);
        self::assertSame(3, $s['approvedCount']);
        self::assertFalse($s['banned']);
        self::assertArrayNotHasKey('tokenHash', $s);

        // entries + versions (ohne payload):
        self::assertCount(1, $s['entries']);
        $e = $s['entries'][0];
        self::assertSame('com.example.shop', $e['formatId']);
        self::assertSame('menu', $e['type']);
        self::assertSame('published', $e['status']);
        self::assertArrayNotHasKey('payload', $e);
        $semvers = array_column($e['versions'], 'semver');
        self::assertContains('1.0.0', $semvers);
        self::assertContains('1.1.0', $semvers);
    }

    public function testAccountIsolationExcludesForeignData(): void
    {
        $mine = $this->makeAccount(str_pad('c', 16, 'c'));
        $other = $this->makeAccount(str_pad('d', 16, 'd'));

        $this->em->persist(new SyncBlob($other, 'settings', 'foreign-cipher'));
        [$foreignSub] = $this->createSubmitterWithToken();
        $foreignSub->account = $other;
        $this->em->flush();

        $data = $this->assembler()->assemble($mine);

        self::assertSame([], (array) $data['sync']);
        self::assertSame([], $data['submitters']);
    }
```

- [ ] **Step 6: Gesamte Testklasse ausführen – erwartet grün**

Run: `php backend/bin/phpunit --filter AccountDataAssemblerTest; echo "EXIT:$?"`
Expected: PASS (3 Tests), `EXIT:0`.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/AccountDataAssembler.php backend/tests/Functional/Api/AccountDataAssemblerTest.php
git commit -m "Baue AccountDataAssembler für die Selbstauskunft"
```

---

### Task 2: `AccountDataController` + Route + Endpunkt-Tests

Dünner Controller: Konto **ohne** `lastSeenAt`-Berührung auflösen, Assembler aufrufen, **danach** explizit berühren und flushen, JSON zurückgeben. Funktionstests decken Auth, `{}`-Serialisierung, End-to-End-Chiffrat und die `lastSeenAt`-Reihenfolge ab.

**Files:**
- Create: `backend/src/Controller/Api/AccountDataController.php`
- Test: `backend/tests/Functional/Api/AccountDataTest.php`

**Interfaces:**
- Consumes: `AccountResolver::requireAccount(Request, bool $touchLastSeen): Account`, `AccountDataAssembler::assemble(Account): array` (Task 1), `EntityManagerInterface`.
- Produces: Route `GET /api/account/data`.

- [ ] **Step 1: Endpunkt-Test schreiben (Auth, Leer-Fall, End-to-End, lastSeenAt-Reihenfolge)**

Erstelle `backend/tests/Functional/Api/AccountDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Tests\Functional\ApiTestCase;

final class AccountDataTest extends ApiTestCase
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
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** Lädt die Account-Entity zum Klartext-Token (Selector ist Index 1). */
    private function accountFor(string $token): Account
    {
        $selector = explode('_', $token)[1];
        $account = $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => $selector]);
        self::assertNotNull($account);

        return $account;
    }

    public function testWithoutTokenIs401(): void
    {
        $this->client->request('GET', '/api/account/data');
        self::assertResponseStatusCodeSame(401);
    }

    public function testWithInvalidTokenIs401(): void
    {
        $bogus = 'gacc_' . str_repeat('a', 16) . '_' . str_repeat('b', 43);
        $this->client->request('GET', '/api/account/data', server: $this->authHdr($bogus));
        self::assertResponseStatusCodeSame(401);
    }

    public function testEmptyAccountReturnsEmptySyncAsObject(): void
    {
        $token = $this->createAccount();

        $this->client->request('GET', '/api/account/data', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);

        $data = $this->json();
        self::assertArrayHasKey('createdAt', $data['account']);
        self::assertArrayHasKey('lastSeenAt', $data['account']);
        self::assertSame([], $data['submitters']);
        // Assoziatives json_decode kollabiert {} und [] — deshalb die Rohantwort prüfen:
        self::assertStringContainsString('"sync":{}', (string) $this->client->getResponse()->getContent());
    }

    public function testEndToEndExposesBlobCiphertext(): void
    {
        $token = $this->createAccount();

        // Blob über den echten Sync-PUT-Pfad anlegen:
        $this->client->request('PUT', '/api/account/sync/settings',
            server: $this->authHdr($token) + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['baseVersion' => 0, 'ciphertext' => 'my-cipher'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/account/data', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('my-cipher', $this->json()['sync']['settings']['ciphertext']);
    }

    public function testLastSeenAtReportsPreviousValueThenTouches(): void
    {
        $token = $this->createAccount();

        // lastSeenAt deterministisch in die Vergangenheit setzen (ATOM ist
        // sekundengenau — ein Echtzeit-Delta wäre in derselben Sekunde flaky):
        $account = $this->accountFor($token);
        $account->lastSeenAt = new \DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $this->em->flush();

        // Erster Abruf meldet den vorherigen Wert (2020) und berührt DANACH:
        $this->client->request('GET', '/api/account/data', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('2020-01-01', $this->json()['account']['lastSeenAt']);

        // Zweiter Abruf: nicht mehr 2020 ⇒ der erste Abruf hat berührt (zählt als Aktivität):
        $this->client->request('GET', '/api/account/data', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsNotWith('2020-01-01', $this->json()['account']['lastSeenAt']);
    }
}
```

- [ ] **Step 2: Tests ausführen – erwartet Fehler (Route fehlt ⇒ 404 statt erwarteter Codes)**

Run: `php backend/bin/phpunit --filter AccountDataTest; echo "EXIT:$?"`
Expected: FAIL – die 200-erwartenden Tests bekommen 404 (Route existiert noch nicht).

- [ ] **Step 3: Controller implementieren**

Erstelle `backend/src/Controller/Api/AccountDataController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\AccountDataAssembler;
use App\Service\AccountResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vollständige Selbstauskunft (»Meine Daten«, Phase 3 E): liefert alle Daten,
 * die der Server über das Konto hält — Metadaten, Sync-Blobs inkl. Chiffrat und
 * die verknüpften Submitter mit ihren Einträgen. Reiner Lesezugriff.
 *
 * Bewusste Reihenfolge: Das Konto wird OHNE lastSeenAt-Berührung aufgelöst,
 * damit der Assembler den echten vorherigen Wert meldet (resolve() flusht die
 * Berührung sonst VOR dem Return und lastSeenAt wäre immer »jetzt«). Erst nach
 * dem Zusammenbauen berührt der Controller explizit, damit der Abruf — wie
 * jeder Konto-Request — als Aktivität fürs Prune-Fenster zählt.
 */
final class AccountDataController
{
    #[Route('/api/account/data', methods: ['GET'])]
    public function __invoke(
        Request $request,
        AccountResolver $resolver,
        AccountDataAssembler $assembler,
        EntityManagerInterface $em,
    ): JsonResponse {
        $account = $resolver->requireAccount($request, touchLastSeen: false);

        $data = $assembler->assemble($account);

        $account->lastSeenAt = new \DateTimeImmutable();
        $em->flush();

        return new JsonResponse($data);
    }
}
```

- [ ] **Step 4: Tests ausführen – erwartet grün**

Run: `php backend/bin/phpunit --filter AccountDataTest; echo "EXIT:$?"`
Expected: PASS (5 Tests), `EXIT:0`.

- [ ] **Step 5: Volle Backend-Suite ausführen (Regression + Deprecation-Gate)**

Run: `php backend/bin/phpunit; echo "EXIT:$?"`
Expected: PASS, `EXIT:0` (keine Deprecations; `failOnDeprecation="true"` würde sonst trotz »OK« mit Exit 1 enden).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controller/Api/AccountDataController.php backend/tests/Functional/Api/AccountDataTest.php
git commit -m "Ergänze GET /api/account/data für die Selbstauskunft"
```

---

## Self-Review

- **Spec coverage:** §3 Endpunkt → Task 2 (Route, Auth, 401). §4 Antwortstruktur → Task 1 (account/sync/submitters, alle Felder). §5 Implementierung → Task 1 (Assembler, Repositories, unidirektionale Relationen, Enums) + Task 2 (dünner Controller). §6 Sicherheit → Isolations-Test (T1 Step 5) + kein-tokenHash-Assertions (T1 Step 1 & 5). §7 Vertrag → reine Doku, keine Umsetzung. §8 Tests → alle acht Testfälle abgedeckt (Auth ×2, Leer, Vollprofil, Isolation, kein tokenHash, ciphertext enthalten, lastSeenAt-Reihenfolge).
- **Placeholder-Scan:** keine TBD/TODO; jeder Code-Schritt enthält vollständigen Code.
- **Typkonsistenz:** `assemble(Account): array` einheitlich in Task 1 definiert und Task 2 konsumiert. `requireAccount(Request, touchLastSeen: false)` entspricht der realen Signatur (`bool $touchLastSeen = true`). Repository-`findBy`-Aufrufe nutzen existierende Felder (`account`, `submitter`, `entry`, `collection`).
- **Konto-Isolation:** durch `findBy(['account' => $account])` bzw. `['submitter' => …]`/`['entry' => …]` strukturell erzwungen; Task 1 Step 5 beweist es mit einem Fremdkonto.
