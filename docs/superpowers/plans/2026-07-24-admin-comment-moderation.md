# Admin-Kommentar-Moderation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admins moderieren wartende Bewertungs-Kommentare über die bestehende Admin-SPA (Queue-Sektion) statt nur per CLI – Backend-Admin-API + UI, 1:1 gespiegelt an der Versions-Moderation.

**Architecture:** Drei dünne Admin-Controller unter `^/api/admin` rufen den vorhandenen `ModerationService::approveComment/rejectComment` (aus D) mit Gates/Audit/Locking auf. Die Admin-SPA-Queue-Seite bekommt eine »Kommentare«-Sektion, die drei neue `adminFetch`-Wrapper nutzt.

**Tech Stack:** Symfony 7.4 (Backend), SvelteKit + Svelte 5 Runes + Vitest + Paraglide (Frontend).

**Spec:** `docs/superpowers/specs/2026-07-24-admin-comment-moderation-design.md`

## Global Constraints

- Endpunkte unter `^/api/admin` (bestehende Cookie-Firewall/`AdminSessionAuthenticator`/CSRF `X-Requested-With`). Kein neuer Security-/Firewall-Code.
- **Approve** (`POST /api/admin/comments/{id}/approve`): kein Step-up; `wrapInTransaction` + `$em->lock($rating, PESSIMISTIC_WRITE)` + `refresh` + `ModerationService::approveComment` + Audit `comment.approve`. Status-Guard (nur `pending`) als Sentinel abfangen → `ApiProblem(409)` NACH dem Commit werfen (nie aus `wrapInTransaction` werfen – schließt den EM).
- **Reject** (`POST /api/admin/comments/{id}/reject`): `BackupPasskeyGate::assertEnough($actor)` + `StepUpGuard::assertFresh()` **vor** der Transaktion; dann Lock + `rejectComment` + Audit `comment.reject` + Sentinel-409. Reject blendet nur den Text aus, der Stern bleibt.
- **Gate-Statuscodes:** `StepUpGuard` wirft `403` (`stepUpRequired`); `BackupPasskeyGate` wirft `409` (`backupRequired`). `404` bei unbekannter `id`.
- **Locking-Aggregat = die `Rating`-Zeile** (nicht der Entry) – es gibt keinen `currentVersion`-Zeiger zu schützen, nur den `commentStatus`-Übergang.
- Audit-Aktionen exakt: `comment.approve`, `comment.reject` (targetType `comment`, targetId = Rating-`id`).
- Keine neue Domänenlogik – `ModerationService::approveComment/rejectComment` (aus D) unverändert nutzen.
- **Frontend:** neue Strings in `frontend/messages/en.json` UND `de.json`; die kompilierte `frontend/src/lib/paraglide/messages.js` ist **getrackt** und muss nach i18n-Änderungen mit-committed werden (das `test`/`check`-Script kompiliert Paraglide automatisch vorab). Alle Entities/Props: public. Backend-PHP lokal `php`; Test-Exit-Code prüfen (`failOnDeprecation="true"`).
- **Frontend-Testlauf:** `npm --prefix frontend run test -- run` (CWD=frontend; kompiliert Paraglide vorab). **Nur** ausführen, solange **kein** `vite dev` läuft. TS-Check: `npm --prefix frontend run check`.

---

### Task 1: Backend – Admin-API (CommentQueue / Approve / Reject)

Drei Controller, die `ModerationService` (aus D) über die Admin-HTTP-Schicht anbinden. Deliverable: die drei Endpunkte inkl. Funktionstest.

**Files:**
- Create: `backend/src/Controller/Admin/CommentQueueController.php`
- Create: `backend/src/Controller/Admin/CommentApproveController.php`
- Create: `backend/src/Controller/Admin/CommentRejectController.php`
- Test: `backend/tests/Functional/Admin/AdminCommentModerationTest.php`

**Interfaces:**
- Consumes: `RatingRepository::{pendingComments(),find()}`, `Rating` (public `id, entry, stars, comment, commentStatus, createdAt`), `ModerationService::{approveComment,rejectComment}(Rating)`, `AuditLogger::log(?AdminUser,string,?string,?string,?array)`, `StepUpGuard::assertFresh()`, `BackupPasskeyGate::assertEnough(AdminUser)`.
- Produces: routes `GET /api/admin/comments`, `POST /api/admin/comments/{id}/approve`, `POST /api/admin/comments/{id}/reject`.

- [ ] **Step 1: Funktionstest schreiben**

Create `backend/tests/Functional/Admin/AdminCommentModerationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Account;
use App\Entity\AuditLogEntry;
use App\Entity\Rating;
use App\Enum\AdminRole;
use App\Enum\CommentStatus;

final class AdminCommentModerationTest extends AdminTestCase
{
    private function seedPendingComment(string $formatId = 'com.example.cmt', int $stars = 4): Rating
    {
        $entry = $this->createPublishedEntry($formatId);
        $account = new Account(bin2hex(random_bytes(8)), 'hash');
        $rating = new Rating($account, $entry, $stars);
        $rating->comment = 'wartet auf Freigabe';
        $rating->commentStatus = CommentStatus::Pending;
        $this->em->persist($account);
        $this->em->persist($rating);
        $this->em->flush();

        return $rating;
    }

    public function testQueueListsOnlyPendingComments(): void
    {
        $admin = $this->createAdmin('chef-cmtq@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $pending = $this->seedPendingComment('com.example.pending-cmt');
        // Ein approved Kommentar darf NICHT erscheinen:
        $approved = $this->seedPendingComment('com.example.approved-cmt');
        $approved->commentStatus = CommentStatus::Approved;
        $this->em->flush();

        $this->client->request('GET', '/api/admin/comments', server: $this->hdr());
        self::assertResponseStatusCodeSame(200);

        $items = $this->json();
        $ids = array_column($items, 'id');
        self::assertContains($pending->id, $ids);
        self::assertNotContains($approved->id, $ids);
    }

    public function testQueueWithoutSessionIs401(): void
    {
        $this->client->request('GET', '/api/admin/comments', server: $this->hdr());
        self::assertResponseStatusCodeSame(401);
    }

    public function testApproveSetsApprovedAndWritesAudit(): void
    {
        $admin = $this->createAdmin('chef-cmta@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);
        $rating = $this->seedPendingComment();
        $id = $rating->id;

        $this->client->request('POST', "/api/admin/comments/{$id}/approve", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertSame(CommentStatus::Approved, $this->em->getRepository(Rating::class)->find($id)->commentStatus);
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, static fn ($a) => $a->action === 'comment.approve'));
    }

    public function testApproveTwiceIs409AndEmStaysUsable(): void
    {
        $admin = $this->createAdmin('chef-cmta2@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);
        $id = $this->seedPendingComment()->id;

        $this->client->request('POST', "/api/admin/comments/{$id}/approve", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        // Zweiter Approve: bereits approved → Guard → 409 (kein 500, EM nutzbar):
        $this->client->request('POST', "/api/admin/comments/{$id}/approve", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
    }

    public function testRejectRequiresBackupGate(): void
    {
        $admin = $this->createAdmin('chef-cmtr1@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1); // nur ein Passkey → Backup-Gate (409)
        $id = $this->seedPendingComment()->id;

        $this->client->request('POST', "/api/admin/comments/{$id}/reject", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);

        $this->em->clear();
        self::assertSame(CommentStatus::Pending, $this->em->getRepository(Rating::class)->find($id)->commentStatus);
    }

    public function testRejectGreenPathHidesTextKeepsStarAndWritesAudit(): void
    {
        $admin = $this->createAdmin('chef-cmtr2@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin); // 2 Passkeys, frische Session
        $rating = $this->seedPendingComment('com.example.reject-cmt', 4);
        $id = $rating->id;

        $this->client->request('POST', "/api/admin/comments/{$id}/reject", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Rating::class)->find($id);
        self::assertSame(CommentStatus::Rejected, $reloaded->commentStatus);
        self::assertSame(4, $reloaded->stars); // Stern bleibt
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, static fn ($a) => $a->action === 'comment.reject'));
    }

    public function testApproveUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('chef-cmt404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $this->client->request('POST', '/api/admin/comments/999999/approve', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 2: Test ausführen – erwartet Fehler (Routen fehlen)**

Run: `php backend/bin/phpunit --filter AdminCommentModerationTest; echo "EXIT:$?"`
Expected: FAIL – die 200/204-erwartenden Aufrufe liefern 404 (Routen existieren noch nicht).

- [ ] **Step 3: CommentQueueController implementieren**

Create `backend/src/Controller/Admin/CommentQueueController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\RatingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert die Kommentar-Moderations-Warteschlange: Bewertungen mit wartendem
 * Kommentar (commentStatus = pending), älteste zuerst. Ungeschützt-lesend wie
 * QueueController (die Aktionen selbst tragen die Gates).
 */
final class CommentQueueController
{
    #[Route('/api/admin/comments', methods: ['GET'])]
    public function __invoke(RatingRepository $ratings): JsonResponse
    {
        $out = [];
        foreach ($ratings->pendingComments() as $rating) {
            $out[] = [
                'id' => $rating->id,
                'entryId' => $rating->entry->id,
                'formatId' => $rating->entry->formatId,
                'stars' => $rating->stars,
                'comment' => $rating->comment,
                'createdAt' => $rating->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse($out);
    }
}
```

- [ ] **Step 4: CommentApproveController implementieren**

Create `backend/src/Controller/Admin/CommentApproveController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\RatingRepository;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gibt einen wartenden Bewertungs-Kommentar frei. Kein Step-up (niedrig-
 * geschützt wie VersionApprove). Mutation + Audit atomar; der Status-Guard aus
 * ModerationService wird als Sentinel abgefangen und erst NACH dem Commit als
 * 409 geworfen (ein Throw aus wrapInTransaction schlösse den EntityManager).
 */
final class CommentApproveController
{
    #[Route('/api/admin/comments/{id}/approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        RatingRepository $ratings,
        ModerationService $moderation,
        AuditLogger $audit,
        Security $security,
        EntityManagerInterface $em,
    ): Response {
        $rating = $ratings->find($id) ?? throw new ApiProblem(404, 'Rating not found');

        /** @var AdminUser $actor */
        $actor = $security->getUser();

        $conflict = $em->wrapInTransaction(function () use ($moderation, $rating, $audit, $actor, $em): ?string {
            // Die Rating-Zeile ist das Aggregat: serialisiert paralleles
            // Approve/Reject auf demselben Kommentar.
            $em->lock($rating, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($rating);
            try {
                $moderation->approveComment($rating);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $audit->log($actor, 'comment.approve', 'comment', (string) $rating->id);

            return null;
        });

        if ($conflict !== null) {
            throw new ApiProblem(409, $conflict);
        }

        return new Response('', 204);
    }
}
```

- [ ] **Step 5: CommentRejectController implementieren**

Create `backend/src/Controller/Admin/CommentRejectController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\RatingRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lehnt einen wartenden Bewertungs-Kommentar ab (blendet nur den Text aus, der
 * Stern bleibt im Aggregat). Destruktive Moderationsaktion: Backup-Passkey-Gate
 * (>= 2 Passkeys) + frisches Step-up, gespiegelt an VersionReject. Gates VOR der
 * Transaktion; Status-Guard als Sentinel → 409 nach dem Commit.
 */
final class CommentRejectController
{
    #[Route('/api/admin/comments/{id}/reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        RatingRepository $ratings,
        ModerationService $moderation,
        AuditLogger $audit,
        Security $security,
        StepUpGuard $stepUp,
        BackupPasskeyGate $backup,
        EntityManagerInterface $em,
    ): Response {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $backup->assertEnough($actor);
        $stepUp->assertFresh();

        $rating = $ratings->find($id) ?? throw new ApiProblem(404, 'Rating not found');

        $conflict = $em->wrapInTransaction(function () use ($moderation, $rating, $audit, $actor, $em): ?string {
            $em->lock($rating, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($rating);
            try {
                $moderation->rejectComment($rating);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $audit->log($actor, 'comment.reject', 'comment', (string) $rating->id);

            return null;
        });

        if ($conflict !== null) {
            throw new ApiProblem(409, $conflict);
        }

        return new Response('', 204);
    }
}
```

- [ ] **Step 6: Test ausführen – erwartet grün**

Run: `php backend/bin/phpunit --filter AdminCommentModerationTest; echo "EXIT:$?"`
Expected: PASS (7 Tests), `EXIT:0`.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Controller/Admin/CommentQueueController.php backend/src/Controller/Admin/CommentApproveController.php backend/src/Controller/Admin/CommentRejectController.php backend/tests/Functional/Admin/AdminCommentModerationTest.php
git commit -m "Ergänze Admin-API für Kommentar-Moderation"
```

---

### Task 2: Frontend – Admin-SPA Queue-Sektion »Kommentare«

Drei API-Wrapper + eine Kommentar-Sektion auf der Queue-Seite (Approve direkt, Reject via Step-up), i18n en/de, Tests.

**Files:**
- Modify: `frontend/src/lib/admin/api.ts` (Typ + drei Wrapper)
- Modify: `frontend/src/routes/admin/queue/+page.svelte` (Kommentar-Sektion + Handler)
- Modify: `frontend/messages/en.json`, `frontend/messages/de.json` (+ regenerierte `frontend/src/lib/paraglide/messages.js`)
- Modify: `frontend/src/lib/admin/api.test.ts`, `frontend/src/routes/admin/queue/queue.test.ts`

**Interfaces:**
- Consumes (Task 1): `GET /api/admin/comments`, `POST /api/admin/comments/{id}/approve|reject`.
- Produces: `commentQueue()`, `approveComment(id)`, `rejectComment(id)`, Typ `PendingComment`.

- [ ] **Step 1: API-Wrapper + Typ ergänzen**

In `frontend/src/lib/admin/api.ts` bei den übrigen Typen (nahe `QueueResponse`) den Typ ergänzen:

```ts
/** Element von GET /comments (wartende Kommentare). */
export interface PendingComment {
	id: number;
	entryId: number;
	formatId: string;
	stars: number;
	comment: string | null;
	createdAt: string;
}
```

Und bei den Moderations-Wrappern (nahe `approveVersion`/`rejectVersion`) ergänzen:

```ts
export const commentQueue = (o?: AdminClientOpts) =>
	adminFetch<PendingComment[]>('/api/admin/comments', {}, o);

export const approveComment = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/comments/${id}/approve`, { method: 'POST' }, o);

export const rejectComment = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/comments/${id}/reject`, { method: 'POST' }, o);
```

- [ ] **Step 2: i18n-Strings ergänzen (en + de)**

In `frontend/messages/en.json` neben den `admin_queue_*`-Keys ergänzen:

```json
	"admin_queue_comments_heading": "Pending comments",
	"admin_queue_comment_stars": "{count} stars",
```

In `frontend/messages/de.json` an gleicher Stelle:

```json
	"admin_queue_comments_heading": "Wartende Kommentare",
	"admin_queue_comment_stars": "{count} Sterne",
```

(Beim JSON-Einfügen auf gültiges JSON achten – Komma nach dem vorherigen Key. Die übrigen benötigten Strings – Approve/Reject-Buttons, `*_failed`, `backup_required` – existieren bereits und werden wiederverwendet.)

- [ ] **Step 3: Queue-Seite um die Kommentar-Sektion erweitern**

In `frontend/src/routes/admin/queue/+page.svelte`:

Den Import-Block um die neuen Symbole erweitern:

```ts
	import {
		queue,
		approveEntry,
		rejectEntry,
		approveVersion,
		rejectVersion,
		commentQueue,
		approveComment,
		rejectComment,
		AdminApiError,
		type QueueEntry,
		type QueueVersion,
		type PendingComment
	} from '$lib/admin/api';
```

State ergänzen (neben `versions`/`busyVersionId`/`versionActionError`):

```ts
	let comments = $state<PendingComment[]>([]);
	let busyCommentId = $state<number | null>(null);
	let commentActionError = $state<string | null>(null);
```

`loadQueue()` beide Quellen laden lassen:

```ts
	async function loadQueue() {
		loading = true;
		loadError = null;
		try {
			const [q, c] = await Promise.all([queue(), commentQueue()]);
			entries = q.entries;
			versions = q.versions;
			comments = c;
		} catch {
			loadError = m.admin_queue_load_failed();
		} finally {
			loading = false;
		}
	}
```

Handler ergänzen (analog zu Version-Approve/Reject):

```ts
	async function onApproveComment(id: number) {
		busyCommentId = id;
		commentActionError = null;
		try {
			await approveComment(id);
			await loadQueue();
		} catch {
			commentActionError = m.admin_queue_approve_failed();
		} finally {
			busyCommentId = null;
		}
	}

	async function onRejectComment(id: number) {
		busyCommentId = id;
		commentActionError = null;
		try {
			await withStepUp(() => rejectComment(id));
			await loadQueue();
		} catch (e) {
			if (e instanceof AdminApiError && e.backupRequired) {
				commentActionError = m.admin_queue_backup_required();
			} else {
				commentActionError = m.admin_queue_reject_failed();
			}
		} finally {
			busyCommentId = null;
		}
	}
```

Die Empty-Bedingung um `comments` erweitern:

```svelte
{:else if entries.length === 0 && versions.length === 0 && comments.length === 0}
	<EmptyState title={m.admin_queue_empty_title()} />
```

Und nach der Versions-`<section>` eine neue Sektion einfügen:

```svelte
	<section>
		<h2>{m.admin_queue_comments_heading()}</h2>
		{#if comments.length === 0}
			<p class="queue-section-empty">{m.admin_queue_empty_title()}</p>
		{:else}
			<div class="queue-list">
				{#each comments as comment (comment.id)}
					<div class="card queue-card">
						<div class="queue-card-head">
							<a
								class="queue-format-link"
								href={localizeHref(`/admin/entries/${comment.entryId}`)}
							>
								{comment.formatId}
							</a>
							<span class="queue-semver">{m.admin_queue_comment_stars({ count: comment.stars })}</span>
						</div>
						{#if comment.comment}<p class="queue-comment-text">{comment.comment}</p>{/if}
						<span class="queue-meta">
							{new Date(comment.createdAt).toLocaleDateString(getLocale())}
						</span>
						<div class="queue-actions">
							<button
								class="btn btn-primary"
								onclick={() => onApproveComment(comment.id)}
								disabled={busyCommentId === comment.id}
							>
								{m.admin_queue_approve_button()}
							</button>
							<button
								class="btn btn-danger"
								onclick={() => onRejectComment(comment.id)}
								disabled={busyCommentId === comment.id}
							>
								{m.admin_queue_reject_button()}
							</button>
						</div>
					</div>
				{/each}
			</div>
		{/if}
		{#if commentActionError}<AdminError message={commentActionError} />{/if}
	</section>
```

(Optional eine `.queue-comment-text`-Regel im `<style>` ergänzen, z. B. `.queue-comment-text { flex: 1 1 100%; margin: 0; color: var(--text-secondary); }` – rein kosmetisch.)

- [ ] **Step 4: api.test.ts erweitern**

In `frontend/src/lib/admin/api.test.ts` einen Test ergänzen, der die drei neuen Wrapper auf URL/Methode prüft (Muster der bestehenden Wrapper-Tests). Import um `commentQueue, approveComment, rejectComment` erweitern und:

```ts
	it('commentQueue/approveComment/rejectComment treffen die richtigen Endpunkte', async () => {
		const fetchMock = vi.fn().mockResolvedValue(new Response('', { status: 204 }));
		await approveComment(5, { fetch: fetchMock, baseUrl: BASE });
		expect(fetchMock.mock.calls[0][0]).toBe(`${BASE}/api/admin/comments/5/approve`);
		expect(fetchMock.mock.calls[0][1].method).toBe('POST');

		await rejectComment(6, { fetch: fetchMock, baseUrl: BASE });
		expect(fetchMock.mock.calls[1][0]).toBe(`${BASE}/api/admin/comments/6/reject`);
		expect(fetchMock.mock.calls[1][1].method).toBe('POST');

		const listMock = vi.fn().mockResolvedValue(jsonResponse([]));
		await commentQueue({ fetch: listMock, baseUrl: BASE });
		expect(listMock.mock.calls[0][0]).toBe(`${BASE}/api/admin/comments`);
	});
```

- [ ] **Step 5: queue.test.ts erweitern**

In `frontend/src/routes/admin/queue/queue.test.ts` die gemockte API um die drei neuen Funktionen erweitern (sonst ist `commentQueue` beim Mount `undefined`):

Im `vi.hoisted`-Block und `vi.mock('$lib/admin/api', …)` ergänzen: `commentQueue: vi.fn()`, `approveComment: vi.fn()`, `rejectComment: vi.fn()`. In jedem bestehenden Test, der `queue.mockResolvedValue(...)` setzt, zusätzlich `commentQueue.mockResolvedValue([])` setzen (bzw. in `beforeEach` einen Default `commentQueue.mockResolvedValue([])`). Dann einen neuen Test ergänzen:

```ts
	it('rendert wartende Kommentare und gibt einen frei', async () => {
		queue.mockResolvedValue({ entries: [], versions: [] });
		commentQueue.mockResolvedValue([
			{ id: 7, entryId: 1, formatId: 'com.example.rated', stars: 4, comment: 'nett', createdAt: '2026-01-03T10:00:00Z' }
		]);
		approveComment.mockResolvedValue(undefined);

		render(QueuePage);
		await waitFor(() => expect(screen.getByText('com.example.rated')).toBeInTheDocument());
		expect(screen.getByText('nett')).toBeInTheDocument();

		const approveButtons = screen.getAllByRole('button', { name: /freigeben|approve/i });
		await fireEvent.click(approveButtons[0]);
		await waitFor(() => expect(approveComment).toHaveBeenCalledWith(7));
	});

	it('lehnt einen Kommentar über withStepUp ab', async () => {
		queue.mockResolvedValue({ entries: [], versions: [] });
		commentQueue.mockResolvedValue([
			{ id: 8, entryId: 1, formatId: 'com.example.rated2', stars: 2, comment: 'mies', createdAt: '2026-01-03T10:00:00Z' }
		]);
		rejectComment.mockResolvedValue(undefined);

		render(QueuePage);
		await waitFor(() => expect(screen.getByText('com.example.rated2')).toBeInTheDocument());

		const rejectButtons = screen.getAllByRole('button', { name: /ablehnen|reject/i });
		await fireEvent.click(rejectButtons[0]);
		await waitFor(() => expect(rejectComment).toHaveBeenCalledWith(8));
		expect(withStepUp).toHaveBeenCalledWith(expect.any(Function));
	});
```

Beachte: Der bestehende Test »zeigt EmptyState bei leerer Warteschlange« braucht jetzt auch `commentQueue.mockResolvedValue([])` (über den `beforeEach`-Default abgedeckt), damit die Empty-Bedingung greift.

- [ ] **Step 6: Frontend-Tests + TS-Check ausführen**

Stelle sicher, dass **kein** `vite dev` läuft. Dann:

Run: `npm --prefix frontend run test -- run src/lib/admin/api.test.ts src/routes/admin/queue/queue.test.ts`
Expected: alle grün. (Das `test`-Script kompiliert Paraglide vorab, sodass `m.admin_queue_comments_heading()`/`m.admin_queue_comment_stars(...)` existieren.)

Run: `npm --prefix frontend run check`
Expected: 0 errors (svelte-check/TypeScript).

- [ ] **Step 7: Volle Frontend-Suite (Regression)**

Run: `npm --prefix frontend run test -- run`
Expected: alle grün (die geänderte Queue-Seite darf keine anderen Tests brechen).

- [ ] **Step 8: Commit**

```bash
git add frontend/src/lib/admin/api.ts frontend/src/routes/admin/queue/+page.svelte frontend/messages/en.json frontend/messages/de.json frontend/src/lib/paraglide/messages.js frontend/src/lib/admin/api.test.ts frontend/src/routes/admin/queue/queue.test.ts
git commit -m "Ergänze Kommentar-Moderation in der Admin-Queue-Oberfläche"
```

(Die regenerierte `frontend/src/lib/paraglide/messages.js` gehört mit in den Commit – sie ist getrackt. Prüfe mit `git status`, ob weitere generierte Paraglide-Dateien geändert wurden, und stage sie mit.)

---

## Self-Review

- **Spec coverage:** §3 Backend-Endpunkte → Task 1 (queue/approve/reject, Gates, Locking, Audit, Sentinel-409). §4 Frontend → Task 2 (Wrapper, Queue-Sektion, withStepUp, i18n). §5 Sicherheit → Gates gespiegelt (approve ohne, reject mit beiden), Audit-Atomarität. §6 Tests → Backend 7 Fälle (inkl. 401, 409-Guard, Backup-Gate-409, Reject-behält-Stern, 404) + Frontend (api-URLs + Queue-Render/Approve/Reject).
- **Statuscodes:** Backup-Gate → 409, Step-up → 403, unbekannte id → 404, Guard-Konflikt → 409 (verifiziert an `BackupPasskeyGate`/`StepUpGuard`/`ModerationTest`).
- **Placeholder-Scan:** kein TBD; jeder Code-Schritt vollständig. Der Frontend-Diff beschreibt gezielte Einfügungen in bestehende Dateien (nicht Voll-Neuschrieb), mit exakten Ankern.
- **Typkonsistenz:** `PendingComment {id, formatId, stars, comment, createdAt}` einheitlich Backend↔Frontend; Audit-Aktionen `comment.approve`/`comment.reject`; `ModerationService::approveComment/rejectComment(Rating)` aus D unverändert.
- **Konventionstreue:** invokable Controller wie VersionApprove/Reject; `wrapInTransaction`+Sentinel+Lock+Audit-Muster 1:1; Frontend-Wrapper/Handler/i18n im bestehenden `admin_queue_*`-Schema; Paraglide-Kompilat mit-committen.
