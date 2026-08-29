<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\EntryStatus;
use App\Repository\EntryRepository;

final class BundleTest extends ApiTestCase
{
    public function testRepositoryReturnsOnlyPublishedWithVersion(): void
    {
        $this->createPublishedEntry('com.example.one');
        $hidden = $this->createPublishedEntry('com.example.hidden');
        $hidden->status = EntryStatus::Hidden;
        $this->em->flush();

        /** @var EntryRepository $repo */
        $repo = static::getContainer()->get(EntryRepository::class);

        $found = $repo->findPublishedByFormatIds(['com.example.one', 'com.example.hidden', 'com.example.ghost']);

        self::assertCount(1, $found);
        self::assertSame('com.example.one', $found[0]->formatId);
        self::assertNotNull($found[0]->currentVersion);
        self::assertSame([], $repo->findPublishedByFormatIds([]));
    }
}
