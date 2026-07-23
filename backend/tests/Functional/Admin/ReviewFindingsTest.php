<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Report;
use App\Entity\WebAuthnCredential;
use App\Enum\AdminRole;
use App\Enum\EntryStatus;
use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\VersionStatus;
use App\Service\PayloadAnalyzer;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Regressionstests zum zweiten Review (2026-07-23). Sichern die dort
 * geschlossenen Lücken ab: Statusguards der Moderation (Finding 1),
 * Step-up beim destruktiven Report-Löschen (3a), Backup-Passkey-Gate beim
 * Deaktivieren (3b), Idle-Timeout (4), Passkey-Mindestanzahl (5) und die
 * Invite-Eingabevalidierung (7).
 */
final class ReviewFindingsTest extends AdminTestCase
{
    // ---- Finding 1: Statusguards der Moderation ------------------------------

    public function testRejectingAnApprovedVersionConflicts(): void
    {
        $admin = $this->createAdmin('reg-rejapproved@example.com');
        $this->loginWithCredentials($admin, 2);
        $entry = $this->createPublishedEntry('com.example.rejapproved');
        $approvedVersionId = $entry->currentVersion->id;

        $this->client->request('POST', "/api/admin/versions/{$approvedVersionId}/reject", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
    }

    public function testApprovingAnAlreadyApprovedVersionConflicts(): void
    {
        $admin = $this->createAdmin('reg-appapproved@example.com');
        $this->loginWithCredentials($admin, 2);
        $entry = $this->createPublishedEntry('com.example.appapproved');
        $approvedVersionId = $entry->currentVersion->id;

        $this->client->request('POST', "/api/admin/versions/{$approvedVersionId}/approve", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
    }

    public function testApprovingAPublishedEntryWithPendingUpdateConflicts(): void
    {
        $admin = $this->createAdmin('reg-apppublished@example.com');
        $this->loginWithCredentials($admin, 2);
        $entry = $this->createPublishedEntry('com.example.apppublished');
        $approvedCountBefore = $entry->submitter->approvedCount;

        // Ein pending Update auf einem bereits veröffentlichten Eintrag.
        $payload = [
            'gesturaMenu' => 1,
            'id' => $entry->formatId,
            'version' => '2.0.0',
            'name' => 'Update',
            'items' => [['id' => 'a', 'label' => 'A', 'action' => 'newTab']],
        ];
        $this->em->persist(new EntryVersion($entry, '2.0.0', $payload, (new PayloadAnalyzer())->contentHash($payload)));
        $this->em->flush();

        // Der Entry-Approve-Endpunkt darf einen published Eintrag nicht erneut
        // freigeben (das würde approvedCount doppelt hochzählen).
        $this->client->request('POST', "/api/admin/entries/{$entry->id}/approve", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Entry::class)->find($entry->id);
        self::assertSame($approvedCountBefore, $reloaded->submitter->approvedCount);
        // Das pending Update bleibt unangetastet (nur approveVersion gibt es frei).
        $pending = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $reloaded, 'semver' => '2.0.0']);
        self::assertSame(VersionStatus::Pending, $pending->status);
    }

    public function testResolvingReportOnDeletedEntryConflicts(): void
    {
        $admin = $this->createAdmin('reg-resdeleted@example.com');
        $this->loginWithCredentials($admin, 2);
        $entry = $this->createPublishedEntry('com.example.resdeleted');
        $report = new Report($entry, ReportReason::Spam, null);
        $this->em->persist($report);
        $entry->status = EntryStatus::Deleted;
        $this->em->flush();

        $this->client->request('POST', "/api/admin/reports/{$report->id}/resolve", server: $this->hdr(),
            content: json_encode(['publish' => true], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testRepublishingBannedSubmittersEntryConflicts(): void
    {
        $admin = $this->createAdmin('reg-resbanned@example.com');
        $this->loginWithCredentials($admin, 2);
        $entry = $this->createPublishedEntry('com.example.resbanned');
        $entry->status = EntryStatus::Hidden;
        $entry->submitter->banned = true;
        $report = new Report($entry, ReportReason::Spam, null);
        $this->em->persist($report);
        $this->em->flush();

        $this->client->request('POST', "/api/admin/reports/{$report->id}/resolve", server: $this->hdr(),
            content: json_encode(['publish' => true], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testResolvingAnAlreadyResolvedReportConflicts(): void
    {
        $admin = $this->createAdmin('reg-resresolved@example.com');
        $this->loginWithCredentials($admin, 2);
        $entry = $this->createPublishedEntry('com.example.resresolved');
        $report = new Report($entry, ReportReason::Spam, null);
        $report->status = ReportStatus::Resolved;
        $this->em->persist($report);
        $this->em->flush();

        $this->client->request('POST', "/api/admin/reports/{$report->id}/resolve", server: $this->hdr(),
            content: json_encode(['publish' => true], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    // ---- Finding 3a: Step-up beim destruktiven Report-Löschen ----------------

    public function testDeletingViaReportRequiresFreshStepUp(): void
    {
        $admin = $this->createAdmin('reg-stepup@example.com');
        // Echte zwei Passkeys anlegen (via Login-Helfer), damit das dem
        // Step-up vorgelagerte Backup-Gate passiert und der Test wirklich am
        // Step-up-Check hängt.
        $this->loginWithCredentials($admin, 2);
        $entry = $this->createPublishedEntry('com.example.stepupdelete');
        $report = new Report($entry, ReportReason::Spam, null);
        $this->em->persist($report);
        $this->em->flush();

        // Session mit veraltetem Step-up, aber frischer Aktivität überschreiben:
        // der Authenticator lässt durch, ReportResolve verlangt für publish:false
        // aber frisches Step-up → 403.
        $this->seedAdminSession($admin, verifiedAt: time() - 3600, lastActivity: time());

        $this->client->request('POST', "/api/admin/reports/{$report->id}/resolve", server: $this->hdr(),
            content: json_encode(['publish' => false], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletingViaReportRequiresBackupPasskey(): void
    {
        $admin = $this->createAdmin('reg-reportnobackup@example.com');
        $this->loginWithCredentials($admin, 1); // frisches Step-up, aber nur ein Passkey
        $entry = $this->createPublishedEntry('com.example.reportnobackup');
        $report = new Report($entry, ReportReason::Spam, null);
        $this->em->persist($report);
        $this->em->flush();

        $this->client->request('POST', "/api/admin/reports/{$report->id}/resolve", server: $this->hdr(),
            content: json_encode(['publish' => false], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    // ---- Finding 3b: Backup-Passkey-Gate beim Deaktivieren -------------------

    public function testDisablingUserRequiresBackupPasskey(): void
    {
        $admin = $this->createAdmin('reg-nobackup@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1); // nur ein Passkey → Backup-Gate greift
        $target = $this->createAdmin('reg-target@example.com', AdminRole::Moderator);

        $this->client->request('POST', "/api/admin/users/{$target->id}/disable", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
        self::assertTrue($this->client->getResponse()->getStatusCode() === 409);
    }

    // ---- Finding 4: Idle-Timeout ---------------------------------------------

    public function testStaleSessionIsRejectedByIdleTimeout(): void
    {
        $admin = $this->createAdmin('reg-idle@example.com', AdminRole::Admin);
        // Letzte Aktivität > 30 Minuten her → harte Abweisung mit 401.
        $this->seedAdminSession($admin, verifiedAt: time(), lastActivity: time() - 2000);

        $this->client->request('GET', '/api/admin/audit', server: $this->hdr());
        self::assertResponseStatusCodeSame(401);
    }

    public function testRecentSessionPassesIdleTimeout(): void
    {
        $admin = $this->createAdmin('reg-idleok@example.com', AdminRole::Admin);
        $this->seedAdminSession($admin, verifiedAt: time(), lastActivity: time() - 60);

        $this->client->request('GET', '/api/admin/audit', server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
    }

    // ---- Finding 5: Passkey-Mindestanzahl beim Entfernen ---------------------

    public function testRemovingCredentialKeepsMinimumOfTwo(): void
    {
        $admin = $this->createAdmin('reg-mintwo@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2); // exakt zwei Passkeys
        $cred = $this->em->getRepository(WebAuthnCredential::class)->findOneBy(['adminUser' => $admin]);

        $this->client->request('POST', "/api/admin/credentials/{$cred->id}/remove", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
    }

    // ---- Finding 7: Invite-Eingabevalidierung --------------------------------

    public function testInvitingWithInvalidEmailIs400(): void
    {
        $admin = $this->createAdmin('reg-invemail@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);

        $this->client->request('POST', '/api/admin/users', server: $this->hdr(),
            content: json_encode(['email' => 'not-an-email', 'displayName' => 'X', 'role' => 'moderator'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public function testInvitingWithOverlongEmailIs400(): void
    {
        $admin = $this->createAdmin('reg-longemail@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);
        $overlong = str_repeat('a', 190) . '@example.com';

        $this->client->request('POST', '/api/admin/users', server: $this->hdr(),
            content: json_encode(['email' => $overlong, 'displayName' => 'X', 'role' => 'moderator'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Seedet eine Admin-Session direkt im mock_file-Storage und hängt das
     * Cookie an den Client – erlaubt das Setzen von Zeitmarken
     * (_admin_verified_at / _admin_last_activity), die über den normalen
     * Login-Flow nicht frei wählbar sind.
     */
    private function seedAdminSession(AdminUser $user, int $verifiedAt, int $lastActivity): void
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('_admin_user_id', $user->id);
        $session->set('_admin_user_email', $user->email);
        $session->set('_admin_verified_at', $verifiedAt);
        $session->set('_admin_last_activity', $lastActivity);
        $session->save();

        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }
}
