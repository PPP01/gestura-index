<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Submitter;
use App\Tests\Functional\ApiTestCase;

final class SubmitterAccountPersistenceTest extends ApiTestCase
{
    public function testSubmitterCanBeLinkedToAccount(): void
    {
        $account = new Account(bin2hex(random_bytes(8)), 'hash');
        $this->em->persist($account);
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $this->em->flush();
        $submitterId = $submitter->id;
        $accountId = $account->id;
        $this->em->clear();

        $found = $this->em->getRepository(Submitter::class)->find($submitterId);
        self::assertNotNull($found->account);
        self::assertSame($accountId, $found->account->id);
    }
}
