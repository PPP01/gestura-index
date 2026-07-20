<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Entity\Report;
use App\Enum\EntryStatus;
use App\Enum\ReportStatus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

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

    public function testResolvePublishClearsAllOpenReportsToPreventImmediateReHide(): void
    {
        $this->createPublishedEntry('com.example.shop');

        for ($i = 0; $i < 3; ++$i) { // REPORT_HIDE_THRESHOLD = 3
            $this->api('POST', '/api/v1/entries/com.example.shop/report', ['reason' => 'spam']);
        }
        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(EntryStatus::Hidden, $entry->status);
        $reports = $this->em->getRepository(Report::class)->findBy(['entry' => $entry]);

        $console = new Application(self::$kernel);
        $tester = new CommandTester($console->find('index:resolve'));
        $tester->execute(['reportId' => (string) $reports[0]->id, '--action' => 'publish']);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(EntryStatus::Published, $entry->status);
        foreach ($this->em->getRepository(Report::class)->findBy(['entry' => $entry]) as $report) {
            self::assertSame(ReportStatus::Resolved, $report->status);
        }

        // Nur 1 neue Meldung (< Threshold 3) darf den Eintrag nicht
        // erneut verstecken — die alten offenen Meldungen dürfen dafür
        // nicht mehr mitzählen.
        $this->api('POST', '/api/v1/entries/com.example.shop/report', ['reason' => 'spam']);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(EntryStatus::Published, $entry->status);
    }
}
