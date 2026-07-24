<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\SyncBlob;
use App\Tests\Functional\ApiTestCase;

final class SyncBlobPersistenceTest extends ApiTestCase
{
    public function testPersistAndFindByAccountAndCollection(): void
    {
        $account = new Account(bin2hex(random_bytes(8)), 'hash');
        $this->em->persist($account);
        $blob = new SyncBlob($account, 'settings', 'ciphertext-payload');
        $this->em->persist($blob);
        $this->em->flush();
        $accountId = $account->id;
        $this->em->clear();

        $found = $this->em->getRepository(SyncBlob::class)->findOneBy([
            'account' => $accountId,
            'collection' => 'settings',
        ]);
        self::assertNotNull($found);
        self::assertSame('ciphertext-payload', $found->ciphertext);
        self::assertSame(1, $found->version);
    }
}
