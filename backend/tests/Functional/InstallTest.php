<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;

final class InstallTest extends ApiTestCase
{
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
