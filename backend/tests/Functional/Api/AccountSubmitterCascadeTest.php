<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Submitter;
use App\Tests\Functional\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regressionsnetz für die SET-NULL-Kaskade der Edit-Token-Migration: sowohl
 * Konto-Löschen (ORM remove) als auch index:account:prune (DQL-Bulk-DELETE)
 * müssen verknüpfte Submitter als anonyme Edit-Token-Submitter zurücklassen —
 * Einträge bleiben erhalten und über das Edit-Token verwaltbar.
 */
final class AccountSubmitterCascadeTest extends ApiTestCase
{
    public function testAccountDeleteSetsSubmitterAccountNullAndEditTokenStillWorks(): void
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);
        $accountToken = $this->json()['token'];
        [, $selector] = explode('_', $accountToken, 3);
        $account = $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => $selector]);

        [$submitter, $editToken] = $this->createSubmitterWithToken();
        $entry = $this->createPublishedEntry(submitter: $submitter);
        $entry->submitter->account = $account;
        $this->em->flush();
        $submitterId = $entry->submitter->id;
        $formatId = $entry->formatId;

        $this->client->request('DELETE', '/api/account',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken]);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $submitter = $this->em->getRepository(Submitter::class)->find($submitterId);
        self::assertNotNull($submitter);
        self::assertNull($submitter->account);

        // Der klassische Edit-Token-Weg funktioniert weiter:
        $this->client->request('DELETE', '/api/v1/entries/' . $formatId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $editToken]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testPruneSetsSubmitterAccountNull(): void
    {
        $account = new Account(bin2hex(random_bytes(8)), 'hash');
        $account->lastSeenAt = new \DateTimeImmutable('-400 days');
        $this->em->persist($account);
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $this->em->flush();
        $submitterId = $submitter->id;
        $this->em->clear();

        $command = (new Application(self::$kernel))->find('index:account:prune');
        $tester = new CommandTester($command);
        $tester->execute(['days' => '365']);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Account::class)->count([]));
        $submitter = $this->em->getRepository(Submitter::class)->find($submitterId);
        self::assertNotNull($submitter);
        self::assertNull($submitter->account);
    }
}
