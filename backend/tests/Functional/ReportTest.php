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
