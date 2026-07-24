# Sterne-Bewertungen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Konto-basierte 1–5-Sterne-Bewertungen (eine pro Konto & Eintrag) mit optionalem Kurzkommentar; der Stern zählt sofort in ein lesend berechnetes öffentliches Aggregat, der Kommentar durchläuft eine Hybrid-Moderation nach Vertrauen.

**Architecture:** Neue `Rating`-Entity + `CommentStatus`-Enum. Dünne Controller unter `/api/v1/entries/{formatId}/rating` (Konto-Bearer) + öffentlicher `/reviews`-Endpunkt; Schreiblogik in `RatingService`. Aggregate lesend über `RatingRepository::aggregatesFor()` (eine gruppierte Query, kein N+1), eingespeist in `EntrySerializer`. Kommentar-Moderation über `ModerationService` + CLI. E-Selbstauskunft wird um einen `ratings`-Block erweitert.

**Tech Stack:** Symfony 7.4, Doctrine ORM/MariaDB, PHPUnit (`failOnDeprecation="true"`).

**Spec:** `docs/superpowers/specs/2026-07-24-phase3-sterne-bewertungen-design.md`

## Global Constraints

- **`Rating`:** `stars` int **1–5**; `comment` nullable, max. **500** Zeichen (`Rating::MAX_COMMENT_LENGTH`); `commentStatus` Enum `CommentStatus` (`pending`|`approved`|`rejected`), Default `approved` (nichts zu moderieren, wenn kein Kommentar). **`UNIQUE(account_id, entry_id)`**.
- **Beide FKs** (`account`, `entry`) tragen DB-seitiges **`ON DELETE CASCADE`**.
- **Aggregate lesend berechnet** – **keine** denormalisierten Zähler auf `Entry`. `average` auf **1 Nachkommastelle** gerundet (`round($avg, 1)`); `count` = Gesamtzahl aller Bewertungen (unabhängig vom Kommentar-Status); ohne Bewertungen `{average: null, count: 0}`.
- **Validierungsfehler → HTTP `400`** (projektweite Konvention; das Repo nutzt kein 422). `stars` fehlt/kein int/außerhalb 1–5 → 400; `comment` kein String / > 500 → 400; ungültiges JSON → 400.
- **Guards (PUT):** unveröffentlichter Eintrag → `404`; **eigener Eintrag** (`entry.submitter->account?->id === account.id`) → `403`; gesperrtes Konto-Bündel (`SubmitterRepository::hasBannedForAccount`) → `403`; fehlendes/ungültiges Token → `401`.
- **Trust-Hybrid (nur Kommentar):** `SubmitterRepository::sumApprovedCountForAccount($account) >= SubmissionService::TRUST_THRESHOLD` (=3) ⇒ `commentStatus = approved`; sonst `pending`. **Der Stern zählt in beiden Fällen sofort.** Geänderter Kommentar wird neu bewertet; Kommentar `null`/`''` ⇒ `commentStatus = approved`, kein Text.
- **Reviews** öffentlich, **anonym** (kein Autor-Feld), nur `comment !== null && commentStatus = approved`, paginiert (`page`, `perPage ≤ 50`).
- **Auth** über bestehenden `AccountResolver` (Bearer `gacc_`). Neuer Rate-Limiter **`rating_write`** (PUT/DELETE), großzügig (1000) in `when@test`/`when@dev`.
- **Moderation nur per CLI** in D (keine Admin-API). Admin-API + Frontend sind der Folgeblock.
- Alle Entities haben **public properties** (keine Getter). Lokale PHP-CLI ist **`php`** (nicht `php85`). Testlauf immer mit Exit-Code prüfen: `php backend/bin/phpunit …; echo "EXIT:$?"` – die Suite läuft mit `failOnDeprecation="true"` (kann »OK« zeigen und trotzdem Exit 1).
- **E-Kopplung:** `AccountDataAssembler` (aus E) bekommt einen `ratings`-Block.

---

### Task 1: `Rating`-Entity + `CommentStatus`-Enum + `RatingRepository` + Migration

Datenfundament. Deliverable: Schema (rating-Tabelle mit beiden CASCADE-FKs + Unique) und ein Repository mit den Aggregat-/Kommentar-Queries. Test: Persistenz, Unique-Constraint, `aggregatesFor()`-Gruppierung, `approvedComments`/`pendingComments`-Filter.

**Files:**
- Create: `backend/src/Enum/CommentStatus.php`
- Create: `backend/src/Entity/Rating.php`
- Create: `backend/src/Repository/RatingRepository.php`
- Create: `backend/migrations/VersionXXXXXXXXXXXXXX.php` (generiert)
- Test: `backend/tests/Functional/Api/RatingRepositoryTest.php`

**Interfaces:**
- Produces: `Rating` (public props `id, account, entry, stars, comment, commentStatus, createdAt, updatedAt`, const `MAX_COMMENT_LENGTH = 500`, ctor `(Account, Entry, int $stars)`); `CommentStatus` enum; `RatingRepository::findForAccountAndEntry(Account, Entry): ?Rating`, `aggregatesFor(int[]): array<int,{average:float,count:int}>`, `approvedComments(Entry,int $offset,int $limit): Rating[]`, `countApprovedComments(Entry): int`, `pendingComments(): Rating[]`.

- [ ] **Step 1: Enum anlegen**

Create `backend/src/Enum/CommentStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Moderationsstatus des optionalen Freitext-Kommentars einer Bewertung (Phase 3 D).
 * Der Stern-Wert ist davon unabhängig und zählt immer sofort ins Aggregat.
 */
enum CommentStatus: string
{
    case Pending = 'pending';    // Kommentar in der Moderations-Warteschlange
    case Approved = 'approved';  // freigegeben (öffentlich in /reviews sichtbar)
    case Rejected = 'rejected';  // abgelehnt – Text ausgeblendet, Stern zählt weiter
}
```

- [ ] **Step 2: Entity anlegen**

Create `backend/src/Entity/Rating.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommentStatus;
use App\Repository\RatingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sterne-Bewertung eines Kontos zu einem Eintrag (Phase 3 D). Genau eine
 * Bewertung pro Konto und Eintrag (UNIQUE). stars (1–5) zählt immer sofort in
 * das lesend berechnete öffentliche Aggregat; der optionale comment durchläuft
 * eine Hybrid-Moderation nach Vertrauen (commentStatus). Beide FKs tragen
 * DB-seitiges ON DELETE CASCADE, damit Konto-Löschung (inkl. des
 * index:account:prune-Bulk-DELETE an der ORM vorbei) und Eintrag-Löschung die
 * Bewertung zuverlässig mitnehmen – da die Aggregate lesend berechnet werden,
 * stimmen sie danach ohne Nachpflege.
 */
#[ORM\Entity(repositoryClass: RatingRepository::class)]
#[ORM\Table(name: 'rating')]
#[ORM\UniqueConstraint(columns: ['account_id', 'entry_id'])]
class Rating
{
    /** Maximale Kommentarlänge in Zeichen. */
    public const MAX_COMMENT_LENGTH = 500;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Account $account;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Entry $entry;

    #[ORM\Column]
    public int $stars;

    #[ORM\Column(length: self::MAX_COMMENT_LENGTH, nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(length: 10, enumType: CommentStatus::class)]
    public CommentStatus $commentStatus = CommentStatus::Approved;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(Account $account, Entry $entry, int $stars)
    {
        $this->account = $account;
        $this->entry = $entry;
        $this->stars = $stars;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
```

- [ ] **Step 3: Repository anlegen**

Create `backend/src/Repository/RatingRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\Rating;
use App\Enum\CommentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rating>
 */
final class RatingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rating::class);
    }

    public function findForAccountAndEntry(Account $account, Entry $entry): ?Rating
    {
        return $this->findOneBy(['account' => $account, 'entry' => $entry]);
    }

    /**
     * Aggregiert Ø-Sterne und Anzahl je Eintrag in EINER gruppierten Query
     * (kein N+1). Fehlende IDs erscheinen nicht in der Map.
     *
     * @param int[] $entryIds
     *
     * @return array<int, array{average: float, count: int}> keyed by entry id
     */
    public function aggregatesFor(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.entry) AS entryId', 'AVG(r.stars) AS average', 'COUNT(r.id) AS cnt')
            ->where('r.entry IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->groupBy('r.entry')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['entryId']] = [
                'average' => round((float) $row['average'], 1),
                'count' => (int) $row['cnt'],
            ];
        }

        return $out;
    }

    /**
     * Paginierte, freigegebene Kommentare eines Eintrags (neueste zuerst).
     *
     * @return Rating[]
     */
    public function approvedComments(Entry $entry, int $offset, int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.entry = :entry')
            ->andWhere('r.comment IS NOT NULL')
            ->andWhere('r.commentStatus = :approved')
            ->setParameter('entry', $entry)
            ->setParameter('approved', CommentStatus::Approved)
            ->orderBy('r.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countApprovedComments(Entry $entry): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.entry = :entry')
            ->andWhere('r.comment IS NOT NULL')
            ->andWhere('r.commentStatus = :approved')
            ->setParameter('entry', $entry)
            ->setParameter('approved', CommentStatus::Approved)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Alle Bewertungen mit wartendem Kommentar (Moderations-Warteschlange),
     * älteste zuerst.
     *
     * @return Rating[]
     */
    public function pendingComments(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.comment IS NOT NULL')
            ->andWhere('r.commentStatus = :pending')
            ->setParameter('pending', CommentStatus::Pending)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
```

- [ ] **Step 4: Migration generieren und prüfen**

Zuerst die dev-DB auf den aktuellen Stand bringen (damit der Diff NUR die neue Tabelle enthält, nicht ältere Rückstände):

```bash
php backend/bin/console doctrine:migrations:migrate --no-interaction
php backend/bin/console doctrine:migrations:diff --no-interaction
```

Öffne die neu erzeugte Datei in `backend/migrations/`. **Verifiziere**, dass sie **ausschließlich**:
- `CREATE TABLE rating (…)` mit Spalten `id, account_id, entry_id, stars, comment VARCHAR(500) DEFAULT NULL, comment_status VARCHAR(10) NOT NULL, created_at, updated_at`,
- einen **UNIQUE INDEX** auf `(account_id, entry_id)`,
- zwei `ADD CONSTRAINT … FOREIGN KEY … REFERENCES account/entry … ON DELETE CASCADE`

enthält – und **keine unerwarteten** DROP/ALTER an anderen Tabellen. Falls doch (DB-Drift), **STOP** und melden, nicht blind übernehmen.

- [ ] **Step 5: Migration auf dev- UND test-DB anwenden**

```bash
php backend/bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=test php backend/bin/console doctrine:migrations:migrate --no-interaction
```

Erwartet: beide Läufe enden mit »successfully migrated«. (Die Test-DB heißt `gestura_index_test` – Suffix `_test` aus `doctrine.yaml`; ohne diesen Schritt fehlt die `rating`-Tabelle in der Testsuite.)

- [ ] **Step 6: Repository-Test schreiben**

Create `backend/tests/Functional/Api/RatingRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Rating;
use App\Enum\CommentStatus;
use App\Repository\RatingRepository;
use App\Tests\Functional\ApiTestCase;

final class RatingRepositoryTest extends ApiTestCase
{
    private function repo(): RatingRepository
    {
        return $this->em->getRepository(Rating::class);
    }

    private function makeAccount(string $selector): Account
    {
        $account = new Account($selector, 'hash-' . $selector);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function testAggregatesForGroupsByEntry(): void
    {
        $entry = $this->createPublishedEntry('com.example.agg');
        $a1 = $this->makeAccount(str_pad('a', 16, 'a'));
        $a2 = $this->makeAccount(str_pad('b', 16, 'b'));
        $this->em->persist(new Rating($a1, $entry, 4));
        $this->em->persist(new Rating($a2, $entry, 5));
        $this->em->flush();

        $agg = $this->repo()->aggregatesFor([$entry->id]);
        self::assertSame(4.5, $agg[$entry->id]['average']);
        self::assertSame(2, $agg[$entry->id]['count']);

        // Unbewerteter Eintrag fehlt in der Map:
        $other = $this->createPublishedEntry('com.example.none');
        self::assertArrayNotHasKey($other->id, $this->repo()->aggregatesFor([$other->id]));
    }

    public function testApprovedCommentsFiltersPendingAndNull(): void
    {
        $entry = $this->createPublishedEntry('com.example.rev');
        $approved = new Rating($this->makeAccount(str_pad('c', 16, 'c')), $entry, 5);
        $approved->comment = 'super';
        $approved->commentStatus = CommentStatus::Approved;
        $pending = new Rating($this->makeAccount(str_pad('d', 16, 'd')), $entry, 3);
        $pending->comment = 'wartet';
        $pending->commentStatus = CommentStatus::Pending;
        $starsOnly = new Rating($this->makeAccount(str_pad('e', 16, 'e')), $entry, 4); // kein Kommentar
        $this->em->persist($approved);
        $this->em->persist($pending);
        $this->em->persist($starsOnly);
        $this->em->flush();

        $comments = $this->repo()->approvedComments($entry, 0, 20);
        self::assertCount(1, $comments);
        self::assertSame('super', $comments[0]->comment);
        self::assertSame(1, $this->repo()->countApprovedComments($entry));
        self::assertCount(1, $this->repo()->pendingComments());
    }

    public function testUniqueConstraintPerAccountAndEntry(): void
    {
        $entry = $this->createPublishedEntry('com.example.uniq');
        $account = $this->makeAccount(str_pad('f', 16, 'f'));
        $this->em->persist(new Rating($account, $entry, 4));
        $this->em->flush();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->persist(new Rating($account, $entry, 2));
        $this->em->flush();
    }
}
```

- [ ] **Step 7: Tests ausführen**

Run: `php backend/bin/phpunit --filter RatingRepositoryTest; echo "EXIT:$?"`
Expected: PASS (3 Tests), `EXIT:0`.

- [ ] **Step 8: Commit**

```bash
git add backend/src/Enum/CommentStatus.php backend/src/Entity/Rating.php backend/src/Repository/RatingRepository.php backend/migrations/ backend/tests/Functional/Api/RatingRepositoryTest.php
git commit -m "Lege Rating-Entity, CommentStatus und Repository an"
```

---

### Task 2: `RatingService` + PUT/GET/DELETE-Endpunkte + `rating_write`-Limiter

Konto-authentifizierte Bewertungs-Endpunkte mit allen Guards und der Trust-Hybrid-Kommentarlogik. Deliverable: PUT/GET/DELETE `/api/v1/entries/{formatId}/rating`.

**Files:**
- Create: `backend/src/Service/RatingService.php`
- Create: `backend/src/Controller/Api/RatingPutController.php`
- Create: `backend/src/Controller/Api/RatingGetController.php`
- Create: `backend/src/Controller/Api/RatingDeleteController.php`
- Modify: `backend/config/packages/rate_limiter.yaml` (Limiter `rating_write` in allen drei Blöcken)
- Test: `backend/tests/Functional/Api/RatingTest.php`

**Interfaces:**
- Consumes: `RatingRepository` (Task 1), `AccountResolver::requireAccount(Request, bool $touchLastSeen = true): Account`, `SubmitterRepository::{hasBannedForAccount,sumApprovedCountForAccount}`, `SubmissionService::TRUST_THRESHOLD` (=3), `RateLimitGuard::consume`, `EntryRepository`.
- Produces: `RatingService::upsert(Account, Entry, int $stars, ?string $comment): Rating`, `RatingService::delete(Account, Entry): bool`.

- [ ] **Step 1: Limiter ergänzen**

In `backend/config/packages/rate_limiter.yaml` in **allen drei** `rate_limiter:`-Blöcken einen Eintrag ergänzen. Im Haupt-`framework:`-Block (Produktion):

```yaml
        rating_write: { policy: sliding_window, limit: 60, interval: '1 hour' }
```

Im `when@test`- **und** `when@dev`-Block jeweils:

```yaml
            rating_write: { policy: sliding_window, limit: 1000, interval: '1 hour' }
```

- [ ] **Step 2: RatingService implementieren**

Create `backend/src/Service/RatingService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\Rating;
use App\Enum\CommentStatus;
use App\Exception\ApiProblem;
use App\Repository\RatingRepository;
use App\Repository\SubmitterRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Schreiblogik für Bewertungen (Phase 3 D): Upsert und Löschen der einen
 * Bewertung pro Konto & Eintrag, inklusive Anti-Gaming-Guards und der
 * Trust-Hybrid-Entscheidung für den Kommentar-Moderationsstatus.
 */
final class RatingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RatingRepository $ratings,
        private readonly SubmitterRepository $submitters,
    ) {
    }

    /**
     * Legt die Bewertung des Kontos für den Eintrag an oder aktualisiert sie.
     * Der Stern zählt immer sofort; ein gesetzter Kommentar wird nach Vertrauen
     * sofort freigegeben (approved) oder in die Warteschlange gestellt (pending).
     *
     * @throws ApiProblem 403 bei eigenem Eintrag oder gesperrtem Konto-Bündel
     */
    public function upsert(Account $account, Entry $entry, int $stars, ?string $comment): Rating
    {
        // Anti-Gaming: das eigene (konto-verknüpfte) Werk nicht bewerten.
        if ($entry->submitter->account?->id === $account->id) {
            throw new ApiProblem(403, 'Cannot rate your own entry');
        }
        // Ban-Bündel (aus C): ein gesperrter Ruf darf nicht bewerten.
        if ($this->submitters->hasBannedForAccount($account)) {
            throw new ApiProblem(403, 'Account is banned');
        }

        $rating = $this->ratings->findForAccountAndEntry($account, $entry);
        if ($rating === null) {
            $rating = new Rating($account, $entry, $stars);
            $this->em->persist($rating);
        } else {
            $rating->stars = $stars;
            $rating->updatedAt = new \DateTimeImmutable();
        }

        if ($comment === null || $comment === '') {
            $rating->comment = null;
            $rating->commentStatus = CommentStatus::Approved; // nichts zu moderieren
        } else {
            $rating->comment = $comment;
            $trusted = $this->submitters->sumApprovedCountForAccount($account) >= SubmissionService::TRUST_THRESHOLD;
            $rating->commentStatus = $trusted ? CommentStatus::Approved : CommentStatus::Pending;
        }

        $this->em->flush();

        return $rating;
    }

    /** Entfernt die Bewertung des Kontos für den Eintrag; false, wenn keine existiert. */
    public function delete(Account $account, Entry $entry): bool
    {
        $rating = $this->ratings->findForAccountAndEntry($account, $entry);
        if ($rating === null) {
            return false;
        }

        $this->em->remove($rating);
        $this->em->flush();

        return true;
    }
}
```

- [ ] **Step 3: PUT-Controller implementieren**

Create `backend/src/Controller/Api/RatingPutController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Rating;
use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\AccountResolver;
use App\Service\RateLimitGuard;
use App\Service\RatingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legt die Bewertung des Kontos für einen veröffentlichten Eintrag an oder
 * aktualisiert sie (Upsert, eine pro Konto & Eintrag). Stern zählt sofort;
 * Kommentar durchläuft die Hybrid-Moderation.
 */
final class RatingPutController
{
    #[Route('/api/v1/entries/{formatId}/rating', methods: ['PUT'])]
    public function __invoke(
        string $formatId,
        Request $request,
        AccountResolver $resolver,
        EntryRepository $entries,
        RatingService $ratingService,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $ratingWriteLimiter,
    ): JsonResponse {
        $account = $resolver->requireAccount($request);
        $guard->consume($ratingWriteLimiter, $request->getClientIp() ?? 'unknown');

        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data)) {
            throw new ApiProblem(400, 'Invalid JSON');
        }

        $stars = $data['stars'] ?? null;
        if (!\is_int($stars) || $stars < 1 || $stars > 5) {
            throw new ApiProblem(400, 'stars must be an integer between 1 and 5');
        }

        $comment = $data['comment'] ?? null;
        if ($comment !== null) {
            if (!\is_string($comment)) {
                throw new ApiProblem(400, 'comment must be a string');
            }
            if (mb_strlen($comment) > Rating::MAX_COMMENT_LENGTH) {
                throw new ApiProblem(400, 'comment too long');
            }
        }

        $rating = $ratingService->upsert($account, $entry, $stars, $comment);

        return new JsonResponse([
            'stars' => $rating->stars,
            'comment' => $rating->comment,
            'commentStatus' => $rating->commentStatus->value,
        ]);
    }
}
```

- [ ] **Step 4: GET- und DELETE-Controller implementieren**

Create `backend/src/Controller/Api/RatingGetController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\RatingRepository;
use App\Service\AccountResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert die eigene Bewertung des Kontos zu einem Eintrag inkl.
 * Kommentar-Moderationsstatus, oder 404, wenn keine vorhanden ist.
 */
final class RatingGetController
{
    #[Route('/api/v1/entries/{formatId}/rating', methods: ['GET'])]
    public function __invoke(
        string $formatId,
        Request $request,
        AccountResolver $resolver,
        EntryRepository $entries,
        RatingRepository $ratings,
    ): JsonResponse {
        $account = $resolver->requireAccount($request);
        $entry = $entries->findOneBy(['formatId' => $formatId]) ?? throw new ApiProblem(404, 'Entry not found');
        $rating = $ratings->findForAccountAndEntry($account, $entry) ?? throw new ApiProblem(404, 'No rating');

        return new JsonResponse([
            'stars' => $rating->stars,
            'comment' => $rating->comment,
            'commentStatus' => $rating->commentStatus->value,
            'createdAt' => $rating->createdAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
```

Create `backend/src/Controller/Api/RatingDeleteController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\AccountResolver;
use App\Service\RateLimitGuard;
use App\Service\RatingService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Entfernt die eigene Bewertung des Kontos zu einem Eintrag (204); 404, wenn
 * keine vorhanden ist. Das Aggregat des Eintrags passt sich lesend automatisch an.
 */
final class RatingDeleteController
{
    #[Route('/api/v1/entries/{formatId}/rating', methods: ['DELETE'])]
    public function __invoke(
        string $formatId,
        Request $request,
        AccountResolver $resolver,
        EntryRepository $entries,
        RatingService $ratingService,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $ratingWriteLimiter,
    ): Response {
        $account = $resolver->requireAccount($request);
        $guard->consume($ratingWriteLimiter, $request->getClientIp() ?? 'unknown');

        $entry = $entries->findOneBy(['formatId' => $formatId]) ?? throw new ApiProblem(404, 'Entry not found');
        if (!$ratingService->delete($account, $entry)) {
            throw new ApiProblem(404, 'No rating');
        }

        return new Response('', 204);
    }
}
```

- [ ] **Step 5: Funktionstest schreiben**

Create `backend/tests/Functional/Api/RatingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Rating;
use App\Entity\Submitter;
use App\Repository\SubmitterRepository;
use App\Tests\Functional\ApiTestCase;

final class RatingTest extends ApiTestCase
{
    /** @return string Klartext-Konto-Token */
    private function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    /** @return array<string, string> */
    private function hdr(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'];
    }

    private function accountFor(string $token): Account
    {
        return $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => explode('_', $token)[1]]);
    }

    private function putRating(string $token, string $formatId, array $body): void
    {
        $this->client->request('PUT', '/api/v1/entries/' . $formatId . '/rating',
            server: $this->hdr($token), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testUpsertUpdateAndDelete(): void
    {
        $this->createPublishedEntry('com.example.r1');
        $token = $this->createAccount();

        $this->putRating($token, 'com.example.r1', ['stars' => 4]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, $this->json()['stars']);

        // Zweites PUT aktualisiert dieselbe Bewertung (kein zweiter Datensatz):
        $this->putRating($token, 'com.example.r1', ['stars' => 2]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json()['stars']);
        self::assertSame(1, $this->em->getRepository(Rating::class)->count([]));

        $this->client->request('GET', '/api/v1/entries/com.example.r1/rating', server: $this->hdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json()['stars']);

        $this->client->request('DELETE', '/api/v1/entries/com.example.r1/rating', server: $this->hdr($token));
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/entries/com.example.r1/rating', server: $this->hdr($token));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCommentFromNewAccountIsPendingButStarCounts(): void
    {
        $this->createPublishedEntry('com.example.r2');
        $token = $this->createAccount();

        $this->putRating($token, 'com.example.r2', ['stars' => 5, 'comment' => 'klasse']);
        self::assertResponseStatusCodeSame(200);
        // Neues Konto (kein approvedCount) → Kommentar wartet:
        self::assertSame('pending', $this->json()['commentStatus']);
    }

    public function testCommentFromTrustedAccountIsApproved(): void
    {
        $entry = $this->createPublishedEntry('com.example.r3');
        $token = $this->createAccount();
        $account = $this->accountFor($token);

        // Vertrauen aufbauen: ein verknüpfter Submitter mit approvedCount ≥ TRUST_THRESHOLD (3).
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $submitter->approvedCount = 3;
        $this->em->flush();

        $this->putRating($token, 'com.example.r3', ['stars' => 5, 'comment' => 'vertraut']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('approved', $this->json()['commentStatus']);
    }

    public function testCannotRateOwnEntry(): void
    {
        $token = $this->createAccount();
        $account = $this->accountFor($token);
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $this->em->flush();
        $this->createPublishedEntry('com.example.mine', [], $submitter);

        $this->putRating($token, 'com.example.mine', ['stars' => 5]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBannedBundleCannotRate(): void
    {
        $this->createPublishedEntry('com.example.r4');
        $token = $this->createAccount();
        $account = $this->accountFor($token);
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $submitter->banned = true;
        $this->em->flush();

        $this->putRating($token, 'com.example.r4', ['stars' => 5]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnpublishedEntryIs404(): void
    {
        $token = $this->createAccount();
        $this->putRating($token, 'com.example.nope', ['stars' => 5]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidStarsAndComment(): void
    {
        $this->createPublishedEntry('com.example.r5');
        $token = $this->createAccount();

        $this->putRating($token, 'com.example.r5', ['stars' => 6]);
        self::assertResponseStatusCodeSame(400);
        $this->putRating($token, 'com.example.r5', ['stars' => 0]);
        self::assertResponseStatusCodeSame(400);
        $this->putRating($token, 'com.example.r5', ['stars' => 'x']);
        self::assertResponseStatusCodeSame(400);
        $this->putRating($token, 'com.example.r5', ['stars' => 3, 'comment' => str_repeat('x', 501)]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testWithoutTokenIs401(): void
    {
        $this->createPublishedEntry('com.example.r6');
        $this->client->request('PUT', '/api/v1/entries/com.example.r6/rating',
            server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['stars' => 5], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);
    }
}
```

- [ ] **Step 6: Tests ausführen**

Run: `php backend/bin/phpunit --filter RatingTest; echo "EXIT:$?"`
Expected: PASS (8 Tests), `EXIT:0`.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/RatingService.php backend/src/Controller/Api/RatingPutController.php backend/src/Controller/Api/RatingGetController.php backend/src/Controller/Api/RatingDeleteController.php backend/config/packages/rate_limiter.yaml backend/tests/Functional/Api/RatingTest.php
git commit -m "Ergänze Rating-Endpunkte (PUT/GET/DELETE) mit Trust-Hybrid"
```

---

### Task 3: Öffentlicher Reviews-Endpunkt

`GET /api/v1/entries/{formatId}/reviews` – paginierte, anonyme Liste freigegebener Kommentare.

**Files:**
- Create: `backend/src/Controller/Api/ReviewListController.php`
- Test: `backend/tests/Functional/Api/ReviewListTest.php`

**Interfaces:**
- Consumes: `RatingRepository::{approvedComments,countApprovedComments}` (Task 1), `EntryRepository`.

- [ ] **Step 1: Controller implementieren**

Create `backend/src/Controller/Api/ReviewListController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Rating;
use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\RatingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Öffentliche, paginierte Liste der freigegebenen Kommentare eines Eintrags –
 * anonym (kein Autor-Bezug). Bewertungen ohne Kommentar oder mit noch nicht
 * freigegebenem/abgelehntem Kommentar erscheinen nicht.
 */
final class ReviewListController
{
    private const MAX_PAGE = 100_000;

    #[Route('/api/v1/entries/{formatId}/reviews', methods: ['GET'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        RatingRepository $ratings,
    ): JsonResponse {
        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        $page = min(self::MAX_PAGE, max(1, $request->query->getInt('page', 1)));
        $perPage = min(50, max(1, $request->query->getInt('perPage', 20)));

        $items = array_map(static fn (Rating $r): array => [
            'stars' => $r->stars,
            'comment' => $r->comment,
            'createdAt' => $r->createdAt->format(\DateTimeInterface::ATOM),
        ], $ratings->approvedComments($entry, ($page - 1) * $perPage, $perPage));

        $response = new JsonResponse([
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $ratings->countApprovedComments($entry),
        ]);
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}
```

- [ ] **Step 2: Test schreiben**

Create `backend/tests/Functional/Api/ReviewListTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Rating;
use App\Enum\CommentStatus;
use App\Tests\Functional\ApiTestCase;

final class ReviewListTest extends ApiTestCase
{
    private function makeAccount(string $selector): Account
    {
        $account = new Account($selector, 'hash-' . $selector);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function testListsOnlyApprovedCommentsAnonymously(): void
    {
        $entry = $this->createPublishedEntry('com.example.rev');

        $approved = new Rating($this->makeAccount(str_pad('a', 16, 'a')), $entry, 5);
        $approved->comment = 'sehr gut';
        $approved->commentStatus = CommentStatus::Approved;
        $pending = new Rating($this->makeAccount(str_pad('b', 16, 'b')), $entry, 2);
        $pending->comment = 'wartet';
        $pending->commentStatus = CommentStatus::Pending;
        $starsOnly = new Rating($this->makeAccount(str_pad('c', 16, 'c')), $entry, 4);
        $this->em->persist($approved);
        $this->em->persist($pending);
        $this->em->persist($starsOnly);
        $this->em->flush();

        $this->client->request('GET', '/api/v1/entries/com.example.rev/reviews');
        self::assertResponseStatusCodeSame(200);

        $data = $this->json();
        self::assertSame(1, $data['total']);
        self::assertCount(1, $data['items']);
        self::assertSame('sehr gut', $data['items'][0]['comment']);
        self::assertSame(5, $data['items'][0]['stars']);
        // Anonym: kein Autor-/Konto-Bezug im Item:
        self::assertArrayNotHasKey('account', $data['items'][0]);
        self::assertArrayNotHasKey('tokenSelector', $data['items'][0]);
    }

    public function testUnknownEntryIs404(): void
    {
        $this->client->request('GET', '/api/v1/entries/com.example.nope/reviews');
        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 3: Tests ausführen**

Run: `php backend/bin/phpunit --filter ReviewListTest; echo "EXIT:$?"`
Expected: PASS (2 Tests), `EXIT:0`.

- [ ] **Step 4: Commit**

```bash
git add backend/src/Controller/Api/ReviewListController.php backend/tests/Functional/Api/ReviewListTest.php
git commit -m "Ergänze öffentlichen Reviews-Endpunkt für freigegebene Kommentare"
```

---

### Task 4: Aggregat in Liste + Detail (`EntrySerializer` + Controller)

Ø-Sterne + Anzahl in `/api/v1/entries` und `/api/v1/entries/{formatId}` – eine gruppierte Aggregat-Query pro Antwort.

**Files:**
- Modify: `backend/src/Service/EntrySerializer.php`
- Modify: `backend/src/Controller/Api/EntryListController.php`
- Modify: `backend/src/Controller/Api/EntryDetailController.php`
- Test: `backend/tests/Functional/Api/RatingAggregateTest.php`

**Interfaces:**
- Consumes: `RatingRepository::aggregatesFor(int[])` (Task 1).
- Produces: `EntrySerializer::toListItem(Entry, ?array $rating = null)`, `toDetail(Entry, array $versions, ?array $rating = null)` – jedes Item bekommt `rating: {average: float|null, count: int}`.

- [ ] **Step 1: Serializer erweitern**

In `backend/src/Service/EntrySerializer.php` die beiden Methoden anpassen (optionaler `$rating`-Parameter, Default-Aggregat):

`toListItem` – Signatur und das zurückgegebene Array um `rating` ergänzen:

```php
    public function toListItem(Entry $entry, ?array $rating = null): array
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
            'rating' => $rating ?? ['average' => null, 'count' => 0],
            'currentVersion' => $entry->currentVersion?->semver,
            'deprecated' => $entry->deprecated,
            'successorFormatId' => $entry->successorFormatId,
            'screenshotUrl' => $entry->screenshotPath === null ? null : '/api/v1/entries/' . $entry->formatId . '/screenshot',
            'updatedAt' => $entry->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
```

`toDetail` – `$rating` durchreichen:

```php
    public function toDetail(Entry $entry, array $versions, ?array $rating = null): array
    {
        return $this->toListItem($entry, $rating) + [
            'versions' => array_map(static fn (EntryVersion $v): array => [
                'semver' => $v->semver,
                'changelog' => $v->changelog,
                'hasTransformCode' => $v->hasTransformCode,
                'submittedAt' => $v->submittedAt->format(\DateTimeInterface::ATOM),
            ], $versions),
        ];
    }
```

(Der bestehende Aufrufer `AdminEntryDetailController` ruft `toDetail($entry, $versionList)` ohne `$rating` auf – bleibt durch den Default `null` unverändert lauffähig, liefert dann `rating: {average: null, count: 0}`.)

- [ ] **Step 2: EntryListController Aggregat einspeisen**

In `backend/src/Controller/Api/EntryListController.php`: `RatingRepository` in die Signatur aufnehmen und die Items mit Aggregaten serialisieren. Import ergänzen: `use App\Entity\Entry;` und `use App\Repository\RatingRepository;`.

Signatur:

```php
    public function __invoke(Request $request, EntryRepository $entries, EntrySerializer $serializer, RatingRepository $ratings): JsonResponse
```

Den `items`-Aufbau (bisher `array_map($serializer->toListItem(...), $result['items'])`) ersetzen durch:

```php
        $entryIds = array_map(static fn (Entry $e): int => $e->id, $result['items']);
        $aggregates = $ratings->aggregatesFor($entryIds);

        $response = new JsonResponse([
            'items' => array_map(
                fn (Entry $e): array => $serializer->toListItem($e, $aggregates[$e->id] ?? null),
                $result['items'],
            ),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $result['total'],
        ]);
```

- [ ] **Step 3: EntryDetailController Aggregat einspeisen**

In `backend/src/Controller/Api/EntryDetailController.php`: `RatingRepository` in die Signatur aufnehmen (Import `use App\Repository\RatingRepository;`), Aggregat für den einen Eintrag holen und durchreichen:

```php
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        EntryVersionRepository $versions,
        EntrySerializer $serializer,
        RatingRepository $ratings,
    ): JsonResponse {
        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        $aggregate = $ratings->aggregatesFor([$entry->id])[$entry->id] ?? null;

        $response = new JsonResponse($serializer->toDetail($entry, $versions->findApproved($entry), $aggregate));
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
```

- [ ] **Step 4: Test schreiben**

Create `backend/tests/Functional/Api/RatingAggregateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Rating;
use App\Tests\Functional\ApiTestCase;

final class RatingAggregateTest extends ApiTestCase
{
    private function makeAccount(string $selector): Account
    {
        $account = new Account($selector, 'hash-' . $selector);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function testDetailAndListExposeAggregate(): void
    {
        $entry = $this->createPublishedEntry('com.example.agg');
        $this->em->persist(new Rating($this->makeAccount(str_pad('a', 16, 'a')), $entry, 4));
        $this->em->persist(new Rating($this->makeAccount(str_pad('b', 16, 'b')), $entry, 5));
        $this->em->flush();

        $this->client->request('GET', '/api/v1/entries/com.example.agg');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(4.5, $this->json()['rating']['average']);
        self::assertSame(2, $this->json()['rating']['count']);

        // Kein q-Filter: q matcht gegen searchText (nicht formatId); der Eintrag
        // ist der einzige in der (pro Test zurückrollenden) DB.
        $this->client->request('GET', '/api/v1/entries');
        self::assertResponseStatusCodeSame(200);
        $item = null;
        foreach ($this->json()['items'] as $it) {
            if ($it['formatId'] === 'com.example.agg') {
                $item = $it;
            }
        }
        self::assertNotNull($item);
        self::assertSame(4.5, $item['rating']['average']);
        self::assertSame(2, $item['rating']['count']);
    }

    public function testUnratedEntryHasNullAverage(): void
    {
        $this->createPublishedEntry('com.example.noagg');

        $this->client->request('GET', '/api/v1/entries/com.example.noagg');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['rating']['average']);
        self::assertSame(0, $this->json()['rating']['count']);
    }
}
```

- [ ] **Step 5: Tests ausführen (inkl. bestehende Entry-Tests als Regression)**

Run: `php backend/bin/phpunit --filter "RatingAggregateTest|EntryListTest|EntryDetailTest"; echo "EXIT:$?"`
Expected: PASS, `EXIT:0`. (Die bestehenden `EntryListTest`/`EntryDetailTest` müssen weiter grün sein – das neue `rating`-Feld darf sie nicht brechen.)

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/EntrySerializer.php backend/src/Controller/Api/EntryListController.php backend/src/Controller/Api/EntryDetailController.php backend/tests/Functional/Api/RatingAggregateTest.php
git commit -m "Zeige Bewertungs-Aggregat in Liste und Detail"
```

---

### Task 5: Kommentar-Moderation (`ModerationService` + CLI)

Admin-Moderation der Kommentare per CLI (keine Admin-API in D).

**Files:**
- Modify: `backend/src/Service/ModerationService.php` (Methoden `approveComment`/`rejectComment`)
- Create: `backend/src/Command/CommentQueueCommand.php`
- Create: `backend/src/Command/CommentApproveCommand.php`
- Create: `backend/src/Command/CommentRejectCommand.php`
- Test: `backend/tests/Command/CommentModerationTest.php`

**Interfaces:**
- Consumes: `RatingRepository::{pendingComments,find}` (Task 1), `CommentStatus`, `Rating`.
- Produces: `ModerationService::approveComment(Rating): void`, `ModerationService::rejectComment(Rating): void`.

- [ ] **Step 1: ModerationService erweitern**

In `backend/src/Service/ModerationService.php` die beiden Methoden ergänzen (z. B. hinter `rejectVersion`). Imports ergänzen: `use App\Entity\Rating;` und `use App\Enum\CommentStatus;`.

```php
    /**
     * Gibt einen wartenden Kommentar frei (öffentlich in /reviews sichtbar).
     * Nur wartende Kommentare sind freigebbar.
     */
    public function approveComment(Rating $rating): void
    {
        if ($rating->commentStatus !== CommentStatus::Pending) {
            throw new \RuntimeException('Nur wartende Kommentare können freigegeben werden');
        }

        $rating->commentStatus = CommentStatus::Approved;
        $this->em->flush();
    }

    /**
     * Lehnt einen wartenden Kommentar ab: blendet nur den Text aus – der
     * Stern-Wert bleibt im Aggregat. Nur wartende Kommentare sind ablehnbar.
     */
    public function rejectComment(Rating $rating): void
    {
        if ($rating->commentStatus !== CommentStatus::Pending) {
            throw new \RuntimeException('Nur wartende Kommentare können abgelehnt werden');
        }

        $rating->commentStatus = CommentStatus::Rejected;
        $this->em->flush();
    }
```

- [ ] **Step 2: Queue-Command anlegen**

Create `backend/src/Command/CommentQueueCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\RatingRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:comments:queue` – listet Bewertungen mit wartendem
 * Kommentar (Moderations-Warteschlange), älteste zuerst.
 */
#[AsCommand(name: 'index:comments:queue', description: 'Zeigt wartende Kommentare')]
final class CommentQueueCommand extends Command
{
    public function __construct(private readonly RatingRepository $ratings)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = [];
        foreach ($this->ratings->pendingComments() as $r) {
            $rows[] = [$r->id, $r->entry->formatId, $r->stars, mb_substr((string) $r->comment, 0, 60), $r->createdAt->format('Y-m-d H:i')];
        }
        $rows === [] ? $io->text('leer') : $io->table(['id', 'formatId', 'Sterne', 'Kommentar', 'erstellt'], $rows);

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 3: Approve- und Reject-Command anlegen**

Create `backend/src/Command/CommentApproveCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\RatingRepository;
use App\Service\ModerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:comments:approve` – gibt einen wartenden Kommentar frei.
 */
#[AsCommand(name: 'index:comments:approve', description: 'Gibt einen wartenden Kommentar frei')]
final class CommentApproveCommand extends Command
{
    public function __construct(
        private readonly RatingRepository $ratings,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Rating-ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rating = $this->ratings->find((int) $input->getArgument('id'));
        if ($rating === null) {
            $io->error('Unbekannte Rating-ID');

            return Command::FAILURE;
        }

        try {
            $this->moderation->approveComment($rating);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success('Kommentar freigegeben');

        return Command::SUCCESS;
    }
}
```

Create `backend/src/Command/CommentRejectCommand.php` (analog, mit `rejectComment`):

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\RatingRepository;
use App\Service\ModerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:comments:reject` – lehnt einen wartenden Kommentar ab
 * (Text ausgeblendet, Stern bleibt).
 */
#[AsCommand(name: 'index:comments:reject', description: 'Lehnt einen wartenden Kommentar ab')]
final class CommentRejectCommand extends Command
{
    public function __construct(
        private readonly RatingRepository $ratings,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Rating-ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rating = $this->ratings->find((int) $input->getArgument('id'));
        if ($rating === null) {
            $io->error('Unbekannte Rating-ID');

            return Command::FAILURE;
        }

        try {
            $this->moderation->rejectComment($rating);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success('Kommentar abgelehnt');

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Test schreiben**

Create `backend/tests/Command/CommentModerationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Rating;
use App\Entity\Submitter;
use App\Enum\CommentStatus;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Service\PayloadAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CommentModerationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Application $console;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->console = new Application(self::$kernel);
    }

    private function seedPendingComment(): Rating
    {
        $submitter = new Submitter(bin2hex(random_bytes(8)), 'hash');
        $entry = new Entry('com.example.cmt', EntryType::Menu, $submitter);
        $entry->status = EntryStatus::Published;
        $payload = ['gesturaMenu' => 1, 'id' => 'com.example.cmt', 'version' => '1.0.0', 'name' => 'X',
            'items' => [['id' => 'a', 'label' => 'A', 'action' => 'newTab']]];
        $version = new EntryVersion($entry, '1.0.0', $payload, (new PayloadAnalyzer())->contentHash($payload));
        $account = new Account(bin2hex(random_bytes(8)), 'hash-a');
        $rating = new Rating($account, $entry, 3);
        $rating->comment = 'wartet auf Freigabe';
        $rating->commentStatus = CommentStatus::Pending;

        $this->em->persist($submitter);
        $this->em->persist($entry);
        $this->em->persist($version);
        $this->em->persist($account);
        $this->em->persist($rating);
        $this->em->flush();

        return $rating;
    }

    private function runCommand(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->console->find($name));
        $tester->execute($input);

        return $tester;
    }

    public function testApproveMakesCommentApproved(): void
    {
        $id = $this->seedPendingComment()->id;

        $tester = $this->runCommand('index:comments:approve', ['id' => (string) $id]);
        self::assertSame(0, $tester->getStatusCode());

        $this->em->clear();
        self::assertSame(CommentStatus::Approved, $this->em->getRepository(Rating::class)->find($id)->commentStatus);
    }

    public function testRejectHidesTextButKeepsStar(): void
    {
        $id = $this->seedPendingComment()->id;

        $tester = $this->runCommand('index:comments:reject', ['id' => (string) $id]);
        self::assertSame(0, $tester->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->getRepository(Rating::class)->find($id);
        self::assertSame(CommentStatus::Rejected, $reloaded->commentStatus);
        self::assertSame(3, $reloaded->stars); // Stern bleibt
    }

    public function testApproveNonPendingFails(): void
    {
        $id = $this->seedPendingComment()->id;
        $this->runCommand('index:comments:approve', ['id' => (string) $id]); // erste Freigabe

        // Zweite Freigabe: bereits approved → Guard schlägt an (FAILURE):
        $tester = $this->runCommand('index:comments:approve', ['id' => (string) $id]);
        self::assertSame(1, $tester->getStatusCode());
    }
}
```

- [ ] **Step 5: Tests ausführen**

Run: `php backend/bin/phpunit --filter CommentModerationTest; echo "EXIT:$?"`
Expected: PASS (3 Tests), `EXIT:0`.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/ModerationService.php backend/src/Command/CommentQueueCommand.php backend/src/Command/CommentApproveCommand.php backend/src/Command/CommentRejectCommand.php backend/tests/Command/CommentModerationTest.php
git commit -m "Ergänze CLI-Moderation für Bewertungs-Kommentare"
```

---

### Task 6: E-Kopplung – `ratings`-Block in der Selbstauskunft

`GET /api/account/data` (aus E) listet die eigenen Bewertungen des Kontos.

**Files:**
- Modify: `backend/src/Service/AccountDataAssembler.php`
- Modify: `backend/tests/Functional/Api/AccountDataTest.php` (Assertion für `ratings`)

**Interfaces:**
- Consumes: `RatingRepository::findBy(['account' => …])` (Task 1).

- [ ] **Step 1: Assembler erweitern**

In `backend/src/Service/AccountDataAssembler.php`: `RatingRepository` in den Konstruktor aufnehmen und einen `ratings`-Block ergänzen. Imports ergänzen: `use App\Entity\Rating;` und `use App\Repository\RatingRepository;`.

Konstruktor um die Abhängigkeit erweitern:

```php
    public function __construct(
        private readonly SyncBlobRepository $blobs,
        private readonly SubmitterRepository $submitters,
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
        private readonly RatingRepository $ratings,
    ) {
    }
```

Im `assemble()`-Rückgabe-Array einen Schlüssel ergänzen:

```php
            'submitters' => $this->assembleSubmitters($account),
            'ratings' => $this->assembleRatings($account),
        ];
```

Neue private Methode:

```php
    /** @return list<array<string, mixed>> */
    private function assembleRatings(Account $account): array
    {
        $out = [];
        foreach ($this->ratings->findBy(['account' => $account]) as $rating) {
            $out[] = [
                'formatId' => $rating->entry->formatId,
                'stars' => $rating->stars,
                'comment' => $rating->comment,
                'commentStatus' => $rating->commentStatus->value,
                'createdAt' => $rating->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return $out;
    }
```

(Falls `AccountDataAssemblerTest` aus E den Assembler direkt `new` instanziiert, dort das zusätzliche Repository `$this->em->getRepository(Rating::class)` als fünftes Konstruktor-Argument ergänzen.)

- [ ] **Step 2: E-Assembler-Test an neue Signatur anpassen**

Falls `backend/tests/Functional/Api/AccountDataAssemblerTest.php` den Assembler per `new AccountDataAssembler(...)` baut, das fünfte Argument ergänzen:

```php
        return new AccountDataAssembler(
            $this->em->getRepository(SyncBlob::class),
            $this->em->getRepository(Submitter::class),
            $this->em->getRepository(Entry::class),
            $this->em->getRepository(EntryVersion::class),
            $this->em->getRepository(Rating::class),
        );
```

(Import `use App\Entity\Rating;` ergänzen. Die bestehenden Assertions bleiben gültig; `ratings` ist bei leerem/vollem Konto zusätzlich vorhanden.)

- [ ] **Step 3: Endpunkt-Test um `ratings` erweitern**

In `backend/tests/Functional/Api/AccountDataTest.php` einen Test ergänzen (Imports `use App\Entity\Rating;` und ggf. vorhandene nutzen):

```php
    public function testDataIncludesOwnRatings(): void
    {
        $entry = $this->createPublishedEntry('com.example.rated');
        $token = $this->createAccount();
        $account = $this->accountFor($token);

        $rating = new Rating($account, $entry, 5);
        $rating->comment = 'top';
        $this->em->persist($rating);
        $this->em->flush();

        $this->client->request('GET', '/api/account/data', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);

        $ratings = $this->json()['ratings'];
        self::assertCount(1, $ratings);
        self::assertSame('com.example.rated', $ratings[0]['formatId']);
        self::assertSame(5, $ratings[0]['stars']);
        self::assertSame('top', $ratings[0]['comment']);
        self::assertArrayHasKey('commentStatus', $ratings[0]);
    }
```

(Nutzt die in `AccountDataTest` bereits vorhandenen Helfer `createAccount()`, `accountFor()`, `authHdr()`. Falls `accountFor()` dort noch nicht existiert, die Variante aus `RatingTest` übernehmen: `findOneBy(['tokenSelector' => explode('_', $token)[1]])`.)

- [ ] **Step 4: Tests ausführen**

Run: `php backend/bin/phpunit --filter "AccountDataTest|AccountDataAssemblerTest"; echo "EXIT:$?"`
Expected: PASS, `EXIT:0`.

- [ ] **Step 5: Volle Suite (Regression + Deprecation-Gate)**

Run: `php backend/bin/phpunit; echo "EXIT:$?"`
Expected: PASS, `EXIT:0`.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/AccountDataAssembler.php backend/tests/Functional/Api/AccountDataAssemblerTest.php backend/tests/Functional/Api/AccountDataTest.php
git commit -m "Ergänze eigene Bewertungen in der Konto-Selbstauskunft"
```

---

## Self-Review

- **Spec coverage:** §3 Datenmodell/Moderation → Task 1 (Entity/Enum/Repo/Migration) + Task 5 (ModerationService/CLI). §4 Aggregation → Task 1 (`aggregatesFor`) + Task 4 (Serializer/Controller). §5 Endpunkte → Task 2 (PUT/GET/DELETE) + Task 3 (reviews). §6 Selbst-Rating → Task 2 (`upsert`-Guard + Test). §7 E-Kopplung → Task 6. §8 Vertrag → Doku. §9 Sicherheit → Guards (Task 2), Kaskaden (Task 1 FKs), Anonymität (Task 3). §10 Tests → über alle Tasks verteilt, inkl. Kaskaden-Regression (siehe Hinweis unten).
- **Kaskaden-Regression:** Die FK-`ON DELETE CASCADE` (Task 1) deckt Eintrag- und Konto-Löschung ab; die bestehenden Kaskaden-Tests (`SyncCascadeTest`, `AccountSubmitterCascadeTest`) und die volle Suite (Task 6 Step 5) prüfen, dass Löschpfade grün bleiben. Ein zusätzlicher Rating-spezifischer Kaskadentest ist optional – das lesend berechnete Aggregat kann nach Löschung gar nicht driften (kein persistenter Zähler).
- **Placeholder-Scan:** kein TBD/TODO; jeder Code-Schritt vollständig. Die Migration ist bewusst generiert (deterministischer Klassenname unmöglich vorherzusagen) – mit präziser Verifikations-Checkliste in Task 1 Step 4.
- **Typkonsistenz:** `aggregatesFor(int[]): array<int,{average,count}>` einheitlich (Task 1 → 4). `upsert(Account,Entry,int,?string):Rating` / `delete(...):bool` (Task 2). `approveComment/rejectComment(Rating):void` (Task 5). `CommentStatus`-Enum-Werte (`pending/approved/rejected`) durchgängig. Validierung durchgehend `400`.
- **Konventionstreue:** invokable Single-`__invoke`-Controller, `ApiProblem`-Statuscodes wie im Repo, Limiter in allen drei YAML-Blöcken, CLI im Muster der bestehenden Moderations-Commands.
