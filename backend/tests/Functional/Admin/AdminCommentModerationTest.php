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
