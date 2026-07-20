# Backend-Kern (Sub-Projekt 1) — Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die vollständige anonyme JSON-API `/api/v1` des Gestura-Index (Stöbern, Detail, Download, Update-Check, Einreichen, Aktualisieren, Löschen, Melden, Screenshot, Install-Ping) inkl. Datenmodell, Moderations-Statusmaschine und Console-Interim-Admin.

**Architecture:** Symfony 7.4 (schlanke Controller, kein API Platform, kein Security-Bundle — Edit-Token-Auth als eigener Service), Doctrine/MariaDB, Validierung über `opis/json-schema` gegen `schema/exchange-schema.json` plus portierte Zusatzregeln aus `js/menu-exchange.js` der Extension. Fehler einheitlich als `application/problem+json`, CORS `*`, Rate-Limits über Symfony RateLimiter.

**Tech Stack:** PHP 8.5 / Symfony 7.4 LTS, Doctrine ORM 3, MariaDB 10.11 (lokal) / MySQL (Server), opis/json-schema, PHPUnit + dama/doctrine-test-bundle, GD (WebP).

**Spec:** `docs/superpowers/specs/2026-07-20-index-backend-core-design.md` — bei Detailfragen gilt der Spec.

## Global Constraints

- Alle Befehle laufen aus dem Repo-Root (`/home/patric/apache/projekte/gestura-index`); PHP-Befehle mit lokalem `php` (8.5.3), Composer mit `composer --working-dir=backend`.
- Alle Endpunkte unter `/api/v1`; Fehler immer `application/problem+json`; Lese-Endpunkte liefern nur `status=published`.
- `schema/exchange-schema.json` ist der Format-Vertrag — **niemals editieren** (Kopie aus dem Extension-Repo).
- Limits aus dem Schema (`x-gestura`): `blobMax` 102400 Bytes, `itemsMax` 100, `urlMax` 2000 usw.; Request-Body-Limit für Einreichungen 131072 Bytes (128 KiB).
- Kategorien-Festliste (10 Keys): `dev`, `shopping`, `video`, `news`, `social`, `productivity`, `search`, `reference`, `entertainment`, `other`. 1–3 pro Entry.
- Edit-Token-Format: `gsti_<selector 16 hex>_<verifier 43 base64url>`; Verifier nur als Argon2id-Hash gespeichert.
- Env-Parameter: `REPORT_HIDE_THRESHOLD` (Default 3), `TRUST_THRESHOLD` (Default 3, in diesem Sub-Projekt nur gezählt, nie angewendet).
- Statuswerte exakt wie im Spec: Entry `pending|published|hidden|deleted`, Version `pending|approved|rejected`, Report `open|resolved`, Meldegründe `spam|broken_links|misleading|legal`.
- Commit-Messages: Deutsch, Imperativ, max. 50 Zeichen Subject, Body mit Warum (72 Zeichen Breite), Abschlusszeile `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Tests: PHPUnit; lokale MariaDB (`gestura_index_test`), dama/doctrine-test-bundle rollt Transaktionen pro Test zurück. Vor jedem Commit: kompletter Testlauf `php backend/bin/phpunit` muss grün sein.
- Doctrine-Entities nutzen **öffentliche typisierte Properties** (ORM 3 + native Lazy Objects, PHP 8.5) — keine Getter/Setter-Zeremonie; Hilfsmethoden nur wo Logik nötig ist.

## Datei-Struktur (Gesamtübersicht)

```text
backend/src/
├── Enum/            EntryType, EntryStatus, VersionStatus, ReportReason, ReportStatus, Category
├── Entity/          Submitter, Entry, EntryCategory, EntryVersion, Report
├── Repository/      SubmitterRepository, EntryRepository, EntryVersionRepository, ReportRepository
├── Exception/       ApiProblem
├── EventSubscriber/ ProblemJsonSubscriber, CorsSubscriber
├── Service/         EditTokenService (+GeneratedToken), ExchangeValidator (+ValidationResult),
│                    PayloadAnalyzer, RateLimitGuard, SubmitterResolver, EntrySerializer,
│                    SubmissionService, ModerationService, ScreenshotProcessor
├── Controller/Api/  EntryListController, EntryDetailController, VersionDownloadController,
│                    UpdateCheckController, InstallController, EntrySubmitController,
│                    EntryUpdateController, EntryDeleteController, ReportController,
│                    ScreenshotController
└── Command/         ModerationQueueCommand, ModerationApproveCommand, ModerationRejectCommand,
                     ModerationReportsCommand, ModerationResolveCommand, ModerationBanCommand

backend/tests/
├── Unit/            EditTokenServiceTest, ExchangeValidatorTest, PayloadAnalyzerTest, RateLimitGuardTest
├── Functional/      ApiTestCase (Basis + Factories), EntryListTest, EntryDetailTest,
│                    UpdateCheckTest, InstallTest, SubmitTest, UpdateTest, DeleteTest,
│                    ReportTest, ScreenshotTest, CorsTest
└── Command/         ModerationCommandsTest
```

Verantwortlichkeiten: Controller sind dünn (Request-Parsing, Statuscode), sämtliche Logik liegt in Services. `SubmissionService` bündelt die gemeinsame Einreichungs-/Update-Logik, `ModerationService` die Statusmaschine (auch von den Commands genutzt).

---

### Task 1: Pakete, Datenbanken, Test-Infrastruktur

**Files:**
- Modify: `backend/composer.json` (via composer require)
- Create: `backend/.env.local`, `backend/.env.test.local` (nicht committet — von `.gitignore` des Skeletons erfasst)
- Modify: `backend/.env` (Threshold-Defaults), `backend/phpunit.dist.xml` (dama-Extension)
- Test: `backend/tests/SmokeTest.php`

**Interfaces:**
- Produces: bootfähiger Kernel mit ORM, RateLimiter, Validator; `php backend/bin/phpunit` läuft; DBs `gestura_index` und `gestura_index_test` existieren.

- [ ] **Step 1: Pakete installieren**

```bash
composer --working-dir=backend require symfony/orm-pack symfony/validator symfony/rate-limiter opis/json-schema
composer --working-dir=backend require --dev symfony/test-pack dama/doctrine-test-bundle symfony/maker-bundle
```

Erwartet: Flex-Rezepte legen `config/packages/doctrine.yaml`, `doctrine_migrations.yaml`, `dama_doctrine_test_bundle.yaml`, `phpunit.dist.xml`, `bin/phpunit` an.

- [ ] **Step 2: Datenbanken und DB-Nutzer anlegen**

```bash
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS gestura_index CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS gestura_index_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'gestura'@'localhost' IDENTIFIED BY 'gestura_dev'; GRANT ALL ON gestura_index.* TO 'gestura'@'localhost'; GRANT ALL ON gestura_index_test.* TO 'gestura'@'localhost'; FLUSH PRIVILEGES;"
```

- [ ] **Step 3: Env-Dateien schreiben**

`backend/.env.local` (neu):

```dotenv
DATABASE_URL="mysql://gestura:gestura_dev@127.0.0.1:3306/gestura_index?serverVersion=mariadb-10.11.14&charset=utf8mb4"
```

`backend/.env.test.local` (neu, gleicher Inhalt — das Doctrine-Rezept hängt im Test-Env automatisch den Suffix `_test` an den DB-Namen):

```dotenv
DATABASE_URL="mysql://gestura:gestura_dev@127.0.0.1:3306/gestura_index?serverVersion=mariadb-10.11.14&charset=utf8mb4"
```

In `backend/.env` ergänzen (Defaults, committbar):

```dotenv
###> app ###
REPORT_HIDE_THRESHOLD=3
TRUST_THRESHOLD=3
###< app ###
```

Prüfen, dass `backend/config/packages/doctrine.yaml` unter `when@test:` den Eintrag `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` enthält (Rezept-Standard) — sonst ergänzen.

- [ ] **Step 4: dama-Extension in PHPUnit registrieren**

In `backend/phpunit.dist.xml` innerhalb von `<phpunit>` ergänzen (falls das Rezept es nicht schon getan hat):

```xml
<extensions>
    <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
</extensions>
```

- [ ] **Step 5: Smoke-Test schreiben**

`backend/tests/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SmokeTest extends KernelTestCase
{
    public function testKernelBoots(): void
    {
        self::bootKernel();
        self::assertSame('test', self::$kernel->getEnvironment());
    }
}
```

- [ ] **Step 6: Testlauf**

Run: `php backend/bin/phpunit`
Erwartet: `OK (1 test)`

- [ ] **Step 7: Commit**

```bash
git add backend/ && git commit -m "Richte Backend-Pakete und Test-Infrastruktur ein" -m "ORM, Validator, RateLimiter und opis/json-schema als Grundausstattung
für den Backend-Kern; dama/doctrine-test-bundle isoliert Tests per
Transaktions-Rollback auf der lokalen MariaDB.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Enums und Entities mit Migration

**Files:**
- Create: `backend/src/Enum/{EntryType,EntryStatus,VersionStatus,ReportReason,ReportStatus,Category}.php`
- Create: `backend/src/Entity/{Submitter,Entry,EntryCategory,EntryVersion,Report}.php`
- Create: `backend/src/Repository/{SubmitterRepository,EntryRepository,EntryVersionRepository,ReportRepository}.php`
- Create: Migration via `doctrine:migrations:diff`
- Test: `backend/tests/Functional/PersistenceTest.php`

**Interfaces:**
- Produces: alle Entities mit **öffentlichen Properties** wie unten; `Entry::setCategories(list<Category>)`, `Entry::categoryKeys(): list<string>`, `Entry::touch()`. Konstruktoren: `new Submitter(string $tokenSelector, string $tokenHash)`, `new Entry(string $formatId, EntryType $type, Submitter $submitter)`, `new EntryVersion(Entry $entry, string $semver, array $payload, string $contentHash)`, `new Report(Entry $entry, ReportReason $reason, ?string $comment)`.

- [ ] **Step 1: Enums anlegen**

`backend/src/Enum/EntryType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum EntryType: string
{
    case Menu = 'menu';
    case Engine = 'engine';
}
```

`backend/src/Enum/EntryStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum EntryStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Hidden = 'hidden';
    case Deleted = 'deleted';
}
```

`backend/src/Enum/VersionStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum VersionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
```

`backend/src/Enum/ReportReason.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum ReportReason: string
{
    case Spam = 'spam';
    case BrokenLinks = 'broken_links';
    case Misleading = 'misleading';
    case Legal = 'legal';
}
```

`backend/src/Enum/ReportStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum ReportStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
```

`backend/src/Enum/Category.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum Category: string
{
    case Dev = 'dev';
    case Shopping = 'shopping';
    case Video = 'video';
    case News = 'news';
    case Social = 'social';
    case Productivity = 'productivity';
    case Search = 'search';
    case Reference = 'reference';
    case Entertainment = 'entertainment';
    case Other = 'other';
}
```

- [ ] **Step 2: Entities anlegen**

`backend/src/Entity/Submitter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubmitterRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubmitterRepository::class)]
class Submitter
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 16, unique: true)]
    public string $tokenSelector;

    #[ORM\Column(length: 255)]
    public string $tokenHash;

    #[ORM\Column]
    public int $approvedCount = 0;

    #[ORM\Column]
    public bool $banned = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $tokenSelector, string $tokenHash)
    {
        $this->tokenSelector = $tokenSelector;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
    }
}
```

`backend/src/Entity/Entry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Repository\EntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Entry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 128, unique: true)]
    public string $formatId;

    #[ORM\Column(length: 10, enumType: EntryType::class)]
    public EntryType $type;

    #[ORM\Column(length: 10, enumType: EntryStatus::class)]
    public EntryStatus $status = EntryStatus::Pending;

    /** @var Collection<int, EntryCategory> */
    #[ORM\OneToMany(mappedBy: 'entry', targetEntity: EntryCategory::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $categories;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $tags = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $domains = [];

    #[ORM\Column(type: 'text')]
    public string $searchText = '';

    #[ORM\Column]
    public int $installCount = 0;

    #[ORM\Column]
    public bool $deprecated = false;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $successorFormatId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $screenshotPath = null;

    #[ORM\ManyToOne(targetEntity: Submitter::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Submitter $submitter;

    #[ORM\OneToOne(targetEntity: EntryVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?EntryVersion $currentVersion = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(string $formatId, EntryType $type, Submitter $submitter)
    {
        $this->formatId = $formatId;
        $this->type = $type;
        $this->submitter = $submitter;
        $this->categories = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @param list<Category> $categories */
    public function setCategories(array $categories): void
    {
        $this->categories->clear();
        foreach (array_unique($categories, SORT_REGULAR) as $category) {
            $this->categories->add(new EntryCategory($this, $category));
        }
    }

    /** @return list<string> */
    public function categoryKeys(): array
    {
        return array_values(array_map(
            static fn (EntryCategory $c): string => $c->category->value,
            $this->categories->toArray(),
        ));
    }
}
```

`backend/src/Entity/EntryCategory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Category;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'entry_category')]
#[ORM\UniqueConstraint(columns: ['entry_id', 'category'])]
#[ORM\Index(columns: ['category'])]
class EntryCategory
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entry::class, inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Entry $entry;

    #[ORM\Column(length: 20, enumType: Category::class)]
    public Category $category;

    public function __construct(Entry $entry, Category $category)
    {
        $this->entry = $entry;
        $this->category = $category;
    }
}
```

`backend/src/Entity/EntryVersion.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VersionStatus;
use App\Repository\EntryVersionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryVersionRepository::class)]
#[ORM\Table(name: 'entry_version')]
#[ORM\UniqueConstraint(columns: ['entry_id', 'semver'])]
#[ORM\Index(columns: ['content_hash'])]
class EntryVersion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Entry $entry;

    #[ORM\Column(length: 17)]
    public string $semver;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public array $payload;

    #[ORM\Column(length: 64)]
    public string $contentHash;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $changelog = null;

    #[ORM\Column(length: 10, enumType: VersionStatus::class)]
    public VersionStatus $status = VersionStatus::Pending;

    #[ORM\Column]
    public bool $hasTransformCode = false;

    #[ORM\Column]
    public \DateTimeImmutable $submittedAt;

    /** @param array<string, mixed> $payload */
    public function __construct(Entry $entry, string $semver, array $payload, string $contentHash)
    {
        $this->entry = $entry;
        $this->semver = $semver;
        $this->payload = $payload;
        $this->contentHash = $contentHash;
        $this->submittedAt = new \DateTimeImmutable();
    }
}
```

`backend/src/Entity/Report.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Repository\ReportRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReportRepository::class)]
class Report
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Entry $entry;

    #[ORM\Column(length: 20, enumType: ReportReason::class)]
    public ReportReason $reason;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(length: 10, enumType: ReportStatus::class)]
    public ReportStatus $status = ReportStatus::Open;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct(Entry $entry, ReportReason $reason, ?string $comment)
    {
        $this->entry = $entry;
        $this->reason = $reason;
        $this->comment = $comment;
        $this->createdAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 3: Repositories anlegen** (alle vier nach demselben Muster; hier `EntryRepository`, die anderen analog mit getauschtem Entity-Typ)

`backend/src/Repository/EntryRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Entry> */
class EntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }
}
```

`SubmitterRepository`, `EntryVersionRepository`, `ReportRepository` identisch mit `Submitter::class`, `EntryVersion::class`, `Report::class`.

- [ ] **Step 4: Native Lazy Objects sicherstellen**

In `backend/config/packages/doctrine.yaml` unter `orm:` prüfen/ergänzen:

```yaml
        enable_native_lazy_objects: true
```

- [ ] **Step 5: Migration erzeugen und ausführen (dev + test)**

```bash
php backend/bin/console doctrine:migrations:diff --no-interaction
php backend/bin/console doctrine:migrations:migrate --no-interaction
php backend/bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Erwartet: Migration legt Tabellen `submitter`, `entry`, `entry_category`, `entry_version`, `report` an.

- [ ] **Step 6: Persistenz-Test schreiben**

`backend/tests/Functional/PersistenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PersistenceTest extends KernelTestCase
{
    public function testEntryRoundTrip(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $submitter = new Submitter(bin2hex(random_bytes(8)), 'hash');
        $entry = new Entry('com.example.shop', EntryType::Menu, $submitter);
        $entry->setCategories([Category::Shopping, Category::Other]);
        $entry->tags = ['shop', 'beispiel'];
        $entry->domains = ['example.com'];
        $version = new EntryVersion($entry, '1.0.0', ['gesturaMenu' => 1, 'id' => 'com.example.shop'], str_repeat('a', 64));

        $em->persist($submitter);
        $em->persist($entry);
        $em->persist($version);
        $em->flush();
        $em->clear();

        $reloaded = $em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertNotNull($reloaded);
        self::assertSame(EntryStatus::Pending, $reloaded->status);
        self::assertEqualsCanonicalizing(['shopping', 'other'], $reloaded->categoryKeys());
        self::assertSame(['example.com'], $reloaded->domains);
    }
}
```

- [ ] **Step 7: Testlauf**

Run: `php backend/bin/phpunit`
Erwartet: `OK (2 tests)` — dama rollt die Testdaten automatisch zurück.

- [ ] **Step 8: Commit**

```bash
git add backend/ && git commit -m "Ergänze Datenmodell des Index-Backends" -m "Entry, EntryVersion, Submitter, Report und die Kategorien-Join-Tabelle
bilden die Statusmaschine aus dem Spec ab; Kategorien als eigene
Tabelle, damit der Haupt-Browse-Filter indexierbar bleibt.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: EditTokenService (Selector/Verifier)

**Files:**
- Create: `backend/src/Service/EditTokenService.php`, `backend/src/Service/GeneratedToken.php`
- Test: `backend/tests/Unit/EditTokenServiceTest.php`

**Interfaces:**
- Produces: `EditTokenService::generate(): GeneratedToken` (`->token`, `->selector`, `->hash`), `EditTokenService::parseAuthorizationHeader(?string $header): ?array{selector: string, verifier: string}`, `EditTokenService::verify(string $verifier, string $hash): bool`.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Unit/EditTokenServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\EditTokenService;
use PHPUnit\Framework\TestCase;

final class EditTokenServiceTest extends TestCase
{
    private EditTokenService $service;

    protected function setUp(): void
    {
        $this->service = new EditTokenService();
    }

    public function testGenerateProducesParsableTokenThatVerifies(): void
    {
        $generated = $this->service->generate();

        self::assertMatchesRegularExpression('/^gsti_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $generated->token);
        self::assertStringStartsWith('$argon2id$', $generated->hash);

        $parsed = $this->service->parseAuthorizationHeader('Bearer ' . $generated->token);
        self::assertNotNull($parsed);
        self::assertSame($generated->selector, $parsed['selector']);
        self::assertTrue($this->service->verify($parsed['verifier'], $generated->hash));
    }

    public function testWrongVerifierFailsVerification(): void
    {
        $generated = $this->service->generate();
        $other = $this->service->generate();
        $parsed = $this->service->parseAuthorizationHeader('Bearer ' . $other->token);
        self::assertFalse($this->service->verify($parsed['verifier'], $generated->hash));
    }

    public function testMalformedHeadersReturnNull(): void
    {
        self::assertNull($this->service->parseAuthorizationHeader(null));
        self::assertNull($this->service->parseAuthorizationHeader('Bearer kaputt'));
        self::assertNull($this->service->parseAuthorizationHeader('Basic abc'));
        self::assertNull($this->service->parseAuthorizationHeader('Bearer gsti_zzzz_kurz'));
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter EditTokenServiceTest`
Erwartet: FAIL (`Class "App\Service\EditTokenService" not found`)

- [ ] **Step 3: Implementieren**

`backend/src/Service/GeneratedToken.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

final readonly class GeneratedToken
{
    public function __construct(
        public string $token,
        public string $selector,
        public string $hash,
    ) {
    }
}
```

`backend/src/Service/EditTokenService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

final class EditTokenService
{
    public function generate(): GeneratedToken
    {
        $selector = bin2hex(random_bytes(8));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new GeneratedToken(
            token: sprintf('gsti_%s_%s', $selector, $verifier),
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
        if (!preg_match('/^gsti_([0-9a-f]{16})_([A-Za-z0-9_-]{43})$/', trim(substr($header, 7)), $m)) {
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

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter EditTokenServiceTest`
Erwartet: `OK (3 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze EditTokenService mit Selector/Verifier" -m "Ein Argon2id-Hash allein ist nicht auffindbar; der Klartext-Selector
dient dem Lookup, nur der Verifier wird gehasht — das Token selbst
wird nie gespeichert (Spec-Vorgabe).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: ExchangeValidator (JSON-Schema + Zusatzregeln)

**Files:**
- Create: `backend/src/Service/ExchangeValidator.php`, `backend/src/Service/ValidationResult.php`
- Test: `backend/tests/Unit/ExchangeValidatorTest.php`

**Interfaces:**
- Consumes: `schema/exchange-schema.json` (Repo-Root, Pfad via Autowire-Parameter).
- Produces: `ExchangeValidator::validate(string $rawJson): ValidationResult` mit `->ok: bool`, `->type: ?string` (`'menu'|'engine'|null`), `->errors: list<string>`, `->payload: ?array` (dekodiert, nur bei ok). Fehler-Codes wie im Referenz-Validator (`js/menu-exchange.js`): `notGesturaFormat`, `tooLarge`, `invalidJson`, `duplicateItemId`, `itemAction`, `itemUrl`, `itemSearch`, `schema`.

- [ ] **Step 1: Fehlschlagenden Test schreiben** (Paritäts-Vektoren aus `tests/menu-exchange.test.mjs` der Extension portiert)

`backend/tests/Unit/ExchangeValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ExchangeValidator;
use PHPUnit\Framework\TestCase;

final class ExchangeValidatorTest extends TestCase
{
    private ExchangeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ExchangeValidator(\dirname(__DIR__, 3) . '/schema/exchange-schema.json');
    }

    /** @return array<string, mixed> */
    private function validMenu(): array
    {
        return [
            'gesturaMenu' => 1,
            'id' => 'com.example.shop',
            'version' => '1.2.0',
            'name' => ['en' => 'Example Shop', 'de' => 'Beispiel-Shop'],
            'patterns' => ['*example.com*'],
            'items' => [
                ['id' => 'orders', 'label' => ['de' => 'Bestellungen'], 'action' => 'openCustomUrl', 'customUrl' => 'https://example.com/orders'],
                ['id' => 'sep1', 'type' => 'separator'],
                ['id' => 'search', 'label' => 'Suche', 'action' => 'searchLink', 'url' => 'https://example.com/s?q='],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validEngine(): array
    {
        return [
            'gesturaEngine' => 1,
            'id' => 'com.example.search',
            'version' => '1.0.0',
            'name' => 'Example Search',
            'url' => 'https://example.com/s?q=%s',
        ];
    }

    private function check(array $payload): \App\Service\ValidationResult
    {
        return $this->validator->validate(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testAcceptsWellFormedMenu(): void
    {
        $result = $this->check($this->validMenu());
        self::assertTrue($result->ok, implode(', ', $result->errors));
        self::assertSame('menu', $result->type);
        self::assertSame('com.example.shop', $result->payload['id']);
    }

    public function testAcceptsWellFormedEngine(): void
    {
        $result = $this->check($this->validEngine());
        self::assertTrue($result->ok, implode(', ', $result->errors));
        self::assertSame('engine', $result->type);
    }

    public function testRejectsUnknownFormat(): void
    {
        $result = $this->check(['foo' => 'bar']);
        self::assertFalse($result->ok);
        self::assertContains('notGesturaFormat', $result->errors);
    }

    public function testRejectsInvalidJson(): void
    {
        $result = $this->validator->validate('{kaputt');
        self::assertFalse($result->ok);
        self::assertContains('invalidJson', $result->errors);
    }

    public function testRejectsJavascriptUrl(): void
    {
        $menu = $this->validMenu();
        $menu['items'][0]['customUrl'] = 'javascript:alert(1)';
        self::assertFalse($this->check($menu)->ok);
    }

    public function testRejectsHttpUrl(): void
    {
        $engine = $this->validEngine();
        $engine['url'] = 'http://example.com/s?q=%s';
        self::assertFalse($this->check($engine)->ok);
    }

    public function testRejectsDuplicateItemIds(): void
    {
        $menu = $this->validMenu();
        $menu['items'][1] = ['id' => 'orders', 'label' => 'Doppelt', 'action' => 'newTab'];
        $result = $this->check($menu);
        self::assertFalse($result->ok);
        self::assertContains('duplicateItemId', $result->errors);
    }

    public function testRejectsDisallowedAction(): void
    {
        $menu = $this->validMenu();
        $menu['items'][0]['action'] = 'executeScript';
        self::assertFalse($this->check($menu)->ok);
    }

    public function testRejectsNonSeparatorItemWithoutAction(): void
    {
        $menu = $this->validMenu();
        unset($menu['items'][0]['action']);
        $result = $this->check($menu);
        self::assertFalse($result->ok);
        self::assertContains('itemAction', $result->errors);
    }

    public function testRejectsSearchLinkWithoutEngineIdOrUrl(): void
    {
        $menu = $this->validMenu();
        $menu['items'][2] = ['id' => 'search', 'label' => 'Suche', 'action' => 'searchLink'];
        $result = $this->check($menu);
        self::assertFalse($result->ok);
        self::assertContains('itemSearch', $result->errors);
    }

    public function testRejectsBadSemver(): void
    {
        $menu = $this->validMenu();
        $menu['version'] = '1.0.0.0';
        self::assertFalse($this->check($menu)->ok);

        $menu['version'] = '123456.0.0'; // SemVer-Overflow (>5 Stellen)
        self::assertFalse($this->check($menu)->ok);
    }

    public function testRejectsTooManyItems(): void
    {
        $menu = $this->validMenu();
        $menu['items'] = [];
        for ($i = 0; $i < 101; ++$i) {
            $menu['items'][] = ['id' => 'item' . $i, 'label' => 'X', 'action' => 'newTab'];
        }
        self::assertFalse($this->check($menu)->ok);
    }

    public function testRejectsOversizedBlob(): void
    {
        $menu = $this->validMenu();
        $menu['description'] = ['en' => str_repeat('x', 1500)];
        $json = json_encode($menu, JSON_THROW_ON_ERROR);
        // Riesen-JSON: über blobMax (102400 Bytes) aufpumpen
        $huge = substr_replace($json, str_repeat(' ', 103000), -1, 0);
        $result = $this->validator->validate($huge);
        self::assertFalse($result->ok);
        self::assertContains('tooLarge', $result->errors);
    }

    public function testRejectsOversizedTransformCode(): void
    {
        $engine = $this->validEngine();
        $engine['transformEnabled'] = true;
        $engine['transformCode'] = str_repeat('x', 10241);
        self::assertFalse($this->check($engine)->ok);
    }

    public function testRejectsUnsafeItemIdCharset(): void
    {
        $menu = $this->validMenu();
        $menu['items'][0]['id'] = '<script>';
        self::assertFalse($this->check($menu)->ok);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter ExchangeValidatorTest`
Erwartet: FAIL (`Class "App\Service\ExchangeValidator" not found`)

- [ ] **Step 3: Implementieren**

`backend/src/Service/ValidationResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

final readonly class ValidationResult
{
    /**
     * @param list<string>              $errors
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public bool $ok,
        public ?string $type,
        public array $errors,
        public ?array $payload,
    ) {
    }

    /** @param list<string> $errors */
    public static function fail(?string $type, array $errors): self
    {
        return new self(false, $type, $errors, null);
    }
}
```

`backend/src/Service/ExchangeValidator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExchangeValidator
{
    private const BLOB_MAX = 102400;

    private const ALLOWED_ACTIONS = [
        'none', 'openCustomUrl', 'searchLink', 'back', 'forward', 'refresh',
        'newTab', 'scrollUp', 'scrollDown', 'scrollToTop', 'scrollToBottom',
    ];

    private string $schemaJson;

    public function __construct(
        #[Autowire('%kernel.project_dir%/../schema/exchange-schema.json')]
        string $schemaPath,
    ) {
        $this->schemaJson = file_get_contents($schemaPath)
            ?: throw new \RuntimeException('exchange-schema.json nicht lesbar: ' . $schemaPath);
    }

    public function validate(string $rawJson): ValidationResult
    {
        if (\strlen($rawJson) > self::BLOB_MAX) {
            return ValidationResult::fail(null, ['tooLarge']);
        }

        try {
            $data = json_decode($rawJson, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ValidationResult::fail(null, ['invalidJson']);
        }

        $type = $this->detectType($data);
        if ($type === null) {
            return ValidationResult::fail(null, ['notGesturaFormat']);
        }

        $errors = [];

        $result = (new Validator())->validate($data, $this->schemaJson);
        if (!$result->isValid()) {
            foreach (array_keys((new ErrorFormatter())->format($result->error())) as $pointer) {
                $errors[] = 'schema:' . $pointer;
            }
        }

        if ($type === 'menu') {
            $this->applyMenuRules($data, $errors);
        }

        if ($errors !== []) {
            return ValidationResult::fail($type, array_values(array_unique($errors)));
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawJson, true, 32, JSON_THROW_ON_ERROR);

        return new ValidationResult(true, $type, [], $payload);
    }

    private function detectType(mixed $data): ?string
    {
        if (!\is_object($data)) {
            return null;
        }
        if (\is_int($data->gesturaMenu ?? null)) {
            return 'menu';
        }
        if (\is_int($data->gesturaEngine ?? null)) {
            return 'engine';
        }

        return null;
    }

    /**
     * Zusatzregeln, die das JSON-Schema nicht ausdrücken kann — portiert aus
     * js/menu-exchange.js (validateMenu): eindeutige Item-IDs, Nicht-Separator
     * braucht Whitelist-Aktion, openCustomUrl/searchLink brauchen https-Ziele.
     *
     * @param list<string> $errors
     */
    private function applyMenuRules(object $menu, array &$errors): void
    {
        $items = $menu->items ?? null;
        if (!\is_array($items)) {
            return; // Struktur bereits vom Schema bemängelt
        }

        $seen = [];
        foreach ($items as $item) {
            if (!\is_object($item) || !\is_string($item->id ?? null)) {
                continue; // Struktur bereits vom Schema bemängelt
            }
            if (isset($seen[$item->id])) {
                $errors[] = 'duplicateItemId';
                continue;
            }
            $seen[$item->id] = true;

            if (($item->type ?? null) === 'separator') {
                continue;
            }
            if (!\in_array($item->action ?? null, self::ALLOWED_ACTIONS, true)) {
                $errors[] = 'itemAction';
                continue;
            }
            if ($item->action === 'openCustomUrl' && !$this->isHttpsUrl($item->customUrl ?? null)) {
                $errors[] = 'itemUrl';
            }
            if ($item->action === 'searchLink') {
                $hasEngine = \is_string($item->engineId ?? null) && ($item->engineId !== '');
                $hasUrl = $this->isHttpsUrl($item->url ?? null);
                if (!$hasEngine && !$hasUrl) {
                    $errors[] = 'itemSearch';
                }
            }
        }
    }

    private function isHttpsUrl(mixed $value): bool
    {
        if (!\is_string($value) || $value === '') {
            return false;
        }
        $parts = parse_url($value);

        return \is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? '') !== '';
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter ExchangeValidatorTest`
Erwartet: `OK (14 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze ExchangeValidator mit Schema und Zusatzregeln" -m "Validiert Einreichungen identisch zum Client: opis/json-schema gegen
den geteilten Format-Vertrag plus die aus js/menu-exchange.js
portierten Regeln; Testvektoren aus menu-exchange.test.mjs übernommen,
damit beide Seiten zum gleichen Urteil kommen.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: PayloadAnalyzer (Domains, Suchtext, Hash, Transform)

**Files:**
- Create: `backend/src/Service/PayloadAnalyzer.php`
- Test: `backend/tests/Unit/PayloadAnalyzerTest.php`

**Interfaces:**
- Produces: `PayloadAnalyzer::extractDomains(array $payload): list<string>`, `::searchText(array $payload): string`, `::contentHash(array $payload): string` (64 Zeichen sha256, key-sortiert-kanonisch), `::hasTransform(array $payload): bool` (Regel wie Extension: `transformEnabled` UND nicht-leerer `transformCode`).

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Unit/PayloadAnalyzerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\PayloadAnalyzer;
use PHPUnit\Framework\TestCase;

final class PayloadAnalyzerTest extends TestCase
{
    private PayloadAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new PayloadAnalyzer();
    }

    public function testExtractDomainsFromPatterns(): void
    {
        $payload = ['patterns' => ['*example.com*', 'https://www.GitHub.com/foo/*', '*shop.example.com/cart*', 'kein-muster', '*example.com*']];
        self::assertSame(['example.com', 'www.github.com', 'shop.example.com'], $this->analyzer->extractDomains($payload));
    }

    public function testSearchTextCollectsAllLanguages(): void
    {
        $payload = [
            'name' => ['en' => 'Example Shop', 'de' => 'Beispiel-Shop'],
            'description' => 'A test',
        ];
        $text = $this->analyzer->searchText($payload);
        self::assertStringContainsString('example shop', $text);
        self::assertStringContainsString('beispiel-shop', $text);
        self::assertStringContainsString('a test', $text);
    }

    public function testContentHashIsKeyOrderIndependent(): void
    {
        $a = ['items' => [['id' => 'x']], 'name' => ['en' => 'A', 'de' => 'B']];
        $b = ['name' => ['de' => 'B', 'en' => 'A'], 'items' => [['id' => 'x']]];
        self::assertSame($this->analyzer->contentHash($a), $this->analyzer->contentHash($b));
        self::assertSame(64, \strlen($this->analyzer->contentHash($a)));
        self::assertNotSame($this->analyzer->contentHash($a), $this->analyzer->contentHash(['name' => 'C']));
    }

    public function testContentHashIgnoresIdAndVersion(): void
    {
        // Duplikat-Erkennung: derselbe Inhalt unter neuer Kennung/Version
        // muss denselben Hash ergeben (Spam-Szenario aus dem Spec).
        $a = ['id' => 'com.example.a', 'version' => '1.0.0', 'name' => 'Gleich', 'items' => [['id' => 'x']]];
        $b = ['id' => 'com.example.b', 'version' => '2.3.4', 'name' => 'Gleich', 'items' => [['id' => 'x']]];
        self::assertSame($this->analyzer->contentHash($a), $this->analyzer->contentHash($b));
    }

    public function testHasTransformRequiresEnabledAndNonEmptyCode(): void
    {
        self::assertTrue($this->analyzer->hasTransform(['transformEnabled' => true, 'transformCode' => 'return x;']));
        self::assertFalse($this->analyzer->hasTransform(['transformEnabled' => true, 'transformCode' => '   ']));
        self::assertFalse($this->analyzer->hasTransform(['transformCode' => 'return x;']));
        self::assertFalse($this->analyzer->hasTransform(['gesturaMenu' => 1]));
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter PayloadAnalyzerTest`
Erwartet: FAIL (Klasse fehlt)

- [ ] **Step 3: Implementieren**

`backend/src/Service/PayloadAnalyzer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

final class PayloadAnalyzer
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public function extractDomains(array $payload): array
    {
        $domains = [];
        foreach ((array) ($payload['patterns'] ?? []) as $pattern) {
            if (!\is_string($pattern)) {
                continue;
            }
            $candidate = strtolower(trim($pattern));
            $candidate = preg_replace('#^[a-z]+://#', '', $candidate) ?? '';
            $candidate = explode('/', $candidate, 2)[0];
            $candidate = trim($candidate, '*.');
            if ($candidate !== '' && str_contains($candidate, '.') && preg_match('/^[a-z0-9.-]+$/', $candidate)) {
                $domains[$candidate] = true;
            }
        }

        return array_keys($domains);
    }

    /** @param array<string, mixed> $payload */
    public function searchText(array $payload): string
    {
        $parts = [];
        foreach (['name', 'description'] as $field) {
            $value = $payload[$field] ?? null;
            if (\is_string($value)) {
                $parts[] = $value;
            } elseif (\is_array($value)) {
                foreach ($value as $translation) {
                    if (\is_string($translation)) {
                        $parts[] = $translation;
                    }
                }
            }
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /** @param array<string, mixed> $payload */
    public function contentHash(array $payload): string
    {
        // id und version fließen bewusst NICHT in den Hash ein: die
        // Duplikat-Erkennung soll identischen Inhalt auch unter neuer
        // Kennung oder Versionsnummer erkennen.
        unset($payload['id'], $payload['version']);
        $this->ksortRecursive($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    public function hasTransform(array $payload): bool
    {
        return ($payload['transformEnabled'] ?? false) === true
            && \is_string($payload['transformCode'] ?? null)
            && trim($payload['transformCode']) !== '';
    }

    private function ksortRecursive(array &$value): void
    {
        // Nur String-Key-Maps sortieren — Listen (items!) behalten ihre Reihenfolge.
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$child) {
            if (\is_array($child)) {
                $this->ksortRecursive($child);
            }
        }
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter PayloadAnalyzerTest`
Erwartet: `OK (4 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze PayloadAnalyzer für Metadaten-Extraktion" -m "Domains aus patterns (Domain-Gruppierung), Suchtext über alle
Sprachen, kanonischer Inhalts-Hash für die Duplikat-Erkennung und
die Transform-Erkennung nach der Regel des Referenz-Validators.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: API-Querschnitt — problem+json, CORS, RateLimitGuard, Test-Basis

**Files:**
- Create: `backend/src/Exception/ApiProblem.php`, `backend/src/EventSubscriber/ProblemJsonSubscriber.php`, `backend/src/EventSubscriber/CorsSubscriber.php`, `backend/src/Service/RateLimitGuard.php`, `backend/config/packages/rate_limiter.yaml`
- Test: `backend/tests/Unit/RateLimitGuardTest.php`, `backend/tests/Functional/ApiTestCase.php`, `backend/tests/Functional/CorsTest.php`

**Interfaces:**
- Produces: `throw new ApiProblem(int $status, string $title, array $extra = [], array $headers = [])`; `RateLimitGuard::consume(RateLimiterFactory $factory, string $key): void` (wirft `ApiProblem` 429 mit `Retry-After`); Limiter-Namen `submit`, `update`, `report`, `token_auth`, `install` (Autowire: `RateLimiterFactory $submitLimiter` usw.); `ApiTestCase` mit `$this->client`, `$this->em`, `createSubmitterWithToken(): array{0: Submitter, 1: string}`, `createPublishedEntry(string $formatId, array $payloadOverrides = [], ?Submitter $submitter = null): Entry`, `menuPayload(array $overrides = []): array`, `enginePayload(array $overrides = []): array`, `api(string $method, string $uri, ?array $body = null, ?string $token = null): void`.

- [ ] **Step 1: Fehlschlagende Tests schreiben**

`backend/tests/Unit/RateLimitGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Service\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimitGuardTest extends TestCase
{
    public function testThrows429WithRetryAfterWhenLimitExceeded(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $guard = new RateLimitGuard();

        $guard->consume($factory, 'key');
        $guard->consume($factory, 'key');

        try {
            $guard->consume($factory, 'key');
            self::fail('Erwartete ApiProblem-Exception blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertArrayHasKey('Retry-After', $e->getHeaders());
        }
    }
}
```

`backend/tests/Functional/CorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class CorsTest extends ApiTestCase
{
    public function testPreflightIsAnswered(): void
    {
        $this->client->request('OPTIONS', '/api/v1/entries', server: [
            'HTTP_ORIGIN' => 'https://example.org',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Authorization', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function testUnknownApiRouteYieldsProblemJsonWithCors(): void
    {
        $this->client->request('GET', '/api/v1/gibt-es-nicht');
        $response = $this->client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
```

`backend/tests/Functional/ApiTestCase.php` (Basis für alle folgenden Funktionstests):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Enum\VersionStatus;
use App\Service\EditTokenService;
use App\Service\PayloadAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: Submitter, 1: string} Submitter und Klartext-Token */
    protected function createSubmitterWithToken(): array
    {
        $generated = (new EditTokenService())->generate();
        $submitter = new Submitter($generated->selector, $generated->hash);
        $this->em->persist($submitter);
        $this->em->flush();

        return [$submitter, $generated->token];
    }

    /** @return array<string, mixed> */
    protected function menuPayload(array $overrides = []): array
    {
        return array_replace([
            'gesturaMenu' => 1,
            'id' => 'com.example.shop',
            'version' => '1.0.0',
            'name' => ['en' => 'Example Shop', 'de' => 'Beispiel-Shop'],
            'description' => ['en' => 'Shop menu'],
            'patterns' => ['*example.com*'],
            'items' => [
                ['id' => 'orders', 'label' => ['de' => 'Bestellungen'], 'action' => 'openCustomUrl', 'customUrl' => 'https://example.com/orders'],
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function enginePayload(array $overrides = []): array
    {
        return array_replace([
            'gesturaEngine' => 1,
            'id' => 'com.example.search',
            'version' => '1.0.0',
            'name' => 'Example Search',
            'url' => 'https://example.com/s?q=%s',
        ], $overrides);
    }

    /**
     * Legt einen veröffentlichten Entry mit freigegebener Version an.
     *
     * @param array<string, mixed> $payloadOverrides
     */
    protected function createPublishedEntry(
        string $formatId = 'com.example.shop',
        array $payloadOverrides = [],
        ?Submitter $submitter = null,
    ): Entry {
        if ($submitter === null) {
            [$submitter] = $this->createSubmitterWithToken();
        }
        $payload = $this->menuPayload(['id' => $formatId] + $payloadOverrides);
        $type = isset($payload['gesturaEngine']) ? EntryType::Engine : EntryType::Menu;
        $analyzer = new PayloadAnalyzer();

        $entry = new Entry($formatId, $type, $submitter);
        $entry->status = EntryStatus::Published;
        $entry->setCategories([Category::Shopping]);
        $entry->domains = $analyzer->extractDomains($payload);
        $entry->searchText = $analyzer->searchText($payload);

        $version = new EntryVersion($entry, $payload['version'], $payload, $analyzer->contentHash($payload));
        $version->status = VersionStatus::Approved;
        $entry->currentVersion = $version;

        $this->em->persist($entry);
        $this->em->persist($version);
        $this->em->flush();

        return $entry;
    }

    /** @param array<string, mixed>|null $body */
    protected function api(string $method, string $uri, ?array $body = null, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, $uri, server: $server, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 64, JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php backend/bin/phpunit --filter 'RateLimitGuardTest|CorsTest'`
Erwartet: FAIL (Klassen fehlen; CORS-Header fehlen)

- [ ] **Step 3: Implementieren**

`backend/src/Exception/ApiProblem.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApiProblem extends HttpException
{
    /**
     * @param array<string, mixed>  $extra   zusätzliche problem+json-Felder (z. B. errors-Liste)
     * @param array<string, string> $headers
     */
    public function __construct(
        int $statusCode,
        string $title,
        public readonly array $extra = [],
        array $headers = [],
    ) {
        parent::__construct($statusCode, $title, null, $headers);
    }
}
```

`backend/src/EventSubscriber/ProblemJsonSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\ApiProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ProblemJsonSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
        $headers = $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : [];

        $data = [
            'type' => 'about:blank',
            'title' => $status === 500 ? 'Internal Server Error' : $throwable->getMessage(),
            'status' => $status,
        ];
        if ($throwable instanceof ApiProblem) {
            $data += $throwable->extra;
        }

        $response = new JsonResponse($data, $status, $headers);
        $response->headers->set('Content-Type', 'application/problem+json');
        $event->setResponse($response);
    }
}
```

`backend/src/EventSubscriber/CorsSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS "*" für die gesamte /api/: öffentliche, cookielose API mit
 * Bearer-Tokens — Extension-Service-Worker und Svelte-Website greifen
 * beide frei zu (Spec, Abschnitt Querschnitt).
 */
final class CorsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->getMethod() === 'OPTIONS' && str_starts_with($request->getPathInfo(), '/api/')) {
            $event->setResponse(new Response('', 204, $this->corsHeaders()));
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            $event->getResponse()->headers->add($this->corsHeaders());
        }
    }

    /** @return array<string, string> */
    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Max-Age' => '86400',
        ];
    }
}
```

`backend/src/Service/RateLimitGuard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiProblem;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class RateLimitGuard
{
    public function consume(RateLimiterFactory $factory, string $key): void
    {
        $limit = $factory->create($key)->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw new ApiProblem(429, 'Rate limit exceeded', ['retryAfter' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
    }
}
```

`backend/config/packages/rate_limiter.yaml`:

```yaml
framework:
    rate_limiter:
        submit:     { policy: sliding_window, limit: 5,  interval: '1 hour' }
        update:     { policy: sliding_window, limit: 10, interval: '1 hour' }
        report:     { policy: sliding_window, limit: 5,  interval: '1 hour' }
        token_auth: { policy: sliding_window, limit: 10, interval: '1 hour' }
        install:    { policy: fixed_window,   limit: 1,  interval: '1 day' }

# Funktionstests feuern viele Requests von 127.0.0.1 — dort großzügige
# Limits; die Drosselung selbst testet RateLimitGuardTest isoliert.
when@test:
    framework:
        rate_limiter:
            submit:     { policy: sliding_window, limit: 1000, interval: '1 hour' }
            update:     { policy: sliding_window, limit: 1000, interval: '1 hour' }
            report:     { policy: sliding_window, limit: 1000, interval: '1 hour' }
            token_auth: { policy: sliding_window, limit: 1000, interval: '1 hour' }
            install:    { policy: fixed_window,   limit: 1000, interval: '1 day' }
```

- [ ] **Step 4: Tests laufen lassen — müssen bestehen**

Run: `php backend/bin/phpunit`
Erwartet: alle Tests grün (Smoke, Persistence, Unit, Cors)

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze API-Querschnitt: problem+json, CORS, Limits" -m "Einheitliches Fehlerformat und CORS * für /api/ als Subscriber statt
Bundle — die API ist cookielos und öffentlich. RateLimitGuard kapselt
das 429-Verhalten inkl. Retry-After; IPs leben nur im Limiter-Cache.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: GET /entries — Liste mit Filtern, Pagination, ETag

**Files:**
- Create: `backend/src/Controller/Api/EntryListController.php`, `backend/src/Service/EntrySerializer.php`
- Modify: `backend/src/Repository/EntryRepository.php` (Methode `search()`)
- Test: `backend/tests/Functional/EntryListTest.php`

**Interfaces:**
- Consumes: `ApiTestCase`-Factories (Task 6), Enums (Task 2).
- Produces: `EntryRepository::search(?string $q, ?string $site, ?Category $category, ?string $tag, ?EntryType $type, string $sort, int $page, int $perPage): array{items: list<Entry>, total: int}`; `EntrySerializer::toListItem(Entry $entry): array` und `::toDetail(Entry $entry, list<EntryVersion> $versions): array`. Listenantwort: `{items: [...], page, perPage, total}`; Item-Felder: `formatId, type, name, description, categories, tags, domains, installCount, currentVersion, deprecated, successorFormatId, screenshotUrl, updatedAt`.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Functional/EntryListTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\EntryStatus;

final class EntryListTest extends ApiTestCase
{
    public function testListsOnlyPublishedEntries(): void
    {
        $this->createPublishedEntry('com.example.one');
        $hidden = $this->createPublishedEntry('com.example.two');
        $hidden->status = EntryStatus::Hidden;
        $this->em->flush();

        $this->api('GET', '/api/v1/entries');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame(1, $data['total']);
        self::assertSame('com.example.one', $data['items'][0]['formatId']);
        self::assertSame(['en' => 'Example Shop', 'de' => 'Beispiel-Shop'], $data['items'][0]['name']);
    }

    public function testFiltersBySiteCategoryTagAndQuery(): void
    {
        $this->createPublishedEntry('com.example.shop');
        $other = $this->createPublishedEntry('org.other.menu', ['patterns' => ['*other.org*'], 'name' => 'Andere Seite']);
        $other->tags = ['spezial'];
        $this->em->flush();

        $this->api('GET', '/api/v1/entries?site=example.com');
        self::assertSame(['com.example.shop'], array_column($this->json()['items'], 'formatId'));

        $this->api('GET', '/api/v1/entries?tag=spezial');
        self::assertSame(['org.other.menu'], array_column($this->json()['items'], 'formatId'));

        $this->api('GET', '/api/v1/entries?category=shopping');
        self::assertSame(2, $this->json()['total']);

        $this->api('GET', '/api/v1/entries?q=andere');
        self::assertSame(['org.other.menu'], array_column($this->json()['items'], 'formatId'));
    }

    public function testInvalidFilterValuesYield400(): void
    {
        $this->api('GET', '/api/v1/entries?category=quatsch');
        self::assertResponseStatusCodeSame(400);

        $this->api('GET', '/api/v1/entries?type=quatsch');
        self::assertResponseStatusCodeSame(400);

        $this->api('GET', '/api/v1/entries?sort=quatsch');
        self::assertResponseStatusCodeSame(400);
    }

    public function testPaginationAndSortByInstalls(): void
    {
        $a = $this->createPublishedEntry('com.example.a');
        $b = $this->createPublishedEntry('com.example.b');
        $a->installCount = 5;
        $b->installCount = 9;
        $this->em->flush();

        $this->api('GET', '/api/v1/entries?sort=installs&perPage=1&page=1');
        $data = $this->json();
        self::assertSame('com.example.b', $data['items'][0]['formatId']);
        self::assertSame(2, $data['total']);
    }

    public function testEtagYields304(): void
    {
        $this->createPublishedEntry('com.example.one');

        $this->api('GET', '/api/v1/entries');
        $etag = $this->client->getResponse()->headers->get('ETag');
        self::assertNotNull($etag);

        $this->client->request('GET', '/api/v1/entries', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(304, $this->client->getResponse()->getStatusCode());
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter EntryListTest`
Erwartet: FAIL (404, Route existiert nicht)

- [ ] **Step 3: Implementieren**

`backend/src/Service/EntrySerializer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\EntryVersion;

final class EntrySerializer
{
    /** @return array<string, mixed> */
    public function toListItem(Entry $entry): array
    {
        $payload = $entry->currentVersion?->payload ?? [];

        return [
            'formatId' => $entry->formatId,
            'type' => $entry->type->value,
            'name' => $payload['name'] ?? $entry->formatId,
            'description' => $payload['description'] ?? null,
            'categories' => $entry->categoryKeys(),
            'tags' => $entry->tags,
            'domains' => $entry->domains,
            'installCount' => $entry->installCount,
            'currentVersion' => $entry->currentVersion?->semver,
            'deprecated' => $entry->deprecated,
            'successorFormatId' => $entry->successorFormatId,
            'screenshotUrl' => $entry->screenshotPath === null ? null : '/' . $entry->screenshotPath,
            'updatedAt' => $entry->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<EntryVersion> $versions freigegebene Versionen, neueste zuerst
     *
     * @return array<string, mixed>
     */
    public function toDetail(Entry $entry, array $versions): array
    {
        return $this->toListItem($entry) + [
            'versions' => array_map(static fn (EntryVersion $v): array => [
                'semver' => $v->semver,
                'changelog' => $v->changelog,
                'hasTransformCode' => $v->hasTransformCode,
                'submittedAt' => $v->submittedAt->format(\DateTimeInterface::ATOM),
            ], $versions),
        ];
    }
}
```

In `backend/src/Repository/EntryRepository.php` ergänzen (Imports: `App\Enum\Category`, `App\Enum\EntryStatus`, `App\Enum\EntryType`):

```php
    /** @return array{items: list<\App\Entity\Entry>, total: int} */
    public function search(
        ?string $q,
        ?string $site,
        ?Category $category,
        ?string $tag,
        ?EntryType $type,
        string $sort,
        int $page,
        int $perPage,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.status = :published')
            ->setParameter('published', EntryStatus::Published);

        if ($type !== null) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }
        if ($category !== null) {
            $qb->innerJoin('e.categories', 'c')
                ->andWhere('c.category = :category')
                ->setParameter('category', $category);
        }
        if ($q !== null && $q !== '') {
            $qb->andWhere('e.searchText LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }
        // tags/domains sind JSON-Arrays normalisierter Strings; das
        // LIKE auf den kodierten Wert ersetzt JSON_CONTAINS, das DQL
        // ohne Zusatzbundle nicht kennt.
        if ($site !== null && $site !== '') {
            $qb->andWhere('e.domains LIKE :site')
                ->setParameter('site', '%' . json_encode(mb_strtolower($site)) . '%');
        }
        if ($tag !== null && $tag !== '') {
            $qb->andWhere('e.tags LIKE :tag')
                ->setParameter('tag', '%' . json_encode(mb_strtolower($tag)) . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(DISTINCT e.id)')->getQuery()->getSingleScalarResult();

        $qb->orderBy($sort === 'installs' ? 'e.installCount' : 'e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }
```

`backend/src/Controller/Api/EntryListController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\Category;
use App\Enum\EntryType;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\EntrySerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class EntryListController
{
    #[Route('/api/v1/entries', methods: ['GET'])]
    public function __invoke(Request $request, EntryRepository $entries, EntrySerializer $serializer): JsonResponse
    {
        $categoryParam = $request->query->get('category');
        $category = $categoryParam === null ? null
            : (Category::tryFrom($categoryParam) ?? throw new ApiProblem(400, 'Unknown category'));

        $typeParam = $request->query->get('type');
        $type = $typeParam === null ? null
            : (EntryType::tryFrom($typeParam) ?? throw new ApiProblem(400, 'Unknown type'));

        $sort = $request->query->get('sort', 'newest');
        if (!\in_array($sort, ['newest', 'installs'], true)) {
            throw new ApiProblem(400, 'Unknown sort');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(50, max(1, $request->query->getInt('perPage', 20)));

        $result = $entries->search(
            q: $request->query->get('q'),
            site: $request->query->get('site'),
            category: $category,
            tag: $request->query->get('tag'),
            type: $type,
            sort: $sort,
            page: $page,
            perPage: $perPage,
        );

        $response = new JsonResponse([
            'items' => array_map($serializer->toListItem(...), $result['items']),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $result['total'],
        ]);
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter EntryListTest`
Erwartet: `OK (5 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze GET /entries mit Filtern und ETag" -m "Stöbern-Endpunkt mit q/site/category/tag/type-Filtern, Pagination und
Cache-Headern; liefert ausschließlich veröffentlichte Einträge.
sort=rating folgt erst mit Konten in Phase 3.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: GET Detail + Versions-Download

**Files:**
- Create: `backend/src/Controller/Api/EntryDetailController.php`, `backend/src/Controller/Api/VersionDownloadController.php`
- Modify: `backend/src/Repository/EntryVersionRepository.php` (Methode `findApproved()`)
- Test: `backend/tests/Functional/EntryDetailTest.php`

**Interfaces:**
- Consumes: `EntrySerializer::toDetail()` (Task 7).
- Produces: `GET /api/v1/entries/{formatId}` (Detail + `versions`-Liste), `GET /api/v1/entries/{formatId}/versions/{semver}` (rohes Format-JSON); `EntryVersionRepository::findApproved(Entry $entry): list<EntryVersion>` (neueste zuerst) und `::findOneApproved(Entry $entry, string $semver): ?EntryVersion`.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Functional/EntryDetailTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\EntryStatus;

final class EntryDetailTest extends ApiTestCase
{
    public function testDetailContainsVersions(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->api('GET', '/api/v1/entries/com.example.shop');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame('com.example.shop', $data['formatId']);
        self::assertCount(1, $data['versions']);
        self::assertSame('1.0.0', $data['versions'][0]['semver']);
        self::assertFalse($data['versions'][0]['hasTransformCode']);
    }

    public function testNonPublishedEntryYields404(): void
    {
        $entry = $this->createPublishedEntry('com.example.hidden');
        $entry->status = EntryStatus::Hidden;
        $this->em->flush();

        $this->api('GET', '/api/v1/entries/com.example.hidden');
        self::assertResponseStatusCodeSame(404);

        $this->api('GET', '/api/v1/entries/com.example.unbekannt');
        self::assertResponseStatusCodeSame(404);
    }

    public function testVersionDownloadReturnsRawPayload(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->api('GET', '/api/v1/entries/com.example.shop/versions/1.0.0');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame(1, $data['gesturaMenu']);
        self::assertSame('com.example.shop', $data['id']);

        $this->api('GET', '/api/v1/entries/com.example.shop/versions/9.9.9');
        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter EntryDetailTest`
Erwartet: FAIL (404 auf Detail-Route, JSON-Felder fehlen)

- [ ] **Step 3: Implementieren**

In `backend/src/Repository/EntryVersionRepository.php` ergänzen (Imports: `App\Entity\Entry`, `App\Enum\VersionStatus`):

```php
    /** @return list<\App\Entity\EntryVersion> */
    public function findApproved(Entry $entry): array
    {
        return $this->findBy(['entry' => $entry, 'status' => VersionStatus::Approved], ['submittedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findOneApproved(Entry $entry, string $semver): ?\App\Entity\EntryVersion
    {
        return $this->findOneBy(['entry' => $entry, 'semver' => $semver, 'status' => VersionStatus::Approved]);
    }
```

`backend/src/Controller/Api/EntryDetailController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Service\EntrySerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class EntryDetailController
{
    #[Route('/api/v1/entries/{formatId}', methods: ['GET'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        EntryVersionRepository $versions,
        EntrySerializer $serializer,
    ): JsonResponse {
        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        $response = new JsonResponse($serializer->toDetail($entry, $versions->findApproved($entry)));
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}
```

`backend/src/Controller/Api/VersionDownloadController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class VersionDownloadController
{
    #[Route('/api/v1/entries/{formatId}/versions/{semver}', methods: ['GET'], requirements: ['semver' => '\d{1,5}\.\d{1,5}\.\d{1,5}'])]
    public function __invoke(
        string $formatId,
        string $semver,
        Request $request,
        EntryRepository $entries,
        EntryVersionRepository $versions,
    ): JsonResponse {
        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');
        $version = $versions->findOneApproved($entry, $semver)
            ?? throw new ApiProblem(404, 'Version not found');

        $response = new JsonResponse($version->payload);
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter EntryDetailTest`
Erwartet: `OK (3 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze Detail- und Download-Endpunkte" -m "Detail liefert die freigegebene Versionsliste inkl. Skript-Kennzeichen;
der Download bleibt dank Install-Ping (eigener Endpunkt, Task 10)
sauber cachebar. Nicht-öffentliche Einträge antworten 404 statt 403,
um Existenz nicht zu leaken.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 9: POST /entries/updates — Update-Check

**Files:**
- Create: `backend/src/Controller/Api/UpdateCheckController.php`
- Test: `backend/tests/Functional/UpdateCheckTest.php`

**Interfaces:**
- Produces: `POST /api/v1/entries/updates`, Body `{"entries": [{"id": "...", "version": "1.0.0"}, ...]}` (max. 200) → `{"updates": [{"id", "latestVersion", "deprecated", "successorFormatId"}]}` nur für Einträge mit neuerer freigegebener Version.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Functional/UpdateCheckTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class UpdateCheckTest extends ApiTestCase
{
    public function testReportsOnlyNewerVersions(): void
    {
        $entry = $this->createPublishedEntry('com.example.shop', ['version' => '2.1.0']);
        $entry->deprecated = true;
        $entry->successorFormatId = 'com.example.shop2';
        $this->createPublishedEntry('com.example.aktuell');
        $this->em->flush();

        $this->api('POST', '/api/v1/entries/updates', ['entries' => [
            ['id' => 'com.example.shop', 'version' => '1.0.0'],
            ['id' => 'com.example.aktuell', 'version' => '1.0.0'],
            ['id' => 'com.example.unbekannt', 'version' => '1.0.0'],
        ]]);

        self::assertResponseIsSuccessful();
        $updates = $this->json()['updates'];
        self::assertCount(1, $updates);
        self::assertSame('com.example.shop', $updates[0]['id']);
        self::assertSame('2.1.0', $updates[0]['latestVersion']);
        self::assertTrue($updates[0]['deprecated']);
        self::assertSame('com.example.shop2', $updates[0]['successorFormatId']);
    }

    public function testRejectsOversizedOrMalformedBody(): void
    {
        $many = array_fill(0, 201, ['id' => 'com.example.x', 'version' => '1.0.0']);
        $this->api('POST', '/api/v1/entries/updates', ['entries' => $many]);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/v1/entries/updates', ['entries' => 'quatsch']);
        self::assertResponseStatusCodeSame(400);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter UpdateCheckTest`
Erwartet: FAIL (404)

- [ ] **Step 3: Implementieren**

`backend/src/Controller/Api/UpdateCheckController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class UpdateCheckController
{
    #[Route('/api/v1/entries/updates', methods: ['POST'])]
    public function __invoke(Request $request, EntryRepository $entries): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }

        $list = $body['entries'] ?? null;
        if (!\is_array($list) || \count($list) > 200) {
            throw new ApiProblem(400, 'entries must be a list with at most 200 items');
        }

        $updates = [];
        foreach ($list as $item) {
            $id = $item['id'] ?? null;
            $version = $item['version'] ?? null;
            if (!\is_string($id) || !\is_string($version) || !preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}$/', $version)) {
                continue; // fehlerhafte Einzelposten still überspringen — der Check bleibt nutzbar
            }
            $entry = $entries->findOneBy(['formatId' => $id, 'status' => EntryStatus::Published]);
            $latest = $entry?->currentVersion?->semver;
            if ($latest !== null && version_compare($latest, $version, '>')) {
                $updates[] = [
                    'id' => $entry->formatId,
                    'latestVersion' => $latest,
                    'deprecated' => $entry->deprecated,
                    'successorFormatId' => $entry->successorFormatId,
                ];
            }
        }

        return new JsonResponse(['updates' => $updates]);
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter UpdateCheckTest`
Erwartet: `OK (2 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze anonymen Update-Check" -m "Antwortet nur für Einträge mit neuerer freigegebener Version inkl.
Deprecation-Hinweis; die Anfrage wird nicht gespeichert und ist an
kein Konto gebunden (Spec-Vorgabe).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 10: POST Install-Ping

**Files:**
- Create: `backend/src/Controller/Api/InstallController.php`
- Test: `backend/tests/Functional/InstallTest.php`

**Interfaces:**
- Consumes: `RateLimitGuard` + Limiter `install` (Task 6).
- Produces: `POST /api/v1/entries/{formatId}/install` → 204, erhöht `installCount` (nur `published`).

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Functional/InstallTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;

final class InstallTest extends ApiTestCase
{
    public function testPingIncrementsCounter(): void
    {
        $entry = $this->createPublishedEntry('com.example.shop');

        $this->api('POST', '/api/v1/entries/com.example.shop/install');

        self::assertResponseStatusCodeSame(204);
        $this->em->clear();
        $reloaded = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(1, $reloaded->installCount);
    }

    public function testUnknownEntryYields404(): void
    {
        $this->api('POST', '/api/v1/entries/com.example.unbekannt/install');
        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter InstallTest`
Erwartet: FAIL (404 statt 204 bzw. Zähler bleibt 0)

- [ ] **Step 3: Implementieren**

`backend/src/Controller/Api/InstallController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class InstallController
{
    #[Route('/api/v1/entries/{formatId}/install', methods: ['POST'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactory $installLimiter,
    ): Response {
        // 1 Ping pro Tag, IP und Entry — die IP lebt nur im Limiter-Cache.
        $guard->consume($installLimiter, ($request->getClientIp() ?? 'unknown') . '|' . $formatId);

        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        ++$entry->installCount;
        $em->flush();

        return new Response('', 204);
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter InstallTest`
Erwartet: `OK (2 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze anonymen Install-Ping" -m "Bewusste Abweichung von der Phase-2-Spec: gezählt wird ein bestätigter
Import per eigenem POST statt des Download-GETs — der Download bleibt
cachebar und Vorschau-Abrufe zählen nicht mit (Sub-Projekt-Spec).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 11: SubmitterResolver + POST /entries (Einreichen)

**Files:**
- Create: `backend/src/Service/SubmitterResolver.php`, `backend/src/Service/SubmissionService.php`, `backend/src/Controller/Api/EntrySubmitController.php`
- Test: `backend/tests/Functional/SubmitTest.php`

**Interfaces:**
- Consumes: `EditTokenService` (Task 3), `ExchangeValidator` (Task 4), `PayloadAnalyzer` (Task 5), `RateLimitGuard`/Limiter (Task 6).
- Produces:
  - `SubmitterResolver::resolve(Request $request): ?Submitter` (null ohne Header; `ApiProblem` 401 bei ungültigem Token) und `::requireOwner(Request $request, Entry $entry): Submitter` (401/403).
  - `SubmissionService::parseSubmissionBody(Request $request): array{payloadJson: string, categories: list<Category>, tags: list<string>, changelog: ?string, deprecated: ?bool, successorFormatId: ?string}` — wirft `ApiProblem` 400/413.
  - `SubmissionService::validatePayload(string $payloadJson, ?EntryType $expectedType, ?string $expectedFormatId): ValidationResult` — wirft `ApiProblem` 400 (mit `errors`-Liste) bei Verstößen.
  - `SubmissionService::assertNoDuplicate(array $payload, ?Entry $ignoreEntry): string` — liefert den contentHash, wirft `ApiProblem` 409 bei Duplikat in fremdem Entry.
  - `SubmissionService::applyMetadata(Entry $entry, array $meta): void` (Kategorien/Tags/Deprecation) und `SubmissionService::refreshDerived(Entry $entry, array $payload): void` (domains + searchText).
  - `POST /api/v1/entries` → 201 `{formatId, status: "pending", editToken?}` (`editToken` nur bei frisch erzeugtem Token).

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Functional/SubmitTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Enum\EntryStatus;

final class SubmitTest extends ApiTestCase
{
    public function testAnonymousSubmissionCreatesPendingEntryAndReturnsToken(): void
    {
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(),
            'categories' => ['shopping', 'other'],
            'tags' => ['Shop ', 'BEISPIEL'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $this->json();
        self::assertSame('com.example.shop', $data['formatId']);
        self::assertSame('pending', $data['status']);
        self::assertMatchesRegularExpression('/^gsti_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $data['editToken']);

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(EntryStatus::Pending, $entry->status);
        self::assertSame(['shop', 'beispiel'], $entry->tags);
        self::assertSame(['example.com'], $entry->domains);
        self::assertStringContainsString('beispiel-shop', $entry->searchText);
    }

    public function testTokenReuseAttachesToSameSubmitterAndReturnsNoToken(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();

        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->enginePayload(),
            'categories' => ['search'],
        ], token: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('editToken', $this->json());

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.search']);
        self::assertSame($submitter->id, $entry->submitter->id);
    }

    public function testInvalidTokenYields401(): void
    {
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(),
            'categories' => ['shopping'],
        ], token: 'gsti_' . str_repeat('0', 16) . '_' . str_repeat('A', 43));

        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidPayloadYields400WithErrors(): void
    {
        $payload = $this->menuPayload();
        $payload['items'][0]['customUrl'] = 'javascript:alert(1)';

        $this->api('POST', '/api/v1/entries', ['payload' => $payload, 'categories' => ['shopping']]);

        self::assertResponseStatusCodeSame(400);
        self::assertNotEmpty($this->json()['errors']);
    }

    public function testBadMetadataYields400(): void
    {
        $this->api('POST', '/api/v1/entries', ['payload' => $this->menuPayload(), 'categories' => []]);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/v1/entries', ['payload' => $this->menuPayload(), 'categories' => ['a', 'b', 'c', 'd']]);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/v1/entries', ['payload' => $this->menuPayload(), 'categories' => ['quatsch']]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testTakenFormatIdYields409(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->api('POST', '/api/v1/entries', ['payload' => $this->menuPayload(), 'categories' => ['shopping']]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testDuplicateContentUnderNewIdYields409(): void
    {
        $this->createPublishedEntry('com.example.original');

        // Identischer Inhalt unter neuer Kennung: contentHash ignoriert
        // id/version, die Kollision mit dem fremden Entry wird erkannt.
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(['id' => 'com.example.kopie']),
            'categories' => ['shopping'],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testOversizedBodyYields413(): void
    {
        $payload = $this->menuPayload(['description' => ['en' => 'x']]);
        $body = json_encode(['payload' => $payload, 'categories' => ['shopping']], JSON_THROW_ON_ERROR);
        $body = substr_replace($body, str_repeat(' ', 132000), -1, 0);

        $this->client->request('POST', '/api/v1/entries', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(413);
    }

    public function testBannedSubmitterYields403(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $submitter->banned = true;
        $this->em->flush();

        $this->api('POST', '/api/v1/entries', ['payload' => $this->menuPayload(), 'categories' => ['shopping']], token: $token);
        self::assertResponseStatusCodeSame(403);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter SubmitTest`
Erwartet: FAIL (404, Route existiert nicht)

- [ ] **Step 3: Implementieren**

`backend/src/Service/SubmitterResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\Submitter;
use App\Exception\ApiProblem;
use App\Repository\SubmitterRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class SubmitterResolver
{
    public function __construct(
        private readonly EditTokenService $tokens,
        private readonly SubmitterRepository $submitters,
        private readonly RateLimitGuard $guard,
        private readonly RateLimiterFactory $tokenAuthLimiter,
    ) {
    }

    /** Liefert null, wenn gar kein Authorization-Header gesendet wurde. */
    public function resolve(Request $request): ?Submitter
    {
        $header = $request->headers->get('Authorization');
        if ($header === null) {
            return null;
        }

        $parsed = $this->tokens->parseAuthorizationHeader($header)
            ?? throw new ApiProblem(401, 'Invalid token');

        // Fehlversuche pro IP+Selector drosseln (Brute-Force-Schutz)
        $this->guard->consume($this->tokenAuthLimiter, ($request->getClientIp() ?? 'unknown') . '|' . $parsed['selector']);

        $submitter = $this->submitters->findOneBy(['tokenSelector' => $parsed['selector']]);
        if ($submitter === null || !$this->tokens->verify($parsed['verifier'], $submitter->tokenHash)) {
            throw new ApiProblem(401, 'Invalid token');
        }

        return $submitter;
    }

    public function requireOwner(Request $request, Entry $entry): Submitter
    {
        $submitter = $this->resolve($request) ?? throw new ApiProblem(401, 'Token required');
        if ($submitter->banned) {
            throw new ApiProblem(403, 'Submitter is banned');
        }
        if ($entry->submitter->id !== $submitter->id) {
            throw new ApiProblem(403, 'Not the owner of this entry');
        }

        return $submitter;
    }
}
```

`backend/src/Service/SubmissionService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Enum\Category;
use App\Enum\EntryType;
use App\Exception\ApiProblem;
use App\Repository\EntryVersionRepository;
use Symfony\Component\HttpFoundation\Request;

final class SubmissionService
{
    private const BODY_MAX = 131072; // 128 KiB: blobMax + Metadaten-Puffer
    private const TAGS_MAX = 10;
    private const TAG_LENGTH_MAX = 50;
    private const CHANGELOG_MAX = 2000;

    public function __construct(
        private readonly ExchangeValidator $validator,
        private readonly PayloadAnalyzer $analyzer,
        private readonly EntryVersionRepository $versions,
    ) {
    }

    /**
     * @return array{payloadJson: string, categories: list<Category>, tags: list<string>,
     *               changelog: ?string, deprecated: ?bool, successorFormatId: ?string}
     */
    public function parseSubmissionBody(Request $request): array
    {
        $raw = $request->getContent();
        if (\strlen($raw) > self::BODY_MAX) {
            throw new ApiProblem(413, 'Request body too large');
        }

        try {
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        if (!\is_array($body) || !\is_array($body['payload'] ?? null)) {
            throw new ApiProblem(400, 'Missing payload object');
        }

        $categories = null;
        if (\array_key_exists('categories', $body)) {
            if (!\is_array($body['categories'])) {
                throw new ApiProblem(400, 'categories must be a list');
            }
            $categories = [];
            foreach ($body['categories'] as $key) {
                $categories[] = \is_string($key)
                    ? (Category::tryFrom($key) ?? throw new ApiProblem(400, 'Unknown category: ' . $key))
                    : throw new ApiProblem(400, 'Unknown category');
            }
            $categories = array_values(array_unique($categories, SORT_REGULAR));
            if (\count($categories) < 1 || \count($categories) > 3) {
                throw new ApiProblem(400, 'Between 1 and 3 categories required');
            }
        }

        $tags = [];
        foreach ((array) ($body['tags'] ?? []) as $tag) {
            if (!\is_string($tag)) {
                throw new ApiProblem(400, 'tags must be strings');
            }
            $normalized = mb_strtolower(trim($tag));
            if ($normalized === '' || \in_array($normalized, $tags, true)) {
                continue;
            }
            if (mb_strlen($normalized) > self::TAG_LENGTH_MAX) {
                throw new ApiProblem(400, 'Tag too long');
            }
            $tags[] = $normalized;
        }
        if (\count($tags) > self::TAGS_MAX) {
            throw new ApiProblem(400, 'At most 10 tags allowed');
        }

        $changelog = $body['changelog'] ?? null;
        if ($changelog !== null && (!\is_string($changelog) || mb_strlen($changelog) > self::CHANGELOG_MAX)) {
            throw new ApiProblem(400, 'Invalid changelog');
        }

        $successor = $body['successorFormatId'] ?? null;
        if ($successor !== null && (!\is_string($successor) || mb_strlen($successor) > 128)) {
            throw new ApiProblem(400, 'Invalid successorFormatId');
        }

        return [
            'payloadJson' => json_encode($body['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'categories' => $categories ?? throw new ApiProblem(400, 'Between 1 and 3 categories required'),
            'tags' => $tags,
            'changelog' => $changelog,
            'deprecated' => isset($body['deprecated']) ? (bool) $body['deprecated'] : null,
            'successorFormatId' => $successor,
        ];
    }

    public function validatePayload(string $payloadJson, ?EntryType $expectedType, ?string $expectedFormatId): ValidationResult
    {
        $result = $this->validator->validate($payloadJson);
        if (!$result->ok) {
            throw new ApiProblem(400, 'Payload validation failed', ['errors' => $result->errors]);
        }
        if ($expectedType !== null && $result->type !== $expectedType->value) {
            throw new ApiProblem(400, 'Payload type does not match entry type');
        }
        if ($expectedFormatId !== null && ($result->payload['id'] ?? null) !== $expectedFormatId) {
            throw new ApiProblem(400, 'Payload id does not match entry formatId');
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string der contentHash des Payloads
     */
    public function assertNoDuplicate(array $payload, ?Entry $ignoreEntry): string
    {
        $hash = $this->analyzer->contentHash($payload);
        foreach ($this->versions->findBy(['contentHash' => $hash]) as $existing) {
            if ($ignoreEntry === null || $existing->entry->id !== $ignoreEntry->id) {
                throw new ApiProblem(409, 'Identical content already exists in the index');
            }
        }

        return $hash;
    }

    /** @param array{categories: ?list<Category>, tags: list<string>, deprecated: ?bool, successorFormatId: ?string} $meta */
    public function applyMetadata(Entry $entry, array $meta): void
    {
        if ($meta['categories'] !== null) {
            $entry->setCategories($meta['categories']);
        }
        $entry->tags = $meta['tags'];
        if ($meta['deprecated'] !== null) {
            $entry->deprecated = $meta['deprecated'];
        }
        if ($meta['successorFormatId'] !== null) {
            $entry->successorFormatId = $meta['successorFormatId'];
        }
    }

    /** @param array<string, mixed> $payload */
    public function refreshDerived(Entry $entry, array $payload): void
    {
        $entry->domains = $this->analyzer->extractDomains($payload);
        $entry->searchText = $this->analyzer->searchText($payload);
    }
}
```

`backend/src/Controller/Api/EntrySubmitController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\EntryType;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\EditTokenService;
use App\Service\PayloadAnalyzer;
use App\Service\RateLimitGuard;
use App\Service\SubmissionService;
use App\Service\SubmitterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class EntrySubmitController
{
    #[Route('/api/v1/entries', methods: ['POST'])]
    public function __invoke(
        Request $request,
        SubmissionService $submission,
        SubmitterResolver $resolver,
        EditTokenService $tokens,
        PayloadAnalyzer $analyzer,
        EntryRepository $entries,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactory $submitLimiter,
    ): JsonResponse {
        $guard->consume($submitLimiter, $request->getClientIp() ?? 'unknown');

        $submitter = $resolver->resolve($request);
        if ($submitter?->banned === true) {
            throw new ApiProblem(403, 'Submitter is banned');
        }

        $meta = $submission->parseSubmissionBody($request);
        $result = $submission->validatePayload($meta['payloadJson'], null, null);
        $payload = $result->payload;
        $formatId = $payload['id'];

        if ($entries->findOneBy(['formatId' => $formatId]) !== null) {
            throw new ApiProblem(409, 'formatId is already taken');
        }
        $hash = $submission->assertNoDuplicate($payload, null);

        $freshToken = null;
        if ($submitter === null) {
            $generated = $tokens->generate();
            $freshToken = $generated->token;
            $submitter = new Submitter($generated->selector, $generated->hash);
            $em->persist($submitter);
        }

        $entry = new Entry($formatId, EntryType::from($result->type), $submitter);
        $submission->applyMetadata($entry, $meta);
        $submission->refreshDerived($entry, $payload);

        $version = new EntryVersion($entry, $payload['version'], $payload, $hash);
        $version->changelog = $meta['changelog'];
        $version->hasTransformCode = $analyzer->hasTransform($payload);

        $em->persist($entry);
        $em->persist($version);
        $em->flush();

        $response = ['formatId' => $entry->formatId, 'status' => $entry->status->value];
        if ($freshToken !== null) {
            $response['editToken'] = $freshToken; // einzige Stelle, an der das Token je herausgegeben wird
        }

        return new JsonResponse($response, 201);
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter SubmitTest`
Erwartet: `OK (9 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze anonymes Einreichen mit Edit-Token" -m "Neueinreichungen starten immer pending (kein Trust-Bonus für anonyme
Submitter, Spec-Entscheidung). Token-Wiederverwendung hängt den
Eintrag an den vorhandenen Submitter; ohne Header wird das Token
serverseitig erzeugt und genau einmal zurückgegeben.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 12: PUT /entries/{formatId} — Update mit Sonderfällen

**Files:**
- Create: `backend/src/Controller/Api/EntryUpdateController.php`
- Modify: `backend/src/Repository/EntryVersionRepository.php` (Methode `maxSemver()`)
- Test: `backend/tests/Functional/UpdateTest.php`

**Interfaces:**
- Consumes: `SubmitterResolver::requireOwner()`, `SubmissionService` (Task 11).
- Produces: `PUT /api/v1/entries/{formatId}` → 200 `{formatId, versionStatus: "approved"|"pending"}`; `EntryVersionRepository::maxSemver(Entry $entry): ?string`.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Functional/UpdateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Enum\EntryStatus;
use App\Enum\VersionStatus;

final class UpdateTest extends ApiTestCase
{
    public function testUpdateWithoutTransformGoesLiveImmediately(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->api('PUT', '/api/v1/entries/com.example.shop', [
            'payload' => $this->menuPayload(['version' => '1.1.0', 'name' => 'Neuer Name']),
            'changelog' => 'Neuer Eintrag ergänzt',
        ], token: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('approved', $this->json()['versionStatus']);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame('1.1.0', $entry->currentVersion->semver);
        self::assertStringContainsString('neuer name', $entry->searchText);
    }

    public function testUpdateWithTransformGoesToQueue(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        // createPublishedEntry baut einen Menü-Payload; für den Engine-Fall
        // Typ und Payload des angelegten Entrys direkt umbiegen:
        $entry = $this->createPublishedEntry('com.example.search', submitter: $submitter);
        $entry->type = \App\Enum\EntryType::Engine;
        $entry->currentVersion->payload = $this->enginePayload(['id' => 'com.example.search']);
        $this->em->flush();

        $this->api('PUT', '/api/v1/entries/com.example.search', [
            'payload' => $this->enginePayload(['id' => 'com.example.search', 'version' => '1.1.0', 'transformEnabled' => true, 'transformCode' => 'return input.toUpperCase();']),
        ], token: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('pending', $this->json()['versionStatus']);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.search']);
        self::assertSame('1.0.0', $entry->currentVersion->semver); // bleibt auf alter Version
    }

    public function testNonMonotonicSemverYields409(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', ['version' => '2.0.0'], $submitter);

        $this->api('PUT', '/api/v1/entries/com.example.shop', [
            'payload' => $this->menuPayload(['version' => '1.9.0']),
        ], token: $token);

        self::assertResponseStatusCodeSame(409);
    }

    public function testForeignTokenYields403AndMissingTokenYields401(): void
    {
        $this->createPublishedEntry('com.example.shop');
        [, $foreignToken] = $this->createSubmitterWithToken();

        $this->api('PUT', '/api/v1/entries/com.example.shop', ['payload' => $this->menuPayload(['version' => '1.1.0'])], token: $foreignToken);
        self::assertResponseStatusCodeSame(403);

        $this->api('PUT', '/api/v1/entries/com.example.shop', ['payload' => $this->menuPayload(['version' => '1.1.0'])]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testPendingEntryUpdateReplacesPendingVersion(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $entry = $this->createPublishedEntry('com.example.shop', submitter: $submitter);
        $entry->status = EntryStatus::Pending;
        $entry->currentVersion->status = VersionStatus::Pending;
        $entry->currentVersion = null;
        $this->em->flush();

        $this->api('PUT', '/api/v1/entries/com.example.shop', [
            'payload' => $this->menuPayload(['version' => '1.0.1']),
        ], token: $token);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $versions = $this->em->getRepository(EntryVersion::class)->findBy([], ['id' => 'ASC']);
        $pending = array_filter($versions, static fn (EntryVersion $v): bool => $v->status === VersionStatus::Pending);
        self::assertCount(1, $pending); // alte pending-Version wurde ersetzt
        self::assertSame('1.0.1', array_values($pending)[0]->semver);
    }

    public function testHiddenYields409AndDeletedYields404(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $hidden = $this->createPublishedEntry('com.example.hidden', submitter: $submitter);
        $hidden->status = EntryStatus::Hidden;
        $deleted = $this->createPublishedEntry('com.example.deleted', submitter: $submitter);
        $deleted->status = EntryStatus::Deleted;
        $this->em->flush();

        $this->api('PUT', '/api/v1/entries/com.example.hidden', ['payload' => $this->menuPayload(['id' => 'com.example.hidden', 'version' => '1.1.0'])], token: $token);
        self::assertResponseStatusCodeSame(409);

        $this->api('PUT', '/api/v1/entries/com.example.deleted', ['payload' => $this->menuPayload(['id' => 'com.example.deleted', 'version' => '1.1.0'])], token: $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPayloadIdMismatchYields400(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->api('PUT', '/api/v1/entries/com.example.shop', [
            'payload' => $this->menuPayload(['id' => 'com.example.anders', 'version' => '1.1.0']),
        ], token: $token);

        self::assertResponseStatusCodeSame(400);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter UpdateTest`
Erwartet: FAIL (405/404, Route existiert nicht)

- [ ] **Step 3: Implementieren**

In `backend/src/Repository/EntryVersionRepository.php` ergänzen:

```php
    public function maxSemver(Entry $entry): ?string
    {
        $semvers = array_column(
            $this->createQueryBuilder('v')->select('v.semver')
                ->andWhere('v.entry = :entry')->setParameter('entry', $entry)
                ->getQuery()->getArrayResult(),
            'semver',
        );
        if ($semvers === []) {
            return null;
        }
        usort($semvers, static fn (string $a, string $b): int => version_compare($a, $b));

        return end($semvers);
    }
```

`backend/src/Controller/Api/EntryUpdateController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\EntryVersion;
use App\Enum\EntryStatus;
use App\Enum\VersionStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Service\PayloadAnalyzer;
use App\Service\RateLimitGuard;
use App\Service\SubmissionService;
use App\Service\SubmitterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class EntryUpdateController
{
    #[Route('/api/v1/entries/{formatId}', methods: ['PUT'])]
    public function __invoke(
        string $formatId,
        Request $request,
        SubmissionService $submission,
        SubmitterResolver $resolver,
        PayloadAnalyzer $analyzer,
        EntryRepository $entries,
        EntryVersionRepository $versions,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactory $updateLimiter,
    ): JsonResponse {
        $guard->consume($updateLimiter, $request->getClientIp() ?? 'unknown');

        $entry = $entries->findOneBy(['formatId' => $formatId]);
        if ($entry === null || $entry->status === EntryStatus::Deleted) {
            throw new ApiProblem(404, 'Entry not found');
        }
        $resolver->requireOwner($request, $entry);
        if ($entry->status === EntryStatus::Hidden) {
            throw new ApiProblem(409, 'Entry is hidden pending moderation');
        }

        $meta = $submission->parseSubmissionBody($request);
        $result = $submission->validatePayload($meta['payloadJson'], $entry->type, $entry->formatId);
        $payload = $result->payload;

        $maxSemver = $versions->maxSemver($entry);
        if ($maxSemver !== null && !version_compare($payload['version'], $maxSemver, '>')) {
            throw new ApiProblem(409, 'Version must be greater than ' . $maxSemver);
        }
        $hash = $submission->assertNoDuplicate($payload, $entry);

        $version = new EntryVersion($entry, $payload['version'], $payload, $hash);
        $version->changelog = $meta['changelog'];
        $version->hasTransformCode = $analyzer->hasTransform($payload);

        if ($entry->status === EntryStatus::Pending) {
            // Sonderfall Spec: wartende pending-Version wird ersetzt, Entry bleibt pending
            foreach ($versions->findBy(['entry' => $entry, 'status' => VersionStatus::Pending]) as $old) {
                $em->remove($old);
            }
        } elseif ($version->hasTransformCode) {
            // Transform-Updates umgehen NIE die Warteschlange (Supply-Chain-Schutz)
        } else {
            $version->status = VersionStatus::Approved;
            $entry->currentVersion = $version;
            $submission->refreshDerived($entry, $payload);
        }

        $submission->applyMetadata($entry, $meta);
        $entry->touch();
        $em->persist($version);
        $em->flush();

        return new JsonResponse(['formatId' => $entry->formatId, 'versionStatus' => $version->status->value]);
    }
}
```

Hinweis: `parseSubmissionBody()` verlangt `categories` — beim PUT sind sie optional. In `SubmissionService::parseSubmissionBody()` dafür einen Parameter ergänzen:

```php
    public function parseSubmissionBody(Request $request, bool $categoriesRequired = true): array
```

und die letzte Zeile des Category-Blocks ersetzen durch:

```php
        return [
            'payloadJson' => json_encode($body['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'categories' => $categories ?? ($categoriesRequired ? throw new ApiProblem(400, 'Between 1 and 3 categories required') : null),
            'tags' => $tags,
            'changelog' => $changelog,
            'deprecated' => isset($body['deprecated']) ? (bool) $body['deprecated'] : null,
            'successorFormatId' => $successor,
        ];
```

Der Update-Controller ruft `parseSubmissionBody($request, categoriesRequired: false)` auf; `applyMetadata()` überspringt `null`-Kategorien bereits. Außerdem in `applyMetadata()` die Tags nur ersetzen, wenn der Body sie enthielt — dafür `tags` analog nullable machen (`'tags' => \array_key_exists('tags', $body) ? $tags : null`) und in `applyMetadata()`:

```php
        if ($meta['tags'] !== null) {
            $entry->tags = $meta['tags'];
        }
```

(Task 11-Tests bleiben grün: dort ist `categories` gesetzt und `tags` explizit übergeben.)

- [ ] **Step 4: Tests laufen lassen — müssen bestehen (inkl. Task-11-Regression)**

Run: `php backend/bin/phpunit --filter 'UpdateTest|SubmitTest'`
Erwartet: alle grün

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze Versions-Updates mit Statusmaschine" -m "Updates ohne Transform gehen sofort live (voll validiert), Updates mit
transformCode immer in die Warteschlange — auch das ist die
Supply-Chain-Regel aus dem Spec. SemVer muss streng wachsen;
pending-Einträge ersetzen ihre wartende Version.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 13: DELETE /entries/{formatId}

**Files:**
- Create: `backend/src/Controller/Api/EntryDeleteController.php`
- Test: `backend/tests/Functional/DeleteTest.php`

**Interfaces:**
- Consumes: `SubmitterResolver::requireOwner()` (Task 11).
- Produces: `DELETE /api/v1/entries/{formatId}` → 204 (Soft-Delete: Status `deleted`, Daten bleiben für Admin-Nachvollzug).

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Functional/DeleteTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Enum\EntryStatus;

final class DeleteTest extends ApiTestCase
{
    public function testOwnerCanSoftDelete(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->api('DELETE', '/api/v1/entries/com.example.shop', token: $token);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(EntryStatus::Deleted, $entry->status);

        // öffentlich nicht mehr sichtbar
        $this->api('GET', '/api/v1/entries/com.example.shop');
        self::assertResponseStatusCodeSame(404);
    }

    public function testForeignTokenCannotDelete(): void
    {
        $this->createPublishedEntry('com.example.shop');
        [, $foreignToken] = $this->createSubmitterWithToken();

        $this->api('DELETE', '/api/v1/entries/com.example.shop', token: $foreignToken);
        self::assertResponseStatusCodeSame(403);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter DeleteTest`
Erwartet: FAIL (405/404)

- [ ] **Step 3: Implementieren**

`backend/src/Controller/Api/EntryDeleteController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\SubmitterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EntryDeleteController
{
    #[Route('/api/v1/entries/{formatId}', methods: ['DELETE'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        SubmitterResolver $resolver,
        EntityManagerInterface $em,
    ): Response {
        $entry = $entries->findOneBy(['formatId' => $formatId]);
        if ($entry === null || $entry->status === EntryStatus::Deleted) {
            throw new ApiProblem(404, 'Entry not found');
        }
        $resolver->requireOwner($request, $entry);

        $entry->status = EntryStatus::Deleted;
        $em->flush();

        return new Response('', 204);
    }
}
```

Zusätzlich in `ApiTestCase::api()` den `$token`-Parameter bereits vorhanden — kein Umbau nötig; `api('DELETE', ..., token: $token)` ruft die Methode mit `body: null` auf. Dafür die Signatur um benannte Parameter nutzbar halten (bereits gegeben: `api(string $method, string $uri, ?array $body = null, ?string $token = null)`).

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter DeleteTest`
Erwartet: `OK (2 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze Soft-Delete für eigene Einträge" -m "Status deleted statt Löschung: Payloads bleiben für den
Admin-Nachvollzug erhalten (Spec); öffentlich verschwindet der
Eintrag sofort.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 14: POST Report + Auto-Hide

**Files:**
- Create: `backend/src/Controller/Api/ReportController.php`
- Modify: `backend/src/Repository/ReportRepository.php` (Methode `countOpenFor()`)
- Modify: `backend/config/services.yaml` (Parameter-Bindings für Thresholds)
- Test: `backend/tests/Functional/ReportTest.php`

**Interfaces:**
- Consumes: `RateLimitGuard` + Limiter `report` (Task 6).
- Produces: `POST /api/v1/entries/{formatId}/report`, Body `{"reason": "spam|broken_links|misleading|legal", "comment"?: string}` → 204; ab `REPORT_HIDE_THRESHOLD` offenen Meldungen wird der Entry automatisch `hidden`. `ReportRepository::countOpenFor(Entry $entry): int`.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Functional/ReportTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Entity\Report;
use App\Enum\EntryStatus;

final class ReportTest extends ApiTestCase
{
    public function testReportIsStoredAnonymously(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->api('POST', '/api/v1/entries/com.example.shop/report', ['reason' => 'broken_links', 'comment' => 'Link 404']);

        self::assertResponseStatusCodeSame(204);
        $reports = $this->em->getRepository(Report::class)->findAll();
        self::assertCount(1, $reports);
        self::assertSame('Link 404', $reports[0]->comment);
    }

    public function testInvalidReasonYields400(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->api('POST', '/api/v1/entries/com.example.shop/report', ['reason' => 'gefaellt-mir-nicht']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testThresholdHidesEntry(): void
    {
        $this->createPublishedEntry('com.example.shop');

        for ($i = 0; $i < 3; ++$i) { // REPORT_HIDE_THRESHOLD = 3
            $this->api('POST', '/api/v1/entries/com.example.shop/report', ['reason' => 'spam']);
            self::assertResponseStatusCodeSame(204);
        }

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(EntryStatus::Hidden, $entry->status);

        // versteckte Einträge können nicht weiter gemeldet werden
        $this->api('POST', '/api/v1/entries/com.example.shop/report', ['reason' => 'spam']);
        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter ReportTest`
Erwartet: FAIL (404)

- [ ] **Step 3: Implementieren**

In `backend/config/services.yaml` unter `parameters:` ergänzen:

```yaml
parameters:
    app.report_hide_threshold: '%env(int:REPORT_HIDE_THRESHOLD)%'
    app.trust_threshold: '%env(int:TRUST_THRESHOLD)%'
```

In `backend/src/Repository/ReportRepository.php` ergänzen (Imports: `App\Entity\Entry`, `App\Enum\ReportStatus`):

```php
    public function countOpenFor(Entry $entry): int
    {
        return $this->count(['entry' => $entry, 'status' => ReportStatus::Open]);
    }
```

`backend/src/Controller/Api/ReportController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Report;
use App\Enum\EntryStatus;
use App\Enum\ReportReason;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\ReportRepository;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController
{
    #[Route('/api/v1/entries/{formatId}/report', methods: ['POST'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        ReportRepository $reports,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactory $reportLimiter,
        #[Autowire('%app.report_hide_threshold%')] int $hideThreshold,
    ): Response {
        $guard->consume($reportLimiter, $request->getClientIp() ?? 'unknown');

        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        try {
            $body = json_decode($request->getContent(), true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }

        $reason = \is_string($body['reason'] ?? null)
            ? (ReportReason::tryFrom($body['reason']) ?? throw new ApiProblem(400, 'Unknown reason'))
            : throw new ApiProblem(400, 'Unknown reason');

        $comment = $body['comment'] ?? null;
        if ($comment !== null && (!\is_string($comment) || mb_strlen($comment) > 2000)) {
            throw new ApiProblem(400, 'Invalid comment');
        }

        $em->persist(new Report($entry, $reason, $comment));
        $em->flush();

        if ($reports->countOpenFor($entry) >= $hideThreshold) {
            $entry->status = EntryStatus::Hidden; // automatisch bis zur Admin-Prüfung
            $em->flush();
        }

        return new Response('', 204);
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter ReportTest`
Erwartet: `OK (3 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze anonyme Meldungen mit Auto-Hide" -m "Feste Meldegründe statt Freitext-Pflicht, keinerlei Kennung des
Meldenden; ab dem konfigurierbaren Schwellwert offener Meldungen
verschwindet der Eintrag automatisch bis zur Prüfung.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 15: Screenshot-Upload (GD → WebP)

**Files:**
- Create: `backend/src/Service/ScreenshotProcessor.php`, `backend/src/Controller/Api/ScreenshotController.php`, `backend/public/media/.gitignore`
- Test: `backend/tests/Functional/ScreenshotTest.php`

**Interfaces:**
- Consumes: `SubmitterResolver::requireOwner()` (Task 11).
- Produces: `POST /api/v1/entries/{formatId}/screenshot` (Multipart-Feld `screenshot`) → 200 `{screenshotUrl}`; `ScreenshotProcessor::process(string $sourcePath): string` (WebP-Binärdaten, max. 1280×800, wirft `ApiProblem` 400 bei Nicht-Bildern).

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Functional/ScreenshotTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ScreenshotTest extends ApiTestCase
{
    private function makePngUpload(int $width = 1600, int $height = 1000): UploadedFile
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 90, 156, 246));
        $path = tempnam(sys_get_temp_dir(), 'shot') . '.png';
        imagepng($img, $path);
        imagedestroy($img);

        return new UploadedFile($path, 'screenshot.png', 'image/png', test: true);
    }

    public function testUploadReencodesToWebpAndScalesDown(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->client->request('POST', '/api/v1/entries/com.example.shop/screenshot',
            files: ['screenshot' => $this->makePngUpload()],
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertResponseIsSuccessful();
        $url = $this->json()['screenshotUrl'];
        self::assertSame('/media/screenshots/com.example.shop.webp', $url);

        $file = static::getContainer()->getParameter('kernel.project_dir') . '/public' . $url;
        self::assertFileExists($file);
        [$w, $h] = getimagesize($file);
        self::assertLessThanOrEqual(1280, $w);
        self::assertLessThanOrEqual(800, $h);
        self::assertSame('image/webp', mime_content_type($file));

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame('media/screenshots/com.example.shop.webp', $entry->screenshotPath);

        unlink($file); // Testartefakt aufräumen (Dateisystem rollt nicht zurück)
    }

    public function testNonImageYields400(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $path = tempnam(sys_get_temp_dir(), 'fake') . '.png';
        file_put_contents($path, 'kein bild');
        $upload = new UploadedFile($path, 'fake.png', 'image/png', test: true);

        $this->client->request('POST', '/api/v1/entries/com.example.shop/screenshot',
            files: ['screenshot' => $upload],
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        self::assertResponseStatusCodeSame(400);
    }

    public function testMissingTokenYields401(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->client->request('POST', '/api/v1/entries/com.example.shop/screenshot',
            files: ['screenshot' => $this->makePngUpload(100, 100)],
        );
        self::assertResponseStatusCodeSame(401);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter ScreenshotTest`
Erwartet: FAIL (404)

- [ ] **Step 3: Implementieren**

`backend/public/media/.gitignore`:

```gitignore
*
!.gitignore
```

`backend/src/Service/ScreenshotProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiProblem;

/**
 * Re-enkodiert Uploads IMMER neu (GD): schneidet eingebettete Metadaten
 * und potentiell präparierte Container ab — es wird nie das Original
 * gespeichert (Spec, Abschnitt Screenshots).
 */
final class ScreenshotProcessor
{
    private const MAX_WIDTH = 1280;
    private const MAX_HEIGHT = 800;
    private const WEBP_QUALITY = 82;

    /** @return string WebP-Binärdaten */
    public function process(string $sourcePath): string
    {
        $raw = @file_get_contents($sourcePath);
        $image = $raw === false ? false : @imagecreatefromstring($raw);
        if ($image === false) {
            throw new ApiProblem(400, 'File is not a decodable image');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height, 1.0);
        if ($scale < 1.0) {
            $scaled = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC);
            imagedestroy($image);
            if ($scaled === false) {
                throw new ApiProblem(400, 'Image could not be processed');
            }
            $image = $scaled;
        }

        ob_start();
        $ok = imagewebp($image, null, self::WEBP_QUALITY);
        $webp = (string) ob_get_clean();
        imagedestroy($image);
        if (!$ok || $webp === '') {
            throw new ApiProblem(400, 'Image could not be encoded');
        }

        return $webp;
    }
}
```

`backend/src/Controller/Api/ScreenshotController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\ScreenshotProcessor;
use App\Service\SubmitterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ScreenshotController
{
    private const UPLOAD_MAX = 2 * 1024 * 1024; // 2 MB

    #[Route('/api/v1/entries/{formatId}/screenshot', methods: ['POST'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        SubmitterResolver $resolver,
        ScreenshotProcessor $processor,
        EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ): JsonResponse {
        $entry = $entries->findOneBy(['formatId' => $formatId]);
        if ($entry === null || $entry->status === EntryStatus::Deleted) {
            throw new ApiProblem(404, 'Entry not found');
        }
        $resolver->requireOwner($request, $entry);

        $upload = $request->files->get('screenshot');
        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
            throw new ApiProblem(400, 'Multipart field "screenshot" required');
        }
        if ($upload->getSize() > self::UPLOAD_MAX) {
            throw new ApiProblem(413, 'Screenshot larger than 2 MB');
        }

        $webp = $processor->process($upload->getPathname());

        $relative = 'media/screenshots/' . $entry->formatId . '.webp';
        $target = $projectDir . '/public/' . $relative;
        if (!is_dir(\dirname($target))) {
            mkdir(\dirname($target), 0775, true);
        }
        file_put_contents($target, $webp);

        $entry->screenshotPath = $relative;
        $entry->touch();
        $em->flush();

        return new JsonResponse(['screenshotUrl' => '/' . $relative]);
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter ScreenshotTest`
Erwartet: `OK (3 tests)`

- [ ] **Step 5: Commit**

```bash
git add backend/ && git commit -m "Ergänze Screenshot-Upload mit WebP-Re-Encoding" -m "GD dekodiert und enkodiert jeden Upload neu (nie das Original
speichern, keine Fremd-URLs), skaliert auf maximal 1280x800 und legt
das Ergebnis statisch ausgeliefert unter public/media ab.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 16: ModerationService + Console-Kommandos

**Files:**
- Create: `backend/src/Service/ModerationService.php`, `backend/src/Command/ModerationQueueCommand.php`, `backend/src/Command/ModerationApproveCommand.php`, `backend/src/Command/ModerationRejectCommand.php`, `backend/src/Command/ModerationReportsCommand.php`, `backend/src/Command/ModerationResolveCommand.php`, `backend/src/Command/ModerationBanCommand.php`
- Test: `backend/tests/Command/ModerationCommandsTest.php`

**Interfaces:**
- Produces: `ModerationService::approveEntry(Entry): void`, `::rejectEntry(Entry): void`, `::approveVersion(EntryVersion): void`, `::rejectVersion(EntryVersion): void`, `::resolveReport(Report, bool $publish): void`, `::ban(Submitter): void`, `::unban(Submitter): void`. Konsole: `index:queue`, `index:approve <formatId>`, `index:reject <formatId>`, `index:reports [--all]`, `index:resolve <reportId> --action=publish|delete`, `index:ban <submitterId>` / `index:ban <submitterId> --unban`.

- [ ] **Step 1: Fehlschlagenden Test schreiben**

`backend/tests/Command/ModerationCommandsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Enum\VersionStatus;
use App\Service\PayloadAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ModerationCommandsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Application $console;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->console = new Application(self::$kernel);
    }

    private function createPendingEntry(string $formatId = 'com.example.neu'): Entry
    {
        $submitter = new Submitter(bin2hex(random_bytes(8)), 'hash');
        $entry = new Entry($formatId, EntryType::Menu, $submitter);
        $entry->setCategories([Category::Other]);
        $payload = ['gesturaMenu' => 1, 'id' => $formatId, 'version' => '1.0.0', 'name' => 'Neu',
            'items' => [['id' => 'a', 'label' => 'A', 'action' => 'newTab']]];
        $version = new EntryVersion($entry, '1.0.0', $payload, (new PayloadAnalyzer())->contentHash($payload));
        $this->em->persist($submitter);
        $this->em->persist($entry);
        $this->em->persist($version);
        $this->em->flush();

        return $entry;
    }

    private function run(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->console->find($name));
        $tester->execute($input);

        return $tester;
    }

    public function testQueueListsPendingEntries(): void
    {
        $this->createPendingEntry();

        $tester = $this->run('index:queue');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('com.example.neu', $tester->getDisplay());
    }

    public function testApprovePublishesEntryAndCountsApproval(): void
    {
        $entry = $this->createPendingEntry();

        $tester = $this->run('index:approve', ['formatId' => 'com.example.neu']);

        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame(EntryStatus::Published, $entry->status);
        self::assertSame(VersionStatus::Approved, $entry->currentVersion->status);
        self::assertSame('1.0.0', $entry->currentVersion->semver);
        self::assertSame(1, $entry->submitter->approvedCount);
        self::assertNotSame('', $entry->searchText);
    }

    public function testRejectDeletesEntry(): void
    {
        $this->createPendingEntry();

        $this->run('index:reject', ['formatId' => 'com.example.neu'])->assertCommandIsSuccessful();

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame(EntryStatus::Deleted, $entry->status);
    }

    public function testBanHidesAllEntriesOfSubmitter(): void
    {
        $entry = $this->createPendingEntry();
        $entry->status = EntryStatus::Published;
        $this->em->flush();
        $submitterId = $entry->submitter->id;

        $this->run('index:ban', ['submitterId' => (string) $submitterId])->assertCommandIsSuccessful();

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertTrue($entry->submitter->banned);
        self::assertSame(EntryStatus::Hidden, $entry->status);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php backend/bin/phpunit --filter ModerationCommandsTest`
Erwartet: FAIL (Command "index:queue" is not defined)

- [ ] **Step 3: ModerationService implementieren**

`backend/src/Service/ModerationService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Report;
use App\Entity\Submitter;
use App\Enum\EntryStatus;
use App\Enum\ReportStatus;
use App\Enum\VersionStatus;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
        private readonly SubmissionService $submission,
    ) {
    }

    public function approveEntry(Entry $entry): void
    {
        $pending = $this->versions->findOneBy(['entry' => $entry, 'status' => VersionStatus::Pending])
            ?? throw new \RuntimeException('Keine wartende Version für ' . $entry->formatId);
        $this->approveVersion($pending);
        $entry->status = EntryStatus::Published;
        ++$entry->submitter->approvedCount;
        $entry->touch();
        $this->em->flush();
    }

    public function rejectEntry(Entry $entry): void
    {
        foreach ($this->versions->findBy(['entry' => $entry, 'status' => VersionStatus::Pending]) as $version) {
            $version->status = VersionStatus::Rejected;
        }
        $entry->status = EntryStatus::Deleted;
        $entry->touch();
        $this->em->flush();
    }

    public function approveVersion(EntryVersion $version): void
    {
        $version->status = VersionStatus::Approved;
        $entry = $version->entry;
        $entry->currentVersion = $version;
        $this->submission->refreshDerived($entry, $version->payload);
        $entry->touch();
        $this->em->flush();
    }

    public function rejectVersion(EntryVersion $version): void
    {
        $version->status = VersionStatus::Rejected;
        $this->em->flush();
    }

    public function resolveReport(Report $report, bool $publish): void
    {
        $report->status = ReportStatus::Resolved;
        $report->entry->status = $publish ? EntryStatus::Published : EntryStatus::Deleted;
        $report->entry->touch();
        $this->em->flush();
    }

    public function ban(Submitter $submitter): void
    {
        $submitter->banned = true;
        foreach ($this->entries->findBy(['submitter' => $submitter]) as $entry) {
            if ($entry->status !== EntryStatus::Deleted) {
                $entry->status = EntryStatus::Hidden;
                $entry->touch();
            }
        }
        $this->em->flush();
    }

    public function unban(Submitter $submitter): void
    {
        $submitter->banned = false; // Einträge bleiben hidden — Freigabe je Eintrag per index:approve/resolve
        $this->em->flush();
    }
}
```

- [ ] **Step 4: Commands implementieren**

`backend/src/Command/ModerationQueueCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\EntryStatus;
use App\Enum\VersionStatus;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'index:queue', description: 'Zeigt die Moderations-Warteschlange')]
final class ModerationQueueCommand extends Command
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('Neue Einträge (pending)');
        $rows = [];
        foreach ($this->entries->findBy(['status' => EntryStatus::Pending], ['createdAt' => 'ASC']) as $entry) {
            $rows[] = [$entry->formatId, $entry->type->value, $entry->createdAt->format('Y-m-d H:i')];
        }
        $rows === [] ? $io->text('leer') : $io->table(['formatId', 'Typ', 'eingereicht'], $rows);

        $io->section('Wartende Versionen veröffentlichter Einträge (Transform-Queue)');
        $rows = [];
        foreach ($this->versions->findBy(['status' => VersionStatus::Pending], ['submittedAt' => 'ASC']) as $version) {
            if ($version->entry->status === EntryStatus::Published) {
                $rows[] = [$version->entry->formatId, $version->semver, $version->hasTransformCode ? 'ja' : 'nein', $version->submittedAt->format('Y-m-d H:i')];
            }
        }
        $rows === [] ? $io->text('leer') : $io->table(['formatId', 'Version', 'Skript', 'eingereicht'], $rows);

        return Command::SUCCESS;
    }
}
```

`backend/src/Command/ModerationApproveCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\EntryStatus;
use App\Enum\VersionStatus;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Service\ModerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'index:approve', description: 'Gibt einen wartenden Eintrag oder eine wartende Version frei')]
final class ModerationApproveCommand extends Command
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('formatId', InputArgument::REQUIRED, 'formatId des Eintrags');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $entry = $this->entries->findOneBy(['formatId' => $input->getArgument('formatId')]);
        if ($entry === null) {
            $io->error('Unbekannte formatId');

            return Command::FAILURE;
        }

        if ($entry->status === EntryStatus::Pending) {
            $this->moderation->approveEntry($entry);
            $io->success($entry->formatId . ' veröffentlicht');

            return Command::SUCCESS;
        }

        $pending = $this->versions->findOneBy(['entry' => $entry, 'status' => VersionStatus::Pending]);
        if ($pending !== null) {
            $this->moderation->approveVersion($pending);
            $io->success($entry->formatId . ' ' . $pending->semver . ' freigegeben');

            return Command::SUCCESS;
        }

        $io->warning('Nichts freizugeben');

        return Command::FAILURE;
    }
}
```

`backend/src/Command/ModerationRejectCommand.php` (identischer Aufbau, gespiegelte Logik):

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\EntryStatus;
use App\Enum\VersionStatus;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Service\ModerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'index:reject', description: 'Lehnt einen wartenden Eintrag oder eine wartende Version ab')]
final class ModerationRejectCommand extends Command
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('formatId', InputArgument::REQUIRED, 'formatId des Eintrags');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $entry = $this->entries->findOneBy(['formatId' => $input->getArgument('formatId')]);
        if ($entry === null) {
            $io->error('Unbekannte formatId');

            return Command::FAILURE;
        }

        if ($entry->status === EntryStatus::Pending) {
            $this->moderation->rejectEntry($entry);
            $io->success($entry->formatId . ' abgelehnt (deleted)');

            return Command::SUCCESS;
        }

        $pending = $this->versions->findOneBy(['entry' => $entry, 'status' => VersionStatus::Pending]);
        if ($pending !== null) {
            $this->moderation->rejectVersion($pending);
            $io->success($entry->formatId . ' ' . $pending->semver . ' abgelehnt');

            return Command::SUCCESS;
        }

        $io->warning('Nichts abzulehnen');

        return Command::FAILURE;
    }
}
```

`backend/src/Command/ModerationReportsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ReportStatus;
use App\Repository\ReportRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'index:reports', description: 'Zeigt Meldungen (Standard: nur offene)')]
final class ModerationReportsCommand extends Command
{
    public function __construct(private readonly ReportRepository $reports)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Auch erledigte Meldungen anzeigen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $criteria = $input->getOption('all') ? [] : ['status' => ReportStatus::Open];

        $rows = [];
        foreach ($this->reports->findBy($criteria, ['createdAt' => 'ASC']) as $report) {
            $rows[] = [$report->id, $report->entry->formatId, $report->reason->value, $report->status->value,
                mb_strimwidth((string) $report->comment, 0, 60, '…'), $report->createdAt->format('Y-m-d H:i')];
        }
        $rows === [] ? $io->text('Keine Meldungen') : $io->table(['ID', 'formatId', 'Grund', 'Status', 'Kommentar', 'gemeldet'], $rows);

        return Command::SUCCESS;
    }
}
```

`backend/src/Command/ModerationResolveCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ReportRepository;
use App\Service\ModerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'index:resolve', description: 'Erledigt eine Meldung: Eintrag wieder veröffentlichen oder löschen')]
final class ModerationResolveCommand extends Command
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('reportId', InputArgument::REQUIRED, 'ID der Meldung');
        $this->addOption('action', null, InputOption::VALUE_REQUIRED, 'publish oder delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->reports->find((int) $input->getArgument('reportId'));
        $action = $input->getOption('action');
        if ($report === null || !\in_array($action, ['publish', 'delete'], true)) {
            $io->error('Unbekannte Meldung oder --action fehlt (publish|delete)');

            return Command::FAILURE;
        }

        $this->moderation->resolveReport($report, $action === 'publish');
        $io->success('Meldung erledigt, Eintrag ' . $report->entry->formatId . ' → ' . $report->entry->status->value);

        return Command::SUCCESS;
    }
}
```

`backend/src/Command/ModerationBanCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SubmitterRepository;
use App\Service\ModerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'index:ban', description: 'Sperrt einen Submitter (alle Einträge → hidden) oder hebt die Sperre auf')]
final class ModerationBanCommand extends Command
{
    public function __construct(
        private readonly SubmitterRepository $submitters,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('submitterId', InputArgument::REQUIRED, 'ID des Submitters');
        $this->addOption('unban', null, InputOption::VALUE_NONE, 'Sperre aufheben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $submitter = $this->submitters->find((int) $input->getArgument('submitterId'));
        if ($submitter === null) {
            $io->error('Unbekannter Submitter');

            return Command::FAILURE;
        }

        if ($input->getOption('unban')) {
            $this->moderation->unban($submitter);
            $io->success('Sperre aufgehoben (Einträge bleiben hidden — je Eintrag per index:approve freigeben)');
        } else {
            $this->moderation->ban($submitter);
            $io->success('Submitter gesperrt, alle Einträge versteckt');
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 5: Test laufen lassen — muss bestehen**

Run: `php backend/bin/phpunit --filter ModerationCommandsTest`
Erwartet: `OK (4 tests)`

- [ ] **Step 6: Commit**

```bash
git add backend/ && git commit -m "Ergänze Moderations-Statusmaschine mit Konsole" -m "Bis zum Admin-Sub-Projekt läuft die Moderation per SSH über
bin/console: Warteschlange, Freigabe/Ablehnung (Entry wie Version),
Meldungs-Auflösung und Sperren inkl. Hidden-Kaskade.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 17: Abschluss — Backend-README und Gesamtverifikation

**Files:**
- Create: `backend/README.md`
- Modify: `CLAUDE.md` (Testbefehl bestätigen — nur falls abweichend)

**Interfaces:**
- Consumes: alles Vorherige.

- [ ] **Step 1: Backend-README schreiben**

`backend/README.md`:

```markdown
# gestura-index Backend

Symfony-JSON-API des Gestura-Index. Spec: `../docs/superpowers/specs/2026-07-20-index-backend-core-design.md`.

## Entwicklung

```bash
composer install                     # im Ordner backend/
php -S localhost:8000 -t public      # Dev-Server
php bin/phpunit                      # Tests (MariaDB gestura_index_test, dama-Rollback)
```

Lokale DB-Zugangsdaten liegen in `.env.local` / `.env.test.local` (nicht im Repo).

## Endpunkte (alle unter /api/v1)

| Methode | Pfad | Auth | Zweck |
| --- | --- | --- | --- |
| GET | /entries | – | Stöbern (q, site, category, tag, type, sort, page, perPage) |
| GET | /entries/{formatId} | – | Detail + freigegebene Versionen |
| GET | /entries/{formatId}/versions/{semver} | – | Format-JSON herunterladen |
| POST | /entries/updates | – | Update-Check (Liste id+version) |
| POST | /entries/{formatId}/install | – | Install-Ping nach bestätigtem Import |
| POST | /entries | optional Token | Einreichen (erzeugt ggf. Edit-Token) |
| PUT | /entries/{formatId} | Token | Neue Version / Metadaten |
| DELETE | /entries/{formatId} | Token | Soft-Delete |
| POST | /entries/{formatId}/report | – | Melden (fester Grund) |
| POST | /entries/{formatId}/screenshot | Token | Screenshot (WebP-Re-Encoding) |

## Moderation (bis Admin-Panel existiert)

`php bin/console index:queue | index:approve <formatId> | index:reject <formatId> | index:reports | index:resolve <id> --action=publish|delete | index:ban <submitterId> [--unban]`
```

- [ ] **Step 2: Gesamtverifikation**

```bash
php backend/bin/phpunit
php backend/bin/console lint:container
php backend/bin/console doctrine:schema:validate
```

Erwartet: alle Tests grün; Container valide; Schema synchron mit Mapping.

- [ ] **Step 3: Commit**

```bash
git add backend/README.md CLAUDE.md && git commit -m "Ergänze Backend-README mit Endpunkt-Übersicht" -m "Kurzreferenz für Entwicklung, Endpunkte und die
Console-Moderation, bis das Admin-Panel (Sub-Projekt 4) existiert.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```




