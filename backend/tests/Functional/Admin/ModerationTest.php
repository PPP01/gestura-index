<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Entity\AuditLogEntry;
use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Report;
use App\Entity\Submitter;
use App\Enum\AdminRole;
use App\Enum\EntryStatus;
use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\VersionStatus;
use App\Service\PayloadAnalyzer;

final class ModerationTest extends AdminTestCase
{
    public function testApproveEntryWritesAudit(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);
        $entry = $this->createPendingEntry('com.example.pending');

        $this->client->request('POST', "/api/admin/entries/{$entry->id}/approve", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->find($entry->id);
        self::assertSame(EntryStatus::Published, $entry->status);
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, static fn ($a) => $a->action === 'entry.approve'));
    }

    public function testApproveEntryUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $this->client->request('POST', '/api/admin/entries/999999/approve', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * approveEntry() wirft eine RuntimeException, wenn keine wartende
     * Version existiert (z. B. ein bereits veröffentlichter Entry ohne
     * erneute Einreichung) — der Controller muss das als 409 melden.
     */
    public function testApproveEntryWithoutPendingVersionIs409(): void
    {
        $admin = $this->createAdmin('chef-noqueue@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);
        $entry = $this->createPublishedEntry('com.example.noqueue');

        $this->client->request('POST', "/api/admin/entries/{$entry->id}/approve", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
    }

    public function testEntryRejectUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('chef-reject404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin); // 2 Passkeys — Backup-Gate muss passieren

        $this->client->request('POST', '/api/admin/entries/999999/reject', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testVersionApproveUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('chef-va404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);

        $this->client->request('POST', '/api/admin/versions/999999/approve', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testVersionRejectUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('chef-vr404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $this->client->request('POST', '/api/admin/versions/999999/reject', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testSubmitterBanUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('chef-ban404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $this->client->request('POST', '/api/admin/submitters/999999/ban', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testSubmitterUnbanUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('chef-unban404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);

        $this->client->request('POST', '/api/admin/submitters/999999/unban', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testReportResolveUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('chef-report404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);

        $this->client->request('POST', '/api/admin/reports/999999/resolve', server: $this->hdr(),
            content: json_encode(['publish' => true], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    public function testReportResolveInvalidJsonIs400(): void
    {
        $admin = $this->createAdmin('chef-reportjson@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);
        $entry = $this->createPublishedEntry('com.example.reportjson');
        $report = new Report($entry, ReportReason::Spam, null);
        $this->em->persist($report);
        $this->em->flush();

        $this->client->request('POST', "/api/admin/reports/{$report->id}/resolve", server: $this->hdr(),
            content: 'not-json');
        self::assertResponseStatusCodeSame(400);
    }

    public function testReportResolveWithPublishFalseDeletesEntryAndWritesAudit(): void
    {
        $admin = $this->createAdmin('chef-reportfalse@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);
        $entry = $this->createPublishedEntry('com.example.reportfalse');
        $entry->status = EntryStatus::Hidden;
        $report = new Report($entry, ReportReason::Spam, 'spammt');
        $this->em->persist($report);
        $this->em->flush();

        $this->client->request('POST', "/api/admin/reports/{$report->id}/resolve", server: $this->hdr(),
            content: json_encode(['publish' => false], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->find($entry->id);
        $report = $this->em->getRepository(Report::class)->find($report->id);
        self::assertSame(EntryStatus::Deleted, $entry->status);
        self::assertSame(ReportStatus::Resolved, $report->status);
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, static fn ($a) => $a->action === 'report.resolve'));
    }

    public function testAdminEntryDetailUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('chef-detail404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);

        $this->client->request('GET', '/api/admin/entries/999999', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectEntryRequiresBackupGate(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1); // nur ein Passkey → Backup-Gate greift
        $entry = $this->createPendingEntry('com.example.onlyone');

        $this->client->request('POST', "/api/admin/entries/{$entry->id}/reject", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->find($entry->id);
        self::assertSame(EntryStatus::Pending, $entry->status); // unverändert
    }

    /**
     * Der Reports-Screen verlinkt auch auf published Entries — ohne den
     * Pending-Guard in ModerationService::rejectEntry() ließe sich ein
     * bereits veröffentlichter Eintrag über Reject hart löschen.
     */
    public function testRejectPublishedEntryIsRejected(): void
    {
        $admin = $this->createAdmin('chef-rejectpublished@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin); // 2 Passkeys, frische Session
        $entry = $this->createPublishedEntry('com.example.rejectpublished');

        $this->client->request('POST', "/api/admin/entries/{$entry->id}/reject", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->find($entry->id);
        self::assertSame(EntryStatus::Published, $entry->status); // unverändert, nicht gelöscht
    }

    public function testRejectEntryWritesAuditAndDeletesEntry(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin); // 2 Passkeys, frische Session
        $entry = $this->createPendingEntry('com.example.reject');

        $this->client->request('POST', "/api/admin/entries/{$entry->id}/reject", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->find($entry->id);
        self::assertSame(EntryStatus::Deleted, $entry->status);
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, static fn ($a) => $a->action === 'entry.reject'));
    }

    public function testVersionRejectWritesAudit(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);
        $entry = $this->createPublishedEntry('com.example.updatereject');
        $version = $this->addPendingVersion($entry, '1.1.0');

        $this->client->request('POST', "/api/admin/versions/{$version->id}/reject", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $version = $this->em->getRepository(EntryVersion::class)->find($version->id);
        self::assertSame(VersionStatus::Rejected, $version->status);
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, static fn ($a) => $a->action === 'version.reject'));
    }

    public function testVersionApproveWritesAuditAndAdvancesCurrentVersion(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);
        $entry = $this->createPublishedEntry('com.example.updateapprove');
        $version = $this->addPendingVersion($entry, '2.0.0');

        $this->client->request('POST', "/api/admin/versions/{$version->id}/approve", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->find($entry->id);
        self::assertSame('2.0.0', $entry->currentVersion->semver);
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, static fn ($a) => $a->action === 'version.approve'));
    }

    public function testBanHidesEntriesAndWritesAudit(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);
        $entry = $this->createPublishedEntry('com.example.ban');
        $submitterId = $entry->submitter->id;

        $this->client->request('POST', "/api/admin/submitters/{$submitterId}/ban", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->find($entry->id);
        self::assertSame(EntryStatus::Hidden, $entry->status);
        self::assertTrue($entry->submitter->banned);
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, static fn ($a) => $a->action === 'submitter.ban'));
    }

    public function testBanRequiresBackupGate(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);
        $entry = $this->createPublishedEntry('com.example.banonlyone');
        $submitterId = $entry->submitter->id;

        $this->client->request('POST', "/api/admin/submitters/{$submitterId}/ban", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
    }

    public function testUnbanDoesNotRequireBackupGateAndWritesAudit(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1); // nur ein Passkey — unban ist NICHT gated
        $entry = $this->createPublishedEntry('com.example.unban');
        $submitter = $entry->submitter;
        $submitter->banned = true;
        $this->em->flush();

        $this->client->request('POST', "/api/admin/submitters/{$submitter->id}/unban", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $submitter = $this->em->getRepository(Submitter::class)->find($submitter->id);
        self::assertFalse($submitter->banned);
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, static fn ($a) => $a->action === 'submitter.unban'));
    }

    public function testReportResolvePublishWritesAudit(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1); // resolve ist NICHT gated
        $entry = $this->createPublishedEntry('com.example.reported');
        $entry->status = EntryStatus::Hidden;
        $report = new Report($entry, ReportReason::Spam, 'spammt');
        $this->em->persist($report);
        $this->em->flush();

        $this->client->request('POST', "/api/admin/reports/{$report->id}/resolve", server: $this->hdr(),
            content: json_encode(['publish' => true], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->find($entry->id);
        $report = $this->em->getRepository(Report::class)->find($report->id);
        self::assertSame(EntryStatus::Published, $entry->status);
        self::assertSame(ReportStatus::Resolved, $report->status);
        $audits = $this->em->getRepository(AuditLogEntry::class)->findAll();
        self::assertNotEmpty(array_filter($audits, static fn ($a) => $a->action === 'report.resolve'));
    }

    public function testReportListReturnsOnlyOpenReports(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);
        $entry = $this->createPublishedEntry('com.example.reportlist');
        $open = new Report($entry, ReportReason::Misleading, 'irreführend');
        $resolved = new Report($entry, ReportReason::Spam, null);
        $resolved->status = ReportStatus::Resolved;
        $this->em->persist($open);
        $this->em->persist($resolved);
        $this->em->flush();

        $this->client->request('GET', '/api/admin/reports', server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
        $data = $this->json();
        $ids = array_column($data, 'id');
        self::assertContains($open->id, $ids);
        self::assertNotContains($resolved->id, $ids);

        $openItem = current(array_filter($data, static fn ($r) => $r['id'] === $open->id));
        self::assertNotFalse($openItem);
        self::assertSame($entry->submitter->id, $openItem['submitterId']);
        self::assertFalse($openItem['submitterBanned']);
    }

    public function testQueueListsPendingEntryAndHighlightsTransformVersion(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);
        $pending = $this->createPendingEntry('com.example.queued');
        $published = $this->createPublishedEntry('com.example.transformupdate');
        $transformVersion = $this->addPendingVersion($published, '1.1.0', true);

        $this->client->request('GET', '/api/admin/queue', server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
        $data = $this->json();

        $entryIds = array_column($data['entries'], 'formatId');
        self::assertContains('com.example.queued', $entryIds);

        $versionEntry = current(array_filter($data['versions'], static fn ($v) => $v['id'] === $transformVersion->id));
        self::assertNotFalse($versionEntry);
        self::assertTrue($versionEntry['hasTransformCode']);
    }

    public function testEntryDetailReturnsVersionsAndOpenReports(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);
        $entry = $this->createPublishedEntry('com.example.detail');
        $report = new Report($entry, ReportReason::BrokenLinks, 'kaputt');
        $this->em->persist($report);
        $this->em->flush();

        $this->client->request('GET', "/api/admin/entries/{$entry->id}", server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
        $data = $this->json();

        self::assertSame('com.example.detail', $data['formatId']);
        self::assertSame('published', $data['status']);
        self::assertCount(1, $data['versions']);
        self::assertCount(1, $data['openReports']);
        self::assertSame($entry->submitter->id, $data['submitterId']);
        self::assertFalse($data['submitterBanned']);
    }

    public function testEntryDetailExposesBannedSubmitter(): void
    {
        $admin = $this->createAdmin('chef-detailbanned@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);
        $entry = $this->createPublishedEntry('com.example.detailbanned');
        $entry->submitter->banned = true;
        $this->em->flush();

        $this->client->request('GET', "/api/admin/entries/{$entry->id}", server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
        $data = $this->json();

        self::assertSame($entry->submitter->id, $data['submitterId']);
        self::assertTrue($data['submitterBanned']);
    }

    private function addPendingVersion(Entry $entry, string $semver, bool $hasTransform = false): EntryVersion
    {
        $payload = [
            'gesturaMenu' => 1,
            'id' => $entry->formatId,
            'version' => $semver,
            'name' => 'Update ' . $semver,
            'items' => [['id' => 'a', 'label' => 'A', 'action' => 'newTab']],
        ];
        $version = new EntryVersion($entry, $semver, $payload, (new PayloadAnalyzer())->contentHash($payload));
        $version->hasTransformCode = $hasTransform;
        $this->em->persist($version);
        $this->em->flush();

        return $version;
    }
}
