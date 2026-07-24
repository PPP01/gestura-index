<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Submitter;
use App\Service\EditTokenService;
use App\Tests\Functional\ApiTestCase;

final class AccountSubmitTest extends ApiTestCase
{
    /** @return array{string, Account} Klartext-Token + Account-Entity */
    private function createAccountWithEntity(): array
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);
        $token = $this->json()['token'];
        [, $selector] = explode('_', $token, 3);
        $account = $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => $selector]);

        return [$token, $account];
    }

    private function submitAs(string $accountToken, string $formatId): void
    {
        // name variiert mit formatId: contentHash ignoriert id/version, ohne
        // diese Differenzierung würden zwei Aufrufe im selben Test (z.B.
        // Submitter-Wiederverwendung mit unterschiedlicher formatId) als
        // inhaltliches Duplikat (409) kollidieren.
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(['id' => $formatId, 'name' => ['en' => $formatId]]),
            'categories' => ['shopping', 'other'],
        ], $accountToken);
    }

    private function linkedSubmitter(Account $account, int $approvedCount = 0, bool $banned = false): Submitter
    {
        $generated = (new EditTokenService())->generate();
        $submitter = new Submitter($generated->selector, $generated->hash);
        $submitter->account = $account;
        $submitter->approvedCount = $approvedCount;
        $submitter->banned = $banned;
        $this->em->persist($submitter);
        $this->em->flush();

        return $submitter;
    }

    public function testFirstAccountSubmitCreatesLinkedSubmitterWithFallbackToken(): void
    {
        [$token, $account] = $this->createAccountWithEntity();

        $this->submitAs($token, 'com.example.acc-first');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $this->json()['status']);
        self::assertArrayHasKey('editToken', $this->json());

        $submitter = $this->em->getRepository(Submitter::class)->findOneBy(['account' => $account]);
        self::assertNotNull($submitter);
    }

    public function testSecondAccountSubmitReusesSubmitterWithoutNewToken(): void
    {
        [$token, $account] = $this->createAccountWithEntity();
        $this->submitAs($token, 'com.example.acc-one');
        self::assertResponseStatusCodeSame(201);

        $this->submitAs($token, 'com.example.acc-two');
        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('editToken', $this->json());
        self::assertSame(1, $this->em->getRepository(Submitter::class)->count(['account' => $account]));
    }

    public function testMigratedTrustPublishesImmediately(): void
    {
        [$token, $account] = $this->createAccountWithEntity();
        $this->linkedSubmitter($account, approvedCount: 3);

        $this->submitAs($token, 'com.example.acc-trusted');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('published', $this->json()['status']);
        self::assertArrayNotHasKey('editToken', $this->json());
    }

    public function testBelowThresholdStaysPending(): void
    {
        [$token, $account] = $this->createAccountWithEntity();
        $this->linkedSubmitter($account, approvedCount: 2);

        $this->submitAs($token, 'com.example.acc-low');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $this->json()['status']);
    }

    public function testTransformCodeAlwaysQueuedDespiteTrust(): void
    {
        [$token, $account] = $this->createAccountWithEntity();
        $this->linkedSubmitter($account, approvedCount: 5);

        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->enginePayload([
                'id' => 'com.example.acc-transform',
                'transformEnabled' => true,
                'transformCode' => 'return r;',
            ]),
            'categories' => ['search'],
        ], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $this->json()['status']);
    }

    public function testBannedBundleBlocksAccountSubmit(): void
    {
        [$token, $account] = $this->createAccountWithEntity();
        $this->linkedSubmitter($account, approvedCount: 5, banned: true);

        $this->submitAs($token, 'com.example.acc-banned');
        self::assertResponseStatusCodeSame(403);
    }
}
