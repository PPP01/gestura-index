<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Rating;
use App\Enum\CommentStatus;
use App\Repository\RatingRepository;
use App\Tests\Functional\ApiTestCase;

final class RatingRepositoryTest extends ApiTestCase
{
    private function repo(): RatingRepository
    {
        return $this->em->getRepository(Rating::class);
    }

    private function makeAccount(string $selector): Account
    {
        $account = new Account($selector, 'hash-' . $selector);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function testAggregatesForGroupsByEntry(): void
    {
        $entry = $this->createPublishedEntry('com.example.agg');
        $a1 = $this->makeAccount(str_pad('a', 16, 'a'));
        $a2 = $this->makeAccount(str_pad('b', 16, 'b'));
        $this->em->persist(new Rating($a1, $entry, 4));
        $this->em->persist(new Rating($a2, $entry, 5));
        $this->em->flush();

        $agg = $this->repo()->aggregatesFor([$entry->id]);
        self::assertSame(4.5, $agg[$entry->id]['average']);
        self::assertSame(2, $agg[$entry->id]['count']);

        // Unbewerteter Eintrag fehlt in der Map:
        $other = $this->createPublishedEntry('com.example.none');
        self::assertArrayNotHasKey($other->id, $this->repo()->aggregatesFor([$other->id]));
    }

    public function testApprovedCommentsFiltersPendingAndNull(): void
    {
        $entry = $this->createPublishedEntry('com.example.rev');
        $approved = new Rating($this->makeAccount(str_pad('c', 16, 'c')), $entry, 5);
        $approved->comment = 'super';
        $approved->commentStatus = CommentStatus::Approved;
        $pending = new Rating($this->makeAccount(str_pad('d', 16, 'd')), $entry, 3);
        $pending->comment = 'wartet';
        $pending->commentStatus = CommentStatus::Pending;
        $starsOnly = new Rating($this->makeAccount(str_pad('e', 16, 'e')), $entry, 4); // kein Kommentar
        $this->em->persist($approved);
        $this->em->persist($pending);
        $this->em->persist($starsOnly);
        $this->em->flush();

        $comments = $this->repo()->approvedComments($entry, 0, 20);
        self::assertCount(1, $comments);
        self::assertSame('super', $comments[0]->comment);
        self::assertSame(1, $this->repo()->countApprovedComments($entry));
        self::assertCount(1, $this->repo()->pendingComments());
    }

    public function testUniqueConstraintPerAccountAndEntry(): void
    {
        $entry = $this->createPublishedEntry('com.example.uniq');
        $account = $this->makeAccount(str_pad('f', 16, 'f'));
        $this->em->persist(new Rating($account, $entry, 4));
        $this->em->flush();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->persist(new Rating($account, $entry, 2));
        $this->em->flush();
    }
}
