<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\EntryStatus;

final class EntryListTest extends ApiTestCase
{
    public function testListsOnlyPublishedEntries(): void
    {
        $this->createPublishedEntry('com.example.one');
        $hidden = $this->createPublishedEntry('com.example.two');
        $hidden->status = EntryStatus::Hidden;
        $this->em->flush();

        $this->api('GET', '/api/v1/entries');

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame(1, $data['total']);
        self::assertSame('com.example.one', $data['items'][0]['formatId']);
        self::assertSame(['en' => 'Example Shop', 'de' => 'Beispiel-Shop'], $data['items'][0]['name']);
    }

    public function testFiltersBySiteCategoryTagAndQuery(): void
    {
        $this->createPublishedEntry('com.example.shop');
        $other = $this->createPublishedEntry('org.other.menu', ['patterns' => ['*other.org*'], 'name' => 'Andere Seite']);
        $other->tags = ['spezial'];
        $this->em->flush();

        $this->api('GET', '/api/v1/entries?site=example.com');
        self::assertSame(['com.example.shop'], array_column($this->json()['items'], 'formatId'));

        $this->api('GET', '/api/v1/entries?tag=spezial');
        self::assertSame(['org.other.menu'], array_column($this->json()['items'], 'formatId'));

        $this->api('GET', '/api/v1/entries?category=shopping');
        self::assertSame(2, $this->json()['total']);

        $this->api('GET', '/api/v1/entries?q=andere');
        self::assertSame(['org.other.menu'], array_column($this->json()['items'], 'formatId'));
    }

    public function testInvalidFilterValuesYield400(): void
    {
        $this->api('GET', '/api/v1/entries?category=quatsch');
        self::assertResponseStatusCodeSame(400);

        $this->api('GET', '/api/v1/entries?type=quatsch');
        self::assertResponseStatusCodeSame(400);

        $this->api('GET', '/api/v1/entries?sort=quatsch');
        self::assertResponseStatusCodeSame(400);
    }

    public function testPaginationAndSortByInstalls(): void
    {
        $a = $this->createPublishedEntry('com.example.a');
        $b = $this->createPublishedEntry('com.example.b');
        $a->installCount = 5;
        $b->installCount = 9;
        $this->em->flush();

        $this->api('GET', '/api/v1/entries?sort=installs&perPage=1&page=1');
        $data = $this->json();
        self::assertSame('com.example.b', $data['items'][0]['formatId']);
        self::assertSame(2, $data['total']);
    }

    public function testLikeWildcardsInFiltersAreLiteral(): void
    {
        $this->createPublishedEntry('com.example.one');

        $this->api('GET', '/api/v1/entries?q=%');
        self::assertSame(0, $this->json()['total']);

        $this->api('GET', '/api/v1/entries?site=%');
        self::assertSame(0, $this->json()['total']);

        $this->api('GET', '/api/v1/entries?tag=_');
        self::assertSame(0, $this->json()['total']);
    }

    public function testEtagYields304(): void
    {
        $this->createPublishedEntry('com.example.one');

        $this->api('GET', '/api/v1/entries');
        $etag = $this->client->getResponse()->headers->get('ETag');
        self::assertNotNull($etag);

        $this->client->request('GET', '/api/v1/entries', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(304, $this->client->getResponse()->getStatusCode());
    }
}
