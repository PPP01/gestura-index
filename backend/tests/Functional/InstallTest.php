<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Repository\EntryRepository;

final class InstallTest extends ApiTestCase
{
    public function testIncrementIsAtomicAndDoesNotLoseConcurrentCounts(): void
    {
        $entry = $this->createPublishedEntry('com.example.shop'); // in-memory installCount = 0

        // Ein paralleler Request erhöht den Zähler in der DB auf 10, während
        // unser Entry-Objekt noch den veralteten Wert 0 hält.
        $this->em->getConnection()->executeStatement(
            'UPDATE entry SET install_count = 10 WHERE id = :id',
            ['id' => $entry->id],
        );

        static::getContainer()->get(EntryRepository::class)->incrementInstallCount($entry);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        // Read-modify-write würde 1 schreiben (Verlust der 10); atomar → 11.
        self::assertSame(11, $reloaded->installCount);
    }

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
