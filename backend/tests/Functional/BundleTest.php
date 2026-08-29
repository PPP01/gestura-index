<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\EntryStatus;
use App\Repository\EntryRepository;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testBundleReturnsSelectedPublishedEntriesInRequestOrder(): void
    {
        $this->createPublishedEntry('com.example.a');
        $this->createPublishedEntry('com.example.b');

        $this->api('POST', '/api/v1/bundle', ['ids' => ['com.example.b', 'com.example.a']]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame(1, $data['gesturaBundle']);
        self::assertCount(2, $data['entries']);
        self::assertSame('com.example.b', $data['entries'][0]['id']);
        self::assertSame('com.example.a', $data['entries'][1]['id']);
    }

    public function testBundleSkipsUnknownAndUnpublishedAndDeduplicates(): void
    {
        $this->createPublishedEntry('com.example.a');
        $hidden = $this->createPublishedEntry('com.example.hidden');
        $hidden->status = EntryStatus::Hidden;
        $this->em->flush();

        $this->api('POST', '/api/v1/bundle', ['ids' => ['com.example.a', 'com.example.a', 'com.example.hidden', 'com.example.ghost']]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertCount(1, $data['entries']);
        self::assertSame('com.example.a', $data['entries'][0]['id']);
    }

    public function testBundleWithOnlyUnknownIdsYieldsEmptyEntries(): void
    {
        $this->api('POST', '/api/v1/bundle', ['ids' => ['com.example.ghost']]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame(1, $data['gesturaBundle']);
        self::assertSame([], $data['entries']);
    }

    #[DataProvider('invalidBodies')]
    public function testBundleRejectsInvalidBody(array|string $body): void
    {
        // roher Content, um auch nicht-Objekt-Bodies zu senden
        $this->client->request('POST', '/api/v1/bundle', server: ['CONTENT_TYPE' => 'application/json'], content: is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public static function invalidBodies(): array
    {
        return [
            'kein JSON-Objekt' => ['nicht json'],
            'ids fehlt' => [['foo' => 'bar']],
            'ids leer' => [['ids' => []]],
            'ids kein Array' => [['ids' => 'com.example.a']],
            'ids nicht-String-Element' => [['ids' => [123]]],
            'zu viele ids' => [['ids' => array_map(fn ($i) => "com.example.$i", range(1, 201))]],
        ];
    }

    public function testBundleResponseHasPublicCors(): void
    {
        $this->createPublishedEntry('com.example.a');
        $this->api('POST', '/api/v1/bundle', ['ids' => ['com.example.a']]);
        self::assertSame('*', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
}
