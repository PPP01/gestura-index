<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\SyncBlob;
use App\Tests\Functional\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regressionsnetz für den in Sub-Projekt A dokumentierten Vorbehalt: sowohl
 * das Konto-Löschen (ORM remove) als auch index:account:prune (DQL-Bulk-
 * DELETE, umgeht die ORM-Kaskade) müssen zugehörige SyncBlobs über die
 * DB-seitige ON-DELETE-CASCADE-Kaskade mit entfernen.
 */
final class SyncCascadeTest extends ApiTestCase
{
    /** @return string Klartext-Token */
    private function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    public function testAccountDeleteCascadesSyncBlobs(): void
    {
        $token = $this->createAccount();
        $this->client->request('PUT', '/api/account/sync/settings',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['baseVersion' => 0, 'ciphertext' => 'cipher'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->client->request('DELETE', '/api/account',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(SyncBlob::class)->count([]));
    }

    public function testPruneCascadesSyncBlobs(): void
    {
        $account = new Account(bin2hex(random_bytes(8)), 'hash');
        $account->lastSeenAt = new \DateTimeImmutable('-400 days');
        $this->em->persist($account);
        $this->em->persist(new SyncBlob($account, 'settings', 'cipher'));
        $this->em->flush();
        $this->em->clear();

        $command = (new Application(self::$kernel))->find('index:account:prune');
        $tester = new CommandTester($command);
        $tester->execute(['days' => '365']);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Account::class)->count([]));
        self::assertSame(0, $this->em->getRepository(SyncBlob::class)->count([]));
    }
}
