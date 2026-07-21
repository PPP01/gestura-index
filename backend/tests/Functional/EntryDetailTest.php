<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\EntryStatus;

final class EntryDetailTest extends ApiTestCase
{
    public function testDetailContainsVersions(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->api('GET', '/api/v1/entries/com.example.shop');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame('com.example.shop', $data['formatId']);
        self::assertCount(1, $data['versions']);
        self::assertSame('1.0.0', $data['versions'][0]['semver']);
        self::assertFalse($data['versions'][0]['hasTransformCode']);
    }

    public function testNonPublishedEntryYields404(): void
    {
        $entry = $this->createPublishedEntry('com.example.hidden');
        $entry->status = EntryStatus::Hidden;
        $this->em->flush();

        $this->api('GET', '/api/v1/entries/com.example.hidden');
        self::assertResponseStatusCodeSame(404);

        $this->api('GET', '/api/v1/entries/com.example.unbekannt');
        self::assertResponseStatusCodeSame(404);
    }

    public function testVersionDownloadReturnsRawPayload(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->api('GET', '/api/v1/entries/com.example.shop/versions/1.0.0');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame(1, $data['gesturaMenu']);
        self::assertSame('com.example.shop', $data['id']);

        $this->api('GET', '/api/v1/entries/com.example.shop/versions/9.9.9');
        self::assertResponseStatusCodeSame(404);
    }
}
