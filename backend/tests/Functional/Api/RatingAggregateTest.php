<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Rating;
use App\Tests\Functional\ApiTestCase;

final class RatingAggregateTest extends ApiTestCase
{
    private function makeAccount(string $selector): Account
    {
        $account = new Account($selector, 'hash-' . $selector);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function testDetailAndListExposeAggregate(): void
    {
        $entry = $this->createPublishedEntry('com.example.agg');
        $this->em->persist(new Rating($this->makeAccount(str_pad('a', 16, 'a')), $entry, 4));
        $this->em->persist(new Rating($this->makeAccount(str_pad('b', 16, 'b')), $entry, 5));
        $this->em->flush();

        $this->client->request('GET', '/api/v1/entries/com.example.agg');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(4.5, $this->json()['rating']['average']);
        self::assertSame(2, $this->json()['rating']['count']);

        // Kein q-Filter: q matcht gegen searchText (nicht formatId); der Eintrag
        // ist der einzige in der (pro Test zurückrollenden) DB.
        $this->client->request('GET', '/api/v1/entries');
        self::assertResponseStatusCodeSame(200);
        $item = null;
        foreach ($this->json()['items'] as $it) {
            if ($it['formatId'] === 'com.example.agg') {
                $item = $it;
            }
        }
        self::assertNotNull($item);
        self::assertSame(4.5, $item['rating']['average']);
        self::assertSame(2, $item['rating']['count']);
    }

    public function testUnratedEntryHasNullAverage(): void
    {
        $this->createPublishedEntry('com.example.noagg');

        $this->client->request('GET', '/api/v1/entries/com.example.noagg');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['rating']['average']);
        self::assertSame(0, $this->json()['rating']['count']);
    }
}
