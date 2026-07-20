<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Enum\EntryStatus;

final class DeleteTest extends ApiTestCase
{
    public function testOwnerCanSoftDelete(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->api('DELETE', '/api/v1/entries/com.example.shop', token: $token);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(EntryStatus::Deleted, $entry->status);

        // öffentlich nicht mehr sichtbar
        $this->api('GET', '/api/v1/entries/com.example.shop');
        self::assertResponseStatusCodeSame(404);
    }

    public function testForeignTokenCannotDelete(): void
    {
        $this->createPublishedEntry('com.example.shop');
        [, $foreignToken] = $this->createSubmitterWithToken();

        $this->api('DELETE', '/api/v1/entries/com.example.shop', token: $foreignToken);
        self::assertResponseStatusCodeSame(403);
    }
}
