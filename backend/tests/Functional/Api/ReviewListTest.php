<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Rating;
use App\Enum\CommentStatus;
use App\Tests\Functional\ApiTestCase;

final class ReviewListTest extends ApiTestCase
{
    private function makeAccount(string $selector): Account
    {
        $account = new Account($selector, 'hash-' . $selector);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function testListsOnlyApprovedCommentsAnonymously(): void
    {
        $entry = $this->createPublishedEntry('com.example.rev');

        $approved = new Rating($this->makeAccount(str_pad('a', 16, 'a')), $entry, 5);
        $approved->comment = 'sehr gut';
        $approved->commentStatus = CommentStatus::Approved;
        $pending = new Rating($this->makeAccount(str_pad('b', 16, 'b')), $entry, 2);
        $pending->comment = 'wartet';
        $pending->commentStatus = CommentStatus::Pending;
        $starsOnly = new Rating($this->makeAccount(str_pad('c', 16, 'c')), $entry, 4);
        $this->em->persist($approved);
        $this->em->persist($pending);
        $this->em->persist($starsOnly);
        $this->em->flush();

        $this->client->request('GET', '/api/v1/entries/com.example.rev/reviews');
        self::assertResponseStatusCodeSame(200);

        $data = $this->json();
        self::assertSame(1, $data['total']);
        self::assertCount(1, $data['items']);
        self::assertSame('sehr gut', $data['items'][0]['comment']);
        self::assertSame(5, $data['items'][0]['stars']);
        // Anonym: kein Autor-/Konto-Bezug im Item:
        self::assertArrayNotHasKey('account', $data['items'][0]);
        self::assertArrayNotHasKey('tokenSelector', $data['items'][0]);
    }

    public function testUnknownEntryIs404(): void
    {
        $this->client->request('GET', '/api/v1/entries/com.example.nope/reviews');
        self::assertResponseStatusCodeSame(404);
    }
}
