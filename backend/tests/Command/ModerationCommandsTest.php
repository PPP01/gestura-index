<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Report;
use App\Entity\Submitter;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\VersionStatus;
use App\Repository\ReportRepository;
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

    private function runCommand(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->console->find($name));
        $tester->execute($input);

        return $tester;
    }

    public function testQueueListsPendingEntries(): void
    {
        $this->createPendingEntry();

        $tester = $this->runCommand('index:queue');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('com.example.neu', $tester->getDisplay());
    }

    public function testApprovePublishesEntryAndCountsApproval(): void
    {
        $entry = $this->createPendingEntry();

        $tester = $this->runCommand('index:approve', ['formatId' => 'com.example.neu']);

        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame(EntryStatus::Published, $entry->status);
        self::assertSame(VersionStatus::Approved, $entry->currentVersion->status);
        self::assertSame('1.0.0', $entry->currentVersion->semver);
        self::assertSame(1, $entry->submitter->approvedCount);
        self::assertNotSame('', $entry->searchText);
    }

    public function testApproveHandlesMissingPendingVersionGracefully(): void
    {
        $entry = $this->createPendingEntry();
        $version = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $version->status = VersionStatus::Rejected;
        $this->em->flush();

        $tester = $this->runCommand('index:approve', ['formatId' => 'com.example.neu']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Keine wartende Version für com.example.neu', $tester->getDisplay());
    }

    public function testApprovePublishesHiddenEntryWithoutPendingVersion(): void
    {
        $entry = $this->createPendingEntry();
        $version = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $version->status = VersionStatus::Approved;
        $entry->currentVersion = $version;
        $entry->status = EntryStatus::Hidden;
        $this->em->flush();

        $tester = $this->runCommand('index:approve', ['formatId' => 'com.example.neu']);

        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame(EntryStatus::Published, $entry->status);
    }

    public function testApproveOfHiddenEntryResolvesOpenReportsAndPreventsImmediateReHide(): void
    {
        $entry = $this->createPendingEntry();
        $version = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $version->status = VersionStatus::Approved;
        $entry->currentVersion = $version;
        $entry->status = EntryStatus::Hidden;
        for ($i = 0; $i < 3; ++$i) { // REPORT_HIDE_THRESHOLD = 3
            $this->em->persist(new Report($entry, ReportReason::Spam, null));
        }
        $this->em->flush();

        $tester = $this->runCommand('index:approve', ['formatId' => 'com.example.neu']);

        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame(EntryStatus::Published, $entry->status);
        foreach ($this->em->getRepository(Report::class)->findBy(['entry' => $entry]) as $report) {
            self::assertSame(ReportStatus::Resolved, $report->status);
        }

        // Ohne die Report-Auflösung im hidden-Branch von index:approve würde
        // dieser Seitenpfad den Re-Hide-Loop aus Fix 2 wieder öffnen: eine
        // einzelne neue Meldung (< Threshold 3) darf den Eintrag NICHT
        // erneut verstecken.
        $this->em->persist(new Report($entry, ReportReason::Spam, null));
        $this->em->flush();
        $reports = self::getContainer()->get(ReportRepository::class);
        self::assertSame(1, $reports->countOpenFor($entry));
    }

    public function testApproveFailsCleanlyForHiddenEntryWithoutCurrentVersion(): void
    {
        $entry = $this->createPendingEntry();
        $version = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $version->status = VersionStatus::Rejected; // keine pending-Version mehr vorhanden
        $entry->status = EntryStatus::Hidden; // currentVersion bleibt null
        $this->em->flush();

        $tester = $this->runCommand('index:approve', ['formatId' => 'com.example.neu']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('keine freigegebene Version', $tester->getDisplay());
    }

    private function addVersion(Entry $entry, string $semver, VersionStatus $status): EntryVersion
    {
        $payload = ['gesturaMenu' => 1, 'id' => $entry->formatId, 'version' => $semver, 'name' => 'V' . $semver,
            'items' => [['id' => 'a', 'label' => 'A', 'action' => 'newTab']]];
        $version = new EntryVersion($entry, $semver, $payload, (new PayloadAnalyzer())->contentHash($payload));
        $version->status = $status;
        $this->em->persist($version);
        $this->em->flush();

        return $version;
    }

    public function testApprovingOlderPendingVersionDoesNotDowngradeCurrentVersion(): void
    {
        $entry = $this->createPendingEntry();
        $current = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $current->status = VersionStatus::Approved;
        $current->semver = '2.0.0';
        $entry->currentVersion = $current;
        $entry->status = EntryStatus::Published;
        $this->em->flush();

        // Eine ältere Transform-Version wartet weiter in der Queue.
        $this->addVersion($entry, '1.5.0', VersionStatus::Pending);

        $this->runCommand('index:approve', ['formatId' => 'com.example.neu'])->assertCommandIsSuccessful();

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        // currentVersion darf NICHT auf die ältere 1.5.0 zurückfallen.
        self::assertSame('2.0.0', $entry->currentVersion->semver);
        $older = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry, 'semver' => '1.5.0']);
        self::assertSame(VersionStatus::Approved, $older->status); // trotzdem als Version freigegeben
    }

    public function testApprovingNewerPendingVersionAdvancesCurrentVersion(): void
    {
        $entry = $this->createPendingEntry();
        $current = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $current->status = VersionStatus::Approved;
        $entry->currentVersion = $current; // 1.0.0
        $entry->status = EntryStatus::Published;
        $this->em->flush();

        $this->addVersion($entry, '2.0.0', VersionStatus::Pending);

        $this->runCommand('index:approve', ['formatId' => 'com.example.neu'])->assertCommandIsSuccessful();

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame('2.0.0', $entry->currentVersion->semver);
    }

    public function testRejectDeletesEntry(): void
    {
        $this->createPendingEntry();

        $this->runCommand('index:reject', ['formatId' => 'com.example.neu'])->assertCommandIsSuccessful();

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

        $this->runCommand('index:ban', ['submitterId' => (string) $submitterId])->assertCommandIsSuccessful();

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertTrue($entry->submitter->banned);
        self::assertSame(EntryStatus::Hidden, $entry->status);
    }

    public function testBanUnknownSubmitterFails(): void
    {
        $tester = $this->runCommand('index:ban', ['submitterId' => '999999']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Unbekannter Submitter', $tester->getDisplay());
    }

    public function testUnbanLiftsBanButKeepsEntriesHidden(): void
    {
        $entry = $this->createPendingEntry();
        $entry->status = EntryStatus::Published;
        $this->em->flush();
        $submitterId = $entry->submitter->id;
        $this->runCommand('index:ban', ['submitterId' => (string) $submitterId])->assertCommandIsSuccessful();

        $tester = $this->runCommand('index:ban', ['submitterId' => (string) $submitterId, '--unban' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Sperre aufgehoben', $tester->getDisplay());
        $this->em->clear();
        $submitter = $this->em->getRepository(Submitter::class)->find($submitterId);
        self::assertFalse($submitter->banned);
        // Unban hebt nur die Sperre auf — die Wiederveröffentlichung der
        // Einträge erfolgt bewusst separat per index:approve/index:resolve.
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame(EntryStatus::Hidden, $entry->status);
    }

    public function testRejectUnknownFormatIdFails(): void
    {
        $tester = $this->runCommand('index:reject', ['formatId' => 'com.example.unbekannt']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Unbekannte formatId', $tester->getDisplay());
    }

    public function testRejectVersionOfPublishedEntryKeepsEntryPublished(): void
    {
        $entry = $this->createPendingEntry();
        $current = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $current->status = VersionStatus::Approved;
        $entry->currentVersion = $current;
        $entry->status = EntryStatus::Published;
        $this->em->flush();
        $this->addVersion($entry, '2.0.0', VersionStatus::Pending);

        $tester = $this->runCommand('index:reject', ['formatId' => 'com.example.neu']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2.0.0 abgelehnt', $tester->getDisplay());
        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame(EntryStatus::Published, $entry->status); // Entry bleibt bestehen
        $rejected = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry, 'semver' => '2.0.0']);
        self::assertSame(VersionStatus::Rejected, $rejected->status);
    }

    public function testRejectFailsWhenNothingToReject(): void
    {
        $entry = $this->createPendingEntry();
        $version = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $version->status = VersionStatus::Approved;
        $entry->currentVersion = $version;
        $entry->status = EntryStatus::Published;
        $this->em->flush();

        $tester = $this->runCommand('index:reject', ['formatId' => 'com.example.neu']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Nichts abzulehnen', $tester->getDisplay());
    }

    public function testApproveUnknownFormatIdFails(): void
    {
        $tester = $this->runCommand('index:approve', ['formatId' => 'com.example.unbekannt']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Unbekannte formatId', $tester->getDisplay());
    }

    public function testApproveFailsWhenNothingToApprove(): void
    {
        $entry = $this->createPendingEntry();
        $version = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $version->status = VersionStatus::Approved;
        $entry->currentVersion = $version;
        $entry->status = EntryStatus::Published;
        $this->em->flush();

        $tester = $this->runCommand('index:approve', ['formatId' => 'com.example.neu']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Nichts freizugeben', $tester->getDisplay());
    }

    public function testResolveUnknownReportIdFails(): void
    {
        $tester = $this->runCommand('index:resolve', ['reportId' => '999999', '--action' => 'publish']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Unbekannte Meldung oder --action fehlt', $tester->getDisplay());
    }

    public function testResolveMissingActionFails(): void
    {
        $entry = $this->createPendingEntry();
        $entry->status = EntryStatus::Published;
        $report = new Report($entry, ReportReason::Spam, null);
        $this->em->persist($report);
        $this->em->flush();

        $tester = $this->runCommand('index:resolve', ['reportId' => (string) $report->id]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Unbekannte Meldung oder --action fehlt', $tester->getDisplay());
    }

    public function testQueueListsPendingVersionsOfPublishedEntries(): void
    {
        $entry = $this->createPendingEntry();
        $current = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $current->status = VersionStatus::Approved;
        $entry->currentVersion = $current;
        $entry->status = EntryStatus::Published;
        $this->em->flush();
        $this->addVersion($entry, '2.0.0', VersionStatus::Pending);

        $tester = $this->runCommand('index:queue');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2.0.0', $tester->getDisplay());
    }

    public function testReportsShowsEmptyMessageWhenNoneOpen(): void
    {
        $tester = $this->runCommand('index:reports');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Keine Meldungen', $tester->getDisplay());
    }

    public function testReportsListsOpenReportsAsTable(): void
    {
        $entry = $this->createPendingEntry();
        $entry->status = EntryStatus::Published;
        $report = new Report($entry, ReportReason::Spam, str_repeat('lang genug, um gekürzt zu werden ', 3));
        $this->em->persist($report);
        $this->em->flush();

        $tester = $this->runCommand('index:reports');

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('com.example.neu', $display);
        self::assertStringContainsString('spam', $display);
        self::assertStringContainsString('open', $display);
        self::assertStringContainsString('…', $display); // gekürzter Kommentar (mb_strimwidth)
    }

    public function testReportsAllOptionAlsoShowsResolvedReports(): void
    {
        $entry = $this->createPendingEntry();
        $entry->status = EntryStatus::Published;
        $report = new Report($entry, ReportReason::Legal, null);
        $report->status = ReportStatus::Resolved;
        $this->em->persist($report);
        $this->em->flush();

        // Ohne --all bleibt die erledigte Meldung unsichtbar.
        $tester = $this->runCommand('index:reports');
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Keine Meldungen', $tester->getDisplay());

        $tester = $this->runCommand('index:reports', ['--all' => true]);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('com.example.neu', $display);
        self::assertStringContainsString('legal', $display);
        self::assertStringContainsString('resolved', $display);
    }
}
