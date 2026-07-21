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
    /**
     * Meldet einen Eintrag von einer bestimmten Client-IP. Der per-Entry-Limiter
     * ist auf IP+formatId gekeyt; distinkte IPs sind daher nötig, um den
     * Hide-Schwellwert legitim (durch mehrere unabhängige Melder) zu erreichen.
     */
    private function reportFrom(string $ip, string $formatId, string $reason = 'spam'): void
    {
        $this->client->request(
            'POST',
            '/api/v1/entries/' . $formatId . '/report',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
            content: json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        );
    }

    public function testReportIsStoredAnonymously(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->client->request('POST', '/api/v1/entries/com.example.shop/report',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.1.0.1'],
            content: json_encode(['reason' => 'broken_links', 'comment' => 'Link 404'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(204);
        $reports = $this->em->getRepository(Report::class)->findAll();
        self::assertCount(1, $reports);
        self::assertSame('Link 404', $reports[0]->comment);
    }

    public function testInvalidReasonYields400(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->client->request('POST', '/api/v1/entries/com.example.shop/report',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.2.0.1'],
            content: json_encode(['reason' => 'gefaellt-mir-nicht'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(400);
    }

    public function testSingleIpCannotReportSameEntryTwice(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->reportFrom('10.5.0.1', 'com.example.shop');
        self::assertResponseStatusCodeSame(204);

        // Zweite Meldung derselben IP zum selben Eintrag: gedrosselt (429),
        // damit eine einzelne IP den Hide-Schwellwert nicht allein erreicht.
        $this->reportFrom('10.5.0.1', 'com.example.shop');
        self::assertResponseStatusCodeSame(429);
    }

    public function testThresholdHidesEntryWithReportsFromDistinctIps(): void
    {
        $this->createPublishedEntry('com.example.shop');

        foreach (['10.3.0.1', '10.3.0.2', '10.3.0.3'] as $ip) { // REPORT_HIDE_THRESHOLD = 3
            $this->reportFrom($ip, 'com.example.shop');
            self::assertResponseStatusCodeSame(204);
        }

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(EntryStatus::Hidden, $entry->status);

        // versteckte Einträge können nicht weiter gemeldet werden
        $this->reportFrom('10.3.0.4', 'com.example.shop');
        self::assertResponseStatusCodeSame(404);
    }

    public function testResolvePublishClearsAllOpenReportsToPreventImmediateReHide(): void
    {
        $this->createPublishedEntry('com.example.shop');

        foreach (['10.4.0.1', '10.4.0.2', '10.4.0.3'] as $ip) { // REPORT_HIDE_THRESHOLD = 3
            $this->reportFrom($ip, 'com.example.shop');
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
        $this->reportFrom('10.4.0.9', 'com.example.shop');
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(EntryStatus::Published, $entry->status);
    }
}
