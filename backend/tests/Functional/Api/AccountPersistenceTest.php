<?php
declare(strict_types=1);
namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Tests\Functional\ApiTestCase;

final class AccountPersistenceTest extends ApiTestCase
{
    public function testPersistAndFindBySelector(): void
    {
        $account = new Account('0123456789abcdef', 'hash-value');
        $this->em->persist($account);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => '0123456789abcdef']);
        self::assertNotNull($found);
        self::assertSame('hash-value', $found->tokenHash);
        self::assertEquals($found->createdAt, $found->lastSeenAt);
    }
}
